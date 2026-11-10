<?php

namespace Tests\Feature;

use App\Models\{ActivityLog, Customer, TechnicianDesignation, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianDesignationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_only_contractor_admin_manages_installation_designations(): void
    {
        $designation = TechnicianDesignation::create(['name' => 'Senior', 'level' => 3, 'is_active' => true]);
        foreach (['super_admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->getJson('/api/v2/designations')->assertForbidden();
            $this->postJson('/api/v2/designations', ['name' => 'Blocked', 'level' => 3, 'is_active' => true])->assertForbidden();
            $this->patchJson('/api/v2/designations/'.$designation->id, ['name' => 'Blocked', 'level' => 4, 'is_active' => false])->assertForbidden();
            $this->deleteJson('/api/v2/designations/'.$designation->id)->assertForbidden();
        }
        $this->actingAs($this->admin());
        $this->postJson('/api/v2/designations', ['name' => 'Specialist', 'level' => 3, 'is_active' => true])->assertCreated();
        $this->patchJson('/api/v2/designations/'.$designation->id, ['name' => 'Senior', 'level' => 4, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v2/designations')->assertOk();
        $this->deleteJson('/api/v2/designations/'.$designation->id)->assertOk();
        $this->assertTrue(ActivityLog::where('event', 'designation.deleted')->where('subject_id', $designation->id)->exists());
    }

    public function test_hierarchy_level_cannot_exceed_fifteen(): void
    {
        $this->actingAs($this->admin());
        $this->postJson('/api/v2/designations', ['name' => 'Principal', 'level' => 15, 'is_active' => true])
            ->assertCreated();
        $this->postJson('/api/v2/designations', ['name' => 'Invalid', 'level' => 16, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('level');
        $designation = TechnicianDesignation::where('name', 'Principal')->firstOrFail();
        $this->patchJson('/api/v2/designations/'.$designation->id, ['name' => 'Principal', 'level' => 16, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->assertSame(15, (int) $designation->fresh()->level);
    }

    public function test_technician_creation_requires_active_local_designation_and_classification(): void
    {
        $this->actingAs($this->admin());
        $body = ['name' => 'New technician', 'email' => 'new-tech@example.test', 'password' => 'SecureTest123!',
            'password_confirmation' => 'SecureTest123!', 'role' => 'technician'];
        $this->postJson('/api/v2/users', $body)->assertUnprocessable()->assertJsonValidationErrors(['designation_id', 'workforce_type']);
        $designation = TechnicianDesignation::create(['name' => 'Engineer', 'level' => 2, 'is_active' => false]);
        foreach ([$designation->id, 99999999] as $id) {
            $this->postJson('/api/v2/users', $body + ['designation_id' => $id, 'workforce_type' => 'employee'])
                ->assertUnprocessable()->assertJsonValidationErrors('designation_id');
        }
        $designation->update(['is_active' => true]);
        $this->postJson('/api/v2/users', $body + ['designation_id' => $designation->id, 'workforce_type' => 'freelancer'])->assertCreated();
        $tech = User::where('email', $body['email'])->firstOrFail();
        $this->assertSame($designation->id, (int) $tech->designation_id);
        $this->assertSame('freelancer', $tech->workforce_type);
        $this->getJson('/api/v2/directory/technicians/'.$tech->id)->assertOk()->assertJsonPath('designation.name', 'Engineer');
        $this->getJson('/api/v2/users/'.$tech->id)->assertOk()->assertJsonPath('designation.name', 'Engineer');
        $this->getJson('/api/v2/users?designation_id='.$designation->id)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v2/designations/'.$designation->id)->assertUnprocessable();
    }

    public function test_identity_edit_also_requires_an_active_designation_and_preserves_account_checks(): void
    {
        $admin = $this->admin();
        $tech = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        // Account edits still reject authenticated requests without a browser session.
        $this->actingAs($admin)->patchJson('/api/v2/users/'.$tech->id.'/details', ['name' => 'Changed name'])
            ->assertForbidden();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Referer', 'http://localhost/')->actingAs($admin)->withSession([
            \Illuminate\Support\Facades\Auth::guard('web')->getName() => $admin->id,
            'amc_mfa_verified_user_id' => $admin->id,
        ]);
        $this->patchJson('/api/v2/users/'.$tech->id.'/details', ['name' => 'Changed name'])
            ->assertUnprocessable()->assertJsonValidationErrors('designation_id');
        $tech->designation->update(['is_active' => false]);
        $this->patchJson('/api/v2/users/'.$tech->id.'/details', ['name' => 'Changed name', 'designation_id' => $tech->designation_id])
            ->assertUnprocessable()->assertJsonValidationErrors('designation_id');
        $this->assertSame($tech->name, $tech->fresh()->name);
        // Supplying a valid designation must not bypass the existing password check for identity changes.
        $tech->designation->update(['is_active' => true]);
        $this->patchJson('/api/v2/users/'.$tech->id.'/details', ['name' => 'Changed name', 'designation_id' => $tech->designation_id])
            ->assertUnprocessable();
        $this->assertSame($tech->name, $tech->fresh()->name);
    }

    public function test_workforce_changes_reject_inactive_or_foreign_ids_and_customer_accounts(): void
    {
        $tech = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $designation = TechnicianDesignation::create(['name' => 'Lead', 'level' => 5, 'is_active' => true]);
        $this->actingAs($this->admin());
        $this->patchJson('/api/v2/users/'.$tech->id.'/workforce', ['workforce_type' => 'employee'])->assertUnprocessable();
        $this->patchJson('/api/v2/users/'.$tech->id.'/workforce', ['designation_id' => 99999999, 'workforce_type' => 'employee'])->assertUnprocessable();
        $this->patchJson('/api/v2/users/'.$tech->id.'/workforce', ['designation_id' => $designation->id, 'workforce_type' => 'employee'])->assertOk();
        $designation->update(['is_active' => false]);
        $this->patchJson('/api/v2/users/'.$tech->id.'/workforce', ['designation_id' => $designation->id, 'workforce_type' => 'freelancer'])->assertUnprocessable();
        $customer = Customer::create(['company_name' => 'Other organization', 'customer_code' => 'DES-OTHER']);
        $other = User::factory()->create(['role' => 'customer_user', 'customer_id' => $customer->id, 'status' => 'active']);
        $designation->update(['is_active' => true]);
        $this->patchJson('/api/v2/users/'.$other->id.'/workforce', ['designation_id' => $designation->id, 'workforce_type' => 'employee'])->assertNotFound();
        $this->actingAs($other)->patchJson('/api/v2/users/'.$tech->id.'/workforce', ['designation_id' => $designation->id, 'workforce_type' => 'employee'])->assertForbidden();
    }
}
