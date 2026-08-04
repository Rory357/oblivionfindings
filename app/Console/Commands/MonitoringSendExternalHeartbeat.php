<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Models\MonitoringExternalHeartbeatState;
use App\Domain\Monitoring\Services\ExternalMonitoringHeartbeatService;
use Illuminate\Console\Command;

final class MonitoringSendExternalHeartbeat extends Command
{
    protected $signature = 'monitoring:send-external-heartbeat';

    protected $description = 'Send the value-free central runtime heartbeat to an independent dead-man monitor';

    public function handle(ExternalMonitoringHeartbeatService $heartbeat): int
    {
        $state = $heartbeat->send();
        $this->line('External monitoring heartbeat state: '.$state->state.'.');

        return in_array($state->state, [
            MonitoringExternalHeartbeatState::STATE_DISABLED,
            MonitoringExternalHeartbeatState::STATE_SENT,
        ], true) ? self::SUCCESS : self::FAILURE;
    }
}
