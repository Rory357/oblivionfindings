<?php

namespace App\Console\Commands;

use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Models\User;
use App\Notifications\Clinical\ClinicalWatchDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Health & Clinical — Step 8c cross-module fanout.
 *
 * Runs daily: counts clients on the NEWS2 deterioration watch (latest vitals in
 * the Medium/High band) and observation protocol schedules now overdue, then
 * delivers a single digest notification (in-app bell) to each clinical oversight
 * staffer (clinical.monitoring.viewAny), scoped to that recipient's allowed
 * Sites. Complements the real-time
 * emitForDeterioration Control Room signal with a daily backstop so a stale watch
 * or recording backlog never sits unseen. Counts reuse the canonical
 * getKpis() snapshot so each digest and that recipient's module Overview never
 * diverge.
 */
class ClinicalDeteriorationReminders extends Command
{
    protected $signature = 'clinical:deterioration-reminders';

    protected $description = 'Surface clients on the NEWS2 deterioration watch and overdue clinical observations to clinical oversight staff.';

    public function handle(ClinicalDashboardService $dashboard): int
    {
        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereHas('permissions', fn ($p) => $p->where('key', 'clinical.monitoring.viewAny')))
            ->get();

        $notified = 0;
        foreach ($recipients as $recipient) {
            $kpis = $dashboard->getKpis($recipient);
            $onWatch = (int) ($kpis['clients_on_watch'] ?? 0);
            $overdueObs = (int) ($kpis['schedules_overdue'] ?? 0);

            if ($onWatch === 0 && $overdueObs === 0) {
                continue;
            }

            $recipient->notify(new ClinicalWatchDigestNotification($onWatch, $overdueObs));
            $notified++;
        }

        $this->info($notified === 0
            ? 'Clinical reminders: nothing visible requires a recipient digest.'
            : "Clinical reminders: notified {$notified} staffer(s) with Site-scoped digests.");

        Log::info('clinical.deterioration_reminders', [
            'recipients_considered' => $recipients->count(),
            'recipients_notified' => $notified,
        ]);

        return self::SUCCESS;
    }
}
