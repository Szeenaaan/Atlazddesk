<?php

namespace Tests\Feature;

use App\Models\{Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianAssignmentLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $first;
    private User $second;
    private Customer $customer;
    private TicketStatus $assigned;
    private TicketCategory $category;
    private TicketPriority $priority;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->first = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $this->second = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $this->customer = Customer::create(['company_name' => 'Limit test client', 'customer_code' => 'LIMIT']);
        $this->category = TicketCategory::create(['name' => 'Limit test category', 'slug' => 'limit-test']);
        $this->priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'limit-medium', 'level' => 2]);
        $this->assigned = TicketStatus::firstOrCreate(['slug' => 'assigned'], ['name' => 'Assigned', 'is_closed' => false]);
    }

    private function ticket(): Ticket
    {
        return Ticket::create(['ticket_number' => 'LIMIT-'.uniqid(), 'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id, 'category_id' => $this->category->id, 'priority_id' => $this->priority->id,
            'status_id' => $this->assigned->id, 'subject' => 'Capacity test', 'description' => 'Ticket assignment']);
    }

    private function assign(Ticket $ticket, User $technician)
    {
        return $this->patchJson('/api/v2/tickets/'.$ticket->id, ['assigned_to' => $technician->id]);
    }

    public function test_default_is_unlimited_and_only_contractor_admin_can_change_limits(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v2/technician-assignment-limits')
            ->assertOk()->assertJsonPath('data.default_limit', null);
        $this->assign($this->ticket(), $this->first)->assertOk();
        $this->assign($this->ticket(), $this->first)->assertOk();
        $this->actingAs($this->first)->getJson('/api/v2/technician-assignment-limits')->assertForbidden();
        $this->putJson('/api/v2/technician-assignment-limits', ['limit' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'status' => 'active']))
            ->putJson('/api/v2/technician-assignment-limits', ['limit' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'customer_admin', 'customer_id' => $this->customer->id, 'status' => 'active']))
            ->getJson('/api/v2/technician-assignment-limits/'.$this->first->id)->assertForbidden();
        $this->actingAs($this->admin)->putJson('/api/v2/technician-assignment-limits', ['limit' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->putJson('/api/v2/technician-assignment-limits', ['limit' => 10001])
            ->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_default_cap_blocks_new_active_assignments_and_completed_work_releases_capacity(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v2/technician-assignment-limits', ['limit' => 1])
            ->assertOk()->assertJsonPath('data.default_limit', 1);
        $firstTicket = $this->ticket();
        $this->assign($firstTicket, $this->first)->assertOk();
        $this->assign($firstTicket, $this->first)->assertOk();
        $next = $this->ticket();
        $this->assign($next, $this->first)->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertNull($next->fresh()->assigned_to);
        $resolved = TicketStatus::firstOrCreate(['slug' => 'resolved'], ['name' => 'Resolved', 'is_closed' => false]);
        $firstTicket->update(['status_id' => $resolved->id]);
        $this->assign($next, $this->first)->assertOk();
        $this->getJson('/api/v2/technician-assignment-limits/'.$this->first->id)
            ->assertOk()->assertJsonPath('data.active_tickets', 1)->assertJsonPath('data.effective_limit', 1);
    }

    public function test_individual_override_only_affects_its_technician_and_can_be_cleared(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v2/technician-assignment-limits', ['limit' => 1])->assertOk();
        $this->putJson('/api/v2/technician-assignment-limits/'.$this->first->id, ['limit' => 2])
            ->assertOk()->assertJsonPath('data.default_limit', 1)->assertJsonPath('data.override_limit', 2)
            ->assertJsonPath('data.effective_limit', 2);
        $this->assign($this->ticket(), $this->first)->assertOk();
        $this->assign($this->ticket(), $this->first)->assertOk();
        $this->assign($this->ticket(), $this->first)->assertUnprocessable();
        $this->assign($this->ticket(), $this->second)->assertOk();
        $this->assign($this->ticket(), $this->second)->assertUnprocessable();
        $this->putJson('/api/v2/technician-assignment-limits/'.$this->first->id, ['limit' => null])
            ->assertOk()->assertJsonPath('data.override_limit', null)->assertJsonPath('data.effective_limit', 1);
        $this->assign($this->ticket(), $this->first)->assertUnprocessable();
        $this->putJson('/api/v2/technician-assignment-limits', ['limit' => null])
            ->assertOk()->assertJsonPath('data.default_limit', null);
        $this->assign($this->ticket(), $this->second)->assertOk();
    }
}
