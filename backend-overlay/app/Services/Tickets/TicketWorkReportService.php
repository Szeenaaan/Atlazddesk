<?php

namespace App\Services\Tickets;

use App\Models\{AmcUserNotification, Ticket, TicketWorkReport, User};
use App\Support\{AmcAccess, AmcAudit, ServiceResult, TicketQueries};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Work reporting is separate from ticket status transitions and customer-visible notes. */
final class TicketWorkReportService
{
    public function list(Request $request, int $ticketId): ServiceResult
    {
        $actor = $request->user();
        abort_unless(AmcAccess::isAdmin($actor) || $actor?->role === 'technician', 403);
        TicketQueries::visible($actor)->findOrFail($ticketId);

        $reports = TicketWorkReport::query()->where('ticket_id', $ticketId)
            ->with('technician:id,name')->latest('id')->paginate(20);

        return ServiceResult::ok($reports, 'Technician reports retrieved.');
    }

    public function submit(Request $request, int $ticketId, array $data): ServiceResult
    {
        abort_unless($request->user()?->role === 'technician', 403);
        if ($data['progress'] === 'completed' && trim((string) ($data['work_performed'] ?? '')) === '') {
            abort(422, 'Describe the work performed before marking technician work complete.');
        }

        $report = DB::transaction(function () use ($request, $ticketId, $data): TicketWorkReport {
            // Re-check the current assignment under a lock to prevent stale access.
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            abort_unless((int) $ticket->assigned_to === (int) $request->user()->id, 404);
            abort_if(in_array($ticket->status()->value('slug'), ['closed', 'cancelled'], true),
                422, 'Cannot report on a closed or cancelled ticket.');

            $report = TicketWorkReport::create([
                ...$data,
                'ticket_id' => $ticket->id,
                'technician_id' => $request->user()->id,
            ]);

            AmcAudit::record($request, 'ticket.work_report_submitted', $ticket, [
                'report_id' => $report->id,
                'progress' => $report->progress,
                'outcome' => $report->outcome,
            ]);

            // Database alerts remain available even when SMTP or the queue is offline.
            foreach (User::query()->where('role', 'admin')->where('status', 'active')->pluck('id') as $adminId) {
                AmcUserNotification::create([
                    'user_id' => $adminId,
                    'ticket_id' => $ticket->id,
                    'event' => 'work_report',
                    'title' => 'Technician work report submitted',
                    'message' => "{$ticket->ticket_number} • {$report->progress} / {$report->outcome}",
                    'deduplication_key' => hash('sha256', "work_report:{$report->id}:{$adminId}"),
                ]);
            }
            DB::afterCommit(fn () => app(\App\Services\Mail\TicketBusinessMail::class)->enqueue(
                $ticket->id, 'work_report', 'work-report:'.$report->id, $request->user()->id));

            return $report;
        });

        return ServiceResult::ok($report->load('technician:id,name'), 'Technician report submitted.', 201);
    }
}
