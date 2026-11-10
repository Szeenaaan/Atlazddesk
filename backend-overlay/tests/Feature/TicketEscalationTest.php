<?php

namespace Tests\Feature;

use App\Jobs\DispatchTicketNotice;
use App\Models\{ActivityLog, AmcUserNotification, Customer, TechnicianDesignation, Ticket, TicketAssignment, TicketCategory, TicketEscalation, TicketPriority, TicketStatus, User};
use App\Services\{AttachmentScanner, TicketEscalationService, TicketService};
use App\Services\Tickets\TicketWorkReportService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\{Bus, DB, Storage};
use Tests\TestCase;

class TicketEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $source;
    private User $target;
    private User $customer;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([DispatchTicketNotice::class]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $junior = TechnicianDesignation::create(['name' => 'Junior', 'level' => 1, 'is_active' => true]);
        $senior = TechnicianDesignation::create(['name' => 'Senior', 'level' => 2, 'is_active' => true]);
        $this->source = $this->technician($junior->id);
        $this->target = $this->technician($senior->id);
        $company = Customer::create(['company_name' => 'Escalation client', 'customer_code' => 'ESC']);
        $this->customer = User::factory()->create(['role' => 'customer_admin', 'status' => 'active', 'customer_id' => $company->id]);
        $category = TicketCategory::create(['name' => 'Escalation category', 'slug' => 'escalation']);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'esc-medium', 'level' => 2]);
        $status = TicketStatus::firstOrCreate(['slug' => 'assigned'], ['name' => 'Assigned', 'is_closed' => false]);
        $this->ticket = Ticket::create(['ticket_number' => 'ESC-1', 'customer_id' => $company->id, 'created_by' => $this->customer->id,
            'assigned_to' => $this->source->id, 'category_id' => $category->id, 'priority_id' => $priority->id,
            'status_id' => $status->id, 'subject' => 'Escalation test', 'description' => 'Service issue']);
        TicketAssignment::create(['ticket_id' => $this->ticket->id, 'technician_id' => $this->source->id,
            'assigned_by' => $this->admin->id, 'assigned_at' => now()]);
    }

    private function technician(int $designationId, array $data = []): User
    {
        return User::factory()->create(array_merge(['role' => 'technician', 'status' => 'active', 'designation_id' => $designationId,
            'workforce_type' => 'employee'], $data));
    }
    private function url(): string { return '/api/v2/tickets/'.$this->ticket->id.'/escalations'; }
    private function makeRequest(): TicketEscalation
    {
        $this->actingAs($this->source)->postJson($this->url(), ['reason' => 'Specialist diagnostic assistance required.'])->assertCreated();
        return TicketEscalation::where('ticket_id', $this->ticket->id)->latest('id')->firstOrFail();
    }
    private function approve(TicketEscalation $record, ?User $target = null)
    {
        return $this->actingAs($this->admin)->patchJson($this->url().'/'.$record->id, [
            'decision' => 'approved', 'selected_employee_id' => ($target ?? $this->target)->id, 'admin_note' => 'Assign specialist.',
        ]);
    }

    public function test_each_request_has_a_unique_reference_and_decided_requests_remain_in_escalations(): void
    {
        $first = $this->makeRequest();
        $this->assertSame('#ES'.$first->created_at->format('Y').str_pad((string) $first->id, 6, '0', STR_PAD_LEFT), $first->displayNumber());
        $this->actingAs($this->admin)->patchJson($this->url().'/'.$first->id, [
            'decision' => 'rejected', 'admin_note' => 'Continue work.',
        ])->assertOk();
        $next = $this->makeRequest();
        $this->assertNotSame($first->displayNumber(), $next->displayNumber());
        $this->actingAs($this->admin)->getJson('/api/v2/escalations')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', $next->displayNumber())->assertJsonPath('data.0.state', 'pending')
            ->assertJsonPath('data.1.reference', $first->displayNumber())->assertJsonPath('data.1.state', 'rejected');
    }

    public function test_pending_request_preserves_reads_but_blocks_all_scripted_writes(): void
    {
        config(['amc.attachments_enabled' => true]);
        Storage::fake('local');
        Storage::disk('local')->put('escalation-report.txt', 'Private ticket attachment');
        $file = $this->ticket->attachments()->create(['uploaded_by' => $this->source->id,
            'file_name' => 'report.txt', 'file_path' => 'escalation-report.txt', 'mime_type' => 'text/plain', 'file_size' => 25]);
        $notification = AmcUserNotification::create(['user_id' => $this->source->id, 'ticket_id' => $this->ticket->id,
            'event' => 'assigned', 'title' => 'Assigned', 'message' => 'Private ticket', 'deduplication_key' => hash('sha256', 'pending-lock')]);
        $this->mock(AttachmentScanner::class)->shouldNotReceive('scan');
        $this->makeRequest();
        $url = '/api/v2/tickets/'.$this->ticket->id;
        foreach (['', '/escalations', '/work-reports', '/work-logs', '/attachments', '/attachments/'.$file->id.'/download'] as $suffix) {
            $this->getJson($url.$suffix)->assertOk();
        }
        $this->getJson('/api/v2/tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/tickets/inbox?search=Escalation')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/bootstrap')->assertOk()->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.read_only', true);
        $this->getJson('/api/v2/reports/summary?timeframe=week')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('unresolved', 1)->assertJsonPath('escalated', 0);
        $this->getJson('/api/v2/reports/tickets')->assertOk()->assertJsonCount(1, 'data');
        $csv = $this->get('/api/v2/reports/tickets.csv')->assertOk();
        $this->assertStringContainsString($this->ticket->displayNumber(), $csv->streamedContent());
        $this->patchJson('/api/v2/notifications/'.$notification->id.'/read')->assertOk();
        $request = Request::create('/'); $request->setUserResolver(fn () => $this->source);
        $notifications = app(NotificationService::class)->list($request)->data;
        $this->assertSame(0, $notifications['unread_count']);
        $this->assertCount(1, $notifications['items']);

        $this->patchJson($url, ['status' => 'In Progress'])->assertForbidden();
        foreach ([false, true] as $internal) $this->postJson($url.'/comments', ['comment' => 'Blocked script', 'is_internal' => $internal])->assertForbidden();
        $this->postJson($url.'/work-logs', ['start_time' => '2026-11-01 10:00:00', 'end_time' => '2026-11-01 11:00:00', 'description' => 'Blocked work'])->assertForbidden();
        $report = ['progress' => 'in_progress', 'outcome' => 'warning', 'diagnosis' => 'Blocked report'];
        $this->postJson($url.'/work-reports', $report)->assertForbidden();
        $this->post($url.'/attachments', ['file' => UploadedFile::fake()->createWithContent('blocked.txt', 'Blocked upload')], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson($url.'/escalations', ['reason' => 'Duplicate request'])->assertConflict();
        // A caller holding a ticket model from before escalation cannot bypass the service checks.
        foreach ([fn () => app(TicketService::class)->update($request, $this->ticket, ['status' => 'In Progress']),
            fn () => app(TicketWorkReportService::class)->submit($request, $this->ticket->id, $report)] as $write) {
            try { $write(); $this->fail('Pending escalation must reject direct service writes.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_work_logs', 0);
        $this->assertDatabaseCount('ticket_work_reports', 0);
        $this->assertDatabaseCount('ticket_attachments', 1);
        $this->assertSame([$file->file_path], Storage::disk('local')->allFiles());
        $this->assertSame($this->ticket->status_id, $this->ticket->fresh()->status_id);
        $this->actingAs($this->admin)->getJson($url)->assertOk();
        $this->getJson('/api/v2/escalations')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->customer)->getJson($url)->assertOk();
    }

    public function test_pending_escalation_locks_customer_actions_until_admin_decides(): void
    {
        $clientUser = User::factory()->create(['role' => 'customer_user', 'status' => 'active', 'customer_id' => $this->ticket->customer_id]);
        $this->ticket->update(['created_by' => $clientUser->id]);
        $record = $this->makeRequest();
        $url = '/api/v2/tickets/'.$this->ticket->id;
        foreach ([$this->customer, $clientUser] as $actor) {
            $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonPath('data.read_only', true);
            $this->getJson('/api/v2/tickets')->assertOk()->assertJsonPath('data.0.read_only', true);
            $this->getJson('/api/v2/bootstrap')->assertOk()->assertJsonPath('tickets.0.read_only', true);
            $this->postJson($url.'/comments', ['comment' => 'Blocked customer reply'])->assertForbidden();
            $this->post($url.'/attachments', ['file' => UploadedFile::fake()->createWithContent('blocked.txt', 'Blocked upload')], ['Accept' => 'application/json'])->assertForbidden();
            $this->patchJson($url, ['status' => 'In Progress'])->assertForbidden();
        }
        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('data.read_only', false);
        $this->postJson($url.'/comments', ['comment' => 'Admin review'])->assertCreated();
        $this->patchJson($this->url().'/'.$record->id, ['decision' => 'rejected', 'admin_note' => 'Continue work.'])->assertOk();
        foreach ([$this->customer, $clientUser] as $actor) {
            $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonPath('data.read_only', false);
            $this->postJson($url.'/comments', ['comment' => 'Customer reply after review'])->assertCreated();
        }
    }

    public function test_status_notifications_describe_the_actual_change_without_unlocking_assignment(): void
    {
        DB::table('amc_mail_settings')->update(['notifications_enabled' => false]);
        foreach (['in_progress' => 'In Progress', 'waiting_parts' => 'Waiting Parts'] as $slug => $name) {
            TicketStatus::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_closed' => false]);
        }
        $url = '/api/v2/tickets/'.$this->ticket->id;
        $this->actingAs($this->source)->patchJson($url, ['status' => 'In Progress'])->assertOk();
        $job = Bus::dispatched(DispatchTicketNotice::class)->first(fn ($job) => $job->event === 'status_changed');
        $this->assertNotNull($job);
        $this->patchJson($url, ['status' => 'Waiting Parts'])->assertOk();
        // Delayed delivery must describe the original change, even if the ticket has since changed again.
        $job->handle();
        $notice = AmcUserNotification::where('user_id', $this->admin->id)->where('event', 'status_changed')->sole();
        $this->assertSame('Ticket status changed', $notice->title);
        $this->assertSame($this->ticket->displayNumber().' moved from Assigned to In Progress.', $notice->message);
        $this->actingAs($this->admin)->patchJson($url, ['assigned_to' => null])->assertConflict();
        $this->assertSame($this->source->id, $this->ticket->fresh()->assigned_to);
        Bus::assertNotDispatched(DispatchTicketNotice::class, fn ($job) => $job->event === 'unassigned');
    }

    public function test_cancelled_ticket_notification_states_the_result_directly(): void
    {
        DB::table('amc_mail_settings')->update(['notifications_enabled' => false]);
        TicketStatus::firstOrCreate(['slug' => 'cancelled'], ['name' => 'Cancelled', 'is_closed' => true]);
        $this->actingAs($this->admin)->patchJson('/api/v2/tickets/'.$this->ticket->id, ['status' => 'Cancelled'])->assertOk();
        $job = Bus::dispatched(DispatchTicketNotice::class)->first(fn ($job) => $job->event === 'status_changed');
        $this->assertNotNull($job);
        $job->handle();
        $notice = AmcUserNotification::where('user_id', $this->customer->id)->where('event', 'status_changed')->sole();
        $this->assertSame('Ticket cancelled', $notice->title);
        $this->assertSame($this->ticket->displayNumber().' was cancelled.', $notice->message);

        $legacy = AmcUserNotification::create([
            'user_id' => $this->admin->id, 'ticket_id' => $this->ticket->id, 'event' => 'status_changed',
            'title' => 'Ticket status changed',
            'message' => $this->ticket->displayNumber().' moved from Assigned to Cancelled.',
            'deduplication_key' => hash('sha256', 'legacy-cancelled-'.$this->ticket->id),
        ]);
        $request = Request::create('/'); $request->setUserResolver(fn () => $this->admin);
        $shown = app(NotificationService::class)->list($request)->data['items']->firstWhere('id', $legacy->id);
        $this->assertSame('Ticket cancelled', $shown->title);
        $this->assertSame($this->ticket->displayNumber().' was cancelled.', $shown->message);
        $this->assertSame('Ticket status changed', $legacy->fresh()->title);
    }

    public function test_rejection_restores_access_and_approval_only_allows_the_replacement_technician(): void
    {
        $record = $this->makeRequest();
        $this->actingAs($this->admin)->patchJson($this->url().'/'.$record->id, ['decision' => 'rejected', 'admin_note' => 'Continue diagnosis.'])->assertOk();
        $url = '/api/v2/tickets/'.$this->ticket->id;
        $this->actingAs($this->source)->getJson($url)->assertOk()->assertJsonPath('data.read_only', false);
        $this->postJson($url.'/comments', ['comment' => 'Continuing work.'])->assertCreated();
        $next = $this->makeRequest();
        $this->approve($next)->assertOk();
        $this->actingAs($this->source)->getJson($url)->assertNotFound();
        $this->postJson($url.'/comments', ['comment' => 'Old assignee script.'])->assertNotFound();
        $this->getJson('/api/v2/tickets/inbox')->assertJsonCount(0, 'data');
        $this->getJson('/api/v2/reports/summary?timeframe=week')->assertOk()
            ->assertJsonPath('escalated', 1)->assertJsonPath('unresolved', 0)->assertJsonPath('completed', 0)
            ->assertJsonCount(0, 'recent_tickets');
        $this->actingAs($this->target)->getJson($url)->assertOk();
        $this->getJson('/api/v2/reports/summary?timeframe=week')->assertOk()->assertJsonPath('escalated', 0)
            ->assertJsonPath('unresolved', 1)->assertJsonPath('completed', 0);
        $this->postJson($url.'/comments', ['comment' => 'Replacement technician working.'])->assertCreated();
    }

    public function test_technician_dashboard_distinguishes_completed_and_unresolved_tickets(): void
    {
        $url = '/api/v2/reports/summary?timeframe=week';
        foreach (['resolved', 'customer_confirmation', 'closed', 'cancelled', 'assigned'] as $slug) {
            $status = TicketStatus::firstOrCreate(['slug' => $slug], ['name' => \Illuminate\Support\Str::headline($slug),
                'is_closed' => in_array($slug, ['closed', 'cancelled'], true)]);
            $this->ticket->update(['status_id' => $status->id]);
            $completed = in_array($slug, ['resolved', 'customer_confirmation', 'closed'], true) ? 1 : 0;
            $this->actingAs($this->source)->getJson($url)->assertOk()
                ->assertJsonPath('completed', $completed)->assertJsonPath('unresolved', $slug === 'assigned' ? 1 : 0)
                ->assertJsonPath('escalated', 0);
        }
        $this->actingAs($this->customer)->getJson($url)->assertOk()->assertJsonMissingPath('escalated');
    }

    public function test_escalation_accepted_during_attachment_scanning_prevents_storage_and_cleans_up(): void
    {
        config(['amc.attachments_enabled' => true]);
        Storage::fake('local');
        $this->mock(AttachmentScanner::class)->shouldReceive('scan')->once()->andReturnUsing(function () {
            $request = Request::create('/'); $request->setUserResolver(fn () => $this->source);
            app(TicketEscalationService::class)->request($request, $this->ticket->id, ['reason' => 'Escalated during upload.']);
        });
        $this->actingAs($this->source)->post('/api/v2/tickets/'.$this->ticket->id.'/attachments',
            ['file' => UploadedFile::fake()->createWithContent('report.txt', 'Upload started before escalation')], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('ticket_escalations', ['ticket_id' => $this->ticket->id, 'state' => 'pending']);
    }

    public function test_request_authorization_reason_and_single_pending_request(): void
    {
        foreach ([$this->admin, $this->customer, $this->target] as $actor) {
            $result = $this->actingAs($actor)->postJson($this->url(), ['reason' => 'Private reason']);
            $this->assertContains($result->status(), [403, 404]);
        }
        $this->actingAs($this->source)->postJson($this->url(), ['reason' => ' '])->assertUnprocessable();
        $this->makeRequest();
        $this->actingAs($this->source)->postJson($this->url(), ['reason' => 'Duplicate'])->assertConflict();
        $this->assertSame(1, TicketEscalation::count());
        Bus::assertDispatched(DispatchTicketNotice::class, fn ($job) => $job->event === 'escalation_pending');
        $this->actingAs($this->target)->getJson($this->url())->assertNotFound();
    }

    public function test_resolved_closed_cancelled_and_unassigned_tickets_cannot_escalate(): void
    {
        foreach (['resolved', 'closed', 'cancelled', 'unresolved', 'customer_confirmation'] as $slug) {
            $status = TicketStatus::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_closed' => in_array($slug, ['closed', 'cancelled'])]);
            $this->ticket->update(['status_id' => $status->id]);
            $this->actingAs($this->source)->postJson($this->url(), ['reason' => 'Invalid state'])->assertUnprocessable();
        }
        $this->ticket->update(['assigned_to' => null]);
        $this->actingAs($this->source)->postJson($this->url(), ['reason' => 'Unassigned'])->assertNotFound();
        $this->assertSame(0, TicketEscalation::count());
    }

    public function test_active_employees_at_the_same_or_higher_level_are_eligible_and_lower_levels_are_rejected(): void
    {
        $record = $this->makeRequest();
        $equal = $this->technician($this->source->designation_id);
        $peerDesignation = TechnicianDesignation::create(['name' => 'Field specialist', 'level' => 1, 'is_active' => true]);
        $peer = $this->technician($peerDesignation->id);
        $lowerDesignation = TechnicianDesignation::create(['name' => 'Trainee', 'level' => 0, 'is_active' => true]);
        $lower = $this->technician($lowerDesignation->id);
        $freelancer = $this->technician($this->target->designation_id, ['workforce_type' => 'freelancer']);
        $inactive = $this->technician($this->target->designation_id, ['status' => 'inactive']);
        $unclassified = $this->technician($this->target->designation_id, ['workforce_type' => null]);
        $foreign = $this->technician($this->target->designation_id, ['customer_id' => $this->customer->customer_id]);
        $disabledDesignation = TechnicianDesignation::create(['name' => 'Retired lead', 'level' => 8, 'is_active' => false]);
        $disabled = $this->technician($disabledDesignation->id);
        $eligible = $this->actingAs($this->admin)->getJson($this->url().'/eligible')->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing([$this->target->id, $equal->id, $peer->id], array_column($eligible->json('data'), 'id'));
        foreach ([$this->source, $lower, $freelancer, $inactive, $foreign, $disabled, $unclassified, $this->customer] as $blocked) {
            $this->approve($record, $blocked)->assertUnprocessable();
            $this->assertSame('pending', $record->fresh()->state);
            $this->assertSame($this->source->id, $this->ticket->fresh()->assigned_to);
        }
        $this->patchJson($this->url().'/'.$record->id, ['decision' => 'approved', 'selected_employee_id' => 99999999])->assertUnprocessable();
        $this->target->designation->update(['is_active' => false]);
        $this->approve($record)->assertUnprocessable();
        $this->approve($record, $peer)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->assertSame($peer->id, $this->ticket->fresh()->assigned_to);
        $this->assertDatabaseHas('ticket_escalations', ['id' => $record->id, 'selected_designation_name' => 'Field specialist', 'selected_level' => 1]);
    }

    public function test_another_employee_with_the_same_designation_can_receive_an_approved_escalation(): void
    {
        $record = $this->makeRequest();
        $peer = $this->technician($this->source->designation_id);
        $this->approve($record, $peer)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->assertSame($peer->id, $this->ticket->fresh()->assigned_to);
    }

    public function test_current_assignee_can_escalate_when_legacy_assignment_history_has_changed(): void
    {
        $record = $this->makeRequest();
        TicketAssignment::whereKey($record->assignment_id)->update(['unassigned_at' => now()]);
        TicketAssignment::create(['ticket_id' => $this->ticket->id, 'technician_id' => $this->source->id,
            'assigned_by' => $this->admin->id, 'assigned_at' => now()]);

        $this->actingAs($this->admin)->getJson($this->url().'/eligible')->assertOk()
            ->assertJsonPath('data.0.id', $this->target->id);
        $this->approve($record)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->assertSame($this->target->id, $this->ticket->fresh()->assigned_to);
    }

    public function test_approval_cannot_reassign_to_an_employee_at_the_active_ticket_limit(): void
    {
        $occupied = $this->ticket->replicate();
        $occupied->ticket_number = 'ESC-CAPACITY';
        $occupied->assigned_to = $this->target->id;
        $occupied->save();
        $this->actingAs($this->admin)->putJson('/api/v2/technician-assignment-limits', ['limit' => 1])->assertOk();
        $record = $this->makeRequest();
        $this->approve($record)->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertSame('pending', $record->fresh()->state);
        $this->assertSame($this->source->id, $this->ticket->fresh()->assigned_to);
        $resolved = TicketStatus::firstOrCreate(['slug' => 'resolved'], ['name' => 'Resolved', 'is_closed' => false]);
        $occupied->update(['status_id' => $resolved->id]);
        $this->approve($record)->assertOk();
        $this->assertSame($this->target->id, $this->ticket->fresh()->assigned_to);
    }

    public function test_approval_reuses_assignment_history_audit_and_queues_and_prevents_repeat_decisions(): void
    {
        $record = $this->makeRequest();
        $old = TicketAssignment::where('ticket_id', $this->ticket->id)->firstOrFail();
        $this->approve($record)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->assertSame($this->target->id, $this->ticket->fresh()->assigned_to);
        $this->assertNotNull($old->fresh()->unassigned_at);
        $this->assertDatabaseHas('ticket_assignments', ['ticket_id' => $this->ticket->id, 'technician_id' => $this->target->id, 'assigned_by' => $this->admin->id, 'unassigned_at' => null]);
        $this->assertDatabaseHas('ticket_escalations', ['id' => $record->id, 'reviewer_id' => $this->admin->id, 'selected_designation_name' => 'Senior', 'previous_designation_name' => 'Junior']);
        $this->assertTrue(ActivityLog::where('event', 'ticket.updated')->where('subject_id', $this->ticket->id)->exists());
        $this->assertTrue(ActivityLog::where('event', 'ticket.escalation_approved')->where('subject_id', $this->ticket->id)->exists());
        Bus::assertDispatched(DispatchTicketNotice::class, fn ($job) => $job->event === 'assigned' && $job->assignedTechnicianId === $this->target->id);
        Bus::assertDispatched(DispatchTicketNotice::class, fn ($job) => $job->event === 'escalation_approved');
        $this->approve($record)->assertConflict();
        $this->actingAs($this->source)->getJson($this->url())->assertNotFound();
        $this->actingAs($this->source)->postJson($this->url(), ['reason' => 'Old assignee'])->assertNotFound();
        $this->actingAs($this->target)->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
        // A new request may move to another employee at this level or higher.
        $this->actingAs($this->target)->postJson($this->url(), ['reason' => 'Need higher specialist'])->assertCreated();
        $next = TicketEscalation::latest('id')->firstOrFail();
        $this->assertSame(2, $next->previous_level);
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson($this->url())->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.reason', 'Specialist diagnostic assistance required.')
            ->assertJsonMissingPath('data.1.selected_employee_id');
        $this->approve($next, $this->source)->assertUnprocessable();
    }

    public function test_only_escalation_approval_can_change_an_existing_assignment(): void
    {
        $url = '/api/v2/tickets/'.$this->ticket->id;
        $this->actingAs($this->admin)->patchJson($url, ['assigned_to' => $this->target->id])->assertConflict();
        $this->patchJson($url, ['assigned_to' => null])->assertConflict();
        $record = $this->makeRequest();
        $this->actingAs($this->admin)->patchJson($url, ['assigned_to' => $this->target->id])->assertConflict();
        $this->patchJson($url, ['assigned_to' => null])->assertConflict();
        $request = Request::create('/'); $request->setUserResolver(fn () => $this->admin);
        try {
            app(TicketService::class)->update($request, $this->ticket, ['assigned_to' => $this->target->id]);
            $this->fail('A direct service call changed the assigned technician.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($this->source->id, $this->ticket->fresh()->assigned_to);
        $this->assertSame(1, TicketAssignment::where('ticket_id', $this->ticket->id)->count());
        $this->approve($record)->assertOk();
        $this->assertSame($this->target->id, $this->ticket->fresh()->assigned_to);
        $this->patchJson($url, ['assigned_to' => $this->source->id])->assertConflict();
    }

    public function test_changed_designation_rejects_stale_escalation_approval(): void
    {
        $record = $this->makeRequest();
        $this->source->designation->update(['level' => 3]);
        $this->approve($record)->assertConflict();
    }

    public function test_rejection_requires_note_preserves_assignment_and_allows_new_request(): void
    {
        $record = $this->makeRequest();
        $this->actingAs($this->admin)->patchJson($this->url().'/'.$record->id, ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('admin_note');
        $this->patchJson($this->url().'/'.$record->id, ['decision' => 'rejected', 'admin_note' => 'Continue diagnosis with vendor.'])->assertOk();
        $this->assertSame($this->source->id, $this->ticket->fresh()->assigned_to);
        $this->assertSame(1, TicketAssignment::where('ticket_id', $this->ticket->id)->count());
        $this->assertNotNull($record->fresh()->decided_at);
        Bus::assertDispatched(DispatchTicketNotice::class, fn ($job) => $job->event === 'escalation_rejected');
        DB::table('amc_mail_settings')->update(['notifications_enabled' => false]);
        $job = new DispatchTicketNotice($this->ticket->id, 'escalation_rejected', null, null, $this->admin->id, null, $record->id);
        $job->handle(); $job->handle();
        $notice = AmcUserNotification::where('event', 'escalation_rejected')->sole();
        $this->assertSame($this->source->id, $notice->user_id);
        $this->assertSame($this->ticket->id, $notice->ticket_id);
        $this->assertSame(\App\Support\TicketNoticeText::REJECTED_ESCALATION_MESSAGE, $notice->message);
        $this->assertStringNotContainsString('Continue diagnosis with vendor.', $notice->message);
        $notice->update(['message' => 'Your escalation request was rejected. Contact Contractor Admin for the next steps.']);
        $this->actingAs($this->source)->getJson('/api/v2/notifications')->assertOk()
            ->assertJsonPath('data.items.0.message', \App\Support\TicketNoticeText::REJECTED_ESCALATION_MESSAGE);
        $this->actingAs($this->source)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.state', 'rejected')
            ->assertJsonMissingPath('data.0.admin_note')
            ->assertDontSee('Continue diagnosis with vendor.');
        $this->actingAs($this->admin)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.admin_note', 'Continue diagnosis with vendor.');
        $this->makeRequest();
        $this->assertSame(2, TicketEscalation::count());
    }

    public function test_transaction_rolls_back_reassignment_if_transition_fails(): void
    {
        $record = $this->makeRequest();
        $mock = \Mockery::mock(TicketService::class);
        $mock->shouldReceive('update')->once()->andReturnUsing(function ($request, $ticket, $data) {
            $ticket->update(['assigned_to' => $data['assigned_to']]);
            throw new \RuntimeException('Simulated failure');
        });
        $request = Request::create('/'); $request->setUserResolver(fn () => $this->admin);
        try {
            (new TicketEscalationService($mock))->decide($request, $this->ticket->id, $record->id, ['decision' => 'approved', 'selected_employee_id' => $this->target->id]);
            $this->fail('Expected simulated failure.');
        } catch (\RuntimeException $error) { $this->assertSame('Simulated failure', $error->getMessage()); }
        $this->assertSame('pending', $record->fresh()->state);
        $this->assertSame($this->source->id, $this->ticket->fresh()->assigned_to);
        $this->assertSame(1, TicketAssignment::where('ticket_id', $this->ticket->id)->count());
    }

    public function test_customer_privacy_and_deduplicated_notifications_after_access_is_revoked(): void
    {
        DB::table('amc_mail_settings')->update(['notifications_enabled' => false]);
        $record = $this->makeRequest();
        $pending = new DispatchTicketNotice($this->ticket->id, 'escalation_pending', null, null, $this->source->id, null, $record->id);
        $pending->handle(); $pending->handle();
        $this->assertSame(1, AmcUserNotification::where('event', 'escalation_pending')->count());
        $notice = AmcUserNotification::where('event', 'escalation_pending')->sole();
        $this->assertSame($this->admin->id, $notice->user_id);
        $this->assertSame('Ticket escalation requested', $notice->title);
        $this->assertSame('A technician requested escalation for '.$this->ticket->displayNumber().'. Review the request in Escalations.', $notice->message);
        $this->approve($record)->assertOk();
        $approved = new DispatchTicketNotice($this->ticket->id, 'escalation_approved', null, null, $this->admin->id, null, $record->id);
        $approved->handle(); $approved->handle();
        (new DispatchTicketNotice($this->ticket->id, 'assigned', $this->target->id, null, $this->admin->id, 'esc-assigned'))->handle();
        $this->assertDatabaseHas('amc_user_notifications', ['user_id' => $this->target->id, 'event' => 'assigned', 'ticket_id' => $this->ticket->id]);
        $notice = AmcUserNotification::where('event', 'escalation_approved')->sole();
        $this->assertSame($this->source->id, $notice->user_id);
        $this->assertNull($notice->ticket_id);
        $this->assertStringNotContainsString($this->ticket->ticket_number, $notice->message);
        $request = Request::create('/'); $request->setUserResolver(fn () => $this->source);
        $this->assertTrue(app(NotificationService::class)->list($request)->data['items']->contains('id', $notice->id));
        foreach (['customer_admin', 'customer_user', 'super_admin'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'status' => 'active', 'customer_id' => $role === 'super_admin' ? null : $this->customer->customer_id]);
            $this->actingAs($actor);
            $this->getJson($this->url())->assertForbidden();
            $this->getJson('/api/v2/escalations')->assertForbidden();
            $this->getJson($this->url().'/eligible')->assertForbidden();
            $this->patchJson($this->url().'/'.$record->id, ['decision' => 'rejected', 'admin_note' => 'No access'])->assertForbidden();
        }
        $this->actingAs($this->customer)->getJson('/api/v2/tickets/'.$this->ticket->id)->assertOk()->assertJsonMissingPath('data.escalations')->assertDontSee('Specialist diagnostic');
        // Even a legacy private notification addressed to a customer is hidden.
        AmcUserNotification::create(['user_id' => $this->customer->id, 'ticket_id' => null, 'event' => 'escalation_rejected',
            'title' => 'Private', 'message' => 'Private note', 'deduplication_key' => hash('sha256', 'legacy-escalation')]);
        $request->setUserResolver(fn () => $this->customer);
        $this->assertFalse(app(NotificationService::class)->list($request)->data['items']->contains('event', 'escalation_rejected'));
    }

    public function test_database_enforces_one_pending_request_even_without_the_service(): void
    {
        $record = $this->makeRequest();
        $this->expectException(\Illuminate\Database\QueryException::class);
        TicketEscalation::create($record->only(['ticket_id', 'requester_id', 'previous_designation_id',
            'previous_designation_name', 'previous_level', 'reason', 'state']));
    }

    public function test_approval_rechecks_ticket_state_and_source_account(): void
    {
        $record = $this->makeRequest();
        $originalStatus = $this->ticket->status_id;
        foreach (['resolved', 'closed', 'cancelled'] as $slug) {
            $status = TicketStatus::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_closed' => $slug !== 'resolved']);
            $this->ticket->update(['status_id' => $status->id]);
            $this->approve($record)->assertConflict();
        }
        $this->ticket->update(['status_id' => $originalStatus]);
        $this->source->update(['status' => 'inactive']);
        $this->approve($record)->assertConflict();
        $this->assertSame('pending', $record->fresh()->state);
    }

    public function test_historical_designation_cannot_be_deleted_after_technician_moves(): void
    {
        $record = $this->makeRequest();
        $this->approve($record)->assertOk();
        $previous = $this->source->designation_id;
        $this->source->update(['designation_id' => $this->target->designation_id]);
        $this->actingAs($this->admin)->deleteJson('/api/v2/designations/'.$previous)->assertUnprocessable();
        $this->assertDatabaseHas('technician_designations', ['id' => $previous]);
    }
}
