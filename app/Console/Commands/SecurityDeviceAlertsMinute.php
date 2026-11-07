<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use Illuminate\Console\Command;

/**
 * Safety fix round (7 Nov 2026): every minute, ended snoozes come back and remind again, and a
 * safety or care alert nobody has said they're on goes to the on-call manager (and Control Room)
 * within the minutes set in Setup › Alerts & checks. The five-minute alerts tick keeps the rest.
 */
final class SecurityDeviceAlertsMinute extends Command
{
    protected $signature = 'security-devices:alerts-minute';

    protected $description = 'Return ended snoozes and escalate unanswered safety and care alerts';

    public function handle(DeviceAlertEngine $alerts): int
    {
        $done = $alerts->minute();
        if ($done['back'] > 0 || $done['escalated'] > 0) {
            $this->info("Snoozes back: {$done['back']} · escalated: {$done['escalated']}");
        }

        return self::SUCCESS;
    }
}
