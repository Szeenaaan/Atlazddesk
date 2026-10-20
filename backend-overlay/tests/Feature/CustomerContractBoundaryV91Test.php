<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\{Customer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Commercial AMC contracts belong to contractor Admin, not client workspaces. */
final class CustomerContractBoundaryV91Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    public function test_client_accounts_cannot_open_or_fetch_amc_contracts(): void
    {
        $customer = Customer::create([
            'company_name' => 'Client One',
            'customer_code' => 'CLIENT-V91',
            'status' => 'active',
        ]);
        foreach (['customer_admin', 'customer_user'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'customer_id' => $customer->id,
                'status' => 'active',
            ]);
            $this->actingAs($user)->getJson('/api/v2/bootstrap')
                ->assertOk()->assertJsonPath('contracts', []);
            $this->getJson('/api/v2/directory/contracts')->assertForbidden();
            $this->getJson('/api/v2/directory/contracts/1')->assertForbidden();
        }
    }

    public function test_contractor_admin_keeps_contract_management_visibility(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->getJson('/api/v2/bootstrap')
            ->assertOk()->assertJsonStructure(['contracts']);
        $this->getJson('/api/v2/directory/contracts')->assertOk();
    }
}
