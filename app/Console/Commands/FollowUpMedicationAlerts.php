<?php

namespace App\Console\Commands;

use App\Services\Medication\Alerts\MedicationAlertFollowUps;
use Illuminate\Console\Command;

/**
 * eMAR P11 B2 chunk 3: re-alert and escalate medication alerts with Follow
 * up on that nobody has attended yet, as Settings › Alerts & access ›
 * Delivery says. Every 15 minutes, one run at a time.
 */
class FollowUpMedicationAlerts extends Command
{
    protected $signature = 'emar:alert-follow-ups';

    protected $description = 'Re-alert and escalate medication alerts nobody has attended yet';

    public function handle(MedicationAlertFollowUps $followUps): int
    {
        $steps = $followUps->tick(now());
        $this->info("Medication alert follow-ups: {$steps} sent.");

        return self::SUCCESS;
    }
}
