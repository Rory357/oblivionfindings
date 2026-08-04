<?php

namespace App\Jobs\Integration;

use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PullIntegrationHealthJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $provider,
        public ?int $siteId = null,
    ) {
        $this->onConnection('redis');
        $this->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider'));
    }

    public function handle(IntegrationAdapterRegistry $registry): void
    {
        $siteConfigs = IntegrationSiteConfig::query()
            ->forProvider($this->provider)
            ->active()
            ->whereHas('site')
            ->when($this->siteId, fn ($q) => $q->where('site_id', $this->siteId))
            ->get();

        if ($siteConfigs->isEmpty()) {
            Log::info('PullIntegrationHealthJob: no active site configs found', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'site_id' => $this->siteId,
            ]));

            return;
        }

        $capabilities = collect([
            ObservationCollectionCapability::class,
            EventCollectionCapability::class,
            SnapshotCollectionCapability::class,
        ])->filter(fn (string $capability): bool => $registry->hasCapability($this->provider, $capability));

        if ($capabilities->isEmpty()) {
            Log::info('PullIntegrationHealthJob: collection capabilities unavailable', [
                'provider' => $this->provider,
                'site_id' => $this->siteId,
            ]);

            return;
        }

        foreach ($siteConfigs as $siteConfig) {
            foreach ($capabilities as $capability) {
                PullProviderCapability::dispatch(
                    $this->provider,
                    (int) $siteConfig->site_id,
                    $capability,
                )
                    ->onConnection('redis')
                    ->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider'));
            }
        }
    }
}
