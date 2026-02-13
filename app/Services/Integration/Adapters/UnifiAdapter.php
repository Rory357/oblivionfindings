<?php

namespace App\Services\Integration\Adapters;

use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\LocationHardware;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\SyncResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnifiAdapter implements IntegrationAdapterInterface
{
    private const BASE_URL = 'https://api.ui.com/ea';

    /**
     * Map UniFi device type prefixes to our hardware categories.
     */
    private const DEVICE_TYPE_MAP = [
        'udm' => LocationHardware::CATEGORY_GATEWAY,
        'uxg' => LocationHardware::CATEGORY_GATEWAY,
        'usw' => LocationHardware::CATEGORY_SWITCH,
        'uap' => LocationHardware::CATEGORY_AP,
        'u6'  => LocationHardware::CATEGORY_AP,
        'u7'  => LocationHardware::CATEGORY_AP,
        'uvc' => LocationHardware::CATEGORY_CAMERA,
        'ucg' => LocationHardware::CATEGORY_GATEWAY,
        'ua'  => LocationHardware::CATEGORY_DOOR,
        'unvr' => LocationHardware::CATEGORY_NVR,
        'uai' => LocationHardware::CATEGORY_AI,
    ];

    public function provider(): string
    {
        return 'unifi';
    }

    public function capabilities(): array
    {
        return ['device_inventory', 'device_health', 'motion_events_webhook'];
    }

    public function testConnection(IntegrationTenantSecret $secret): bool
    {
        try {
            $apiKey = Crypt::decryptString($secret->secret_encrypted);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->withToken($apiKey)->get(self::BASE_URL . '/sites');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('UniFi testConnection failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function discoverSites(IntegrationTenantSecret $secret): array
    {
        try {
            $apiKey = Crypt::decryptString($secret->secret_encrypted);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->withToken($apiKey)->get(self::BASE_URL . '/sites');

            if (!$response->successful()) {
                Log::warning('UniFi discoverSites request failed', [
                    'tenant_id' => $secret->tenant_id,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $sites = $response->json('data', []);

            return array_map(fn (array $site) => [
                'external_id' => $site['id'] ?? $site['_id'] ?? '',
                'name' => $site['name'] ?? $site['desc'] ?? 'Unknown',
                'meta' => [
                    'device_count' => $site['device_count'] ?? null,
                    'health_status' => $site['health'] ?? null,
                ],
            ], $sites);
        } catch (\Throwable $e) {
            Log::error('UniFi discoverSites failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): SyncResult
    {
        try {
            $apiKey = Crypt::decryptString($tenantSecret->secret_encrypted);
            $externalSiteId = $siteConfig->mapped_external_site_id;

            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->withToken($apiKey)->get(self::BASE_URL . "/sites/{$externalSiteId}/devices");

            if (!$response->successful()) {
                return new SyncResult(
                    error: "UniFi API returned HTTP {$response->status()} when fetching devices for site {$externalSiteId}",
                );
            }

            $devices = $response->json('data', []);
            $created = 0;
            $updated = 0;
            $errored = 0;

            foreach ($devices as $device) {
                try {
                    $providerEntityId = $device['id'] ?? $device['_id'] ?? null;

                    if (!$providerEntityId) {
                        $errored++;
                        continue;
                    }

                    $category = $this->resolveCategory($device['type'] ?? $device['model'] ?? '');

                    $hardware = LocationHardware::where('tenant_id', $siteConfig->tenant_id)
                        ->where('site_id', $siteConfig->site_id)
                        ->where('provider', 'unifi')
                        ->whereJsonContains('external_ref->provider_entity_id', $providerEntityId)
                        ->first();

                    $attributes = [
                        'tenant_id' => $siteConfig->tenant_id,
                        'site_id' => $siteConfig->site_id,
                        'provider' => 'unifi',
                        'category' => $category,
                        'name' => $device['name'] ?? $device['hostname'] ?? $device['model'] ?? 'Unknown Device',
                        'serial' => $device['serial'] ?? null,
                        'mac' => $device['mac'] ?? null,
                        'status' => $this->mapDeviceStatus($device['state'] ?? null),
                        'last_seen_at' => isset($device['last_seen'])
                            ? \Carbon\Carbon::createFromTimestamp($device['last_seen'])
                            : now(),
                        'external_ref' => [
                            'provider' => 'unifi',
                            'provider_entity_id' => $providerEntityId,
                            'provider_type' => $device['type'] ?? null,
                            'model' => $device['model'] ?? null,
                            'firmware' => $device['version'] ?? $device['firmware_version'] ?? null,
                            'ip' => $device['ip'] ?? null,
                        ],
                        'meta' => [
                            'provider_type' => $device['type'] ?? null,
                            'model_long' => $device['model_long_name'] ?? $device['model'] ?? null,
                            'uptime' => $device['uptime'] ?? null,
                            'experience_score' => $device['satisfaction'] ?? null,
                        ],
                    ];

                    if ($hardware) {
                        $hardware->update($attributes);
                        $updated++;
                    } else {
                        LocationHardware::create($attributes);
                        $created++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('UniFi syncDevices: error processing device', [
                        'device_id' => $device['id'] ?? $device['_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $errored++;
                }
            }

            return new SyncResult(
                processed: count($devices),
                created: $created,
                updated: $updated,
                errored: $errored,
            );
        } catch (\Throwable $e) {
            Log::error('UniFi syncDevices failed', [
                'tenant_id' => $siteConfig->tenant_id,
                'site_id' => $siteConfig->site_id,
                'error' => $e->getMessage(),
            ]);

            return new SyncResult(error: $e->getMessage());
        }
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): array
    {
        // TODO: Implement pullHealth via UniFi Cloud API device status endpoint
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret, ?\DateTimeInterface $since = null): array
    {
        // TODO: Implement pullEvents via UniFi Cloud API events/alerts endpoint
        return [];
    }

    /* ---------------------------------------------------------------
     * Private helpers
     * ------------------------------------------------------------- */

    /**
     * Resolve our hardware category from a UniFi device type or model string.
     */
    private function resolveCategory(string $typeOrModel): string
    {
        $lower = strtolower($typeOrModel);

        foreach (self::DEVICE_TYPE_MAP as $prefix => $category) {
            if (str_starts_with($lower, $prefix)) {
                return $category;
            }
        }

        return LocationHardware::CATEGORY_OTHER;
    }

    /**
     * Map a UniFi device state integer to our status string.
     */
    private function mapDeviceStatus(mixed $state): string
    {
        return match ($state) {
            1 => LocationHardware::STATUS_ONLINE,
            0 => LocationHardware::STATUS_OFFLINE,
            default => LocationHardware::STATUS_UNKNOWN,
        };
    }
}
