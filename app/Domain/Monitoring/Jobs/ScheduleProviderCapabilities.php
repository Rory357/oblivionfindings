<?php

namespace App\Domain\Monitoring\Jobs;

use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Jobs\Integration\SyncIntegrationDevicesJob;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ScheduleProviderCapabilities implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue('monitoring');
    }

    public function handle(IntegrationAdapterRegistry $registry): void
    {
        foreach ($registry->providers() as $provider) {
            if (! IntegrationProviderConnection::query()
                ->forProvider($provider)
                ->connected()
                ->exists()) {
                continue;
            }

            $siteIds = IntegrationSiteConfig::query()
                ->forProvider($provider)
                ->active()
                ->whereNotNull('mapped_external_site_id')
                ->where('mapped_external_site_id', '<>', '')
                ->whereHas('site', fn ($site) => $site
                    ->where('is_active', true)
                    ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                    ->whereNull('archived_at'))
                ->pluck('site_id');
            if ($siteIds->isEmpty()) {
                continue;
            }

            $collectsRuntimeEvidence = $registry->hasCapability($provider, ObservationCollectionCapability::class)
                || $registry->hasCapability($provider, EventCollectionCapability::class)
                || $registry->hasCapability($provider, SnapshotCollectionCapability::class);
            if ($collectsRuntimeEvidence) {
                $siteIds->each(fn (int $siteId) => PullIntegrationHealthJob::dispatch($provider, $siteId)
                    ->onConnection('redis')
                    ->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider')));
            }

            if ($registry->hasCapability($provider, DeviceSyncCapability::class)) {
                $siteIds->each(fn (int $siteId) => SyncIntegrationDevicesJob::dispatch($provider, $siteId)
                    ->onConnection('redis')
                    ->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider')));
            }

            if (! $registry->hasCapability($provider, TopologyCollectionCapability::class)) {
                continue;
            }

            $manifest = $registry->manifest($provider);
            $interval = max(60, $manifest->minimumIntervalSeconds);
            $checkpoint = 'scheduled:'.(int) (floor(now()->timestamp / $interval) * $interval);
            $siteIds->each(fn (int $siteId) => BuildTopologySnapshot::dispatch(
                siteId: $siteId,
                source: "provider:{$provider}:topology",
                checkpoint: $checkpoint,
                provider: $provider,
            )
                ->onConnection('redis')
                ->onQueue((string) config('monitoring.queues.topology', 'monitoring-topology')));
        }
    }

    public function uniqueId(): string
    {
        return 'provider-capability-orchestrator';
    }
}
