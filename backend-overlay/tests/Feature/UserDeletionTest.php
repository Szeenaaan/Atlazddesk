<?php
namespace Tests\Feature;

use App\Models\{Customer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_reactivate_then_permanently_delete_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $user->createToken('test');
        $this->actingAs($admin)->patchJson("/api/v2/users/{$user->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->assertSame(0, $user->tokens()->count());
        $this->getJson('/api/v2/users?status=inactive')->assertOk()->assertJsonPath('data.0.id', $user->id);
        $this->patchJson("/api/v2/users/{$user->id}/status", ['status' => 'active'])->assertOk();
        $this->deleteJson("/api/v2/users/{$user->id}")->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'user.deleted', 'subject_id' => $user->id]);
    }

    public function test_delete_preserves_role_and_customer_boundaries(): void
    {
        $customer = Customer::create(['company_name' => 'Own', 'customer_code' => 'DELETE-OWN']);
        $other = Customer::create(['company_name' => 'Other', 'customer_code' => 'DELETE-OTHER']);
        $admin = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $customer->id, 'status' => 'active']);
        $own = User::factory()->create(['role' => 'customer_user', 'customer_id' => $customer->id]);
        $foreign = User::factory()->create(['role' => 'customer_user', 'customer_id' => $other->id]);
        $technical = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->deleteJson("/api/v2/users/{$foreign->id}")->assertStatus(405);
        $this->deleteJson("/api/v2/users/{$technical->id}")->assertStatus(405);
        $this->deleteJson("/api/v2/users/{$admin->id}")->assertStatus(405);
        $this->deleteJson("/api/v2/users/{$own->id}")->assertStatus(405);
        $this->actingAs($foreign)->deleteJson("/api/v2/users/{$admin->id}")->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $foreign->id]);
    }
    public function test_business_history_blocks_deletion_without_removing_tokens_or_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $user->createToken('retained');
        $customer = Customer::create(['company_name' => 'History', 'customer_code' => 'DELETE-HISTORY']);
        $contract = \App\Models\AmcContract::create(['customer_id' => $customer->id, 'contract_number' => 'DELETE-HISTORY', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'contract_type' => 'Annual']);
        \Illuminate\Support\Facades\DB::table('contract_documents')->insert(['amc_contract_id' => $contract->id, 'uploaded_by' => $user->id, 'file_name' => 'history.pdf', 'file_path' => 'private/history.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100]);
        $this->actingAs($admin)->deleteJson("/api/v2/users/{$user->id}")->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('contract_documents', ['uploaded_by' => $user->id]);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseMissing('activity_logs', ['event' => 'user.deleted', 'subject_id' => $user->id]);
    }

}
