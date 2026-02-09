<?php

namespace App\Jobs;

use App\Models\SiteHazard;
use App\Notifications\HazardOverdueNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HazardOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $today = now()->toDateString();

        // Find hazards approaching due date (2 days warning)
        $warningDate = now()->addDays(2)->toDateString();
        
        $warningHazards = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereDate('due_date', '<=', $warningDate)
            ->whereDate('due_date', '>=', $today)
            ->whereNull('warning_sent_at')
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->get();

        foreach ($warningHazards as $hazard) {
            if ($hazard->assignedTo) {
                $hazard->assignedTo->notify(new HazardOverdueNotification($hazard, 'warning'));
            }
            $hazard->update(['warning_sent_at' => now()]);
        }

        // Find overdue hazards
        $overdueHazards = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereDate('due_date', '<', $today)
            ->whereNull('overdue_notified_at')
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->get();

        foreach ($overdueHazards as $hazard) {
            if ($hazard->assignedTo) {
                $hazard->assignedTo->notify(new HazardOverdueNotification($hazard, 'overdue'));
            }
            
            // Also notify H&S officers
            $hsOfficers = \App\Models\User::whereHas('roles', fn($q) => $q->where('name', 'health_safety_officer'))->get();
            foreach ($hsOfficers as $officer) {
                $officer->notify(new HazardOverdueNotification($hazard, 'overdue_escalation'));
            }
            
            $hazard->update(['overdue_notified_at' => now()]);
        }
    }
}
