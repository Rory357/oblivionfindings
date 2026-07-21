<?php

namespace App\Services\Integration\Adapters;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\LocationHardware;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Services\Integration\SyncResult;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Support\SafeOperationalData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnifiAdapter implements IntegrationAdapterInterface
{
    private const BASE_URL = 'https://api.ui.com/v1';

    private const SITE_IDENTIFIER_MAX_LENGTH = 255;

    private const SITE_LABEL_MAX_LENGTH = 255;

    private const SITE_STATUS_MAX_LENGTH = 100;

    private const SITE_DEVICE_COUNT_MAX = 1000000;

    private const HOST_COUNT_MAX = 10000;

    private const HOST_CONTROLLER_COUNT_MAX = 100;

    public function __construct(
        private readonly UnifiOperationalBridgeService $runtime,
    ) {}

    /**
     * Map UniFi device type prefixes to our hardware categories.
     */
    private const DEVICE_TYPE_MAP = [
        'udm' => LocationHardware::CATEGORY_GATEWAY,
        'uxg' => LocationHardware::CATEGORY_GATEWAY,
        'usw' => LocationHardware::CATEGORY_SWITCH,
        'uap' => LocationHardware::CATEGORY_AP,
        'u6' => LocationHardware::CATEGORY_AP,
        'u7' => LocationHardware::CATEGORY_AP,
        'uvc' => LocationHardware::CATEGORY_CAMERA,
        'ucg' => LocationHardware::CATEGORY_GATEWAY,
        'ua' => LocationHardware::CATEGORY_DOOR,
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

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        try {
            $apiKey = Crypt::decryptString($connection->secret_encrypted);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL.'/sites');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('UniFi testConnection failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return false;
        }
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        try {
            $apiKey = Crypt::decryptString($connection->secret_encrypted);

            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ];

            $response = Http::withHeaders($headers)->get(self::BASE_URL.'/sites');

            if (! $response->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($response->status());
            }

            $sites = $response->json('data');
            if (! is_array($sites) || collect($sites)->contains(fn ($site): bool => ! is_array($site))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $sites = array_map(fn (array $site): array => $this->normalizeDiscoveredSite($site), $sites);
            $mainDeviceByHost = [];
            $hostsById = [];

            try {
                $hostsResponse = Http::withHeaders($headers)->get(self::BASE_URL.'/hosts');
                if ($hostsResponse->successful()) {
                    $hosts = $hostsResponse->json('data', []);
                    $hostsById = $this->indexHosts($hosts);
                } else {
                    Log::warning('UniFi discoverSites hosts request failed', [
                        'provider_connection_id' => $connection->id,
                        'status' => $hostsResponse->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('UniFi discoverSites hosts enrichment failed', SafeOperationalData::logContext([
                    'provider_connection_id' => $connection->id,
                    'error_category' => SafeOperationalData::failureCategory($e),
                ]));
            }

            try {
                $devicesResponse = Http::withHeaders($headers)->get(self::BASE_URL.'/devices');
                if ($devicesResponse->successful()) {
                    $deviceGroups = $devicesResponse->json('data', []);
                    foreach ($deviceGroups as $group) {
                        $hostId = $group['hostId'] ?? null;
                        if (! $hostId) {
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
                Log::warning('UniFi discoverSites device enrichment failed', SafeOperationalData::logContext([
                    'provider_connection_id' => $connection->id,
                    'error_category' => SafeOperationalData::failureCategory($e),
                ]));
            }

            return array_map(function (array $site) use ($mainDeviceByHost, $hostsById) {
                $hostId = $site['_host_id'];
                $main = $hostId && isset($mainDeviceByHost[$hostId]) ? $mainDeviceByHost[$hostId] : null;
                $host = $hostId && isset($hostsById[$hostId]) ? $hostsById[$hostId] : null;
                $hostMain = $this->resolveHostPrimary($host);

                $useHost = $hostMain && in_array($hostMain['role'], ['protect', 'nvr', 'nas', 'console', 'access'], true);
                $mainName = $this->normalizeOptionalProjectedText(
                    $useHost ? $hostMain['name'] : ($main ? $this->resolveDeviceName($main) : null),
                    self::SITE_LABEL_MAX_LENGTH,
                );
                $mainModel = $this->normalizeOptionalProjectedText(
                    $useHost ? $hostMain['model'] : ($main['model'] ?? $main['shortname'] ?? null),
                    self::SITE_LABEL_MAX_LENGTH,
                );
                $mainRole = $this->normalizeOptionalProjectedText(
                    $useHost ? $hostMain['role'] : ($main ? $this->resolveMainDeviceRole($main) : null),
                    self::SITE_STATUS_MAX_LENGTH,
                );

                return [
                    'external_id' => $site['external_id'],
                    'name' => $site['name'],
                    'meta' => [
                        'device_count' => $site['device_count'],
                        'health_status' => $site['health_status'],
                        'main_device_name' => $mainName,
                        'main_device_model' => $mainModel,
                        'main_device_role' => $mainRole,
                    ],
                ];
            }, $sites);
        } catch (\Throwable $e) {
            $failure = IntegrationDiscoveryException::fromThrowable($e);
            Log::error('UniFi discoverSites failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => $failure->failureCategory(),
            ]));

            throw $failure;
        }
    }

    /** @param array<string, mixed> $site @return array<string, mixed> */
    private function normalizeDiscoveredSite(array $site): array
    {
        $externalId = null;
        foreach (['siteId', 'id', '_id'] as $field) {
            if (! array_key_exists($field, $site)) {
                continue;
            }

            $externalId = $this->normalizeRequiredSiteIdentifier($site[$field]);
            break;
        }

        if ($externalId === null) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $meta = $site['meta'] ?? [];
        $statistics = $site['statistics'] ?? [];
        if (! is_array($meta) || ! is_array($statistics)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $counts = $statistics['counts'] ?? [];
        if (! is_array($counts)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $name = null;
        foreach ([$site['name'] ?? null, $site['desc'] ?? null, $meta['name'] ?? null, $meta['desc'] ?? null] as $candidate) {
            $name = $this->normalizeOptionalProjectedText($candidate, self::SITE_LABEL_MAX_LENGTH);
            if ($name !== null) {
                break;
            }
        }

        return [
            '_host_id' => $this->normalizeOptionalProjectedText(
                $site['hostId'] ?? null,
                self::SITE_IDENTIFIER_MAX_LENGTH,
            ),
            'external_id' => $externalId,
            'name' => $name ?? 'Unknown',
            'device_count' => $this->normalizeOptionalDeviceCount(
                $counts['totalDevice'] ?? $site['device_count'] ?? null,
            ),
            'health_status' => $this->normalizeOptionalProjectedText(
                $site['health'] ?? null,
                self::SITE_STATUS_MAX_LENGTH,
            ),
        ];
    }

    private function normalizeRequiredSiteIdentifier(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || mb_strlen($normalized) > self::SITE_IDENTIFIER_MAX_LENGTH) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $normalized;
    }

    private function normalizeOptionalProjectedText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $maxLength) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $normalized;
    }

    private function normalizeOptionalDeviceCount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ((is_int($value) || is_string($value)) && preg_match('/^\d+$/', (string) $value) === 1) {
            $normalized = (int) $value;
            if ($normalized <= self::SITE_DEVICE_COUNT_MAX) {
                return $normalized;
            }
        }

        throw IntegrationDiscoveryException::invalidResponse();
    }

    public function discoverHosts(IntegrationProviderConnection $connection): array
    {
        try {
            $apiKey = Crypt::decryptString($connection->secret_encrypted);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL.'/hosts');

            if (! $response->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($response->status());
            }

            $hosts = $response->json('data');
            if (! is_array($hosts)
                || count($hosts) > self::HOST_COUNT_MAX
                || collect($hosts)->contains(fn ($host): bool => ! is_array($host))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }

            return array_values(array_map(
                fn (array $host): array => $this->normalizeDiscoveredHost($host),
                $hosts,
            ));
        } catch (\Throwable $e) {
            $failure = IntegrationDiscoveryException::fromThrowable($e);
            Log::error('UniFi discoverHosts failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => $failure->failureCategory(),
            ]));

            throw $failure;
        }
    }

    /** @param array<string, mixed> $host @return array<string, mixed> */
    private function normalizeDiscoveredHost(array $host): array
    {
        $hostId = null;
        foreach (['id', '_id', 'hostId'] as $field) {
            if (! array_key_exists($field, $host)) {
                continue;
            }
            $hostId = $this->normalizeRequiredSiteIdentifier($host[$field]);
            break;
        }
        if ($hostId === null) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $name = $this->resolveHostName($host) ?? 'Unknown';
        $model = $this->resolveHostModel($host);
        $controllers = $this->resolveHostControllers($host);

        return [
            'host_id' => $hostId,
            'name' => $name,
            'model' => $model,
            'role' => $this->resolveHostRole($host, $model, $controllers),
            'controllers' => $controllers,
        ];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        try {
            $apiKey = Crypt::decryptString($providerConnection->secret_encrypted);
            $externalSiteId = $siteConfig->mapped_external_site_id;

            $sitesResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL.'/sites');

            if (! $sitesResponse->successful()) {
                return new SyncResult(
                    error: SafeOperationalData::failureSummary(),
                );
            }

            $sites = $sitesResponse->json('data', []);
            $hostId = $this->resolveHostId($sites, $externalSiteId);

            if (! $hostId) {
                return new SyncResult(
                    error: SafeOperationalData::failureSummary(),
                );
            }

            $devicesResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ])->get(self::BASE_URL.'/devices');

            if (! $devicesResponse->successful()) {
                return new SyncResult(
                    error: SafeOperationalData::failureSummary(),
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

                        if (! $providerEntityId) {
                            $errored++;

                            continue;
                        }

                        $device['_resolved_host_id'] = $targetHostId;
                        $sync = $this->runtime->syncInventoryDevice($siteConfig, $device);

                        if ($sync['created']) {
                            $created++;
                        } else {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('UniFi syncDevices: error processing device', SafeOperationalData::logContext([
                            'tenant_id' => $siteConfig->tenant_id,
                            'site_id' => $siteConfig->site_id,
                            'error_category' => SafeOperationalData::failureCategory($e),
                        ]));
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
            Log::error('UniFi syncDevices failed', SafeOperationalData::logContext([
                'tenant_id' => $siteConfig->tenant_id,
                'site_id' => $siteConfig->site_id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return new SyncResult(error: SafeOperationalData::failureSummary());
        }
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        // TODO: Implement pullHealth via UniFi Cloud API device status endpoint
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array
    {
        try {
            $accessSecret = IntegrationSiteSecret::query()
                ->where('site_id', $siteConfig->site_id)
                ->where('provider', $this->provider())
                ->where('capability', 'access_api')
                ->where('is_enabled', true)
                ->first();

            if (! $accessSecret || empty($accessSecret->base_url)) {
                return [];
            }

            $apiKey = Crypt::decryptString($accessSecret->secret_encrypted);
            $baseUrl = rtrim($accessSecret->base_url, '/');

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$apiKey}",
            ])
                ->withOptions(['verify' => false])
                ->get($baseUrl.'/api/v1/developer/system/logs', [
                    'limit' => 200,
                    'offset' => 0,
                    'topics' => 'door_openings',
                ]);

            if (! $response->successful()) {
                $status = $response->status();
                Log::warning('UniFi Access pullEvents failed', [
                    'tenant_id' => $siteConfig->tenant_id,
                    'site_id' => $siteConfig->site_id,
                    'status' => $status,
                ]);

                throw new \RuntimeException("UniFi Access API returned HTTP {$status}.");
            }

            $sinceAt = $since ? Carbon::instance($since) : null;
            $entries = $response->json('data', []);
            $events = [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $normalized = $this->normalizeAccessLogEntry($entry);
                if (! $normalized) {
                    continue;
                }

                if ($sinceAt && $normalized['occurred_at']->lt($sinceAt)) {
                    continue;
                }

                $events[] = $normalized;
            }

            return $events;
        } catch (\Throwable $e) {
            Log::warning('UniFi Access pullEvents error', SafeOperationalData::logContext([
                'tenant_id' => $siteConfig->tenant_id,
                'site_id' => $siteConfig->site_id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

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
        if (! $externalSiteId) {
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
            if (! is_array($host)) {
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
        if (! $host) {
            return null;
        }

        $name = $this->resolveHostName($host);
        $model = $this->resolveHostModel($host);
        $controllers = $this->resolveHostControllers($host);
        $role = $this->resolveHostRole($host, $model, $controllers);

        if (! $name && ! $model) {
            return null;
        }

        if (! $name && $model) {
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
            $normalized = $this->normalizeOptionalProjectedText($value, self::SITE_LABEL_MAX_LENGTH);
            if ($normalized !== null) {
                return $normalized;
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
            $normalized = $this->normalizeOptionalProjectedText($value, self::SITE_LABEL_MAX_LENGTH);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function resolveHostControllers(array $host): array
    {
        $controllers = [];

        $raw = data_get($host, 'userData.controllers');
        if ($raw !== null && ! is_array($raw)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                $controllers[] = $this->normalizeHostController($entry);
            }
        }

        $fallback = $host['controllers'] ?? $host['apps'] ?? null;
        if ($fallback !== null && ! is_array($fallback)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        if (is_array($fallback)) {
            foreach ($fallback as $entry) {
                $controllers[] = $this->normalizeHostController($entry);
            }
        }

        $groupMembers = data_get($host, 'userData.consoleGroupMembers');
        if ($groupMembers !== null && ! is_array($groupMembers)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        if (is_array($groupMembers)) {
            foreach ($groupMembers as $member) {
                if (! is_array($member)) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $apps = data_get($member, 'roleAttributes.applications');
                if ($apps !== null && ! is_array($apps)) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                if (! is_array($apps)) {
                    continue;
                }
                foreach ($apps as $app => $meta) {
                    if (! is_array($meta)) {
                        throw IntegrationDiscoveryException::invalidResponse();
                    }
                    if ($meta['supported'] ?? false) {
                        $controllers[] = $this->normalizeHostController($app);
                    }
                }
            }
        }

        $controllers = array_values(array_unique(array_filter($controllers)));
        if (count($controllers) > self::HOST_CONTROLLER_COUNT_MAX) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $controllers;
    }

    private function normalizeHostController(mixed $entry): string
    {
        $value = is_array($entry) && array_key_exists('name', $entry)
            ? $entry['name']
            : $entry;
        $normalized = $this->normalizeOptionalProjectedText($value, self::SITE_STATUS_MAX_LENGTH);
        if ($normalized === null) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return strtolower($normalized);
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

        $type = $this->normalizeOptionalProjectedText($host['type'] ?? null, self::SITE_STATUS_MAX_LENGTH);
        if ($type !== null) {
            return strtolower($type);
        }

        return 'console';
    }

    private function isProtectDevice(mixed $device): bool
    {
        if (! is_array($device)) {
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
        if (! $occurredAt) {
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

    private function parseAccessTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $numeric = (int) $value;
            if ($numeric > 1000000000000) {
                return Carbon::createFromTimestamp($numeric / 1000);
            }

            return Carbon::createFromTimestamp($numeric);
        }

        try {
            return Carbon::parse($value);
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
            if (! is_array($device)) {
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
        if (! is_array($device)) {
            return 'device';
        }

        if (! empty($device['isConsole'])) {
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
}
