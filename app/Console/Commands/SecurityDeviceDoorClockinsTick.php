<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\DoorClockins\DoorClockinEngine;
use Illuminate\Console\Command;

final class SecurityDeviceDoorClockinsTick extends Command
{
    protected $signature = 'security-devices:door-clockins-tick {--prune : Only remove rows older than 90 days}';

    protected $description = 'Suggest clock-ins from door badges near a rostered start, and tell people about gaps (nothing is clocked in until the person chooses)';

    public function handle(DoorClockinEngine $engine): int
    {
        if ($this->option('prune')) {
            $this->info('Clock-in rows removed after 90 days: '.$engine->prune());

            return self::SUCCESS;
        }
        $result = $engine->tick();
        $this->info("Rows opened: {$result['opened']} · suggested: {$result['suggested']} · not on the roster: {$result['not_rostered']} · reminded: {$result['reminded']} · managers told: {$result['told']} · closed: {$result['closed']}");

        return self::SUCCESS;
    }
}
