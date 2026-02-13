<?php

namespace App\Jobs\Integration;

use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\LocationHardware;
use App\Services\Integration\IntegrationAdapterRegistry;
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
        public int $tenantId,
        public string $provider,
        public ?int $siteId = null,
    ) {}

    public function handle(IntegrationAdapterRegistry $registry): void
    {
        try {
            $adapter = $registry->resolve($this->provider);
        } catch (\RuntimeException $e) {
            Log::error('PullIntegrationHealthJob: adapter not found', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $tenantSecret = IntegrationTenantSecret::forTenant($this->tenantId)
            ->where('provider', $this->provider)
            ->connected()
            ->first();

        if (!$tenantSecret) {
            Log::warning('PullIntegrationHealthJob: no connected secret found', [
                'tenant_id' => $this->tenantId,
                'provider' => $this->provider,
            ]);

            return;
        }

        $siteConfigs = IntegrationSiteConfig::forTenant($this->tenantId)
            ->forProvider($this->provider)
            ->active()
            ->when($this->siteId, fn ($q) => $q->where('site_id', $this->siteId))
            ->get();

        if ($siteConfigs->isEmpty()) {
            Log::info('PullIntegrationHealthJob: no active site configs found', [
                'tenant_id' => $this->tenantId,
                'provider' => $this->provider,
                'site_id' => $this->siteId,
            ]);

            return;
        }

        foreach ($siteConfigs as $siteConfig) {
            $syncLog = IntegrationSyncLog::create([
                'tenant_id' => $this->tenantId,
                'provider' => $this->provider,
                'site_id' => $siteConfig->site_id,
                'action' => 'pull_health',
                'status' => IntegrationSyncLog::STATUS_STARTED,
                'started_at' => now(),
            ]);

            try {
                $healthResults = $adapter->pullHealth($siteConfig, $tenantSecret);

                $updated = 0;
                $errored = 0;

                foreach ($healthResults as $entry) {
                    try {
                        $hardware = LocationHardware::find($entry['hardware_id']);

                        if (!$hardware) {
                            $errored++;
                            continue;
                        }

                        $hardware->update([
                            'status' => $entry['status'],
                            'last_seen_at' => $entry['last_seen_at'] ?? now(),
                        ]);

                        $updated++;
                    } catch (\Throwable $e) {
                        Log::warning('PullIntegrationHealthJob: error updating hardware', [
                            'hardware_id' => $entry['hardware_id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $errored++;
                    }
                }

                $syncLog->update([
                    'items_processed' => count($healthResults),
                    'items_updated' => $updated,
                    'items_errored' => $errored,
                ]);

                if ($errored === 0) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_SUCCESS);
                } elseif ($updated > 0) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_PARTIAL);
                } else {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, 'All health updates failed');
                }
            } catch (\Throwable $e) {
                Log::error('PullIntegrationHealthJob: pull failed for site', [
                    'tenant_id' => $this->tenantId,
                    'provider' => $this->provider,
                    'site_id' => $siteConfig->site_id,
                    'error' => $e->getMessage(),
                ]);

                $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $e->getMessage());
            }
        }
    }
}
