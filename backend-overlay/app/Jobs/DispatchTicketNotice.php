<?php

namespace App\Jobs;

use App\Models\{Ticket, User, AmcUserNotification};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Services\Mail\ContractorMailService;
use Illuminate\Support\Str;

class DispatchTicketNotice implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 25;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $ticketId, public string $event, public ?int $assignedTechnicianId = null, public ?int $commentId = null, public ?int $actorId = null, public ?string $eventKey = null)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        if (! in_array($this->event, ['created','assigned','updated','commented','internal_note','sla_warning','sla_breach','contract_expiry','attachment_uploaded','work_logged'], true)) return;
        $mail = app(ContractorMailService::class);
        $ticket = Ticket::with('customer')->find($this->ticketId);
        if (! $ticket) return;
        // A delayed queue worker must never deliver an old assignment to a newly assigned technician.
        if ($this->event === 'assigned' && $this->assignedTechnicianId !== null
            && (int) $ticket->assigned_to !== $this->assignedTechnicianId) return;
        $excerpt = null;
        $internal = in_array($this->event, ['internal_note', 'work_logged'], true);
        if ($this->commentId !== null) {
            $comment = $ticket->comments()->find($this->commentId);
            if (! $comment || (bool) $comment->is_internal !== $internal) return;
            // Derive authorship from the stored comment, including older queued jobs.
            $this->actorId = (int) $comment->user_id;
            $excerpt = Str::limit(Str::squish($comment->comment), 160, '.....');
        } elseif ($this->event === 'internal_note') return;
        $recipients = User::query()->where('status', 'active')->where(function ($query) use ($ticket, $internal) {
            $query->where('role', 'admin')
                ->orWhere(fn ($q) => $q->where('role', 'technician')->whereKey($ticket->assigned_to ?? 0));
            if (! $internal) {
                $query->orWhere(fn ($q) => $q->where('role', 'customer_admin')->where('customer_id', $ticket->customer_id))
                    ->orWhere(fn ($q) => $q->where('role', 'customer_user')->where('customer_id', $ticket->customer_id)
                        ->whereKey($ticket->created_by)->whereHas('assignedAssets', fn ($assets) => $assets->where('assets.id', $ticket->asset_id)->where('assets.customer_id', $ticket->customer_id)));
            }
        });
        if ($this->actorId !== null && in_array($this->event, ['commented', 'internal_note', 'work_logged'], true)) $recipients->where('id', '!=', $this->actorId);
        $failure = null;
        foreach ($recipients->cursor() as $recipient) {
            // Stored before email send: technicians can see assignments even if SMTP is down.
            $key = hash('sha256', "{$ticket->id}:{$this->event}:{$recipient->id}:".($this->eventKey ?? $this->commentId ?? $ticket->updated_at?->format('U.u')));
            AmcUserNotification::firstOrCreate(['deduplication_key'=>$key], [
                'user_id'=>$recipient->id, 'ticket_id'=>$ticket->id,
                'event'=>$this->event,
                'title'=>$this->event === 'assigned' ? ($recipient->role === 'technician' ? 'Ticket assigned to you' : 'Ticket assignment updated') : 'Ticket update',
                'message'=>"Ticket {$ticket->ticket_number} • ".($excerpt ?? $this->event),
            ]);
            try {
                $mail->sendNotification($recipient, 'Atlazddesk ticket update',
                    "Ticket {$ticket->ticket_number} has an update ({$this->event}). Sign in to view it securely.");
            } catch (\Throwable $e) {
                Log::error('amc.notification_failure', ['ticket_id'=>$ticket->id, 'recipient_id'=>$recipient->id, 'exception'=>$e::class]);
                $failure = $e;
            }
        }
        if ($failure) throw $failure;
    }
}
