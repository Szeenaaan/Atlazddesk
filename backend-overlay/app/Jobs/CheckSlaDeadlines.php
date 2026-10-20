<?php

namespace App\Jobs;

use App\Models\{Ticket, TicketStatus, AmcAlertEvent};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckSlaDeadlines implements ShouldQueue
{
    use Queueable;
    public int $tries=2;
    public int $timeout=60;

    public function handle(): void
    {
        $closed = TicketStatus::whereIn('slug',['resolved','closed','cancelled'])->pluck('id');
        Ticket::whereNotIn('status_id',$closed)->whereNotNull('sla_resolution_due_at')
            ->where('sla_resolution_due_at','<=',now()->addMinutes(config('amc.sla_warning_minutes',30)))
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $event = $ticket->sla_resolution_due_at->isPast() ? 'sla_breach' : 'sla_warning';
                    $key = hash('sha256', $event.'|'.$ticket->id.'|'.$ticket->sla_resolution_due_at->timestamp);
                    $alert = AmcAlertEvent::firstOrCreate(['event_key'=>$key], [
                        'event'=>$event,'subject_type'=>'ticket','subject_id'=>$ticket->id,
                    ]);
                    if ($alert->wasRecentlyCreated) DispatchTicketNotice::dispatch($ticket->id,$event);
                }
            });
    }
}
