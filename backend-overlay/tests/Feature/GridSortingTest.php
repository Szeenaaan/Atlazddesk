<?php
namespace Tests\Feature;
use App\Models\{User, Customer, Asset, TicketCategory, AmcContract, PreventiveMaintenance};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class GridSortingTest extends TestCase
{
    use RefreshDatabase;
    public function test_sorting_precedes_pagination_and_supports_both_directions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        foreach (['Zebra', 'Alpha', 'Middle'] as $name) Customer::create(['company_name' => $name, 'customer_code' => $name]);
        $this->getJson('/api/v2/directory/customers?sort=company_name&direction=asc&per_page=1')->assertOk()->assertJsonPath('data.0.company_name', 'Alpha')->assertJsonPath('total', 3);
        $this->getJson('/api/v2/directory/customers?sort=company_name&direction=desc&per_page=1')->assertOk()->assertJsonPath('data.0.company_name', 'Zebra');
        $this->getJson('/api/v2/directory/customers?sort=password')->assertUnprocessable();
        $this->getJson('/api/v2/users?sort=name&direction=invalid')->assertUnprocessable();
    }
    public function test_contract_dates_and_maintenance_customer_order_are_supported(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $customer = Customer::create(['company_name' => 'Date Client', 'customer_code' => 'DATES']);
        foreach (['2027-01-01', '2026-12-31'] as $index => $date) {
            AmcContract::create(['customer_id' => $customer->id, 'contract_number' => 'SORT-'.$index, 'start_date' => '2026-01-01', 'end_date' => $date]);
        }
        PreventiveMaintenance::create(['customer_id' => $customer->id, 'title' => 'Inspection', 'frequency' => 'monthly', 'next_due_date' => '2026-12-01', 'status' => 'scheduled']);
        $this->getJson('/api/v2/directory/contracts?sort=end_date&direction=asc&per_page=1')->assertOk()->assertJsonPath('data.0.contract_number', 'SORT-1');
        $this->getJson('/api/v2/directory/maintenance?sort=customer&direction=asc')->assertOk()->assertJsonPath('data.0.customer.company_name', 'Date Client');
    }
    public function test_related_sort_preserves_customer_scope(): void
    {
        $category = TicketCategory::create(['name' => 'Network', 'slug' => 'network']);
        $own = Customer::create(['company_name' => 'Own', 'customer_code' => 'OWN']);
        $other = Customer::create(['company_name' => 'Other', 'customer_code' => 'OTHER']);
        foreach ([$own, $other] as $customer) Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'name' => $customer->company_name, 'asset_tag' => $customer->customer_code]);
        $this->actingAs(User::factory()->create(['role' => 'customer_admin', 'status' => 'active', 'customer_id' => $own->id]));
        $this->getJson('/api/v2/directory/assets?sort=category&direction=asc')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.customer_id', $own->id);
        $this->getJson('/api/v2/users?sort=customer&direction=asc')->assertOk();
        $this->getJson('/api/v2/tickets?sort=priority&direction=desc')->assertOk();
    }
}
