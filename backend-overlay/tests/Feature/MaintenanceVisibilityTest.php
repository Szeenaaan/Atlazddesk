<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class MaintenanceVisibilityTest extends TestCase
{
    use RefreshDatabase;
    public function test_bootstrap_exposes_effective_maintenance_setting(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class);
        $this->actingAs(User::factory()->create(['role'=>'admin','status'=>'active']));
        foreach ([false, true] as $enabled) {
            config(['amc.maintenance_enabled'=>$enabled]);
            $this->getJson('/api/v2/bootstrap')->assertOk()->assertJsonPath('features.maintenance_enabled',$enabled);
        }
    }
}
