<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Notifications\OnboardingTaskDueNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nudge onboarding task assignees about work that is overdue or due soon.
 *
 * Overdue tasks (due before today, not completed) re-notify each run — an
 * escalating daily nudge. Due-soon tasks notify once, when they fall exactly
 * two days out, so assignees aren't spammed across the whole window.
 */
class SendOnboardingRemindersCommand extends Command
{
    protected $signature = 'hr:onboarding-reminders';

    protected $description = 'Remind onboarding task owners about overdue and imminent tasks.';

    public function handle(): int
    {
        $today = Carbon::today();
        $dueSoon = $today->copy()->addDays(2)->toDateString();

        $tasks = HrOnboardingTask::query()
            ->whereNotNull('assigned_to_user_id')
            ->where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->where(fn ($q) => $q
                ->whereDate('due_date', '<', $today->toDateString())
                ->orWhereDate('due_date', '=', $dueSoon))
            ->whereHas('checklist', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']))
            ->with('assignedTo')
            ->get();

        $sent = 0;

        foreach ($tasks as $task) {
            $assignee = $task->assignedTo;
            if (! $assignee instanceof User) {
                continue;
            }

            $reason = Carbon::parse($task->due_date)->startOfDay()->lt($today) ? 'overdue' : 'due_soon';

            try {
                $assignee->notify(new OnboardingTaskDueNotification($task, $reason));
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send onboarding reminder', [
                    'task_id' => $task->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} onboarding reminder(s).");

        return self::SUCCESS;
    }
}
