<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\RetentionEnforcer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class EnforceMonitoringRetention implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue((string) config('monitoring.queues.maintenance', 'monitoring-maintenance'));
    }

    public function handle(RetentionEnforcer $retention): void
    {
        $retention->enforce();
    }
}
