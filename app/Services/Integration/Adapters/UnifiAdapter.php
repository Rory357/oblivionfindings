<?php

namespace App\Services\Integration\Adapters;

use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\LocationHardware;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\SyncResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnifiAdapter implements IntegrationAdapterInterface
{
    private const BASE_URL = 'https://api.ui.com/v1';

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
        return ['device_inventory', 'device_health', 'motion_events_webhook', 'access_events'];
    }

    public function testConnection(IntegrationTenantSecret $secret): bool
    {
        try {
            $apiKey = Crypt::decryptString($secret->secret_encrypted);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL . '/sites');

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

            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ];

            $response = Http::withHeaders($headers)->get(self::BASE_URL . '/sites');

            if (!$response->successful()) {
                Log::warning('UniFi discoverSites request failed', [
                    'tenant_id' => $secret->tenant_id,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $sites = $response->json('data', []);
            $mainDeviceByHost = [];
            $hostsById = [];

            try {
                $hostsResponse = Http::withHeaders($headers)->get(self::BASE_URL . '/hosts');
                if ($hostsResponse->successful()) {
                    $hosts = $hostsResponse->json('data', []);
                    $hostsById = $this->indexHosts($hosts);
                } else {
                    Log::warning('UniFi discoverSites hosts request failed', [
                        'tenant_id' => $secret->tenant_id,
                        'status' => $hostsResponse->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('UniFi discoverSites hosts enrichment failed', [
                    'tenant_id' => $secret->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $devicesResponse = Http::withHeaders($headers)->get(self::BASE_URL . '/devices');
                if ($devicesResponse->successful()) {
                    $deviceGroups = $devicesResponse->json('data', []);
                    foreach ($deviceGroups as $group) {
                        $hostId = $group['hostId'] ?? null;
                        if (!$hostId) {
                            continue;
                        }

                        $devices = is_array($group['devices'] ?? null) ? $group['devices'] : [];
                        $main = $this->resolveMainDevice($devices);
                        if ($main) {
                            $mainDeviceByHost[$hostId] = $main;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('UniFi discoverSites device enrichment failed', [
                    'tenant_id' => $secret->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return array_map(function (array $site) use ($mainDeviceByHost, $hostsById) {
                $meta = is_array($site['meta'] ?? null) ? $site['meta'] : [];
                $stats = is_array($site['statistics'] ?? null) ? $site['statistics'] : [];
                $counts = is_array($stats['counts'] ?? null) ? $stats['counts'] : [];
                $hostId = $site['hostId'] ?? null;
                $main = $hostId && isset($mainDeviceByHost[$hostId]) ? $mainDeviceByHost[$hostId] : null;
                $host = $hostId && isset($hostsById[$hostId]) ? $hostsById[$hostId] : null;
                $hostMain = $this->resolveHostPrimary($host);

                $useHost = $hostMain && in_array($hostMain['role'], ['protect', 'nvr', 'nas', 'console', 'access'], true);
                $mainName = $useHost ? $hostMain['name'] : ($main ? $this->resolveDeviceName($main) : null);
                $mainModel = $useHost ? $hostMain['model'] : ($main['model'] ?? $main['shortname'] ?? null);
                $mainRole = $useHost ? $hostMain['role'] : ($main ? $this->resolveMainDeviceRole($main) : null);

                return [
                    'external_id' => $site['siteId'] ?? $site['id'] ?? $site['_id'] ?? '',
                    'name' => $site['name'] ?? $site['desc'] ?? $meta['name'] ?? $meta['desc'] ?? 'Unknown',
                    'meta' => [
                        'device_count' => $counts['totalDevice'] ?? $site['device_count'] ?? null,
                        'health_status' => $site['health'] ?? null,
                        'main_device_name' => $mainName,
                        'main_device_model' => $mainModel,
                        'main_device_role' => $mainRole,
                    ],
                ];
            }, $sites);
        } catch (\Throwable $e) {
            Log::error('UniFi discoverSites failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function discoverHosts(IntegrationTenantSecret $secret): array
    {
        try {
            $apiKey = Crypt::decryptString($secret->secret_encrypted);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL . '/hosts');

            if (!$response->successful()) {
                Log::warning('UniFi discoverHosts request failed', [
                    'tenant_id' => $secret->tenant_id,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $hosts = $response->json('data', []);

            return array_values(array_filter(array_map(function ($host) {
                if (!is_array($host)) {
                    return null;
                }

                $hostId = $host['id'] ?? $host['_id'] ?? $host['hostId'] ?? null;
                if (!$hostId) {
                    return null;
                }

                $name = $this->resolveHostName($host) ?? 'Unknown';
                $model = $this->resolveHostModel($host);
                $controllers = $this->resolveHostControllers($host);
                $role = $this->resolveHostRole($host, $model, $controllers);

                return [
                    'host_id' => (string) $hostId,
                    'name' => $name,
                    'model' => $model,
                    'role' => $role,
                    'controllers' => $controllers,
                ];
            }, $hosts)));
        } catch (\Throwable $e) {
            Log::warning('UniFi discoverHosts failed', [
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

            $sitesResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL . '/sites');

            if (!$sitesResponse->successful()) {
                return new SyncResult(
                    error: "UniFi API returned HTTP {$sitesResponse->status()} when fetching sites.",
                );
            }

            $sites = $sitesResponse->json('data', []);
            $hostId = $this->resolveHostId($sites, $externalSiteId);

            if (!$hostId) {
                return new SyncResult(
                    error: "UniFi API did not return a hostId for site {$externalSiteId}.",
                );
            }

            $devicesResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL . '/devices');

            if (!$devicesResponse->successful()) {
                return new SyncResult(
                    error: "UniFi API returned HTTP {$devicesResponse->status()} when fetching devices for host {$hostId}.",
                );
            }

            $deviceGroups = $devicesResponse->json('data', []);
            $overrides = is_array($siteConfig->overrides) ? $siteConfig->overrides : [];
            $protectHostId = $overrides['protect_host_id'] ?? null;

            $hostIds = array_values(array_unique(array_filter([$hostId, $protectHostId])));
            $processed = 0;
            $created = 0;
            $updated = 0;
            $errored = 0;

            foreach ($hostIds as $targetHostId) {
                $devices = $this->resolveDevicesForHost($deviceGroups, $targetHostId);

                if ($protectHostId && $targetHostId === $protectHostId && $protectHostId !== $hostId) {
                    $devices = array_values(array_filter($devices, fn ($device) => $this->isProtectDevice($device)));
                }

                $processed += count($devices);

                foreach ($devices as $device) {
                    try {
                        $providerEntityId = $device['id'] ?? $device['_id'] ?? $device['mac'] ?? null;

                        if (!$providerEntityId) {
                            $errored++;
                            continue;
                        }

                        $productLine = strtolower((string) ($device['productLine'] ?? ''));
                        $category = $this->resolveCategory($device['model'] ?? $device['shortname'] ?? '');
                        if ($productLine === 'protect' && $category === LocationHardware::CATEGORY_OTHER) {
                            $category = LocationHardware::CATEGORY_CAMERA;
                        }

                        $lastSeenAt = $this->parseDeviceTimestamp(
                            $device['lastSeen']
                                ?? $device['last_seen']
                                ?? $device['startupTime']
                                ?? $device['adoptionTime']
                                ?? null
                        );

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
                            'name' => $this->resolveDeviceName($device),
                            'serial' => $device['serial'] ?? null,
                            'mac' => $device['mac'] ?? null,
                            'status' => $this->mapDeviceStatus($device['status'] ?? $device['state'] ?? null),
                            'last_seen_at' => $lastSeenAt ?? now(),
                            'external_ref' => [
                                'provider' => 'unifi',
                                'provider_entity_id' => $providerEntityId,
                                'provider_type' => $device['shortname'] ?? $device['productLine'] ?? null,
                                'model' => $device['model'] ?? null,
                                'firmware' => $device['version'] ?? $device['firmware_version'] ?? null,
                                'ip' => $device['ip'] ?? null,
                                'source_app' => $productLine ?: null,
                                'host_id' => $targetHostId,
                            ],
                            'meta' => [
                                'provider_type' => $device['shortname'] ?? null,
                                'model_long' => $device['model'] ?? $device['model_long_name'] ?? null,
                                'product_line' => $productLine ?: null,
                                'firmware_status' => $device['firmwareStatus'] ?? null,
                                'uptime' => $device['uptime'] ?? null,
                                'experience_score' => $device['satisfaction'] ?? null,
                                'host_id' => $targetHostId,
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
            }

            return new SyncResult(
                processed: $processed,
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
        try {
            $accessSecret = IntegrationSiteSecret::query()
                ->where('tenant_id', $siteConfig->tenant_id)
                ->where('site_id', $siteConfig->site_id)
                ->where('provider', $this->provider())
                ->where('capability', 'access_api')
                ->where('is_enabled', true)
                ->first();

            if (!$accessSecret || empty($accessSecret->base_url)) {
                return [];
            }

            $apiKey = Crypt::decryptString($accessSecret->secret_encrypted);
            $baseUrl = rtrim($accessSecret->base_url, '/');

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$apiKey}",
            ])
                ->withOptions(['verify' => false])
                ->get($baseUrl . '/api/v1/developer/system/logs', [
                    'limit' => 200,
                    'offset' => 0,
                    'topics' => 'door_openings',
                ]);

            if (!$response->successful()) {
                $status = $response->status();
                Log::warning('UniFi Access pullEvents failed', [
                    'tenant_id' => $siteConfig->tenant_id,
                    'site_id' => $siteConfig->site_id,
                    'status' => $status,
                ]);

                throw new \RuntimeException("UniFi Access API returned HTTP {$status}.");
            }

            $sinceAt = $since ? \Carbon\Carbon::instance($since) : null;
            $entries = $response->json('data', []);
            $events = [];

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $normalized = $this->normalizeAccessLogEntry($entry);
                if (!$normalized) {
                    continue;
                }

                if ($sinceAt && $normalized['occurred_at']->lt($sinceAt)) {
                    continue;
                }

                $events[] = $normalized;
            }

            return $events;
        } catch (\Throwable $e) {
            Log::warning('UniFi Access pullEvents error', [
                'tenant_id' => $siteConfig->tenant_id,
                'site_id' => $siteConfig->site_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
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
        if (is_string($state)) {
            $value = strtolower($state);

            return match ($value) {
                'online', 'connected', 'up' => LocationHardware::STATUS_ONLINE,
                'offline', 'disconnected', 'down' => LocationHardware::STATUS_OFFLINE,
                default => LocationHardware::STATUS_UNKNOWN,
            };
        }

        return match ($state) {
            1 => LocationHardware::STATUS_ONLINE,
            0 => LocationHardware::STATUS_OFFLINE,
            default => LocationHardware::STATUS_UNKNOWN,
        };
    }

    /**
     * Build a human-friendly device name with a stable suffix when missing.
     */
    private function resolveDeviceName(array $device): string
    {
        $name = trim((string) ($device['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $hostname = trim((string) ($device['hostname'] ?? ''));
        if ($hostname !== '') {
            return $hostname;
        }

        $modelLong = trim((string) ($device['model_long_name'] ?? ''));
        $model = trim((string) ($device['model'] ?? ''));
        $type = trim((string) ($device['type'] ?? ''));

        $base = $modelLong !== '' ? $modelLong : ($model !== '' ? $model : ($type !== '' ? strtoupper($type) : 'UniFi Device'));
        $suffix = $this->resolveDeviceSuffix($device);

        return $suffix ? "{$base} ({$suffix})" : $base;
    }

    private function resolveDeviceSuffix(array $device): string
    {
        $mac = preg_replace('/[^a-fA-F0-9]/', '', (string) ($device['mac'] ?? ''));
        if ($mac !== '') {
            return strtoupper(substr($mac, -4));
        }

        $serial = trim((string) ($device['serial'] ?? ''));
        if ($serial !== '') {
            return strtoupper(substr($serial, -4));
        }

        return '';
    }

    private function resolveHostId(array $sites, ?string $externalSiteId): ?string
    {
        if (!$externalSiteId) {
            return null;
        }

        foreach ($sites as $site) {
            $siteId = $site['siteId'] ?? $site['id'] ?? $site['_id'] ?? null;
            if ($siteId === $externalSiteId) {
                return $site['hostId'] ?? null;
            }
        }

        return null;
    }

    private function resolveDevicesForHost(array $deviceGroups, string $hostId): array
    {
        foreach ($deviceGroups as $group) {
            if (($group['hostId'] ?? null) === $hostId) {
                return is_array($group['devices'] ?? null) ? $group['devices'] : [];
            }
        }

        return [];
    }

    private function indexHosts(array $hosts): array
    {
        $indexed = [];
        foreach ($hosts as $host) {
            if (!is_array($host)) {
                continue;
            }
            $id = $host['id'] ?? $host['_id'] ?? $host['hostId'] ?? null;
            if ($id) {
                $indexed[$id] = $host;
            }
        }

        return $indexed;
    }

    private function resolveHostPrimary(?array $host): ?array
    {
        if (!$host) {
            return null;
        }

        $name = $this->resolveHostName($host);
        $model = $this->resolveHostModel($host);
        $controllers = $this->resolveHostControllers($host);
        $role = $this->resolveHostRole($host, $model, $controllers);

        if (!$name && !$model) {
            return null;
        }

        if (!$name && $model) {
            $name = $model;
        }

        return [
            'name' => $name,
            'model' => $model,
            'role' => $role,
            'controllers' => $controllers,
        ];
    }

    private function resolveHostName(array $host): ?string
    {
        $candidates = [
            $host['name'] ?? null,
            $host['displayName'] ?? null,
            $host['hostName'] ?? null,
            $host['hostname'] ?? null,
            data_get($host, 'reportedState.name'),
            data_get($host, 'reportedState.hostname'),
            data_get($host, 'reportedState.hostName'),
            data_get($host, 'reportedState.system.name'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function resolveHostModel(array $host): ?string
    {
        $candidates = [
            $host['model'] ?? null,
            $host['hardwareModel'] ?? null,
            data_get($host, 'reportedState.hardware.model'),
            data_get($host, 'reportedState.hardware.modelName'),
            data_get($host, 'reportedState.model'),
            data_get($host, 'reportedState.hardware.shortname'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function resolveHostControllers(array $host): array
    {
        $controllers = [];

        $raw = data_get($host, 'userData.controllers');
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry)) {
                    $controllers[] = strtolower($entry);
                } elseif (is_array($entry) && isset($entry['name'])) {
                    $controllers[] = strtolower((string) $entry['name']);
                }
            }
        }

        $fallback = $host['controllers'] ?? $host['apps'] ?? null;
        if (is_array($fallback)) {
            foreach ($fallback as $entry) {
                if (is_string($entry)) {
                    $controllers[] = strtolower($entry);
                } elseif (is_array($entry) && isset($entry['name'])) {
                    $controllers[] = strtolower((string) $entry['name']);
                }
            }
        }

        $groupMembers = data_get($host, 'userData.consoleGroupMembers');
        if (is_array($groupMembers)) {
            foreach ($groupMembers as $member) {
                $apps = data_get($member, 'roleAttributes.applications');
                if (!is_array($apps)) {
                    continue;
                }
                foreach ($apps as $app => $meta) {
                    if (is_array($meta) && ($meta['supported'] ?? false)) {
                        $controllers[] = strtolower((string) $app);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($controllers)));
    }

    private function resolveHostRole(array $host, ?string $model, array $controllers): string
    {
        $upper = strtoupper((string) ($model ?? ''));

        if ($upper !== '') {
            if (str_contains($upper, 'UNVR') || str_contains($upper, 'NVR')) {
                return 'nvr';
            }
            if (str_contains($upper, 'UNAS') || str_contains($upper, 'NAS')) {
                return 'nas';
            }
            if (str_contains($upper, 'UDM') || str_contains($upper, 'UDR') || str_contains($upper, 'UDW')) {
                return 'gateway';
            }
            if (str_contains($upper, 'UXG') || str_contains($upper, 'USG') || str_contains($upper, 'UCG')) {
                return 'gateway';
            }
        }

        if (in_array('protect', $controllers, true)) {
            return 'protect';
        }

        if (in_array('access', $controllers, true)) {
            return 'access';
        }

        $type = strtolower((string) ($host['type'] ?? ''));
        if ($type !== '') {
            return $type;
        }

        return 'console';
    }

    private function isProtectDevice(mixed $device): bool
    {
        if (!is_array($device)) {
            return false;
        }

        $productLine = strtolower((string) ($device['productLine'] ?? ''));
        if ($productLine === 'protect') {
            return true;
        }

        $model = $device['model'] ?? $device['shortname'] ?? '';
        $category = $this->resolveCategory((string) $model);

        return $category === LocationHardware::CATEGORY_CAMERA;
    }

    private function normalizeAccessLogEntry(array $entry): ?array
    {
        $eventId = (string) ($entry['id'] ?? $entry['_id'] ?? '');
        if ($eventId === '') {
            return null;
        }

        $occurredAt = $this->parseAccessTimestamp($entry['time'] ?? null);
        if (!$occurredAt) {
            return null;
        }

        $doorName = data_get($entry, 'door.name') ?? data_get($entry, 'details.doorName');
        $userName = data_get($entry, 'user.name') ?? data_get($entry, 'actor.name');
        $direction = data_get($entry, 'details.direction') ?? data_get($entry, 'details.reason');
        $topic = (string) ($entry['topic'] ?? $entry['type'] ?? 'door_openings');

        $summary = $this->formatAccessSummary($userName, $doorName, $direction);

        return [
            'source_event_id' => $eventId,
            'occurred_at' => $occurredAt,
            'event_type' => $topic,
            'summary' => $summary,
            'door_name' => $doorName,
            'user_name' => $userName,
            'direction' => $direction,
            'raw' => $entry,
        ];
    }

    private function formatAccessSummary(?string $userName, ?string $doorName, ?string $direction): string
    {
        $who = $userName ?: 'Unknown user';
        $where = $doorName ? " at {$doorName}" : '';
        $dir = $direction ? strtoupper((string) $direction) : 'ACCESS';

        return "{$dir}: {$who}{$where}";
    }

    private function parseAccessTimestamp(mixed $value): ?\Carbon\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $numeric = (int) $value;
            if ($numeric > 1000000000000) {
                return \Carbon\Carbon::createFromTimestamp($numeric / 1000);
            }

            return \Carbon\Carbon::createFromTimestamp($numeric);
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveMainDevice(array $devices): ?array
    {
        if (empty($devices)) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            $model = strtoupper((string) ($device['model'] ?? $device['shortname'] ?? ''));
            $productLine = strtolower((string) ($device['productLine'] ?? ''));
            $isConsole = (bool) ($device['isConsole'] ?? false);

            $score = 0;
            if ($isConsole) {
                $score = 100;
            }

            if ($model !== '') {
                if (str_contains($model, 'UNVR') || str_contains($model, 'NVR')) {
                    $score = max($score, 90);
                }
                if (str_contains($model, 'UNAS') || str_contains($model, 'NAS')) {
                    $score = max($score, 85);
                }
                if (str_contains($model, 'UDM') || str_contains($model, 'UDR') || str_contains($model, 'UDW')) {
                    $score = max($score, 80);
                }
                if (str_contains($model, 'UXG') || str_contains($model, 'USG') || str_contains($model, 'UCG')) {
                    $score = max($score, 70);
                }
            }

            if ($productLine === 'protect') {
                $score = max($score, 60);
            } elseif ($productLine === 'network') {
                $score = max($score, 50);
            }

            if (trim((string) ($device['name'] ?? '')) !== '') {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $device;
            }
        }

        return $best ?? (is_array($devices[0]) ? $devices[0] : null);
    }

    private function resolveMainDeviceRole(array $device): string
    {
        if (!is_array($device)) {
            return 'device';
        }

        if (!empty($device['isConsole'])) {
            return 'console';
        }

        $model = strtoupper((string) ($device['model'] ?? $device['shortname'] ?? ''));
        if ($model !== '') {
            if (str_contains($model, 'UNVR') || str_contains($model, 'NVR')) {
                return 'nvr';
            }
            if (str_contains($model, 'UNAS') || str_contains($model, 'NAS')) {
                return 'nas';
            }
            if (str_contains($model, 'UDM') || str_contains($model, 'UDR') || str_contains($model, 'UDW')) {
                return 'gateway';
            }
            if (str_contains($model, 'UXG') || str_contains($model, 'USG') || str_contains($model, 'UCG')) {
                return 'gateway';
            }
        }

        $productLine = strtolower((string) ($device['productLine'] ?? ''));
        return $productLine !== '' ? $productLine : 'device';
    }

    private function parseDeviceTimestamp(?string $value): ?\Carbon\Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
