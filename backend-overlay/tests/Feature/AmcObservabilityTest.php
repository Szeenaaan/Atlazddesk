<?php
namespace Tests\Feature;
use App\Models\{AmcIncident, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmcObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }
        parent::setUp();
    }

    public function test_guest_cannot_access_incidents(): void
    {
        $this->getJson('/api/v2/admin/incidents')->assertUnauthorized();
    }
    public function test_customer_cannot_access_incidents(): void
    {
        $user=User::factory()->create(['role'=>'customer_user','status'=>'active']);
        $this->actingAs($user)->getJson('/api/v2/admin/incidents')->assertForbidden();
    }
    public function test_super_admin_can_view_and_resolve_incident(): void
    {
        $admin=User::factory()->create(['role'=>'super_admin','status'=>'active']);
        $incident=AmcIncident::create(['fingerprint'=>str_repeat('a',64),'kind'=>'exception','message'=>'RuntimeException at Example.php:10','occurrences'=>1,'first_seen_at'=>now(),'last_seen_at'=>now()]);
        $this->actingAs($admin)->getJson('/api/v2/admin/incidents')->assertOk()->assertJsonPath('total',1);
        $this->actingAs($admin)->patchJson('/api/v2/admin/incidents/'.$incident->id.'/resolve')->assertOk();
        $this->assertNotNull($incident->fresh()->resolved_at);
    }
}
