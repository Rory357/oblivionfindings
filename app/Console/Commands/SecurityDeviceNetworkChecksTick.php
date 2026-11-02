<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\NetworkChecks\NetworkChecks;
use Illuminate\Console\Command;

final class SecurityDeviceNetworkChecksTick extends Command
{
    protected $signature = 'security-devices:network-checks-tick {--prune : Only remove sessions older than a year}';

    protected $description = 'Move Obli’s network checks on (checks the hub didn’t answer in time, answers still to write); with --prune, remove sessions past their year';

    public function handle(NetworkChecks $checks): int
    {
        if ($this->option('prune')) {
            $this->info('Network check sessions removed after a year: '.$checks->prune());

            return self::SUCCESS;
        }
        $this->info('Network check sessions moved on: '.$checks->sweep());

        return self::SUCCESS;
    }
}
