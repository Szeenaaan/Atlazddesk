<?php

namespace App\Jobs;

use App\Models\{MaintenanceVisit, PreventiveMaintenance};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateMaintenanceVisits implements ShouldQueue
{
    use Queueable;
    public int $tries=2;
    public int $timeout=60;

    public function handle(): void
    {
        PreventiveMaintenance::where('status','active')->whereDate('next_due_date','<=',today())
            ->chunkById(100, function ($tasks) {
                foreach ($tasks as $task) {
                    DB::transaction(function () use ($task) {
                        $current = PreventiveMaintenance::whereKey($task->id)->lockForUpdate()->first();
                        if (! $current || $current->status !== 'active' || $current->next_due_date->isFuture()) return;
                        MaintenanceVisit::create([
                            'preventive_maintenance_id'=>$current->id,
                            'technician_id'=>$current->assigned_to,
                            'scheduled_at'=>now()->startOfDay(),
                            'status'=>'scheduled',
                        ]);
                        $next = $current->next_due_date->copy();
                        do {
                            $next = match ($current->frequency) {
                                'daily'=>$next->addDay(), 'weekly'=>$next->addWeek(),
                                'monthly'=>$next->addMonthNoOverflow(),
                                'quarterly'=>$next->addMonthsNoOverflow(3),
                                'yearly'=>$next->addYearNoOverflow(),
                                default=>$next->addMonthNoOverflow(),
                            };
                        } while ($next->isPast());
                        $current->update(['next_due_date'=>$next->toDateString()]);
                    });
                }
            });
    }
}
