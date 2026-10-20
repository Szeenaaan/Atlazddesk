<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Jobs\DispatchTicketNotice;
use App\Models\{AmcUserNotification, Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, TicketWorkReport, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Mail};
use Tests\TestCase;

/** Uses RefreshDatabase. Run ONLY with DB_DATABASE=amc_platform_test. */
final class AmcWorkReportsV8Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $db = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $db, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending in _test first.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function user(string $role, ?Customer $customer = null): User
    {
        return User::factory()->create([
            'role'=>$role, 'status'=>'active', 'customer_id'=>$customer?->id,
            'password'=>Hash::make('SecurePassword#2026'),
        ]);
    }

    private function ticket(User $technician, ?User $creator = null): Ticket
    {
        $customer = Customer::create(['company_name'=>'Site '.uniqid(),'customer_code'=>'T'.strtoupper(bin2hex(random_bytes(5))), 'status'=>'active']);
        $admin = $creator ?? $this->user('admin');
        $category = TicketCategory::firstOrCreate(['slug'=>'hardware'],['name'=>'Hardware']);
        $priority = TicketPriority::firstOrCreate(['slug'=>'medium'],['name'=>'Medium','level'=>2]);
        $status = TicketStatus::firstOrCreate(['slug'=>'assigned'],['name'=>'Assigned','is_closed'=>false]);
        return Ticket::create([
            'ticket_number'=>'V8-'.strtoupper(bin2hex(random_bytes(5))), 'customer_id'=>$customer->id,
            'created_by'=>$admin->id, 'assigned_to'=>$technician->id,
            'category_id'=>$category->id, 'priority_id'=>$priority->id,
            'status_id'=>$status->id, 'subject'=>'Server failure', 'description'=>'Power fault',
        ]);
    }

    private function report(): array
    {
        return [
            'progress'=>'completed','outcome'=>'unrepairable',
            'diagnosis'=>'Motherboard short circuit after inspection',
            'work_performed'=>'Replaced power supply for testing; board does not boot',
            'recommendation'=>'Replace motherboard', 'parts_required'=>'Board model 001',
        ];
    }

    public function test_technician_submits_structured_report_and_admin_gets_notification_without_closing_ticket(): void
    {
        $technician = $this->user('technician');
        $admin = $this->user('admin');
        $ticket = $this->ticket($technician);
        $this->actingAs($technician)->postJson("/api/v2/tickets/{$ticket->id}/work-reports", $this->report())
            ->assertCreated()->assertJsonPath('data.outcome','unrepairable');
        $this->assertDatabaseHas('ticket_work_reports', ['ticket_id'=>$ticket->id, 'technician_id'=>$technician->id, 'progress'=>'completed']);
        $this->assertDatabaseHas('amc_user_notifications', ['user_id'=>$admin->id, 'ticket_id'=>$ticket->id, 'event'=>'work_report']);
        $this->assertSame('assigned', $ticket->fresh()->status->slug);
        $this->actingAs($admin)->getJson("/api/v2/tickets/{$ticket->id}/work-reports")
            ->assertOk()->assertJsonCount(1,'data');
        $this->actingAs($technician)->getJson('/api/v2/notifications')->assertJsonPath('data.unread_count',0);
    }

    public function test_other_technician_cannot_view_or_create_report_for_unassigned_ticket(): void
    {
        $owner = $this->user('technician');
        $other = $this->user('technician');
        $ticket = $this->ticket($owner);
        $this->actingAs($other)->getJson("/api/v2/tickets/{$ticket->id}/work-reports")->assertNotFound();
        $this->postJson("/api/v2/tickets/{$ticket->id}/work-reports", $this->report())->assertNotFound();
        $this->assertDatabaseCount('ticket_work_reports', 0);
    }

    public function test_customers_super_admin_and_contractor_admin_cannot_submit_technician_reports(): void
    {
        $technician = $this->user('technician');
        $ticket = $this->ticket($technician);
        $customer = $this->user('customer_admin', Customer::findOrFail($ticket->customer_id));
        foreach ([$this->user('admin'), $customer, $this->user('super_admin')] as $actor) {
            $this->actingAs($actor)->postJson("/api/v2/tickets/{$ticket->id}/work-reports", $this->report())->assertForbidden();
        }
        $this->actingAs($customer)->getJson("/api/v2/tickets/{$ticket->id}/work-reports")->assertForbidden();
        $this->assertDatabaseCount('ticket_work_reports', 0);
    }

    public function test_report_requires_diagnosis_and_work_description_when_completed(): void
    {
        $technician = $this->user('technician');
        $ticket = $this->ticket($technician);
        $this->actingAs($technician)->postJson("/api/v2/tickets/{$ticket->id}/work-reports", [
            'progress'=>'completed', 'outcome'=>'success', 'diagnosis'=>'Found damaged fan',
        ])->assertUnprocessable();
        $this->postJson("/api/v2/tickets/{$ticket->id}/work-reports", [
            'progress'=>'in_progress', 'outcome'=>'invalid', 'diagnosis'=>'Found damaged fan',
        ])->assertUnprocessable();
    }

    public function test_reassignment_revokes_report_permission(): void
    {
        $old = $this->user('technician');
        $new = $this->user('technician');
        $ticket = $this->ticket($old);
        $ticket->update(['assigned_to'=>$new->id]);
        $this->actingAs($old)->postJson("/api/v2/tickets/{$ticket->id}/work-reports", $this->report())->assertNotFound();
        $this->actingAs($new)->postJson("/api/v2/tickets/{$ticket->id}/work-reports", $this->report())->assertCreated();
    }

    public function test_old_queued_assignment_does_not_alert_wrong_technician(): void
    {
        Mail::fake();
        $old = $this->user('technician');
        $new = $this->user('technician');
        $ticket = $this->ticket($old);
        $ticket->update(['assigned_to'=>$new->id]);
        (new DispatchTicketNotice($ticket->id,'assigned',$old->id))->handle();
        $this->assertDatabaseMissing('amc_user_notifications', ['user_id'=>$old->id, 'ticket_id'=>$ticket->id]);
        (new DispatchTicketNotice($ticket->id,'assigned',$new->id))->handle();
        $this->assertDatabaseHas('amc_user_notifications', ['user_id'=>$new->id, 'ticket_id'=>$ticket->id, 'event'=>'assigned']);
    }

    public function test_super_admin_provisions_contractor_admin_without_client_workspace_access(): void
    {
        $super = $this->user('super_admin');
        $input = [
            'name'=>'Contractor Manager','email'=>'managerv8@example.test',
            'password'=>'VeryStrongPassword#2026','password_confirmation'=>'VeryStrongPassword#2026',
        ];
        $this->actingAs($super)->postJson('/api/v2/system/admins', $input)
            ->assertCreated()->assertJsonPath('data.role','admin');
        $this->getJson('/api/v2/system/admins')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/v2/tickets')->assertForbidden();
        $this->actingAs($this->user('admin'))->getJson('/api/v2/system/admins')->assertForbidden();
        $this->postJson('/api/v2/system/admins',$input)->assertForbidden();
    }
}
