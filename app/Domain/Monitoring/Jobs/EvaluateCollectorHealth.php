<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class EvaluateCollectorHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue('monitoring-maintenance');
    }

    public function handle(CollectorHealthService $health): void
    {
        MonitoringCollector::query()
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->chunkById(250, fn ($collectors) => $collectors->each(
                fn (MonitoringCollector $collector) => $health->evaluate($collector),
            ));
    }
}
