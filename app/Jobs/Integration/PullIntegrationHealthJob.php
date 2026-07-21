<?php

namespace App\Jobs\Integration;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Services\Integration\CanonicalIntegrationDeviceResolver;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Support\LegacyStorageContext;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
    ) {}

    public function handle(
        IntegrationAdapterRegistry $registry,
        UnifiOperationalBridgeService $unifiRuntime,
    ): void {
        try {
            $adapter = $registry->resolve($this->provider);
        } catch (\RuntimeException $e) {
            Log::error('PullIntegrationHealthJob: adapter not found', SafeOperationalData::logContext([
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
            Log::warning('PullIntegrationHealthJob: no connected provider connection found', SafeOperationalData::logContext([
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
            Log::info('PullIntegrationHealthJob: no active site configs found', SafeOperationalData::logContext([
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
                'action' => 'pull_health',
                'status' => IntegrationSyncLog::STATUS_STARTED,
                'started_at' => now(),
            ]);

            try {
                $healthResults = $adapter->pullHealth($siteConfig, $providerConnection);

                $updated = 0;
                $errored = 0;

                foreach ($healthResults as $entry) {
                    try {
                        if ($this->provider === 'unifi') {
                            if ($unifiRuntime->applyHealthUpdate($siteConfig, $entry)) {
                                $updated++;
                            } else {
                                $errored++;
                            }

                            continue;
                        }

                        $device = $this->resolveCanonicalDevice($siteConfig, $entry);

                        if (! $device) {
                            $errored++;

                            continue;
                        }

                        $status = $this->mapCanonicalStatus($entry['status'] ?? null);
                        $device->fill([
                            'status' => $status,
                            'health_status' => $this->mapHealthStatus($status),
                            'last_seen_at' => $this->parseTimestamp($entry['last_seen_at'] ?? null)
                                ?? $device->last_seen_at
                                ?? now(),
                        ]);
                        $device->save();

                        $updated++;
                    } catch (\Throwable $e) {
                        Log::warning('PullIntegrationHealthJob: error updating device', SafeOperationalData::logContext([
                            'provider' => $this->provider,
                            'device_id' => $entry['device_id'] ?? null,
                            'error_category' => SafeOperationalData::failureCategory($e),
                        ]));
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
                Log::error('PullIntegrationHealthJob: pull failed for site', SafeOperationalData::logContext([
                    'provider' => $this->provider,
                    'site_id' => $siteConfig->site_id,
                    'error_category' => SafeOperationalData::failureCategory($e),
                ]));

                $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            }
        }
    }

    /**
     * Resolve the canonical Device for a non-UniFi health entry.
     *
     * Resolution order mirrors UnifiOperationalBridgeService::resolveCanonicalDeviceForHealth:
     *   1. Explicit device_id from the adapter payload.
     *   2. provider_entity_id via external_ref JSON (how UnifiOperationalBridgeService
     *      finds canonical rows for UniFi).
     *   3. legacy_location_hardware_id fallback, for rows that were migrated from
     *      the legacy location_hardware table but haven't had external_ref backfilled.
     */
    private function resolveCanonicalDevice(IntegrationSiteConfig $siteConfig, array $entry): ?Device
    {
        return app(CanonicalIntegrationDeviceResolver::class)
            ->resolveHealth($siteConfig, $this->provider, $entry);
    }

    private function mapCanonicalStatus(mixed $state): DeviceStatus
    {
        if (is_string($state)) {
            return match (strtolower(trim($state))) {
                'active', 'online', 'connected', 'up' => DeviceStatus::Active,
                'offline', 'disconnected', 'down' => DeviceStatus::Offline,
                'degraded', 'warn', 'warning', 'unknown' => DeviceStatus::Degraded,
                'maintenance' => DeviceStatus::Maintenance,
                'decommissioned', 'retired' => DeviceStatus::Decommissioned,
                'in_stock' => DeviceStatus::InStock,
                'lost' => DeviceStatus::Lost,
                default => DeviceStatus::Degraded,
            };
        }

        return match ($state) {
            1 => DeviceStatus::Active,
            0 => DeviceStatus::Offline,
            default => DeviceStatus::Degraded,
        };
    }

    private function mapHealthStatus(DeviceStatus $status): HealthStatus
    {
        return match ($status) {
            DeviceStatus::Active => HealthStatus::Healthy,
            DeviceStatus::Degraded, DeviceStatus::Maintenance => HealthStatus::Warning,
            DeviceStatus::Offline => HealthStatus::Critical,
            default => HealthStatus::Unknown,
        };
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
