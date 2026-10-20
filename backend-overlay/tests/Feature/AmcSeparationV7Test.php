<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Jobs\DispatchTicketNotice;
use App\Models\{AmcUserNotification, Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use App\Services\Auth\MfaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Mail};
use Tests\TestCase;

/** Isolated test DB only; never operate on a customer installation database. */
final class AmcSeparationV7Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (!str_ends_with((string)$database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role, ?Customer $customer = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('SecurePassword#2026'),
            'customer_id' => $customer?->id,
        ]);
    }

    private function client(string $code): Customer
    {
        return Customer::create(['company_name'=>'Hospital '.$code, 'customer_code'=>$code, 'status'=>'active']);
    }

    public function test_super_admin_has_technical_access_but_no_customer_or_ticket_workspace(): void
    {
        $this->actingAs($this->account('super_admin'));
        $this->getJson('/api/v2/admin/metrics')->assertOk()->assertJsonMissingPath('tickets_total');
        $this->getJson('/api/v2/admin/incidents')->assertOk();
        $this->getJson('/api/v2/admin/security/mfa')->assertForbidden();
        $this->getJson('/api/v2/bootstrap')->assertForbidden();
        $this->getJson('/api/v2/lookups')->assertForbidden();
        $this->getJson('/api/v2/users')->assertForbidden();
        $this->getJson('/api/v2/tickets')->assertForbidden();
        $this->getJson('/api/v2/reports/summary')->assertForbidden();
        $this->postJson('/api/v2/admin/customers/onboard', [])->assertForbidden();
        $client = $this->client('BOUNDARY-V8');
        $this->getJson('/api/v2/admin/client-mfa/customers/'.$client->id)->assertForbidden();
    }

    public function test_company_admin_manages_client_mfa_but_cannot_access_system_settings(): void
    {
        $admin = $this->account('admin');
        $client = $this->client('A-1');
        $this->actingAs($admin);
        $this->getJson('/api/v2/admin/metrics')->assertForbidden();
        $this->getJson('/api/v2/admin/security/mfa')->assertOk();
        $this->getJson('/api/v2/admin/client-mfa/customers/'.$client->id)
            ->assertOk()->assertJsonPath('data.totp_enabled', false);
        $this->putJson('/api/v2/admin/client-mfa/customers/'.$client->id, [
            'totp_enabled'=>true, 'email_enabled'=>false, 'require_mfa'=>true,
            'current_password'=>'SecurePassword#2026',
        ])->assertOk()->assertJsonPath('data.require_mfa', true);
        $this->assertDatabaseHas('amc_client_mfa_policies', ['customer_id'=>$client->id, 'require_mfa'=>1]);
    }

    public function test_mandatory_email_only_policy_is_rejected_to_avoid_account_lockout(): void
    {
        $client=$this->client('A-2');
        $this->actingAs($this->account('admin'))->putJson('/api/v2/admin/client-mfa/customers/'.$client->id, [
            'totp_enabled'=>false,'email_enabled'=>true,'require_mfa'=>true,
            'current_password'=>'SecurePassword#2026',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('amc_client_mfa_policies',['customer_id'=>$client->id]);
    }

    public function test_individual_override_is_limited_to_customer_users_and_can_be_removed(): void
    {
        $client = $this->client('C-1');
        $customerUser=$this->account('customer_user',$client);
        $this->actingAs($this->account('admin'));
        $path='/api/v2/admin/client-mfa/users/'.$customerUser->id;
        $this->putJson($path, [
            'totp_enabled'=>true,'email_enabled'=>false,'require_mfa'=>true,
            'current_password'=>'SecurePassword#2026',
        ])->assertOk()->assertJsonPath('data.effective.require_mfa',true);
        $this->assertTrue(app(MfaSettings::class)->requiredFor($customerUser));
        $this->deleteJson($path,['current_password'=>'SecurePassword#2026'])
            ->assertOk()->assertJsonPath('data.override',null);
        $this->assertFalse(app(MfaSettings::class)->requiredFor($customerUser));
        $this->getJson('/api/v2/admin/client-mfa/users/'.$this->account('super_admin')->id)->assertForbidden();
    }

    public function test_customer_admin_and_technician_cannot_manage_client_mfa(): void
    {
        $customer=$this->client('C-2');
        foreach (['customer_admin','customer_user','technician'] as $role) {
            $this->actingAs($this->account($role, $role === 'technician' ? null : $customer));
            $this->getJson('/api/v2/admin/client-mfa/customers/'.$customer->id)->assertForbidden();
            $this->putJson('/api/v2/admin/client-mfa/customers/'.$customer->id, [])->assertForbidden();
        }
    }

    public function test_notifications_are_isolated_and_can_be_marked_read(): void
    {
        $first=$this->account('technician');
        $other=$this->account('technician');
        $a=AmcUserNotification::create([
            'user_id'=>$first->id, 'event'=>'assigned', 'title'=>'Ticket assigned',
            'message'=>'A new task','deduplication_key'=>hash('sha256','a'),
        ]);
        $b=AmcUserNotification::create([
            'user_id'=>$other->id, 'event'=>'assigned', 'title'=>'Different ticket',
            'message'=>'Other task','deduplication_key'=>hash('sha256','b'),
        ]);
        $this->actingAs($first)->getJson('/api/v2/notifications')
            ->assertOk()->assertJsonPath('success',true)->assertJsonPath('statuscode',200)
            ->assertJsonPath('data.unread_count',1)->assertJsonCount(1,'data.items');
        $this->patchJson('/api/v2/notifications/'.$b->id.'/read')->assertNotFound();
        $this->patchJson('/api/v2/notifications/'.$a->id.'/read')->assertOk();
        $this->assertNotNull($a->fresh()->read_at);
        $this->getJson('/api/v2/notifications')->assertJsonPath('data.unread_count',0);
        $this->actingAs($this->account('super_admin'))->getJson('/api/v2/notifications')->assertForbidden();
    }

    public function test_ticket_assignment_creates_a_technician_notification(): void
    {
        Mail::fake();
        $client=$this->client('N-1');
        $tech=$this->account('technician');
        $admin=$this->account('admin');
        $category=TicketCategory::create(['name'=>'Network','slug'=>'network']);
        $priority=TicketPriority::create(['name'=>'High','slug'=>'high','level'=>3]);
        $status=TicketStatus::create(['name'=>'Assigned','slug'=>'assigned','is_closed'=>false]);
        $ticket=Ticket::create([
            'ticket_number'=>'TASK-V7-1','customer_id'=>$client->id,'created_by'=>$admin->id,
            'category_id'=>$category->id,'priority_id'=>$priority->id,'status_id'=>$status->id,
            'assigned_to'=>$tech->id,'subject'=>'Router offline','description'=>'Investigate the router',
        ]);
        (new DispatchTicketNotice($ticket->id, 'assigned'))->handle();
        $this->assertDatabaseHas('amc_user_notifications', [
            'user_id'=>$tech->id, 'ticket_id'=>$ticket->id, 'event'=>'assigned',
        ]);
        (new DispatchTicketNotice($ticket->id, 'assigned'))->handle();
        $this->assertSame(1,AmcUserNotification::where('user_id',$tech->id)->where('ticket_id',$ticket->id)->count());
    }
}
