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
        // S&D Phase G: terminals past their idle or hour limit close; what devices sent is dropped an hour after.
        $terminals = app(\App\Domain\SecurityDevices\Terminal\TerminalSessions::class)->sweep();
        // S&D Phase G: idle device web pages close; any answer never served is dropped.
        app(\App\Domain\SecurityDevices\WebPages\WebPages::class)->sweep();
        // S&D Phase H (D14): names on door events are cleared after 90 days.
        app(\App\Domain\SecurityDevices\Access\UnifiAccessSync::class)->forgetOldNames();
        // S&D Phase H (D20): leavers are turned off in UniFi Access after their last day; stale changes close.
        app(\App\Domain\SecurityDevices\Access\AccessLeavers::class)->tick();
        app(\App\Domain\SecurityDevices\Access\AccessJobs::class)->expire();
        $this->info("Back from snooze: {$result['back']} · reminders sent: {$result['reminded']} · unlinked (consent ended): {$unlinked} · out of bed: {$outOfBed} · scald alerts closed: {$cool} · terminals closed: {$terminals}");

        return self::SUCCESS;
    }
}
