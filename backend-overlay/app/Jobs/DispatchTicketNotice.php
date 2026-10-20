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

    public function __construct(public int $ticketId, public string $event, public ?int $assignedTechnicianId = null)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $mail = app(ContractorMailService::class);
        $ticket = Ticket::with('customer')->find($this->ticketId);
        if (! $ticket) return;
        // A delayed queue worker must never deliver an old assignment to a newly assigned technician.
        if ($this->event === 'assigned' && $this->assignedTechnicianId !== null
            && (int) $ticket->assigned_to !== $this->assignedTechnicianId) return;
        $recipients = match ($this->event) {
            'created' => User::where('role','admin')->where('status','active'),
            'assigned' => User::whereKey($this->assignedTechnicianId ?? $ticket->assigned_to)->where('role','technician')->where('status','active'),
            'updated','commented','sla_warning','sla_breach','contract_expiry' =>
                User::where('customer_id',$ticket->customer_id)
                    ->where('role','customer_admin')->where('status','active'),
            default => User::whereRaw('1 = 0'),
        };
        foreach ($recipients->cursor() as $recipient) {
            // Stored before email send: technicians can see assignments even if SMTP is down.
            $key = hash('sha256', "{$ticket->id}:{$this->event}:{$recipient->id}:".$ticket->updated_at?->format('U.u'));
            AmcUserNotification::firstOrCreate(['deduplication_key'=>$key], [
                'user_id'=>$recipient->id, 'ticket_id'=>$ticket->id,
                'event'=>$this->event,
                'title'=>$this->event === 'assigned' ? 'Ticket assigned to you' : 'Ticket update',
                'message'=>"Ticket {$ticket->ticket_number} • {$this->event}",
            ]);
            try {
                $mail->sendNotification($recipient, 'AtlasDesk ticket update',
                    "Ticket {$ticket->ticket_number} has an update ({$this->event}). Sign in to view it securely.");
            } catch (\Throwable $e) {
                Log::error('amc.notification_failure', ['ticket_id'=>$ticket->id, 'recipient_id'=>$recipient->id, 'exception'=>$e::class]);
                throw $e;
            }
        }
    }
}
