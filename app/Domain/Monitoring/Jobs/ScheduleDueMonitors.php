<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\MonitorScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ScheduleDueMonitors implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue('monitoring');
    }

    public function handle(MonitorScheduler $scheduler): void
    {
        $scheduler->dispatchDue(CarbonImmutable::now('UTC')->startOfMinute());
    }
}
