<?php

namespace App\Console\Commands;

use App\Services\Medication\Alerts\MedicationAlertFollowUps;
use App\Services\Medication\Alerts\MedicationAlerts;
use Illuminate\Console\Command;

/**
 * eMAR P11 B2 chunk 3: re-alert and escalate medication alerts with Follow
 * up on that nobody has attended yet, as Settings › Alerts & access ›
 * Delivery says. Every 15 minutes, one run at a time.
 *
 * B2 chunk 5: it also sends the email and push held for quiet hours once
 * those hours end.
 */
class FollowUpMedicationAlerts extends Command
{
    protected $signature = 'emar:alert-follow-ups';

    protected $description = 'Re-alert and escalate medication alerts nobody has attended yet';

    public function handle(MedicationAlertFollowUps $followUps, MedicationAlerts $alerts): int
    {
        $steps = $followUps->tick(now());
        $released = $alerts->releaseHeld(now());
        $this->info("Medication alert follow-ups: {$steps} sent. Held for quiet hours: {$released} sent.");

        return self::SUCCESS;
    }
}
