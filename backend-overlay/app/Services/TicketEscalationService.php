<?php

namespace App\Services;

use App\Jobs\DispatchTicketNotice;
use App\Models\{TechnicianDesignation, Ticket, TicketAssignment, TicketEscalation, User};
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

class TicketEscalationService
{
    public function __construct(private readonly TicketService $tickets) {}

    public function visible(Request $request, int $id): Ticket
    {
        abort_unless(in_array($request->user()->role, ['admin', 'technician'], true), 403);
        $ticket = AmcAccess::scopeTicket(Ticket::query(), $request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', [TicketEscalation::class, $ticket]);
        return $ticket;
    }

    public function open(Ticket $ticket): bool
    {
        return $ticket->assigned_to && $ticket->status && ! $ticket->isFinalized();
    }

    private function assignment(Ticket $ticket): ?int
    {
        return TicketAssignment::where('ticket_id', $ticket->id)->whereNull('unassigned_at')->latest('id')->value('id');
    }

    public function request(Request $request, int $id, array $data): TicketEscalation
    {
        return DB::transaction(function () use ($request, $id, $data) {
            $ticket = AmcAccess::scopeTicket(Ticket::query(), $request->user())->lockForUpdate()->findOrFail($id);
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('request', [TicketEscalation::class, $ticket]);
            abort_unless($this->open($ticket), 422, 'Escalation requires an open ticket assigned to you.');
            abort_if(TicketEscalation::where('ticket_id', $id)->where('state', 'pending')->exists(), 409, 'This ticket already has a pending escalation request.');
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 5000) throw ValidationException::withMessages(['reason' => 'Enter an escalation reason of up to 5,000 characters.']);
            $designation = TechnicianDesignation::lockForUpdate()->findOrFail($actor->designation_id);
            $escalation = TicketEscalation::create([
                'ticket_id' => $id, 'requester_id' => $actor->id, 'previous_designation_id' => $designation->id,
                'previous_designation_name' => $designation->name, 'previous_level' => $designation->level,
                'assignment_id' => $this->assignment($ticket), 'reason' => $reason, 'state' => 'pending',
            ]);
            AmcAudit::record($request, 'ticket.escalation_requested', $ticket, ['escalation_id' => $escalation->id]);
            $this->notice($request, $escalation);
            return $escalation;
        });
    }

    public function eligible(Request $request, int $id)
    {
        Gate::forUser($request->user())->authorize('review', TicketEscalation::class);
        $ticket = $this->visible($request, $id);
        $pending = TicketEscalation::where('ticket_id', $id)->where('state', 'pending')->first();
        $source = $ticket->assignee;
        if (! $pending || ! $this->open($ticket) || ! $source || $source->status !== 'active'
            || $source->role !== 'technician' || $source->customer_id !== null || (int) $source->id !== (int) $pending->requester_id
            || (int) $source->designation_id !== (int) $pending->previous_designation_id
            || $source->designation?->level !== $pending->previous_level) return collect();
        return User::query()->where('role', 'technician')->whereNull('customer_id')->where('status', 'active')
            ->where('workforce_type', 'employee')->where('id', '!=', $source->id)
            ->whereHas('designation', fn ($q) => $q->where('is_active', true)->where('level', '>=', $source->designation->level))
            ->with('designation:id,name,level,is_active')->orderBy('name')
            ->get(['id', 'name', 'designation_id']);
    }

    public function decide(Request $request, int $ticketId, int $id, array $data): TicketEscalation
    {
        Gate::forUser($request->user())->authorize('review', TicketEscalation::class);
        return DB::transaction(function () use ($request, $ticketId, $id, $data) {
            // All assignment mutations serialize on the ticket first.
            $ticket = AmcAccess::scopeTicket(Ticket::query(), $request->user())->lockForUpdate()->findOrFail($ticketId);
            $escalation = TicketEscalation::where('ticket_id', $ticketId)->lockForUpdate()->findOrFail($id);
            abort_unless($escalation->state === 'pending', 409, 'This escalation request has already been decided. Refresh the ticket.');
            $decision = $data['decision'] ?? '';
            abort_unless(in_array($decision, ['approved', 'rejected'], true), 422, 'Choose approve or reject.');
            $note = trim((string) ($data['admin_note'] ?? ''));
            if (mb_strlen($note) > 5000 || ($decision === 'rejected' && $note === '')) throw ValidationException::withMessages(['admin_note' => 'Enter an administrative note of up to 5,000 characters.']);
            if ($decision === 'approved') {
                $targetId = (int) ($data['selected_employee_id'] ?? 0);
                $users = User::whereIn('id', [$escalation->requester_id, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_unless($this->open($ticket) && (int) $ticket->assigned_to === (int) $escalation->requester_id, 409,
                    'The ticket changed, so this request cannot be approved. Review the ticket and ask the assigned technician to submit a new request.');
                $source = $users->get($escalation->requester_id);
                $target = $users->get($targetId);
                $designations = TechnicianDesignation::whereIn('id', array_filter([$source?->designation_id, $target?->designation_id]))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $previous = $designations->get($source?->designation_id);
                $selected = $designations->get($target?->designation_id);
                abort_unless($source && $source->role === 'technician' && $source->status === 'active' && $source->customer_id === null
                    && $previous && (int) $previous->id === (int) $escalation->previous_designation_id
                    && $previous->level === $escalation->previous_level, 409,
                    'The technician’s details changed, so this request cannot be approved. Ask the technician to submit a new request.');
                if (! $target || $target->role !== 'technician' || $target->customer_id !== null || $target->status !== 'active'
                    || $target->workforce_type !== 'employee' || $target->id === $source->id
                    || ! $selected?->is_active || $selected->level < $previous->level) {
                    throw ValidationException::withMessages(['selected_employee_id' => 'Choose an active contractor employee at the same designation level or higher.']);
                }
                // Reuse all ordinary assignment checks, history, audit and queued assignment notices.
                $this->tickets->update($request, $ticket, ['assigned_to' => $target->id], $escalation->id);
                $escalation->fill([
                    'selected_employee_id' => $target->id, 'selected_designation_id' => $selected->id,
                    'selected_designation_name' => $selected->name, 'selected_level' => $selected->level,
                ]);
            }
            $escalation->fill(['state' => $decision, 'admin_note' => $note ?: null,
                'reviewer_id' => AmcAudit::actorId($request), 'decided_at' => now()])->save();
            AmcAudit::record($request, 'ticket.escalation_'.$decision, $ticket, [
                'escalation_id' => $escalation->id, 'requester_id' => $escalation->requester_id,
                'selected_employee_id' => $escalation->selected_employee_id,
            ]);
            $this->notice($request, $escalation);
            return $escalation;
        }, 3);
    }

    private function notice(Request $request, TicketEscalation $escalation): void
    {
        DispatchTicketNotice::dispatch($escalation->ticket_id, 'escalation_'.$escalation->state, null, null,
            AmcAudit::actorId($request), 'escalation:'.$escalation->id.':'.$escalation->state, $escalation->id)->afterCommit();
    }
}
