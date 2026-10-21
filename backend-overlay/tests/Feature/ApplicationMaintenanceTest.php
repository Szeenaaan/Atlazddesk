<?php
namespace Tests\Feature;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Tests\TestCase;
final class ApplicationMaintenanceTest extends TestCase
{
    public function test_status_is_public_but_business_routes_are_blocked_while_down(): void
    {
        $mode = \Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturn(true);
        $mode->shouldReceive('data')->andReturn(['time'=>time(),'status'=>503,'retry'=>60]);
        $this->app->instance(MaintenanceMode::class, $mode);
        $this->getJson('/api/maintenance-status')->assertOk()->assertJsonPath('maintenance', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v2/bootstrap')->assertStatus(503)->assertJsonPath('code','APPLICATION_MAINTENANCE');
        $this->get('/api/v2/auth/me', ['Accept'=>'text/html'])->assertRedirect('/maintenance');
        config(['amc.frontend_url'=>'https://desk.example.test']);
        $this->get('/api/v2/auth/me', ['Accept'=>'text/html'])->assertRedirect('https://desk.example.test/maintenance');
    }
    public function test_status_reports_ready_when_up(): void
    {
        $mode = \Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturn(false);
        $this->app->instance(MaintenanceMode::class, $mode);
        $this->getJson('/api/maintenance-status')->assertOk()->assertJsonPath('maintenance',false);
    }
}
