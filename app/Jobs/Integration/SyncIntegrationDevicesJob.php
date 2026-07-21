<?php

namespace App\Jobs\Integration;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Support\LegacyStorageContext;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncIntegrationDevicesJob implements ShouldQueue
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
    ) {}

    public function handle(IntegrationAdapterRegistry $registry): void
    {
        try {
            $adapter = $registry->resolve($this->provider);
        } catch (\RuntimeException $e) {
            Log::error('SyncIntegrationDevicesJob: adapter not found', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

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

        $siteConfigs = IntegrationSiteConfig::query()
            ->forProvider($this->provider)
            ->active()
            ->whereHas('site')
            ->when($this->siteId, fn ($q) => $q->where('site_id', $this->siteId))
            ->get();

        if ($siteConfigs->isEmpty()) {
            Log::info('SyncIntegrationDevicesJob: no active site configs found', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'site_id' => $this->siteId,
            ]));

            return;
        }

        foreach ($siteConfigs as $siteConfig) {
            $syncLog = IntegrationSyncLog::create([
                'tenant_id' => LegacyStorageContext::id(),
                'provider' => $this->provider,
                'site_id' => $siteConfig->site_id,
                'action' => 'sync_devices',
                'status' => IntegrationSyncLog::STATUS_STARTED,
                'started_at' => now(),
            ]);

            try {
                $result = $adapter->syncDevices($siteConfig, $providerConnection);

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
}
