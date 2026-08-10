<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\MonitoringRuntimeHeartbeatService;
use Illuminate\Console\Command;

final class MonitoringDispatchRuntimeHeartbeats extends Command
{
    protected $signature = 'monitoring:dispatch-runtime-heartbeats';

    protected $description = 'Dispatch one durable canary to every isolated monitoring runtime queue';

    public function handle(MonitoringRuntimeHeartbeatService $heartbeats): int
    {
        $count = $heartbeats->dispatch();
        $this->info("Dispatched {$count} monitoring runtime heartbeats.");

        return self::SUCCESS;
    }
}
