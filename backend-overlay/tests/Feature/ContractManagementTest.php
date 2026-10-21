<?php

namespace Tests\Feature;

use App\Models\{AmcContract, AmcService, Asset, Customer, SlaPolicy, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ContractManagementTest extends TestCase
{
    use RefreshDatabase;

    private function policyPayload(): array
    {
        $data = ['name' => 'Business SLA', 'is_active' => true];
        foreach (['critical', 'high', 'medium', 'low'] as $p) {
            $data[$p.'_response_minutes'] = 20;
            $data[$p.'_resolution_minutes'] = 180;
        }
        return $data;
    }

    public function test_admin_can_create_catalogs_and_link_or_change_existing_contract_coverage(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin);
        $policy = $this->postJson('/api/v2/sla-policies', $this->policyPayload())->assertCreated()->json('data.id');
        $service = $this->postJson('/api/v2/contract-services', ['name' => 'Network care', 'description' => 'Network equipment', 'is_active' => true])->assertCreated()->json('data.id');
        $this->getJson('/api/v2/contract-options')->assertOk()->assertJsonPath('policies.0.id', $policy)->assertJsonPath('services.0.id', $service);
        $customer = Customer::create(['company_name' => 'Contract Client', 'customer_code' => 'CONTRACT-MGMT']);
        $id = $this->postJson('/api/v2/directory/contracts', ['customer_id' => $customer->id, 'contract_number' => 'CONTRACT-MGMT',
            'contract_type' => 'Comprehensive', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'sla_policy_id' => $policy, 'service_ids' => [$service], 'notes' => 'Agreed scope'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('amc_contracts', ['id' => $id, 'sla_policy_id' => $policy, 'contract_type' => 'Comprehensive']);
        $this->assertDatabaseHas('amc_contract_service', ['amc_contract_id' => $id, 'amc_service_id' => $service]);
        $this->getJson("/api/v2/directory/contracts/$id")->assertOk()->assertJsonPath('sla_policy.id', $policy)->assertJsonPath('services.0.id', $service);
        $this->patchJson("/api/v2/directory/contracts/$id", ['contract_type' => 'Labor only'])->assertOk();
        $this->assertDatabaseHas('amc_contract_service', ['amc_contract_id' => $id, 'amc_service_id' => $service]);
        $this->patchJson("/api/v2/directory/contracts/$id", ['sla_policy_id' => null, 'service_ids' => []])->assertOk();
        $this->assertDatabaseMissing('amc_contract_service', ['amc_contract_id' => $id]);
        $this->assertDatabaseHas('amc_contracts', ['id' => $id, 'sla_policy_id' => null]);
    }

    public function test_catalog_mutations_are_admin_only_and_timing_values_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $data = $this->policyPayload();
        $data['critical_resolution_minutes'] = 5;
        $this->postJson('/api/v2/sla-policies', $data)->assertUnprocessable()->assertJsonValidationErrors('critical_resolution_minutes');
        $id = $this->postJson('/api/v2/sla-policies', $this->policyPayload())->assertCreated()->json('data.id');
        $service = $this->postJson('/api/v2/contract-services', ['name' => 'Support', 'is_active' => true])->assertCreated()->json('data.id');
        foreach (['super_admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->getJson('/api/v2/contract-options')->assertForbidden();
            $this->postJson('/api/v2/sla-policies', $this->policyPayload())->assertForbidden();
            $this->patchJson("/api/v2/sla-policies/$id", $this->policyPayload())->assertForbidden();
            $this->postJson('/api/v2/contract-services', ['name' => 'Blocked'])->assertForbidden();
            $this->patchJson("/api/v2/contract-services/$service", ['name' => 'Blocked'])->assertForbidden();
        }
    }

    public function test_invalid_or_new_inactive_coverage_does_not_replace_existing_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $customer = Customer::create(['company_name' => 'Preserved', 'customer_code' => 'PRESERVED-COVERAGE']);
        $service = AmcService::create(['name' => 'Existing service', 'is_active' => true]);
        $inactive = AmcService::create(['name' => 'Inactive service', 'is_active' => false]);
        $policy = SlaPolicy::create([...$this->policyPayload(), 'is_active' => false]);
        $contract = AmcContract::create(['customer_id' => $customer->id, 'contract_number' => 'PRESERVED', 'contract_type' => 'Custom legacy type',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'sla_policy_id' => $policy->id]);
        $contract->services()->attach($service->id, ['notes' => 'Retain service notes']);
        $url = "/api/v2/directory/contracts/{$contract->id}";
        $this->patchJson($url, ['service_ids' => [$inactive->id]])->assertUnprocessable();
        $this->patchJson($url, ['service_ids' => [999999]])->assertUnprocessable();
        $this->assertDatabaseHas('amc_contract_service', ['amc_contract_id' => $contract->id, 'amc_service_id' => $service->id, 'notes' => 'Retain service notes']);
        // Existing inactive policy links and custom contract type values remain editable.
        $this->patchJson($url, ['sla_policy_id' => $policy->id, 'notes' => 'Updated notes', 'service_ids' => [$service->id]])->assertOk();
        $this->assertDatabaseHas('amc_contracts', ['id' => $contract->id, 'contract_type' => 'Custom legacy type', 'sla_policy_id' => $policy->id]);
        $this->assertDatabaseHas('amc_contract_service', ['amc_contract_id' => $contract->id, 'notes' => 'Retain service notes']);
    }

    public function test_linked_sla_is_used_for_new_ticket_deadlines(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customer = Customer::create(['company_name' => 'SLA Client', 'customer_code' => 'CONTRACT-SLA']);
        $policy = SlaPolicy::create($this->policyPayload());
        AmcContract::create(['customer_id' => $customer->id, 'contract_number' => 'CONTRACT-SLA', 'contract_type' => 'Annual maintenance',
            'start_date' => today()->subDay(), 'end_date' => today()->addYear(), 'sla_policy_id' => $policy->id, 'status' => 'active']);
        $category = TicketCategory::create(['name' => 'Systems', 'slug' => 'contract-systems']);
        $asset = Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'asset_tag' => 'SLA-ASSET', 'name' => 'SLA asset']);
        $priority = TicketPriority::create(['name' => 'Critical', 'slug' => 'critical', 'level' => 4]);
        TicketStatus::create(['name' => 'New', 'slug' => 'new']);
        $this->freezeTime();
        $request = Request::create('/api/v2/tickets', 'POST');
        $request->setUserResolver(fn () => $admin);
        $ticket = app(\App\Services\TicketService::class)->create($request, ['customer_id' => $customer->id, 'category_id' => $category->id,
            'priority_id' => $priority->id, 'asset_id' => $asset->id, 'subject' => 'SLA verification', 'description' => 'Check linked SLA']);
        $this->assertSame($policy->id, $ticket->sla_policy_id);
        $this->assertSame(now()->addMinutes(20)->getTimestamp(), $ticket->sla_response_due_at->getTimestamp());
        $this->assertSame(now()->addMinutes(180)->getTimestamp(), $ticket->sla_resolution_due_at->getTimestamp());
    }
}
