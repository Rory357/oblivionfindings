<?php

namespace App\Jobs;

use App\Models\SiteChecklistRun;
use App\Notifications\ChecklistDueNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ChecklistDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $today = now()->toDateString();

        // Find checklists due tomorrow (reminder)
        $runs = SiteChecklistRun::query()
            ->where('status', 'scheduled')
            ->whereDate('scheduled_date', $tomorrow)
            ->with(['site:id,name', 'assignment.assignedTo:id,name', 'template:id,name'])
            ->get();

        foreach ($runs as $run) {
            $user = $run->assignment?->assignedTo;
            
            if ($user) {
                $user->notify(new ChecklistDueNotification($run, 'reminder'));
            }
        }

        // Find overdue checklists
        $overdueRuns = SiteChecklistRun::query()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereDate('scheduled_date', '<', $today)
            ->with(['site:id,name', 'assignment.assignedTo:id,name', 'template:id,name'])
            ->get();

        foreach ($overdueRuns as $run) {
            // Update status to overdue
            if ($run->status === 'scheduled') {
                $run->update(['status' => 'overdue']);
            }

            $user = $run->assignment?->assignedTo;
            
            if ($user) {
                $user->notify(new ChecklistDueNotification($run, 'overdue'));
            }
        }
    }
}
