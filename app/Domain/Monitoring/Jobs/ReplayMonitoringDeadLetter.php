<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\MonitoringReplayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReplayMonitoringDeadLetter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(
        public readonly int $deadLetterId,
        public readonly string $intentToken,
    ) {}

    public function handle(MonitoringReplayService $replay): void
    {
        $replay->completeReplay($this->deadLetterId, $this->intentToken);
    }
}
