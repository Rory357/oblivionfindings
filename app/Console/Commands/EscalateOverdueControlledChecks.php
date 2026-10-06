<?php

namespace App\Console\Commands;

use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\Controlled\ControlledCountStatus;
use Illuminate\Console\Command;

/** Escalate configured roster or explicitly anchored weekly counts and retire stale projections from the same evidence. */
class EscalateOverdueControlledChecks extends Command
{
    // Retain old scheduled/manual invocations; a day threshold cannot override organisation policy.
    protected $signature = 'emar:escalate-overdue-cd-checks {--days= : Deprecated; configured count policy is always used}';

    protected $description = 'Reconcile overdue controlled counts using the configured organisation cadence and count timing.';

    public function handle(ControlledCountStatus $counts): int
    {
        $overdue = $counts->refreshDashboardAlerts();
        app(MedicationAlertSources::class)->controlledChecks($overdue);
        $this->info("Controlled count escalation complete. {$overdue->count()} overdue medicine(s) under the configured count policy.");

        return self::SUCCESS;
    }
}
