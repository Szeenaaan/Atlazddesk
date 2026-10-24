<?php

namespace Tests\Feature;

use App\Models\{ActivityLog, Asset, AssetType, Customer, Ticket, TicketCategory, TicketComment, TicketPriority, TicketStatus, User};
use App\Services\Auth\{AccountSession, MfaSettings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Tests\TestCase;

final class CustomerWorkspaceAndCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function clientAccount(string $code): User
    {
        $customer = Customer::create(['company_name' => $code, 'customer_code' => $code, 'status' => 'active']);
        return User::factory()->create(['role' => 'customer_admin', 'customer_id' => $customer->id, 'status' => 'active']);
    }

    private function browser(User $user): Request
    {
        $request = Request::create('/api/v2/auth/me');
        $session = new Store('workspace-test', new ArraySessionHandler(120));
        $session->start(); $request->setLaravelSession($session);
        app(AccountSession::class)->claim($request, $user);
        return $request;
    }

    private function contractor(): User
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $browser = $this->browser($actor);
        $this->withHeader('Referer', 'http://localhost/')->actingAs($actor)->withSession([
            Auth::guard('web')->getName() => $actor->id,
            'amc_account_session_token' => $browser->session()->get('amc_account_session_token'),
        ]);
        return $actor;
    }

    private function ticket(User $user, TicketCategory $category, string $number): Ticket
    {
        $priority = TicketPriority::firstOrCreate(['slug' => 'medium'], ['name' => 'Medium', 'level' => 2]);
        $status = TicketStatus::firstOrCreate(['slug' => 'new'], ['name' => 'New', 'sort_order' => 1]);
        return Ticket::create(['customer_id' => $user->customer_id, 'created_by' => $user->id,
            'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id,
            'ticket_number' => $number, 'subject' => 'Customer issue', 'description' => 'Details']);
    }

    public function test_delegation_preserves_real_login_and_customer_session_and_scopes_writes(): void
    {
        $target = $this->clientAccount('FIRST');
        $other = $this->clientAccount('SECOND');
        $this->browser($target);
        $customerClaim = Cache::get('amc:account-session:'.$target->id.':0');
        $actor = $this->contractor();
        $category = TicketCategory::create(['name' => 'General', 'slug' => 'general']);
        $mine = $this->ticket($target, $category, 'OWN');
        $outside = $this->ticket($other, $category, 'OTHER');
        TicketComment::create(['ticket_id' => $mine->id, 'user_id' => $actor->id, 'comment' => 'Staff private note', 'is_internal' => true]);
        $ownUser = User::factory()->create(['role' => 'customer_user', 'customer_id' => $target->customer_id, 'status' => 'active']);
        $otherUser = User::factory()->create(['role' => 'customer_user', 'customer_id' => $other->customer_id, 'status' => 'active']);

        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertOk()
            ->assertJsonPath('user.id', $target->id)->assertJsonPath('acting_as.actor.id', $actor->id);
        $this->assertAuthenticatedAs($actor, 'web');
        $this->getJson('/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $target->id);
        $this->getJson('/api/v2/bootstrap')->assertOk()->assertJsonCount(1, 'customers')->assertJsonCount(1, 'tickets');
        $this->getJson('/api/v2/tickets/'.$mine->id)->assertOk()->assertJsonCount(0, 'data.comments');
        $this->getJson('/api/v2/tickets/'.$outside->id)->assertNotFound();
        $this->postJson('/api/v2/tickets/'.$mine->id.'/comments', ['comment' => 'Forbidden staff note', 'is_internal' => true])->assertForbidden();
        $this->patchJson('/api/v2/users/'.$otherUser->id.'/status', ['status' => 'inactive'])->assertNotFound();
        $this->patchJson('/api/v2/users/'.$ownUser->id.'/status', ['status' => 'inactive'])->assertOk();
        $audit = ActivityLog::where('event', 'user.status_changed')->latest('id')->firstOrFail();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($target->id, $audit->properties['acting_as_user_id']);
        $this->getJson('/api/v2/categories')->assertForbidden();
        $this->patchJson('/api/v2/auth/profile', ['name' => 'Unauthorized'])->assertForbidden();
        $this->getJson('/api/v2/auth/mfa/status')->assertForbidden();
        $this->postJson('/api/v2/auth/mfa/recovery/regenerate', [])->assertForbidden();
        $this->assertSame($customerClaim, Cache::get('amc:account-session:'.$target->id.':0'));
        $this->deleteJson('/api/v2/customer-workspace')->assertOk()->assertJsonPath('user.id', $actor->id);
        $this->getJson('/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $actor->id);
        $this->assertAuthenticatedAs($actor, 'web');
    }

    public function test_delegation_rejects_other_roles_and_inactive_targets_and_can_exit_after_target_deactivation(): void
    {
        $target = $this->clientAccount('TARGET');
        foreach (['customer_admin', 'customer_user', 'technician', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active', 'customer_id' => str_starts_with($role, 'customer') ? $target->customer_id : null]);
            $this->actingAs($user)->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertForbidden();
        }
        $this->contractor();
        $invalid = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->postJson('/api/v2/users/'.$invalid->id.'/dashboard')->assertNotFound();
        $target->update(['status' => 'inactive']);
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertNotFound();
        $target->update(['status' => 'active']);
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertOk();
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertForbidden();
        $target->update(['status' => 'inactive']);
        $this->getJson('/api/v2/bootstrap')->assertNotFound();
        $this->deleteJson('/api/v2/customer-workspace')->assertOk();
    }

    public function test_delegated_comments_record_the_real_actor_and_forged_context_is_rejected(): void
    {
        $target = $this->clientAccount('ACTOR');
        $actor = $this->contractor();
        $category = TicketCategory::create(['name' => 'General', 'slug' => 'general']);
        $ticket = $this->ticket($target, $category, 'ACTOR-TICKET');
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertOk();
        $this->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Public operator reply'])->assertCreated();
        $this->assertDatabaseHas('ticket_comments', ['ticket_id' => $ticket->id, 'comment' => 'Public operator reply', 'user_id' => $actor->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'ticket.commented', 'user_id' => $actor->id]);
        $asset = Asset::create(['name' => 'Customer device', 'asset_tag' => 'ACTOR-ASSET',
            'customer_id' => $target->customer_id, 'category_id' => $category->id, 'status' => 'active']);
        $this->postJson('/api/v2/tickets', ['subject' => 'Operator created ticket', 'description' => 'Customer request',
            'category_id' => $category->id, 'priority_id' => $ticket->priority_id, 'asset_id' => $asset->id])->assertForbidden();
        $this->assertDatabaseMissing('tickets', ['subject' => 'Operator created ticket']);
        $this->withSession([\App\Services\Auth\CustomerWorkspace::KEY.'_actor' => $target->id]);
        $this->getJson('/api/v2/bootstrap')->assertForbidden();
    }

    public function test_anonymous_workspace_entry_is_rejected(): void
    {
        $target = $this->clientAccount('ANON');
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertUnauthorized();
    }

    public function test_contractor_idle_limit_is_not_extended_by_switching_customer_workspaces(): void
    {
        $target = $this->clientAccount('IDLE');
        $this->contractor();
        $this->travel(170)->seconds();
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertOk();
        $this->travel(11)->seconds();
        $this->getJson('/api/v2/bootstrap')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_category_asset_filter_and_delete_preserve_asset_and_ticket_history(): void
    {
        $this->contractor();
        $client = $this->clientAccount('CATEGORIES');
        $empty = TicketCategory::create(['name' => 'Empty', 'slug' => 'empty']);
        $linked = TicketCategory::create(['name' => 'Linked', 'slug' => 'linked']);
        $history = TicketCategory::create(['name' => 'History', 'slug' => 'history']);
        $type = AssetType::create(['name' => 'Legacy type']);
        $asset = Asset::create(['name' => 'Equipment', 'asset_tag' => 'LINKED', 'asset_type_id' => $type->id,
            'customer_id' => $client->customer_id, 'category_id' => $linked->id, 'status' => 'retired']);
        $ticket = $this->ticket($client, $history, 'HISTORY');
        $this->getJson('/api/v2/categories?asset_filter=without_assets')->assertOk()->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Empty', 'assets_count' => 0]);
        $this->getJson('/api/v2/categories?asset_filter=with_assets')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $linked->id);
        $this->getJson('/api/v2/categories?asset_filter=invalid')->assertUnprocessable();
        $this->deleteJson('/api/v2/categories/'.$linked->id)->assertUnprocessable();
        $this->deleteJson('/api/v2/categories/'.$history->id)->assertUnprocessable();
        $this->deleteJson('/api/v2/categories/'.$empty->id)->assertOk();
        $this->assertDatabaseMissing('ticket_categories', ['id' => $empty->id]);
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'category_id' => $linked->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'category_id' => $history->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'category.deleted', 'subject_id' => $empty->id]);
    }

    public function test_customer_workspace_entry_never_bypasses_contractor_mfa(): void
    {
        $target = $this->clientAccount('MFA');
        $this->contractor();
        DB::table('amc_security_settings')->updateOrInsert(['key' => 'mfa_email_enabled'], ['enabled' => true, 'updated_at' => now()]);
        app(MfaSettings::class)->invalidate();
        $this->postJson('/api/v2/users/'.$target->id.'/dashboard')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->assertFalse(session()->has(\App\Services\Auth\CustomerWorkspace::KEY));
    }

    public function test_category_deletion_is_contractor_only(): void
    {
        $category = TicketCategory::create(['name' => 'Protected', 'slug' => 'protected']);
        $this->deleteJson('/api/v2/categories/'.$category->id)->assertUnauthorized();
        $customer = $this->clientAccount('DELETE');
        foreach (['customer_admin', 'customer_user', 'technician', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active', 'customer_id' => str_starts_with($role, 'customer') ? $customer->customer_id : null]);
            $this->actingAs($user)->deleteJson('/api/v2/categories/'.$category->id)->assertForbidden();
        }
        $this->assertDatabaseHas('ticket_categories', ['id' => $category->id]);
    }
}
