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
 * staffer (clinical.monitoring.viewAny). Complements the real-time
 * emitForDeterioration Control Room signal with a daily backstop so a stale watch
 * or recording backlog never sits unseen. Counts reuse the canonical
 * getKpis() snapshot so the digest and the module Overview never diverge.
 */
class ClinicalDeteriorationReminders extends Command
{
    protected $signature = 'clinical:deterioration-reminders';

    protected $description = 'Surface clients on the NEWS2 deterioration watch and overdue clinical observations to clinical oversight staff.';

    public function handle(ClinicalDashboardService $dashboard): int
    {
        $kpis = $dashboard->getKpis();
        $onWatch = (int) ($kpis['clients_on_watch'] ?? 0);
        $overdueObs = (int) ($kpis['schedules_overdue'] ?? 0);

        if ($onWatch === 0 && $overdueObs === 0) {
            $this->info('Clinical reminders: nothing on watch, no overdue observations.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereHas('permissions', fn ($p) => $p->where('key', 'clinical.monitoring.viewAny')))
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new ClinicalWatchDigestNotification($onWatch, $overdueObs));
        }

        $this->info("Clinical reminders: {$onWatch} on watch, {$overdueObs} overdue observation(s); notified {$recipients->count()} staffer(s).");

        Log::info('clinical.deterioration_reminders', [
            'clients_on_watch' => $onWatch,
            'overdue_observations' => $overdueObs,
            'recipients_notified' => $recipients->count(),
        ]);

        return self::SUCCESS;
    }
}
