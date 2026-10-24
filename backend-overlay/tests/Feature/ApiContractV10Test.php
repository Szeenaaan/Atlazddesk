<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiContractV10Test extends TestCase
{
    public function test_server_errors_explain_the_affected_action_without_exposing_internal_details(): void
    {
        $request = \Illuminate\Http\Request::create('/api/v2/admin/mail/providers');
        $request->attributes->set('request_id', 'reference-12345678');
        $response = \App\Exceptions\ApiExceptionFormatter::render(new \RuntimeException('private-database-details'), $request);
        $this->assertSame(500, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('Email settings could not be loaded. Try again or contact your server administrator.', $data['message']);
        $this->assertSame('reference-12345678', $data['request_id']);
        $this->assertStringNotContainsString('private-database-details', $response->getContent());
    }
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

    public function test_api_validation_errors_have_stable_response_contract(): void
    {
        $this->postJson('/api/v2/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statuscode', 422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_contractor_admin_service_returns_standard_envelope_without_password(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($superAdmin)->getJson('/api/v2/system/admins')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statuscode', 200)
            ->assertJsonStructure(['message', 'data', 'pagination'])
            ->assertDontSee('password');
    }

    public function test_customer_cannot_access_admin_provisioning_even_with_invalid_input(): void
    {
        $customer = User::factory()->create(['role' => 'customer_user', 'status' => 'active']);
        $this->actingAs($customer)->postJson('/api/v2/system/admins', [])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statuscode', 403);
    }
}
