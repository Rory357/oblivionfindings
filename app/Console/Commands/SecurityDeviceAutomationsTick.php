<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Automations\AutomationEngine;
use Illuminate\Console\Command;

final class SecurityDeviceAutomationsTick extends Command
{
    protected $signature = 'security-devices:automations-tick';

    protected $description = 'Run the house automations that start at a time of day (safe actions only), once per start';

    public function handle(AutomationEngine $engine): int
    {
        $ran = $engine->tick();
        $this->info("Time-of-day automations run: {$ran}");

        return self::SUCCESS;
    }
}
