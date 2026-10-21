<?php

namespace Tests\Feature;

use App\Models\{AmcContract, AmcService, SlaPolicy, Asset, Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecordDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_details_include_customer_category_and_contract_relations(): void
    {
        $customer = Customer::create(['company_name' => 'Detail Client', 'customer_code' => 'DETAIL-A']);
        $category = TicketCategory::create(['name' => 'Networking', 'slug' => 'networking']);
        $asset = Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'name' => 'Router', 'asset_tag' => 'DETAIL-ROUTER']);
        $contract = AmcContract::create(['customer_id' => $customer->id, 'contract_number' => 'DETAIL-CONTRACT', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'contract_type' => 'Annual']);
        $policy = SlaPolicy::create(['name' => 'Detail SLA', 'critical_response_minutes' => 20, 'critical_resolution_minutes' => 180]);
        $contract->update(['sla_policy_id' => $policy->id, 'contract_value' => 1250, 'currency' => 'AED', 'notes' => 'Annual coverage']);
        $service = AmcService::create(['name' => 'Network maintenance', 'description' => 'Router and switch support', 'is_active' => true]);
        $contract->services()->attach($service->id, ['notes' => 'Business hours only']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->getJson("/api/v2/directory/customers/{$customer->id}")->assertOk()->assertJsonPath('company_name', 'Detail Client');
        $this->getJson("/api/v2/directory/assets/{$asset->id}")->assertOk()->assertJsonPath('customer.company_name', 'Detail Client')->assertJsonPath('category.name', 'Networking');
        $this->getJson("/api/v2/directory/contracts/{$contract->id}")->assertOk()->assertJsonPath('customer.company_name', 'Detail Client')
            ->assertJsonPath('sla_policy.name', 'Detail SLA')
            ->assertJsonMissingPath('sla_policy.critical_response_minutes')
            ->assertJsonPath('services.0.name', 'Network maintenance')
            ->assertJsonPath('services.0.description', 'Router and switch support')
            ->assertJsonPath('services.0.notes', 'Business hours only')
            ->assertJsonPath('notes', 'Annual coverage')->assertJsonPath('contract_value', '1250.00')
            ->assertJsonMissingPath('customer.users')->assertJsonMissingPath('password');
        foreach (['super_admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'customer_id' => $customer->id, 'status' => 'active']))
                ->getJson("/api/v2/directory/contracts/{$contract->id}")->assertForbidden();
        }
    }

    public function test_detail_dto_drops_secrets_unknown_attributes_and_internal_metadata(): void
    {
        foreach ([new Customer(['company_name' => 'Client']), new Asset(['name' => 'Asset']), new AmcContract(['contract_number' => 'SAFE'])] as $record) {
            foreach (['password', 'password_confirmation', 'credentials', 'api_token', 'created_at', 'asset_type_id'] as $field) {
                $record->setAttribute($field, $field === 'created_at' ? '2026-01-01 00:00:00' : 'private-value');
            }
            $related = new Customer(['company_name' => 'Related client']);
            $related->setAttribute('password', 'nested-secret');
            $record->setRelation('customer', $related);
            $data = (new \App\Http\Resources\DirectoryRecordResource($record))->resolve(request());
            foreach (['password', 'password_confirmation', 'credentials', 'api_token', 'created_at', 'asset_type_id'] as $field) {
                $this->assertArrayNotHasKey($field, $data);
            }
            $this->assertStringNotContainsString('nested-secret', json_encode($data));
        }
    }

    public function test_technician_details_are_admin_only_and_exclude_authentication_fields(): void
    {
        $technician = User::factory()->create(['role' => 'technician', 'status' => 'active', 'phone' => '+1234567890', 'last_login_at' => '2026-10-20 07:00:00']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $url = "/api/v2/directory/technicians/{$technician->id}";
        $response = $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('phone', '+1234567890')->assertJsonPath('role', 'technician');
        $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'phone', 'role', 'status', 'last_login_at'], array_keys($response->json()));
        $this->getJson("/api/v2/directory/technicians/{$admin->id}")->assertNotFound();
        foreach (['super_admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($actor)->getJson($url)->assertForbidden();
        }
        $model = new User(['role' => 'technician', 'name' => 'Safe profile']);
        $model->setRawAttributes(['role' => 'technician', 'name' => 'Safe profile', 'password' => 'secret', 'api_token' => 'secret', 'mfa_totp_secret' => 'secret']);
        $data = (new \App\Http\Resources\DirectoryRecordResource($model))->resolve(request());
        $this->assertSame(['name' => 'Safe profile', 'role' => 'technician'], $data);
    }

    public function test_customer_user_can_only_open_assigned_own_customer_assets(): void
    {
        $mine = Customer::create(['company_name' => 'Mine', 'customer_code' => 'DETAIL-MINE']);
        $other = Customer::create(['company_name' => 'Other', 'customer_code' => 'DETAIL-OTHER']);
        $category = TicketCategory::create(['name' => 'Hardware', 'slug' => 'hardware']);
        $assigned = Asset::create(['customer_id' => $mine->id, 'category_id' => $category->id, 'name' => 'Assigned', 'asset_tag' => 'DETAIL-ASSIGNED']);
        $unassigned = Asset::create(['customer_id' => $mine->id, 'category_id' => $category->id, 'name' => 'Unassigned', 'asset_tag' => 'DETAIL-UNASSIGNED']);
        $foreign = Asset::create(['customer_id' => $other->id, 'category_id' => $category->id, 'name' => 'Other', 'asset_tag' => 'DETAIL-FOREIGN']);
        $user = User::factory()->create(['role' => 'customer_user', 'customer_id' => $mine->id, 'status' => 'active']);
        $user->assignedAssets()->attach($assigned->id);
        $this->actingAs($user)->getJson("/api/v2/directory/assets/{$assigned->id}")->assertOk()->assertJsonPath('category.name', 'Hardware');
        foreach ([$unassigned, $foreign] as $asset) $this->getJson("/api/v2/directory/assets/{$asset->id}")->assertNotFound();
        $this->getJson("/api/v2/directory/customers/{$other->id}")->assertNotFound();
    }

    public function test_technician_reads_asset_context_only_through_assigned_tickets(): void
    {
        $customer = Customer::create(['company_name' => 'Assigned Client', 'customer_code' => 'DETAIL-TECH']);
        $category = TicketCategory::create(['name' => 'Systems', 'slug' => 'systems']);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'medium', 'level' => 2]);
        $status = TicketStatus::create(['name' => 'New', 'slug' => 'new']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $technician = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $assigned = Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'name' => 'Work asset', 'asset_tag' => 'DETAIL-TECH-A']);
        $other = Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'name' => 'Other asset', 'asset_tag' => 'DETAIL-TECH-B']);
        Ticket::create(['ticket_number' => 'DETAIL-TICKET', 'customer_id' => $customer->id, 'asset_id' => $assigned->id, 'assigned_to' => $technician->id, 'created_by' => $admin->id, 'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id, 'subject' => 'Assigned work', 'description' => 'Work']);
        $this->actingAs($technician)->getJson("/api/v2/directory/assets/{$assigned->id}")->assertForbidden();
        $this->getJson("/api/v2/directory/assets/{$other->id}")->assertForbidden();
        foreach (['assets', 'customers', 'maintenance'] as $resource) $this->getJson('/api/v2/directory/'.$resource)->assertForbidden();
        $this->getJson('/api/v2/bootstrap')->assertOk()->assertJsonPath('assets', [])->assertJsonPath('maintenance', [])->assertJsonPath('customers', []);
        $this->getJson('/api/v2/tickets/inbox')->assertOk()->assertJsonPath('data.0.asset', $assigned->name);

    }
}
