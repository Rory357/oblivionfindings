<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\MonitoringRuntimeHeartbeatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RuntimeQueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public int $timeout = 30;

    public function __construct(
        public readonly string $component,
        public readonly string $queueName,
        public readonly string $dispatchToken,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $component) !== 1
            || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $queueName) !== 1
            || ! Str::isUuid($dispatchToken)) {
            throw new InvalidArgumentException('Monitoring runtime heartbeat identity is invalid.');
        }

        $this->onConnection((string) config('monitoring.delivery.queue_connection', 'redis'));
        $this->onQueue($queueName);
    }

    public function handle(MonitoringRuntimeHeartbeatService $heartbeats): void
    {
        $heartbeats->acknowledge($this->component, $this->queueName, $this->dispatchToken);
    }
}
