<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Modes\HouseModes;
use Illuminate\Console\Command;

final class SecurityDeviceHouseModesTick extends Command
{
    protected $signature = 'security-devices:house-modes-tick';

    protected $description = 'Switch house modes at their start times, running only their safe actions, and run the alarm checks that are due';

    public function handle(HouseModes $modes): int
    {
        $result = $modes->tick();
        $this->info("House modes switched: {$result['switched']} · alarm checks run: {$result['checks']}");

        return self::SUCCESS;
    }
}
