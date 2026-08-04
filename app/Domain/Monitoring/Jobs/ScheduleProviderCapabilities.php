<?php

namespace App\Domain\Monitoring\Jobs;

use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ScheduleProviderCapabilities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue('monitoring');
    }

    public function handle(IntegrationAdapterRegistry $registry): void
    {
        foreach ($registry->providers() as $provider) {
            if (! $registry->hasCapability($provider, ObservationCollectionCapability::class)
                && ! $registry->hasCapability($provider, EventCollectionCapability::class)
                && ! $registry->hasCapability($provider, SnapshotCollectionCapability::class)) {
                continue;
            }

            PullIntegrationHealthJob::dispatch($provider)
                ->onConnection('redis')
                ->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider'));
        }
    }
}
