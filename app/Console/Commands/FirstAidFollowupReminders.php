<?php

namespace App\Console\Commands;

use App\Models\FirstAidFollowup;
use App\Models\User;
use App\Notifications\HealthSafety\FirstAidFollowupDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * First Aid Register — daily follow-up reminders (cross-module seam).
 *
 * Runs daily: finds open first-aid follow-ups (not completed, with a due date
 * and an assignee) whose due date falls within the reminder window, then
 * delivers a digest notification to each assigned responder (in-app bell).
 *
 * No throttle column — a follow-up drops out of the digest as soon as its
 * completed_at is set, matching the house convention for these nags.
 */
class FirstAidFollowupReminders extends Command
{
    protected $signature = 'first-aid:followup-reminders {--window=0}';

    protected $description = 'Surface first-aid follow-ups that are due or overdue on open records.';

    public function handle(): int
    {
        $window = max(0, (int) $this->option('window'));
        $cutoff = now()->addDays($window);

        $followups = FirstAidFollowup::query()
            ->whereNull('completed_at')
            ->whereNotNull('due_at')
            ->whereNotNull('assigned_to_user_id')
            ->where('due_at', '<=', $cutoff)
            ->whereHas('record')
            ->get(['id', 'assigned_to_user_id', 'due_at']);

        // Tally outstanding items per assigned responder → one digest each.
        $perLead = []; // user_id => ['total' => int, 'overdue' => int]
        foreach ($followups as $f) {
            $lead = $f->assigned_to_user_id;
            if (! $lead) {
                continue;
            }
            $perLead[$lead]['total'] = ($perLead[$lead]['total'] ?? 0) + 1;
            if ($f->due_at !== null && $f->due_at->isPast()) {
                $perLead[$lead]['overdue'] = ($perLead[$lead]['overdue'] ?? 0) + 1;
            }
        }

        $notified = 0;
        if ($perLead !== []) {
            foreach (User::query()->whereIn('id', array_keys($perLead))->get() as $lead) {
                $counts = $perLead[$lead->id];
                $lead->notify(new FirstAidFollowupDueNotification(
                    $counts['total'] ?? 0,
                    $counts['overdue'] ?? 0,
                ));
                $notified++;
            }
        }

        $this->info("First aid follow-up reminders: {$followups->count()} due/overdue follow-up(s); notified {$notified} responder(s).");

        Log::info('first_aid.followup_reminders', [
            'followups_due' => $followups->count(),
            'leads_notified' => $notified,
            'window_days' => $window,
        ]);

        return self::SUCCESS;
    }
}
