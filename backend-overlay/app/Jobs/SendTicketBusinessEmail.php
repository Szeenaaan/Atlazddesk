<?php

namespace App\Jobs;

use App\Services\Mail\TicketBusinessMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendTicketBusinessEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 25;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $deliveryId)
    {
        $this->onConnection(config('amc.ticket_mail_queue_connection', 'database'));
        $this->onQueue('notifications');
    }

    public function handle(TicketBusinessMail $mail): void
    {
        $mail->send($this->deliveryId);
    }
}
