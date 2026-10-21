<?php
namespace Tests\Feature;

use App\Models\{Asset, AssetType, Customer, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorDirectoryFixesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User { return User::factory()->create(['role' => 'admin', 'status' => 'active']); }

    public function test_asset_requires_customer_and_category_match_but_not_matching_type_id(): void {
        $admin = $this->admin();
        $customer = Customer::create(['company_name' => 'Alpha', 'customer_code' => 'A']);
        $other = Customer::create(['company_name' => 'Beta', 'customer_code' => 'B']);
        $category = TicketCategory::create(['name' => 'Configured category', 'slug' => 'configured']);
        $wrongCategory = TicketCategory::create(['name' => 'Other category', 'slug' => 'other']);
        AssetType::create(['name' => 'Separate type']);
        $type = AssetType::create(['name' => 'Independent type']);
        $asset = Asset::create(['name' => 'Camera', 'asset_tag' => 'CAM', 'customer_id' => $customer->id, 'category_id' => $category->id, 'asset_type_id' => $type->id]);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'medium', 'level' => 2]);
        TicketStatus::create(['name' => 'New', 'slug' => 'new', 'sort_order' => 1]);
        $body = ['subject' => 'Asset issue', 'description' => 'Needs inspection', 'customer_id' => $customer->id, 'category_id' => $category->id, 'priority_id' => $priority->id, 'asset_id' => $asset->id];
        $this->actingAs($admin)->postJson('/api/v2/tickets', array_replace($body, ['category_id' => $wrongCategory->id]))->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->postJson('/api/v2/tickets', array_replace($body, ['customer_id' => $other->id]))->assertUnprocessable();
        $this->postJson('/api/v2/tickets', $body)->assertCreated();
        $asset->update(['category_id' => null]);
        $this->postJson('/api/v2/tickets', $body)->assertUnprocessable();
        $this->postJson('/api/v2/tickets', array_replace($body, ['asset_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $actor = User::factory()->create(['role' => 'customer_user', 'status' => 'active', 'customer_id' => $customer->id]);
        $actor->assignedAssets()->attach($asset->id);
        unset($body['customer_id']);
        $asset->update(['category_id' => $category->id]);
        $this->actingAs($actor)->postJson('/api/v2/tickets', $body)->assertCreated();
        $this->getJson('/api/v2/directory/assets?customer_id='.$other->id)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_directory_filters_combine_and_count_beyond_bootstrap_cap(): void {
        $customer = Customer::create(['company_name' => 'Alpha', 'customer_code' => 'FIND-001', 'city' => 'Kochi', 'status' => 'active']);
        Customer::create(['company_name' => 'FIND Other', 'customer_code' => 'OTHER', 'city' => 'Delhi', 'status' => 'active']);
        $type = AssetType::create(['name' => 'Hardware']);
        $category = TicketCategory::create(['name' => 'General', 'slug' => 'general']);
        for ($i = 0; $i < 105; $i++) Asset::create(['name' => 'Equipment', 'asset_tag' => 'FIND-'.$i, 'customer_id' => $customer->id, 'category_id' => $category->id, 'asset_type_id' => $type->id]);
        $this->actingAs($this->admin())->getJson('/api/v2/directory/customers?search=FIND&city=Kochi&status=active')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $customer->id);
        $query = '/api/v2/directory/assets?search=FIND&customer_id='.$customer->id.'&asset_type_id='.$type->id.'&category_id='.$category->id.'&status=active';
        $this->getJson($query)->assertOk()->assertJsonPath('total', 105)->assertJsonCount(25, 'data');
        $this->getJson($query.'&page=5')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson($query.'&status=retired')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v2/directory/assets')->assertOk()->assertJsonPath('total', 105);
        $tech = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $this->actingAs($tech)->getJson($query)->assertForbidden();
    }

    public function test_only_contractor_admin_manages_existing_category_catalog_and_asset_classification(): void {
        $this->actingAs($this->admin())->postJson('/api/v2/categories', ['name' => 'Custom service'])->assertCreated();
        $category = TicketCategory::firstOrFail();
        $this->patchJson('/api/v2/categories/'.$category->id, ['name' => 'Renamed service'])->assertOk();
        $customer = Customer::create(['company_name' => 'Alpha', 'customer_code' => 'A']);
        $type = AssetType::create(['name' => 'Device']);
        $body = ['name' => 'Device', 'asset_tag' => 'D1', 'customer_id' => $customer->id];
        $this->postJson('/api/v2/directory/assets', $body)->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v2/directory/assets', $body + ['category_id' => $category->id])->assertCreated();
        $asset = Asset::firstOrFail();
        $this->assertNull($asset->asset_type_id);
        $asset->update(['asset_type_id' => $type->id]);
        $other = TicketCategory::create(['name' => 'Other', 'slug' => 'other']);
        $this->patchJson('/api/v2/directory/assets/'.$asset->id, ['category_id' => $other->id])->assertOk();
        $this->assertEquals($type->id, $asset->fresh()->asset_type_id);
        $this->getJson('/api/v2/directory/assets?category_id='.$category->id)->assertJsonPath('total', 0);
        $this->getJson('/api/v2/directory/assets?category_id='.$other->id)->assertJsonPath('total', 1);
        foreach (['customer_admin', 'customer_user', 'technician', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active', 'customer_id' => str_starts_with($role, 'customer') ? $customer->id : null]))->postJson('/api/v2/categories', ['name' => 'Forbidden'])->assertForbidden();
        }
    }
}
