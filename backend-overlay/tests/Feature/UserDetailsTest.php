<?php

namespace Tests\Feature;

use App\Models\{Asset, Customer, TicketCategory, User};
use App\Http\Resources\UserDetailsResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gets_safe_details_and_only_customer_owned_assigned_assets(): void
    {
        $customer = Customer::create(['company_name' => 'Detail Client', 'customer_code' => 'USER-DETAIL']);
        $other = Customer::create(['company_name' => 'Other Client', 'customer_code' => 'USER-OTHER']);
        $category = TicketCategory::create(['name' => 'Devices', 'slug' => 'user-detail-devices']);
        $target = User::factory()->create(['role' => 'customer_user', 'customer_id' => $customer->id, 'phone' => '+123456789', 'status' => 'active']);
        foreach ([$customer, $other] as $i => $owner) {
            $asset = Asset::create(['customer_id' => $owner->id, 'category_id' => $category->id, 'asset_tag' => 'USER-DETAIL-'.$i, 'name' => 'Device '.$i]);
            $target->assignedAssets()->attach($asset->id);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']))
            ->getJson("/api/v2/users/{$target->id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('phone', '+123456789')->assertJsonPath('customer.company_name', 'Detail Client')
            ->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.name', 'Device 0')
            ->assertJsonMissingPath('password')->assertJsonMissingPath('mfa_totp_secret')
            ->assertJsonMissingPath('assets.0.pivot');
        $technical = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->getJson("/api/v2/users/{$technical->id}")->assertNotFound();
    }

    public function test_customer_admin_can_only_view_own_customer_users(): void
    {
        $customer = Customer::create(['company_name' => 'Own Client', 'customer_code' => 'OWN-USER-DETAIL']);
        $other = Customer::create(['company_name' => 'Foreign Client', 'customer_code' => 'FOREIGN-USER-DETAIL']);
        $admin = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $customer->id, 'status' => 'active']);
        $own = User::factory()->create(['role' => 'customer_user', 'customer_id' => $customer->id, 'status' => 'active']);
        $foreign = User::factory()->create(['role' => 'customer_user', 'customer_id' => $other->id, 'status' => 'active']);
        $technician = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $this->actingAs($admin)->getJson("/api/v2/users/{$own->id}")->assertOk();
        foreach ([$foreign, $technician, $admin] as $blocked) $this->getJson("/api/v2/users/{$blocked->id}")->assertNotFound();
        foreach (['technician', 'customer_user', 'super_admin'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($actor)->getJson("/api/v2/users/{$own->id}")->assertForbidden();
        }
    }

    public function test_user_dto_is_an_allowlist_even_for_unknown_secret_attributes(): void
    {
        $user = new User;
        $user->setRawAttributes(['id' => 7, 'name' => 'Visible', 'email' => 'visible@example.test', 'role' => 'technician',
            'password' => 'private', 'password_confirmation' => 'private', 'mfa_totp_secret' => 'private',
            'remember_token' => 'private', 'api_token' => 'private', 'credentials' => 'private']);
        $data = (new UserDetailsResource($user))->resolve(request());
        $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'role'], array_keys($data));
    }
}
