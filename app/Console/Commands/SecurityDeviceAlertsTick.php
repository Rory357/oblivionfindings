<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use Illuminate\Console\Command;

final class SecurityDeviceAlertsTick extends Command
{
    protected $signature = 'security-devices:alerts-tick';

    protected $description = 'Bring back device alerts whose snooze has ended and remind people about open alerts nobody has acknowledged';

    public function handle(DeviceAlertEngine $alerts): int
    {
        $result = $alerts->tick();
        $this->info("Back from snooze: {$result['back']} · reminders sent: {$result['reminded']}");

        return self::SUCCESS;
    }
}
