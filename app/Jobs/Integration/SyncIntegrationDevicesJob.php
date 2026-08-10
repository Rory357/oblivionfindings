<?php

namespace App\Jobs\Integration;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Exceptions\CapabilityUnavailable;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SyncIntegrationDevicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public string $provider,
        public ?int $siteId = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider) !== 1
            || ($siteId !== null && $siteId < 1)) {
            throw new InvalidArgumentException('Provider device sync scope is invalid.');
        }

        $this->onConnection('redis');
        $this->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider'));
    }

    public function handle(IntegrationAdapterRegistry $registry): void
    {
        try {
            $adapter = $registry->capability($this->provider, DeviceSyncCapability::class);
        } catch (CapabilityUnavailable|\RuntimeException $e) {
            Log::info('SyncIntegrationDevicesJob: capability unavailable', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return;
        }

        if (! $adapter instanceof DeviceSyncCapability) {
            return;
        }

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider($this->provider)
            ->connected()
            ->first();

        if (! $providerConnection) {
            Log::warning('SyncIntegrationDevicesJob: no connected provider connection found', SafeOperationalData::logContext([
                'provider' => $this->provider,
            ]));

            return;
        }

        $minimumInterval = $registry->manifest($this->provider)->minimumIntervalSeconds;

        $siteConfigs = IntegrationSiteConfig::query()
            ->forProvider($this->provider)
            ->active()
            ->whereNotNull('mapped_external_site_id')
            ->where('mapped_external_site_id', '<>', '')
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->when($this->siteId !== null, fn ($q) => $q->where('site_id', $this->siteId))
            ->get()
            ->filter(fn (IntegrationSiteConfig $siteConfig): bool => $this->siteSyncIsDue(
                (int) $siteConfig->site_id,
                $minimumInterval,
            ));

        if ($siteConfigs->isEmpty()) {
            Log::info('SyncIntegrationDevicesJob: no eligible Site sync is due', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'site_id' => $this->siteId,
            ]));

            return;
        }

        foreach ($siteConfigs as $siteConfig) {
            $mappedExternalSiteId = trim((string) $siteConfig->mapped_external_site_id);
            if (! $this->syncScopeStillUsable(
                (int) $providerConnection->id,
                (int) $siteConfig->id,
                (int) $siteConfig->site_id,
                $mappedExternalSiteId,
            )) {
                Log::info('SyncIntegrationDevicesJob: provider or Site scope became unavailable', SafeOperationalData::logContext([
                    'provider' => $this->provider,
                    'site_id' => $siteConfig->site_id,
                ]));

                return;
            }

            $syncLog = IntegrationSyncLog::create([
                'provider' => $this->provider,
                'site_id' => $siteConfig->site_id,
                'action' => 'sync_devices',
                'status' => IntegrationSyncLog::STATUS_STARTED,
                'started_at' => now(),
            ]);

            try {
                $result = $adapter->syncDevices($siteConfig, $providerConnection);
                if (! $this->syncScopeStillUsable(
                    (int) $providerConnection->id,
                    (int) $siteConfig->id,
                    (int) $siteConfig->site_id,
                    $mappedExternalSiteId,
                )) {
                    Log::info('SyncIntegrationDevicesJob: provider result discarded after scope became unavailable', SafeOperationalData::logContext([
                        'provider' => $this->provider,
                        'site_id' => $siteConfig->site_id,
                    ]));
                    $syncLog->markCompleted(
                        IntegrationSyncLog::STATUS_FAILED,
                        SafeOperationalData::failureSummary(),
                    );

                    continue;
                }

                $syncLog->update([
                    'items_processed' => $result->processed,
                    'items_created' => $result->created,
                    'items_updated' => $result->updated,
                    'items_errored' => $result->errored,
                ]);

                if ($result->isSuccess()) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_SUCCESS);
                } elseif ($result->isPartial()) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_PARTIAL);
                } else {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
                }

                $providerConnection->update(['last_synced_at' => now()]);
            } catch (\Throwable $e) {
                Log::error('SyncIntegrationDevicesJob: sync failed for site', SafeOperationalData::logContext([
                    'provider' => $this->provider,
                    'site_id' => $siteConfig->site_id,
                    'error_category' => SafeOperationalData::failureCategory($e),
                ]));

                $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            }
        }
    }

    public function uniqueId(): string
    {
        return $this->provider.':'.($this->siteId ?? 'all');
    }

    private function siteSyncIsDue(int $siteId, int $minimumInterval): bool
    {
        $latest = IntegrationSyncLog::query()
            ->forProvider($this->provider)
            ->where('site_id', $siteId)
            ->where('action', 'sync_devices')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->first(['completed_at']);

        return $latest?->completed_at === null
            || ! $latest->completed_at->isAfter(now()->subSeconds($minimumInterval));
    }

    private function syncScopeStillUsable(
        int $connectionId,
        int $siteConfigId,
        int $siteId,
        string $mappedExternalSiteId,
    ): bool {
        if ($mappedExternalSiteId === '') {
            return false;
        }

        return IntegrationProviderConnection::query()
            ->whereKey($connectionId)
            ->forProvider($this->provider)
            ->connected()
            ->exists()
            && IntegrationSiteConfig::query()
                ->whereKey($siteConfigId)
                ->forProvider($this->provider)
                ->active()
                ->where('site_id', $siteId)
                ->where('mapped_external_site_id', $mappedExternalSiteId)
                ->whereRaw("TRIM(`mapped_external_site_id`) <> ''")
                ->whereHas('site', fn ($site) => $site
                    ->where('is_active', true)
                    ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                    ->whereNull('archived_at'))
                ->exists();
    }
}
