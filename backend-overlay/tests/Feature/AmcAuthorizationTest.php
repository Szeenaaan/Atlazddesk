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

    public function test_contractor_can_view_assign_and_interact_with_existing_tickets_but_cannot_create(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$ticket, $other, , , $technician] = $this->setupTickets();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        TicketStatus::create(['name' => 'Assigned', 'slug' => 'assigned']);
        $this->actingAs($actor)->getJson('/api/v2/tickets')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v2/tickets/'.$other->id)->assertOk();
        $this->patchJson('/api/v2/tickets/'.$ticket->id, ['assigned_to' => $technician->id])->assertOk();
        $this->assertSame($technician->id, $ticket->fresh()->assigned_to);
        foreach ([false, true] as $internal) {
            $this->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Contractor reply', 'is_internal' => $internal])->assertCreated();
        }
        $this->postJson('/api/v2/tickets', [])->assertForbidden();
        $this->assertDatabaseCount('tickets', 2);
    }

    public function test_only_contractor_admin_can_resolve_a_ticket(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$ticket, , , , $technician] = $this->setupTickets();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inProgress = TicketStatus::create(['name' => 'In Progress', 'slug' => 'in_progress']);
        TicketStatus::create(['name' => 'Resolved', 'slug' => 'resolved']);
        $ticket->update(['assigned_to' => $technician->id, 'status_id' => $inProgress->id]);

        $this->actingAs($technician)->patchJson('/api/v2/tickets/'.$ticket->id, ['status' => 'Resolved'])->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/v2/tickets/'.$ticket->id, ['status' => 'Resolved'])->assertOk();
        $this->assertSame('resolved', $ticket->fresh()->status->slug);
    }

    public function test_client_admin_can_comment_on_active_ticket_but_cannot_change_status(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$ticket] = $this->setupTickets();
        $actor = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $ticket->customer_id, 'status' => 'active']);
        TicketStatus::create(['name' => 'In Progress', 'slug' => 'in_progress']);
        $this->actingAs($actor)->patchJson('/api/v2/tickets/'.$ticket->id, ['status' => 'In Progress'])->assertForbidden();
        $this->patchJson('/api/v2/tickets/'.$ticket->id, ['status' => 'New'])->assertForbidden();
        $this->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Client reply'])->assertCreated();
        $this->assertSame('new', $ticket->fresh()->status->slug);
    }

    public function test_final_tickets_are_read_only_for_clients_and_technicians(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$ticket, , $customer, , $technician] = $this->setupTickets();
        $clientAdmin = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $ticket->customer_id, 'status' => 'active']);
        $ticket->update(['assigned_to' => $technician->id]);
        foreach (['resolved', 'cancelled', 'unresolved', 'closed', 'customer_confirmation'] as $slug) {
            $status = TicketStatus::create(['name' => $slug, 'slug' => $slug]);
            $ticket->update(['status_id' => $status->id]);
            foreach ([$customer, $clientAdmin, $technician] as $actor) {
                $url = '/api/v2/tickets/'.$ticket->id;
                $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonPath('data.read_only', true);
                $this->postJson($url.'/comments', ['comment' => 'Blocked reply'])->assertForbidden();
                $this->patchJson($url, ['status' => 'In Progress'])->assertForbidden();
                $this->postJson($url.'/attachments')->assertForbidden();
            }
            $this->actingAs($technician)->postJson($url.'/work-logs', [
                'start_time' => '2026-11-09 10:00:00', 'end_time' => '2026-11-09 10:30:00', 'description' => 'Blocked work',
            ])->assertForbidden();
            $this->postJson($url.'/work-reports', ['progress' => 'in_progress', 'outcome' => 'other', 'diagnosis' => 'Blocked report'])->assertForbidden();
        }
        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_work_logs', 0);
        $this->assertDatabaseCount('ticket_work_reports', 0);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('data.read_only', false);
        $this->postJson($url.'/comments', ['comment' => 'Admin review'])->assertCreated();
    }

    public function test_ticket_service_also_rejects_contractor_creation(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $request = \Illuminate\Http\Request::create('/api/v2/tickets', 'POST');
        $request->setUserResolver(fn () => $actor);
        try {
            app(\App\Services\TicketService::class)->create($request, []);
            $this->fail('Contractor ticket creation was accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('tickets', 0);
    }
}
