<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\{Asset, AssetType, Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Run against an isolated *_test database. Never run RefreshDatabase on production DB. */
final class AmcRoleMatrixV6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // RefreshDatabase runs from Laravel's parent setUp(): reject unsafe DBs first.
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }
        parent::setUp();
        // Test RBAC separately from the challenge handshake; auth and role middleware remain active.
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role, ?Customer $customer = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'customer_id' => $customer?->id,
        ]);
    }

    private function customer(string $code): Customer
    {
        return Customer::create(['company_name' => 'Client '.$code, 'customer_code' => $code, 'status' => 'active']);
    }

    private function credentials(): array
    {
        return [
            'name'=>'Hospital Systems', 'email'=>'frontdesk@example.test',
            'password'=>'SecurePassword#2026', 'password_confirmation'=>'SecurePassword#2026',
            'role'=>'customer_admin',
        ];
    }

    public function test_admin_cannot_read_super_admin_diagnostics_or_change_mfa(): void
    {
        $this->actingAs($this->account('admin'))
            ->getJson('/api/v2/admin/metrics')->assertForbidden();
        $this->getJson('/api/v2/admin/incidents')->assertForbidden();
        $this->getJson('/api/v2/admin/audit')->assertForbidden();
        $this->getJson('/api/v2/admin/security/mfa')->assertOk();
        $this->patchJson('/api/v2/admin/security/mfa', ['totp_enabled'=>true,'current_password'=>'NotRelevant'])->assertUnprocessable();
        $this->getJson('/api/v2/health')->assertForbidden();
    }

    public function test_super_admin_can_access_system_diagnostics(): void
    {
        $this->actingAs($this->account('super_admin'))
            ->getJson('/api/v2/admin/metrics')->assertOk();
        $this->getJson('/api/v2/admin/audit')->assertOk();
        $this->getJson('/api/v2/admin/incidents')->assertOk();
        $this->getJson('/api/v2/admin/security/mfa')->assertForbidden();
    }

    public function test_admin_onboards_company_and_first_customer_admin_atomically(): void
    {
        $data = [
            'company_name'=>'Regional Hospital', 'customer_code'=>'HOSP-0001',
            'customer_admin_name'=>'Helpdesk Lead', 'customer_admin_email'=>'lead@hospital.test',
            'customer_admin_password'=>'SecurePassword#2026',
            'customer_admin_password_confirmation'=>'SecurePassword#2026',
        ];
        $this->actingAs($this->account('admin'))
            ->postJson('/api/v2/admin/customers/onboard', $data)
            ->assertCreated()->assertJsonPath('data.customer_admin.role','customer_admin');
        $hospital = Customer::where('customer_code','HOSP-0001')->firstOrFail();
        $contact = User::where('email','lead@hospital.test')->firstOrFail();
        $this->assertSame($hospital->id, $contact->customer_id);
        $this->assertTrue(password_verify($data['customer_admin_password'], $contact->password));
        $this->assertSame(1, User::where('role','customer_admin')->count());
    }

    public function test_invalid_customer_admin_does_not_create_orphan_company(): void
    {
        $data = [
            'company_name'=>'Invalid Hospital', 'customer_code'=>'HOSP-9999',
            'customer_admin_name'=>'Incomplete', 'customer_admin_email'=>'invalid-email',
            'customer_admin_password'=>'wrong', 'customer_admin_password_confirmation'=>'wrong',
        ];
        $this->actingAs($this->account('admin'))
            ->postJson('/api/v2/admin/customers/onboard', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('customers', ['customer_code'=>'HOSP-9999']);
    }

    public function test_customer_admin_cannot_onboard_another_company(): void
    {
        $this->actingAs($this->account('customer_admin', $this->customer('TEN-1')))
            ->postJson('/api/v2/admin/customers/onboard', [])->assertForbidden();
    }

    public function test_admin_can_register_technician_but_cannot_create_super_admin(): void
    {
        $this->actingAs($this->account('admin'))
            ->postJson('/api/v2/users', [
                'name'=>'Technician One','email'=>'tech1@example.test','role'=>'technician',
                'password'=>'SecurePassword#2026','password_confirmation'=>'SecurePassword#2026',
            ])->assertCreated();
        $this->postJson('/api/v2/users', [
            'name'=>'Attack','email'=>'attack@example.test','role'=>'super_admin',
            'password'=>'SecurePassword#2026','password_confirmation'=>'SecurePassword#2026',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email'=>'attack@example.test']);
    }

    public function test_customer_admin_creates_only_own_customer_users(): void
    {
        $mine = $this->customer('TEN-A');
        $other = $this->customer('TEN-B');
        $this->actingAs($this->account('customer_admin', $mine));
        $this->postJson('/api/v2/users', [
            'name'=>'Customer User','email'=>'own@example.test','role'=>'customer_user',
            'password'=>'SecurePassword#2026','password_confirmation'=>'SecurePassword#2026',
        ])->assertCreated();
        $this->assertDatabaseHas('users', [
            'email'=>'own@example.test','role'=>'customer_user','customer_id'=>$mine->id,
        ]);
        $this->postJson('/api/v2/users', [
            'name'=>'Cross tenant','email'=>'cross@example.test','role'=>'customer_user',
            'customer_id'=>$other->id,
            'password'=>'SecurePassword#2026','password_confirmation'=>'SecurePassword#2026',
        ])->assertUnprocessable();
        $this->postJson('/api/v2/users', [
            'name'=>'Privilege','email'=>'priv@example.test','role'=>'admin',
            'password'=>'SecurePassword#2026','password_confirmation'=>'SecurePassword#2026',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email'=>'priv@example.test']);
    }

    public function test_customer_admin_can_register_assets_only_for_own_company(): void
    {
        $mine = $this->customer('ASSET-A');
        $other = $this->customer('ASSET-B');
        $category = TicketCategory::create(['name'=>'Equipment','slug'=>'equipment']);
        $type = AssetType::create(['name'=>'Server','is_active'=>true]);
        $foreign = Asset::create([
            'customer_id'=>$other->id,'asset_type_id'=>$type->id,
            'asset_tag'=>'FOREIGN-001','name'=>'Foreign server','status'=>'active',
        ]);
        $this->actingAs($this->account('customer_admin', $mine));
        $this->postJson('/api/v2/directory/assets', [
            'name'=>'Hospital server','asset_tag'=>'HOSP-001','asset_type_id'=>$type->id,'category_id'=>$category->id,
        ])->assertCreated();
        $this->assertDatabaseHas('assets', ['asset_tag'=>'HOSP-001','customer_id'=>$mine->id]);
        $this->postJson('/api/v2/directory/assets', [
            'name'=>'Attempt','asset_tag'=>'HOSP-002','asset_type_id'=>$type->id,'category_id'=>$category->id,
            'customer_id'=>$other->id,
        ])->assertUnprocessable();
        $this->patchJson('/api/v2/directory/assets/'.$foreign->id, ['name'=>'Tampered'])->assertNotFound();
        $this->assertSame('Foreign server', $foreign->fresh()->name);
    }

    public function test_customer_user_and_technician_cannot_manage_clients_users_or_assets(): void
    {
        $customer = $this->customer('LOCK-1');
        foreach (['customer_user','technician'] as $role) {
            $this->actingAs($this->account($role, $role === 'customer_user' ? $customer : null));
            $this->postJson('/api/v2/users', $this->credentials())->assertForbidden();
            $this->postJson('/api/v2/directory/assets', [])->assertForbidden();
            $this->postJson('/api/v2/admin/customers/onboard', [])->assertForbidden();
        }
    }

    public function test_technician_inbox_contains_only_assigned_tickets_and_supports_reply(): void
    {
        $customer = $this->customer('INBOX-1');
        $category = TicketCategory::create(['name'=>'Network','slug'=>'network']);
        $priority = TicketPriority::create(['name'=>'High','slug'=>'high','level'=>3]);
        $status = TicketStatus::create(['name'=>'Assigned','slug'=>'assigned','is_closed'=>false]);
        $technician = $this->account('technician');
        $otherTechnician = $this->account('technician');
        $admin = $this->account('admin');
        $create = function (string $number, User $assignee) use ($customer, $category, $priority, $status, $admin) {
            return Ticket::create([
                'ticket_number'=>$number,'customer_id'=>$customer->id,'created_by'=>$admin->id,
                'category_id'=>$category->id,'priority_id'=>$priority->id,
                'status_id'=>$status->id,'assigned_to'=>$assignee->id,
                'subject'=>'Support request','description'=>'Please inspect',
            ]);
        };
        $mine = $create('TASK-001',$technician);
        $notMine = $create('TASK-002',$otherTechnician);
        $this->actingAs($technician)->getJson('/api/v2/tickets/inbox')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$mine->id);
        $this->getJson('/api/v2/tickets/'.$notMine->id)->assertNotFound();
        $this->postJson('/api/v2/tickets/'.$mine->id.'/comments', [
            'comment'=>'Started diagnosis','is_internal'=>true,
        ])->assertCreated();
        $this->postJson('/api/v2/tickets', [])->assertForbidden();
    }

    public function test_legacy_service_manager_is_not_implicitly_admin(): void
    {
        $this->actingAs($this->account('service_manager'))
            ->getJson('/api/v2/bootstrap')->assertForbidden();
    }
}
