<?php

namespace Tests\Feature;

use App\Models\{Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmcAuthorizationTest extends TestCase
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


    private function setupTickets(): array
    {
        $one=Customer::create(['company_name'=>'One','customer_code'=>'A-001']);
        $two=Customer::create(['company_name'=>'Two','customer_code'=>'B-002']);
        $category=TicketCategory::create(['name'=>'Network','slug'=>'network']);
        $priority=TicketPriority::create(['name'=>'Medium','slug'=>'medium','level'=>2]);
        $status=TicketStatus::create(['name'=>'New','slug'=>'new']);
        $a=User::factory()->create(['role'=>'customer_user','customer_id'=>$one->id,'status'=>'active']);
        $b=User::factory()->create(['role'=>'customer_admin','customer_id'=>$two->id,'status'=>'active']);
        $tech=User::factory()->create(['role'=>'technician','status'=>'active']);
        $admin=User::factory()->create(['role'=>'super_admin','status'=>'active']);
        $make=fn ($customer,$user,$num)=>Ticket::create([
            'customer_id'=>$customer->id,'created_by'=>$user->id,
            'category_id'=>$category->id,'priority_id'=>$priority->id,'status_id'=>$status->id,
            'ticket_number'=>$num,'subject'=>'Issue','description'=>'Private issue',
        ]);
        return [$make($one,$a,'T-001'),$make($two,$b,'T-002'),$a,$b,$tech,$admin];
    }

    public function test_customer_is_blocked_from_another_tenant_ticket(): void
    {
        [$mine,$other,$user]=$this->setupTickets();
        $this->actingAs($user)->getJson('/api/v2/tickets/'.$mine->id)->assertOk();
        $this->actingAs($user)->getJson('/api/v2/tickets/'.$other->id)->assertNotFound();
    }

    public function test_customer_cannot_post_internal_note(): void
    {
        [$mine,,$user]=$this->setupTickets();
        $this->actingAs($user)->postJson('/api/v2/tickets/'.$mine->id.'/comments', [
            'comment'=>'Unauthorized internal note','is_internal'=>true,
        ])->assertForbidden();
    }

    public function test_unassigned_technician_cannot_access_tickets(): void
    {
        [$mine,,,,$tech]=$this->setupTickets();
        $this->actingAs($tech)->getJson('/api/v2/tickets/'.$mine->id)->assertNotFound();
    }

    public function test_super_admin_cannot_view_client_tickets(): void
    {
        [$first,$second,,,, $admin]=$this->setupTickets();
        $this->actingAs($admin)->getJson('/api/v2/tickets/'.$first->id)->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v2/tickets/'.$second->id)->assertForbidden();
    }

    public function test_customer_cannot_read_admin_metrics(): void
    {
        [,, $customer]=$this->setupTickets();
        $this->actingAs($customer)->getJson('/api/v2/admin/metrics')->assertForbidden();
    }
}
