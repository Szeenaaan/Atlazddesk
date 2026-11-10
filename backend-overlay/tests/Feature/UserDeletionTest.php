<?php
namespace Tests\Feature;

use App\Models\{Asset, Customer, TicketCategory, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_reactivate_then_delete_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $user->createToken('test');
        $this->actingAs($admin)->patchJson("/api/v2/users/{$user->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->assertSame(0, $user->tokens()->count());
        $this->getJson('/api/v2/users?status=inactive')->assertOk()->assertJsonPath('data.0.id', $user->id);
        $this->patchJson("/api/v2/users/{$user->id}/status", ['status' => 'active'])->assertOk();
        $this->deleteJson("/api/v2/users/{$user->id}")->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->getJson('/api/v2/users')->assertJsonMissing(['id' => $user->id]);
    }

    public function test_delete_preserves_role_and_customer_boundaries(): void
    {
        $customer = Customer::create(['company_name' => 'Own', 'customer_code' => 'DELETE-OWN']);
        $other = Customer::create(['company_name' => 'Other', 'customer_code' => 'DELETE-OTHER']);
        $admin = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $customer->id, 'status' => 'active']);
        $own = User::factory()->create(['role' => 'customer_user', 'customer_id' => $customer->id]);
        $foreign = User::factory()->create(['role' => 'customer_user', 'customer_id' => $other->id]);
        $technical = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->deleteJson("/api/v2/users/{$foreign->id}")->assertForbidden();
        $this->deleteJson("/api/v2/users/{$technical->id}")->assertForbidden();
        $this->deleteJson("/api/v2/users/{$admin->id}")->assertForbidden();
        $this->deleteJson("/api/v2/users/{$own->id}")->assertForbidden();
        $this->actingAs($foreign)->deleteJson("/api/v2/users/{$admin->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $foreign->id]);
    }
    public function test_business_history_is_preserved_after_account_deletion(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $user->createToken('retained');
        $customer = Customer::create(['company_name' => 'History', 'customer_code' => 'DELETE-HISTORY']);
        $contract = \App\Models\AmcContract::create(['customer_id' => $customer->id, 'contract_number' => 'DELETE-HISTORY', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'contract_type' => 'Annual']);
        \Illuminate\Support\Facades\DB::table('contract_documents')->insert(['amc_contract_id' => $contract->id, 'uploaded_by' => $user->id, 'file_name' => 'history.pdf', 'file_path' => 'private/history.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100]);
        $this->actingAs($admin)->deleteJson("/api/v2/users/{$user->id}")->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('contract_documents', ['uploaded_by' => $user->id]);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_last_client_admin_is_protected_per_company_and_delete_clears_asset_assignments(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $a = Customer::create(['company_name' => 'A', 'customer_code' => 'DELETE-A']);
        $b = Customer::create(['company_name' => 'B', 'customer_code' => 'DELETE-B']);
        $a1 = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $a->id]);
        $a2 = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $a->id]);
        $b1 = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $b->id]);
        $this->actingAs($actor)->getJson('/api/v2/users?role=customer_admin')->assertOk()
            ->assertJsonFragment(['id' => $b1->id, 'client_admin_count' => 1]);
        $this->deleteJson("/api/v2/users/{$b1->id}")->assertUnprocessable();
        $this->deleteJson("/api/v2/users/{$a1->id}")->assertNoContent();
        $this->deleteJson("/api/v2/users/{$a2->id}")->assertUnprocessable();

        $category = TicketCategory::create(['name' => 'Devices', 'slug' => 'delete-devices']);
        $asset = Asset::create(['customer_id' => $a->id, 'category_id' => $category->id, 'name' => 'Printer', 'asset_tag' => 'DELETE-PRINTER']);
        $user = User::factory()->create(['role' => 'customer_user', 'customer_id' => $a->id]);
        $user->assignedAssets()->attach($asset);
        $this->deleteJson("/api/v2/users/{$user->id}")->assertNoContent();
        $this->assertDatabaseMissing('asset_user', ['asset_id' => $asset->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

}
