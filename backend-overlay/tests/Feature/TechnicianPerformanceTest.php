<?php

namespace Tests\Feature;

use App\Models\{Customer, TechnicianDesignation, Ticket, TicketCategory, TicketEscalation, TicketPriority, TicketStatus, TicketWorkReport, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class TechnicianPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name, array $attributes = []): User
    {
        $designation = TechnicianDesignation::firstOrCreate(['name' => 'Service engineer'], ['level' => 1, 'is_active' => true]);
        return User::factory()->create(['name' => $name, 'role' => 'technician', 'customer_id' => null,
            'status' => 'active', 'designation_id' => $designation->id, 'workforce_type' => 'employee', ...$attributes]);
    }

    private function ticket(User $assignee, ?string $deadline): Ticket
    {
        $customer = Customer::firstOrCreate(['customer_code' => 'PERF'], ['company_name' => 'Report client']);
        $category = TicketCategory::firstOrCreate(['slug' => 'report'], ['name' => 'Report category']);
        $priority = TicketPriority::firstOrCreate(['slug' => 'medium'], ['name' => 'Medium', 'level' => 2]);
        $status = TicketStatus::firstOrCreate(['slug' => 'resolved'], ['name' => 'Resolved']);
        return Ticket::create(['ticket_number' => 'PERF-'.Ticket::count(), 'subject' => 'Report ticket', 'description' => 'Private description',
            'customer_id' => $customer->id, 'created_by' => $assignee->id, 'assigned_to' => $assignee->id,
            'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id, 'sla_resolution_due_at' => $deadline]);
    }

    private function work(User $technician, Ticket $ticket, string $at): void
    {
        $report = new TicketWorkReport(['ticket_id' => $ticket->id, 'technician_id' => $technician->id,
            'progress' => 'completed', 'outcome' => 'success', 'diagnosis' => 'Checked device', 'work_performed' => 'Repair completed']);
        $report->created_at = $at;
        $report->save();
    }

    public function test_counts_use_recorded_actors_actual_deadlines_and_distinct_tickets(): void
    {
        $source = $this->technician('Alpha');
        $target = $this->technician('Beta');
        $short = $this->ticket($target, '2026-10-10 12:15:00');
        $long = $this->ticket($target, '2026-10-10 18:00:00');
        $without = $this->ticket($source, null);
        $this->work($source, $short, '2026-10-10 12:00:00');
        $this->work($source, $short, '2026-10-10 12:05:00');
        $this->work($source, $long, '2026-10-10 18:01:00');
        $this->work($source, $without, '2026-10-10 12:10:00');
        $this->work($target, $long, '2026-10-10 18:01:00');
        foreach ([['pending', '2026-10-10 12:00:00', null], ['approved', '2026-10-10 13:00:00', '2026-10-10 14:00:00'],
            ['approved', '2026-10-09 12:00:00', '2026-10-10 15:00:00']] as [$state, $created, $decided]) {
            $record = new TicketEscalation(['ticket_id' => $short->id, 'requester_id' => $source->id,
                'previous_designation_id' => $source->designation_id, 'previous_designation_name' => 'Service engineer', 'previous_level' => 1,
                'reason' => 'Specialist required', 'state' => $state, 'decided_at' => $decided]);
            $record->created_at = $created;
            $record->save();
        }
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $response = $this->actingAs($admin)->getJson('/api/v2/reports/technicians?from=2026-10-10&to=2026-10-10')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');
        $row = $rows[$source->id];
        $this->assertSame(3, $row['completed_work']);
        $this->assertSame(2, $row['escalation_requests']);
        $this->assertSame(2, $row['approved_escalations']);
        $this->assertSame(1, $row['sla_on_time']);
        $this->assertSame(1, $row['sla_late']);
        $this->assertSame(50, $row['sla_rate']);
        $this->assertSame(1, $rows[$target->id]['sla_late']);
        $this->assertSame(0, $rows[$target->id]['sla_rate']);
        $this->assertSame($source->id, $this->getJson('/api/v2/reports/technicians?sort=sla_rate&direction=desc')
            ->assertOk()->json('data.0.id'));
        $this->assertSame('Service engineer', $row['designation']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'designation', 'workforce_type', 'status', 'completed_work',
            'escalation_requests', 'approved_escalations', 'sla_on_time', 'sla_late', 'sla_rate'], array_keys($row));
    }

    public function test_admin_filters_sorting_pagination_and_exports_use_the_same_rows(): void
    {
        $alpha = $this->technician('Alpha <engineer>');
        $this->technician('Beta', ['status' => 'inactive', 'workforce_type' => 'freelancer']);
        $this->technician('Percent%');
        User::factory()->create(['name' => 'Alpha client', 'role' => 'customer_user']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->getJson('/api/v2/reports/technicians?sort=name&direction=asc&per_page=1&page=2')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.name', 'Beta');
        $this->getJson('/api/v2/reports/technicians?search=%25')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Percent%');
        $this->getJson('/api/v2/reports/technicians?workforce_type=freelancer&status=inactive')->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Beta');
        $this->getJson('/api/v2/reports/technicians?sort=password')->assertUnprocessable();
        $this->getJson('/api/v2/reports/technicians?from=2026-10-11&to=2026-10-10')->assertUnprocessable();
        $this->getJson('/api/v2/reports/technicians?search=Alpha')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $alpha->id);
        $csv = $this->get('/api/v2/reports/technicians.csv?search=Alpha')->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha <engineer>', $csv);
        $this->assertStringContainsString('Within SLA', $csv);
        $this->assertStringContainsString('Outside SLA', $csv);
        $this->assertStringContainsString('SLA compliance', $csv);
        $this->assertStringNotContainsString('Tickets resolved', $csv);
        $this->assertStringNotContainsString('Beta', $csv);
        foreach (['xlsx' => 'xl/worksheets/sheet1.xml', 'docx' => 'word/document.xml'] as $format => $entry) {
            $response = $this->get('/api/v2/reports/technicians.'.$format.'?search=Alpha')->assertOk();
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
            $xml = $zip->getFromName($entry);
            $zip->close();
            $this->assertStringContainsString('Alpha &lt;engineer&gt;', $xml);
            $this->assertStringContainsString('Within SLA', $xml);
            $this->assertStringContainsString('SLA compliance', $xml);
            $this->assertStringNotContainsString('Tickets resolved', $xml);
            $this->assertStringNotContainsString('Beta', $xml);
        }
        $pdf = $this->get('/api/v2/reports/technicians.pdf?search=Alpha')->assertOk()->baseResponse->getFile()->getPathname();
        $content = file_get_contents($pdf);
        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringContainsString(strtoupper(bin2hex('Technician performance')), $content);
        $this->assertStringContainsString(strtoupper(bin2hex('Alpha <engineer>')), $content);
    }

    public function test_other_roles_cannot_read_or_export_technician_performance(): void
    {
        foreach (['super_admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->getJson('/api/v2/reports/technicians')->assertForbidden();
            foreach (['csv', 'xlsx', 'docx', 'pdf'] as $format) {
                $this->getJson('/api/v2/reports/technicians.'.$format)->assertForbidden();
            }
        }
    }
}
