<?php

namespace App\Jobs;

use App\Models\SiteChecklistRun;
use App\Models\User;
use App\Notifications\ChecklistDueNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;

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
            ->with(['site:id,name,type', 'assignment.assignedTo:id,name', 'template:id,name'])
            ->get();

        foreach ($runs as $run) {
            $user = $this->eligibleRecipient($run);

            if ($user) {
                $user->notify(new ChecklistDueNotification($run, 'reminder'));
            }
        }

        // Find overdue checklists
        $overdueRuns = SiteChecklistRun::query()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereDate('scheduled_date', '<', $today)
            ->with(['site:id,name,type', 'assignment.assignedTo:id,name', 'template:id,name'])
            ->get();

        foreach ($overdueRuns as $run) {
            // Update status to overdue
            if ($run->status === 'scheduled') {
                $run->update(['status' => 'overdue']);
            }

            $user = $this->eligibleRecipient($run);

            if ($user) {
                $user->notify(new ChecklistDueNotification($run, 'overdue'));
            }
        }
    }

    private function eligibleRecipient(SiteChecklistRun $run): ?User
    {
        $recipientId = $run->effectiveAssigneeUserId();
        if ($recipientId === null) {
            return null;
        }

        $recipient = User::query()->find($recipientId);

        return $recipient && Gate::forUser($recipient)->allows('execute', $run)
            ? $recipient
            : null;
    }
}
