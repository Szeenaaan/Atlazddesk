<?php

namespace Tests\Feature;

use App\Models\{Asset, AssetType, Customer, TicketCategory, TicketPriority, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContractorUiV1012Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: DB_DATABASE must end in _test.');
        }
        parent::setUp();
    }

    private function customers(): array
    {
        return [
            Customer::create(['company_name' => 'Alpha Services', 'customer_code' => 'ALPHA-001']),
            Customer::create(['company_name' => 'Beta Services', 'customer_code' => 'BETA-002']),
        ];
    }

    public function test_contractor_user_search_combines_customer_role_and_status(): void
    {
        [$alpha, $beta] = $this->customers();
        $admin = User::factory()->create(['role' => 'admin', 'customer_id' => null, 'status' => 'active']);
        $match = User::factory()->create(['name' => 'Unique Alpha Technician', 'role' => 'technician', 'customer_id' => null, 'status' => 'active']);
        $target = User::factory()->create(['name' => 'Unique Alpha User', 'role' => 'customer_user', 'customer_id' => $alpha->id, 'status' => 'active']);
        User::factory()->create(['name' => 'Unique Alpha Inactive', 'role' => 'customer_user', 'customer_id' => $alpha->id, 'status' => 'inactive']);
        User::factory()->create(['name' => 'Unique Beta User', 'role' => 'customer_user', 'customer_id' => $beta->id, 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/api/v2/users?search=Unique&customer_id='.$alpha->id.'&role=customer_user&status=active');
        $response->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $target->id);

        $workforce = $this->actingAs($admin)->getJson('/api/v2/users?search=Unique&workforce_only=1');
        $workforce->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $match->id);
    }

    public function test_customer_admin_cannot_expand_its_user_search_scope(): void
    {
        [$alpha, $beta] = $this->customers();
        $actor = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $alpha->id, 'status' => 'active']);
        $mine = User::factory()->create(['name' => 'Scoped Person', 'role' => 'customer_user', 'customer_id' => $alpha->id, 'status' => 'active']);
        User::factory()->create(['name' => 'Scoped Person', 'role' => 'customer_user', 'customer_id' => $beta->id, 'status' => 'active']);

        $this->actingAs($actor)->getJson('/api/v2/users?search=Scoped&customer_id='.$beta->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_ticket_asset_must_belong_to_selected_customer(): void
    {
        [$alpha, $beta] = $this->customers();
        $actor = User::factory()->create(['role' => 'admin', 'customer_id' => null, 'status' => 'active']);
        $type = AssetType::create(['name' => 'Server']);
        $otherAsset = Asset::create(['name' => 'Other company server', 'asset_tag' => 'BAD-ASSOC', 'customer_id' => $beta->id, 'asset_type_id' => $type->id]);
        $category = TicketCategory::create(['name' => 'Hardware', 'slug' => 'hardware']);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'medium', 'level' => 2]);
        $this->actingAs($actor)->postJson('/api/v2/tickets', [
            'subject' => 'Incorrect asset association', 'description' => 'An asset from another customer must not be accepted.',
            'customer_id' => $alpha->id, 'category_id' => $category->id,
            'priority_id' => $priority->id, 'asset_id' => $otherAsset->id,
        ])->assertStatus(422);
    }
}
