<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PublishMonitoringOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(
        public readonly int $outboxId,
        public readonly string $dispatchToken,
    ) {}

    public function handle(MonitoringOutboxPublisher $publisher): void
    {
        $publisher->publish($this->outboxId, $this->dispatchToken);
    }
}
