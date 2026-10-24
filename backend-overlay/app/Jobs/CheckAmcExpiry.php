<?php

namespace App\Jobs;

use App\Models\{AmcContract, AmcAlertEvent, User};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Mail\ContractorMailService;

class CheckAmcExpiry implements ShouldQueue
{
    use Queueable;
    public int $tries=2;
    public int $timeout=60;

    public function __construct()
    {
        $this->onConnection(config('amc.ticket_mail_queue_connection', 'database'));
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $mail = app(ContractorMailService::class);
        AmcContract::where('status','active')->whereBetween('end_date', [
            today()->toDateString(), today()->addDays(config('amc.contract_warning_days',30))->toDateString(),
        ])
            ->chunkById(100, function ($contracts) use ($mail) {
                foreach ($contracts as $contract) {
                    $key = hash('sha256','contract_expiry|'.$contract->id.'|'.$contract->end_date->toDateString());
                    $alert = AmcAlertEvent::firstOrCreate(['event_key'=>$key], [
                        'event'=>'contract_expiry','subject_type'=>'contract','subject_id'=>$contract->id,
                    ]);
                    if (! $alert->wasRecentlyCreated) continue;
                    foreach (User::where('role','admin')->where('status','active')->cursor() as $user) {
                        $mail->sendNotification($user, 'AMC contract renewal reminder',
                            "Contract {$contract->contract_number} expires on {$contract->end_date->toDateString()}.");
                    }
                }
            });
    }
}
