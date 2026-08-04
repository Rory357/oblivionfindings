<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\MonitorCheckRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

final class RunMonitorCheck implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $monitorId,
        public readonly string $scheduleKey,
    ) {
        if ($monitorId < 1 || ! MonitorCheckRunner::validScheduleKey($scheduleKey)) {
            throw new InvalidArgumentException('Monitoring check identity is invalid.');
        }

        $this->onConnection('redis');
        $this->onQueue('monitoring-checks');
    }

    public function handle(MonitorCheckRunner $runner): void
    {
        $runner->run($this->monitorId, $this->scheduleKey);
    }

    public function uniqueId(): string
    {
        return "{$this->monitorId}:{$this->scheduleKey}";
    }
}
