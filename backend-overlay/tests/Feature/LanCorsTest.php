<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

final class LanCorsTest extends TestCase
{
    public function test_trusted_lan_preflight_and_login_validation_have_credential_headers(): void
    {
        $origin = 'http://192.168.17.2:5173';
        config(['cors.allowed_origins' => [$origin]]);
        $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/v2/auth/login')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withHeader('Origin', $origin)->postJson('/api/v2/auth/login', [])
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_untrusted_origin_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://trusted.example']]);
        $this->withHeaders(['Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST'])
            ->options('/api/v2/auth/login')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://trusted.example');
    }

    public function test_configured_frontend_origins_are_stateful_with_exact_ports(): void
    {
        $origins = ['http://192.168.17.2:5173'];
        config(['cors.allowed_origins' => $origins]);
        $sanctum = require config_path('sanctum.php');
        config(['sanctum.stateful' => $sanctum['stateful']]);
        foreach ($origins as $origin) {
            $request = Request::create('/api/v2/auth/me');
            $request->headers->set('Origin', $origin);
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        }
        $request = Request::create('/api/v2/auth/me');
        $request->headers->set('Origin', 'https://untrusted.example');
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }
}
