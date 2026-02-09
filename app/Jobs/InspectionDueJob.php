<?php

namespace App\Jobs;

use App\Models\SiteInspectionSchedule;
use App\Notifications\InspectionDueNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InspectionDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $today = now()->toDateString();
        $nextWeek = now()->addWeek()->toDateString();

        // Find inspections due soon (reminder)
        $schedules = SiteInspectionSchedule::query()
            ->where('is_active', true)
            ->whereDate('next_due_date', '<=', $nextWeek)
            ->whereDate('next_due_date', '>=', $today)
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->get();

        foreach ($schedules as $schedule) {
            if ($schedule->assignedTo) {
                $schedule->assignedTo->notify(new InspectionDueNotification($schedule, 'upcoming'));
            }
        }

        // Find overdue inspections
        $overdueSchedules = SiteInspectionSchedule::query()
            ->where('is_active', true)
            ->whereDate('next_due_date', '<', $today)
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->get();

        foreach ($overdueSchedules as $schedule) {
            if ($schedule->assignedTo) {
                $schedule->assignedTo->notify(new InspectionDueNotification($schedule, 'overdue'));
            }
        }
    }
}
