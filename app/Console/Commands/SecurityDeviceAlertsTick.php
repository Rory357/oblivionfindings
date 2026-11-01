<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Care\CareRules;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\ScaldWatch;
use Illuminate\Console\Command;

final class SecurityDeviceAlertsTick extends Command
{
    protected $signature = 'security-devices:alerts-tick';

    protected $description = 'Bring back device alerts whose snooze has ended, remind people about open alerts nobody has acknowledged, and run the care-plan and scald checks';

    public function handle(DeviceAlertEngine $alerts, CareRules $care, ScaldWatch $scald, DevicePersonLinks $links): int
    {
        $result = $alerts->tick();
        // S&D Phase F: links whose consent ended come off first, so no rule runs without consent.
        $unlinked = $links->sweepConsents();
        $outOfBed = $care->tick();
        $cool = $scald->tick();
        $this->info("Back from snooze: {$result['back']} · reminders sent: {$result['reminded']} · unlinked (consent ended): {$unlinked} · out of bed: {$outOfBed} · scald alerts closed: {$cool}");

        return self::SUCCESS;
    }
}
