<?php

namespace App\Services\Integration\Adapters;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\LocationHardware;
use App\Services\Integration\CanonicalIntegrationDeviceResolver;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use App\Services\Integration\Data\ProviderEventPage;
use App\Services\Integration\Data\ProviderObservationPage;
use App\Services\Integration\Data\ProviderSnapshotPage;
use App\Services\Integration\Data\ProviderTopologyPage;
use App\Services\Integration\Data\ProviderWebhookRequest;
use App\Services\Integration\Data\VerifiedProviderEvent;
use App\Services\Integration\Data\VerifiedProviderEventBatch;
use App\Services\Integration\Data\VerifiedWebhookBinding;
use App\Services\Integration\Exceptions\WebhookRejected;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use App\Services\Integration\SyncResult;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Services\Integration\UnifiTransportSecurity;
use App\Support\SafeOperationalData;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UnifiAdapter implements ConnectionHealthCapability, DeviceSyncCapability, EventCollectionCapability, IntegrationAdapterInterface, InventoryDiscoveryCapability, ObservationCollectionCapability, SnapshotCollectionCapability, TopologyCollectionCapability, WebhookVerificationCapability
{
    private const BASE_URL = 'https://api.ui.com/v1';

    private const NETWORK_API_MAX_PAGE_SIZE = 200;

    private const OBSERVATION_MAX_PAGE_SIZE = 25;

    private const OBSERVATION_CURSOR_PREFIX = 'unifi-health-v1:';

    private const SNAPSHOT_CURSOR_PREFIX = 'unifi-network-config-v1:';

    private const TOPOLOGY_CURSOR_MAX = 1000000000;

    private const SITE_IDENTIFIER_MAX_LENGTH = 255;

    private const SITE_LABEL_MAX_LENGTH = 255;

    private const SITE_STATUS_MAX_LENGTH = 100;

    private const SITE_DEVICE_COUNT_MAX = 1000000;

    private const HOST_COUNT_MAX = 10000;

    private const HOST_CONTROLLER_COUNT_MAX = 100;

    private const ACCESS_EVENT_CURSOR_PREFIX = 'access-v1:';

    private const ACCESS_EVENT_DEFAULT_LOOKBACK_SECONDS = 172800;

    private const ACCESS_EVENT_MAX_WINDOW_SECONDS = 2592000;

    private const ACCESS_EVENT_MAX_PAGE = 10000;

    private const ACCESS_EVENT_MAX_TOTAL = 1000000;

    public function __construct(
        private readonly UnifiOperationalBridgeService $runtime,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly RuntimeEnvelopeCodec $codec,
        private readonly CanonicalIntegrationDeviceResolver $deviceResolver,
        private readonly IntegrationSecretMaterialService $secrets,
        private readonly UnifiTransportSecurity $transport,
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
        return [
            ConnectionHealthCapability::class,
            InventoryDiscoveryCapability::class,
            DeviceSyncCapability::class,
            ObservationCollectionCapability::class,
            SnapshotCollectionCapability::class,
            EventCollectionCapability::class,
            TopologyCollectionCapability::class,
            WebhookVerificationCapability::class,
        ];
    }

    public function verifyWebhook(
        IntegrationProviderConnection $connection,
        ProviderWebhookRequest $request,
    ): VerifiedProviderEventBatch {
        if (strlen($request->body) < 2 || strlen($request->body) > 262144) {
            throw new WebhookRejected('body_size', 413);
        }

        try {
            $secret = $this->secrets->application(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );
        } catch (\Throwable) {
            throw new WebhookRejected('credentials_unavailable');
        }

        $integrationKey = $request->header('X-Integration-Key');
        if (! is_string($integrationKey) || ! hash_equals($secret, $integrationKey)) {
            throw new WebhookRejected('authentication');
        }

        $timestamp = $request->header('X-Webhook-Timestamp');
        if (! is_string($timestamp) || preg_match('/^\d{10}$/', $timestamp) !== 1
            || abs($request->receivedAt->timestamp - (int) $timestamp) > (int) config('integration-capabilities.webhook.maximum_skew_seconds', 300)) {
            throw new WebhookRejected('timestamp');
        }

        $nonce = $request->header('X-Webhook-Nonce');
        if (! is_string($nonce) || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce) !== 1) {
            throw new WebhookRejected('nonce');
        }

        $signature = $request->header('X-Webhook-Signature');
        if (! is_string($signature) || preg_match('/^sha256=([a-f0-9]{64})$/i', $signature, $matches) !== 1) {
            throw new WebhookRejected('signature');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body, $secret);
        if (! hash_equals($expected, strtolower($matches[1]))) {
            throw new WebhookRejected('signature');
        }

        try {
            $payload = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookRejected('payload', 422);
        }

        if (! is_array($payload)) {
            throw new WebhookRejected('payload', 422);
        }

        $externalSiteId = $this->boundedWebhookText($payload['site_id'] ?? $payload['siteId'] ?? null, 255);
        $sourceEventId = $this->boundedWebhookText($payload['_id'] ?? $payload['event_id'] ?? $payload['id'] ?? null, 255);
        $eventType = $this->boundedWebhookText($payload['key'] ?? $payload['type'] ?? null, 255);
        $deviceIdentifier = $this->boundedWebhookText($payload['mac'] ?? $payload['device_id'] ?? null, 255);
        if ($externalSiteId === null || $eventType === null || $deviceIdentifier === null) {
            throw new WebhookRejected('payload', 422);
        }

        $siteConfigs = IntegrationSiteConfig::query()
            ->forProvider($this->provider())
            ->active()
            ->where('mapped_external_site_id', $externalSiteId)
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->limit(2)
            ->get();
        if ($siteConfigs->count() !== 1) {
            throw new WebhookRejected('site_identity', 404);
        }
        $siteConfig = $siteConfigs->sole();
        $siteId = (int) $siteConfig->site_id;

        try {
            $device = $this->deviceResolver->resolveInventory(
                $siteConfig,
                $this->provider(),
                $deviceIdentifier,
                [
                    'mac' => $payload['mac'] ?? null,
                    'serial' => $payload['serial'] ?? $payload['serial_number'] ?? null,
                ],
            );
            if ($device === null || $this->siteResolver->resolve((int) $device->id) !== $siteId) {
                throw new \RuntimeException('Canonical webhook Device is unavailable.');
            }
        } catch (\Throwable) {
            throw new WebhookRejected('site_identity', 404);
        }

        $providerEntityId = $this->boundedWebhookText(
            data_get($device->external_ref, 'provider_entity_id'),
            255,
        );
        if ($providerEntityId === null) {
            throw new WebhookRejected('site_identity', 404);
        }

        $reportedOccurredAt = $this->parseWebhookOccurredAt($payload['time'] ?? $payload['timestamp'] ?? null);
        if ($reportedOccurredAt === null
            || $reportedOccurredAt->lt($request->receivedAt->subSeconds(
                (int) config('integration-capabilities.webhook.maximum_event_age_seconds', 86400),
            ))
            || $reportedOccurredAt->gt($request->receivedAt->addSeconds(
                (int) config('integration-capabilities.webhook.maximum_skew_seconds', 300),
            ))) {
            throw new WebhookRejected('event_timestamp');
        }
        $occurredAt = $reportedOccurredAt;
        try {
            $bodyHash = hash('sha256', $this->codec->canonicalPayloadBytes($payload));
        } catch (\UnexpectedValueException) {
            throw new WebhookRejected('payload', 422);
        }
        if ($sourceEventId === null) {
            $sourceEventId = 'oblivion-fallback-v1:'.hash('sha256', $this->provider().'|'.$bodyHash);
        } elseif (str_starts_with($sourceEventId, 'oblivion-fallback-v1:')) {
            throw new WebhookRejected('payload', 422);
        }
        $severity = match (strtolower((string) ($payload['severity'] ?? 'info'))) {
            'critical', 'emergency', 'fatal', 'urgent' => 'critical',
            'warn', 'warning', 'high', 'major' => 'warn',
            default => 'info',
        };

        $replayStore = (string) (config('integration-capabilities.webhook.replay_store') ?: config('cache.default'));
        $replayDriver = config("cache.stores.{$replayStore}.driver");
        $allowLocalReplay = app()->environment('testing')
            && (bool) config('integration-capabilities.webhook.allow_local_replay_store_for_tests', false);
        if ($replayDriver !== 'redis' && ! $allowLocalReplay) {
            throw new WebhookRejected('replay_store_unavailable', 503);
        }

        $cacheKey = 'monitoring:provider-webhook-replay:'.hash('sha256', $connection->id.':'.$nonce);
        try {
            $reserved = Cache::store($replayStore)
                ->add($cacheKey, true, (int) config('integration-capabilities.webhook.replay_ttl_seconds', 600));
        } catch (\Throwable) {
            throw new WebhookRejected('replay_store_unavailable', 503);
        }

        if (! $reserved) {
            throw new WebhookRejected('replay');
        }

        return new VerifiedProviderEventBatch([
            new VerifiedProviderEvent(
                siteId: $siteId,
                sourceApp: 'unifi',
                sourceEventId: $sourceEventId,
                occurredAt: $occurredAt,
                severity: $severity,
                eventType: $eventType,
                normalizedPayload: array_filter([
                    'summary' => $this->boundedWebhookText($payload['msg'] ?? $payload['message'] ?? null, 1000),
                    'subsystem' => $this->boundedWebhookText($payload['subsystem'] ?? null, 100),
                    'device_identifier' => $providerEntityId,
                    'canonical_device_id' => (int) $device->id,
                ], static fn (mixed $value): bool => $value !== null),
                bodyHash: $bodyHash,
                binding: new VerifiedWebhookBinding(
                    siteConfigId: (int) $siteConfig->id,
                    externalSiteId: $externalSiteId,
                    canonicalDeviceId: (int) $device->id,
                    providerEntityId: $providerEntityId,
                ),
            ),
        ]);
    }

    private function boundedWebhookText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && mb_strlen($value) <= $maximum ? $value : null;
    }

    private function parseWebhookOccurredAt(mixed $value): ?CarbonImmutable
    {
        try {
            if ((is_int($value) || is_string($value)) && preg_match('/^\d{13}$/', (string) $value) === 1) {
                return CarbonImmutable::createFromTimestampMs((int) $value)->utc();
            }

            if ((is_int($value) || is_string($value)) && preg_match('/^\d{10}$/', (string) $value) === 1) {
                return CarbonImmutable::createFromTimestamp((int) $value)->utc();
            }

            if (is_string($value) && strlen($value) <= 64) {
                return CarbonImmutable::parse($value)->utc();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        try {
            $apiKey = $this->secrets->application(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );

            $response = $this->providerRequest([
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
            $apiKey = $this->secrets->application(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );

            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ];

            $response = $this->providerRequest($headers)->get(self::BASE_URL.'/sites');

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
                $hostsResponse = $this->providerRequest($headers)->get(self::BASE_URL.'/hosts');
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
                $devicesResponse = $this->providerRequest($headers)->get(self::BASE_URL.'/devices');
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
            $apiKey = $this->secrets->application(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );

            $response = $this->providerRequest([
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
        $externalSiteId = is_scalar($siteConfig->mapped_external_site_id)
            ? trim((string) $siteConfig->mapped_external_site_id)
            : '';

        try {
            $apiKey = $this->secrets->application(
                $providerConnection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );

            $sitesResponse = $this->providerRequest([
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

            $devicesResponse = $this->providerRequest([
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
                        if (! $this->deviceSyncScopeStillUsable(
                            (int) $providerConnection->id,
                            (int) $siteConfig->id,
                            (int) $siteConfig->site_id,
                            $externalSiteId,
                        )) {
                            return new SyncResult(
                                processed: $processed,
                                created: $created,
                                updated: $updated,
                                errored: $errored,
                                error: SafeOperationalData::failureSummary(),
                            );
                        }
                        $sync = $this->runtime->syncInventoryDevice($siteConfig, $device);

                        if ($sync['created']) {
                            $created++;
                        } else {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('UniFi syncDevices: error processing device', SafeOperationalData::logContext([
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
                'site_id' => $siteConfig->site_id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return new SyncResult(error: SafeOperationalData::failureSummary());
        }
    }

    private function deviceSyncScopeStillUsable(
        int $connectionId,
        int $siteConfigId,
        int $siteId,
        string $externalSiteId,
    ): bool {
        if ($externalSiteId === '') {
            return false;
        }

        return IntegrationProviderConnection::query()
            ->whereKey($connectionId)
            ->forProvider($this->provider())
            ->connected()
            ->exists()
            && IntegrationSiteConfig::query()
                ->whereKey($siteConfigId)
                ->forProvider($this->provider())
                ->active()
                ->where('site_id', $siteId)
                ->where('mapped_external_site_id', $externalSiteId)
                ->whereRaw("TRIM(`mapped_external_site_id`) <> ''")
                ->whereHas('site', fn ($site) => $site
                    ->where('is_active', true)
                    ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                    ->whereNull('archived_at'))
                ->exists();
    }

    public function collectTopology(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderTopologyPage {
        if ($siteConfig->provider !== $this->provider()
            || $providerConnection->provider !== $this->provider()
            || $limit < 1
            || $limit > self::NETWORK_API_MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('UniFi topology request is invalid.');
        }

        try {
            $offset = $this->topologyOffset($cursor);
            $apiKey = $this->secrets->application(
                $providerConnection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );
            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ];
            $sitesResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get(self::BASE_URL.'/sites');
            if ($sitesResponse->status() === 429) {
                return $this->deferredTopologyPage($sitesResponse->header('Retry-After'));
            }
            if (! $sitesResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($sitesResponse->status());
            }

            $sites = $sitesResponse->json('data');
            if (! is_array($sites) || ! array_is_list($sites) || count($sites) > 10000
                || collect($sites)->contains(fn (mixed $site): bool => ! is_array($site))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $externalSiteId = $this->normalizeRequiredSiteIdentifier($siteConfig->mapped_external_site_id);
            $hostId = $this->resolveHostId($sites, $externalSiteId);
            if (! is_string($hostId) && ! is_int($hostId)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $consoleId = $this->normalizeRequiredSiteIdentifier($hostId);
            $siteBaseUrl = self::BASE_URL.'/connector/consoles/'.rawurlencode($consoleId)
                .'/proxy/network/integration/v1/sites/'.rawurlencode($externalSiteId);

            $devicesResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($siteBaseUrl.'/devices', [
                    'offset' => $offset,
                    'limit' => $limit,
                ]);
            if ($devicesResponse->status() === 429) {
                return $this->deferredTopologyPage($devicesResponse->header('Retry-After'));
            }
            if (! $devicesResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($devicesResponse->status());
            }

            $page = $devicesResponse->json();
            if (! is_array($page) || ! is_array($page['data'] ?? null) || ! array_is_list($page['data'])
                || count($page['data']) > $limit) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $reportedOffset = $this->boundedTopologyInteger($page['offset'] ?? null, self::TOPOLOGY_CURSOR_MAX);
            $reportedLimit = $this->boundedTopologyInteger($page['limit'] ?? null, self::NETWORK_API_MAX_PAGE_SIZE);
            $reportedCount = $this->boundedTopologyInteger($page['count'] ?? null, self::NETWORK_API_MAX_PAGE_SIZE);
            $totalCount = $this->boundedTopologyInteger($page['totalCount'] ?? null, self::TOPOLOGY_CURSOR_MAX);
            $devices = $page['data'];
            if ($reportedOffset !== $offset
                || $reportedLimit < 1
                || $reportedLimit > $limit
                || $reportedCount !== count($devices)
                || $totalCount < $offset + $reportedCount
                || ($reportedCount === 0 && $totalCount > $offset)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }

            $nodes = [];
            $edges = [];
            foreach ($devices as $device) {
                if (! is_array($device)) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $deviceId = $this->normalizeRequiredSiteIdentifier($device['id'] ?? null);
                $deviceKey = $this->unifiTopologyNodeKey($deviceId);
                $nodes[$deviceKey] = $this->unifiTopologyNode($deviceId, $device);

                $detailResponse = $this->providerRequest($headers)
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get($siteBaseUrl.'/devices/'.rawurlencode($deviceId));
                if ($detailResponse->status() === 429) {
                    return $this->deferredTopologyPage($detailResponse->header('Retry-After'));
                }
                if (! $detailResponse->successful()) {
                    throw IntegrationDiscoveryException::forHttpStatus($detailResponse->status());
                }
                $detail = $detailResponse->json();
                if (! is_array($detail)
                    || $this->normalizeRequiredSiteIdentifier($detail['id'] ?? null) !== $deviceId) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $uplink = $detail['uplink'] ?? null;
                if ($uplink === null) {
                    continue;
                }
                if (! is_array($uplink)) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $parentId = $this->normalizeRequiredSiteIdentifier($uplink['deviceId'] ?? null);
                if ($parentId === $deviceId) {
                    continue;
                }
                $parentKey = $this->unifiTopologyNodeKey($parentId);
                $nodes[$parentKey] ??= $this->unifiTopologyNode($parentId);
                $edges[$deviceKey.':'.$parentKey] = [
                    'from' => $deviceKey,
                    'to' => $parentKey,
                    'source' => 'provider',
                    'kind' => 'uplink',
                    'local_port' => null,
                    'remote_port' => null,
                    'confidence' => 0.99,
                    'evidence' => [
                        'protocol' => 'unifi_network_api',
                        'relationship' => 'reported_uplink',
                    ],
                ];
            }

            $nextOffset = $offset + $reportedCount;

            return new ProviderTopologyPage(
                nodes: array_values($nodes),
                edges: array_values($edges),
                nextCursor: $nextOffset < $totalCount ? (string) $nextOffset : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $failure = IntegrationDiscoveryException::fromThrowable($exception);
            Log::error('UniFi topology collection failed', SafeOperationalData::logContext([
                'site_id' => $siteConfig->site_id,
                'provider_connection_id' => $providerConnection->id,
                'error_category' => $failure->failureCategory(),
            ]));

            throw $failure;
        }
    }

    public function collectObservations(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderObservationPage {
        $externalSiteId = $this->normalizeRequiredSiteIdentifier($siteConfig->mapped_external_site_id);
        if ($siteConfig->provider !== $this->provider()
            || $providerConnection->provider !== $this->provider()
            || $limit < 1
            || $limit > self::NETWORK_API_MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('UniFi observation request is invalid.');
        }
        $offset = $this->observationOffset($cursor);
        $pageLimit = min($limit, self::OBSERVATION_MAX_PAGE_SIZE);

        try {
            $apiKey = $this->secrets->application(
                $providerConnection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );
            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ];
            $sitesResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get(self::BASE_URL.'/sites');
            if ($sitesResponse->status() === 429) {
                return $this->deferredObservationPage($cursor, $sitesResponse->header('Retry-After'));
            }
            if (! $sitesResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($sitesResponse->status());
            }

            $sites = $sitesResponse->json('data');
            if (! is_array($sites) || ! array_is_list($sites) || count($sites) > 10000
                || collect($sites)->contains(fn (mixed $site): bool => ! is_array($site))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $hostId = $this->resolveHostId($sites, $externalSiteId);
            if (! is_string($hostId) && ! is_int($hostId)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $consoleId = $this->normalizeRequiredSiteIdentifier($hostId);
            $siteBaseUrl = self::BASE_URL.'/connector/consoles/'.rawurlencode($consoleId)
                .'/proxy/network/integration/v1/sites/'.rawurlencode($externalSiteId);
            $devicesResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($siteBaseUrl.'/devices', [
                    'offset' => $offset,
                    'limit' => $pageLimit,
                ]);
            if ($devicesResponse->status() === 429) {
                return $this->deferredObservationPage($cursor, $devicesResponse->header('Retry-After'));
            }
            if (! $devicesResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($devicesResponse->status());
            }

            $page = $devicesResponse->json();
            if (! is_array($page) || ! is_array($page['data'] ?? null) || ! array_is_list($page['data'])
                || count($page['data']) > $pageLimit) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $reportedOffset = $this->boundedTopologyInteger($page['offset'] ?? null, self::TOPOLOGY_CURSOR_MAX);
            $reportedLimit = $this->boundedTopologyInteger($page['limit'] ?? null, self::OBSERVATION_MAX_PAGE_SIZE);
            $reportedCount = $this->boundedTopologyInteger($page['count'] ?? null, self::OBSERVATION_MAX_PAGE_SIZE);
            $totalCount = $this->boundedTopologyInteger($page['totalCount'] ?? null, self::TOPOLOGY_CURSOR_MAX);
            $devices = $page['data'];
            if ($reportedOffset !== $offset
                || $reportedLimit < 1
                || $reportedLimit > $pageLimit
                || $reportedCount !== count($devices)
                || $totalCount < $offset + $reportedCount
                || ($reportedCount === 0 && $totalCount > $offset)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }

            $items = [];
            $exceptions = [];
            $collectedAt = CarbonImmutable::now('UTC')->startOfSecond();
            $nextOffset = $offset;
            $seen = [];

            foreach ($devices as $index => $payload) {
                if (! is_array($payload)) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $providerEntityId = $this->normalizeRequiredSiteIdentifier($payload['id'] ?? null);
                if (isset($seen[$providerEntityId])) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $seen[$providerEntityId] = true;
                $state = $this->observationConnectivity($payload['state'] ?? null);
                [$device, $monitor, $scopeException] = $this->observationScope(
                    $siteConfig,
                    $consoleId,
                    $providerEntityId,
                );
                if ($scopeException !== null) {
                    $exceptions[] = $scopeException;

                    break;
                }

                $statisticsResponse = $this->providerRequest($headers)
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get($siteBaseUrl.'/devices/'.rawurlencode($providerEntityId).'/statistics/latest');
                if ($statisticsResponse->status() === 429) {
                    $exceptions[] = [
                        'code' => 'provider_rate_limited',
                        'item_reference' => null,
                    ];

                    return new ProviderObservationPage(
                        items: $items,
                        nextCursor: self::OBSERVATION_CURSOR_PREFIX.$nextOffset,
                        partial: true,
                        retryAfterSeconds: $this->accessRetryAfter($statisticsResponse->header('Retry-After')),
                        exceptions: $exceptions,
                    );
                }
                if ($statisticsResponse->status() === 404) {
                    $statistics = null;
                } elseif (! $statisticsResponse->successful()) {
                    throw IntegrationDiscoveryException::forHttpStatus($statisticsResponse->status());
                } else {
                    $statistics = $statisticsResponse->json();
                    if (! is_array($statistics) || array_is_list($statistics)) {
                        throw IntegrationDiscoveryException::invalidResponse();
                    }
                }

                $absoluteIndex = $offset + $index;
                $candidateNextOffset = $absoluteIndex + 1 >= $totalCount ? 0 : $absoluteIndex + 1;
                $items[] = $this->observationItem(
                    $siteConfig,
                    $device,
                    $monitor,
                    $providerEntityId,
                    $state,
                    $statistics,
                    $collectedAt,
                    self::OBSERVATION_CURSOR_PREFIX.$candidateNextOffset,
                );
                $nextOffset = $candidateNextOffset;
            }

            if ($exceptions === [] && $offset === 0) {
                [$wanDevice, $wanMonitor, $wanScopeException] = $this->wanObservationScope(
                    $siteConfig,
                    $consoleId,
                    $externalSiteId,
                );
                if ($wanScopeException !== null) {
                    $exceptions[] = $wanScopeException;
                } elseif ($wanDevice !== null && $wanMonitor !== null) {
                    $wanResponse = $this->providerRequest($headers)
                        ->connectTimeout(5)
                        ->timeout(30)
                        ->get(self::BASE_URL.'/isp-metrics/5m', [
                            'beginTimestamp' => $collectedAt->subMinutes(10)->toIso8601String(),
                            'endTimestamp' => $collectedAt->toIso8601String(),
                        ]);
                    if ($wanResponse->status() === 429) {
                        $exceptions[] = [
                            'code' => 'provider_rate_limited',
                            'item_reference' => null,
                        ];

                        return new ProviderObservationPage(
                            items: $items,
                            nextCursor: self::OBSERVATION_CURSOR_PREFIX.$nextOffset,
                            partial: true,
                            retryAfterSeconds: $this->accessRetryAfter($wanResponse->header('Retry-After')),
                            exceptions: $exceptions,
                        );
                    }
                    if (! $wanResponse->successful()) {
                        throw IntegrationDiscoveryException::forHttpStatus($wanResponse->status());
                    }
                    $wanPage = $wanResponse->json();
                    if (! is_array($wanPage) || array_is_list($wanPage)) {
                        throw IntegrationDiscoveryException::invalidResponse();
                    }
                    [$wanItem, $wanException] = $this->wanObservationItem(
                        $siteConfig,
                        $wanDevice,
                        $wanMonitor,
                        $consoleId,
                        $externalSiteId,
                        $wanPage,
                        $collectedAt,
                        self::OBSERVATION_CURSOR_PREFIX.$nextOffset,
                    );
                    if ($wanItem !== null) {
                        $items[] = $wanItem;
                    }
                    if ($wanException !== null) {
                        $exceptions[] = $wanException;
                    }
                }
            }

            return new ProviderObservationPage(
                items: $items,
                nextCursor: self::OBSERVATION_CURSOR_PREFIX.$nextOffset,
                partial: $exceptions !== [],
                exceptions: $exceptions,
            );
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $failure = IntegrationDiscoveryException::fromThrowable($exception);
            Log::error('UniFi observation collection failed', SafeOperationalData::logContext([
                'site_id' => $siteConfig->site_id,
                'provider_connection_id' => $providerConnection->id,
                'error_category' => $failure->failureCategory(),
            ]));

            throw $failure;
        }
    }

    public function collectSnapshots(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderSnapshotPage {
        if ($siteConfig->provider !== $this->provider()
            || $providerConnection->provider !== $this->provider()
            || $limit < 1
            || $limit > self::NETWORK_API_MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('UniFi configuration snapshot request is invalid.');
        }
        $safeCursor = $this->snapshotCursor($cursor);

        try {
            $externalSiteId = $this->normalizeRequiredSiteIdentifier($siteConfig->mapped_external_site_id);
            $apiKey = $this->secrets->application(
                $providerConnection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );
            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ];
            $sitesResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get(self::BASE_URL.'/sites');
            if ($sitesResponse->status() === 429) {
                return $this->deferredSnapshotPage($safeCursor, $sitesResponse->header('Retry-After'));
            }
            if (! $sitesResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($sitesResponse->status());
            }

            $sites = $sitesResponse->json('data');
            if (! is_array($sites) || ! array_is_list($sites) || count($sites) > 10000
                || collect($sites)->contains(fn (mixed $site): bool => ! is_array($site))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $hostId = $this->resolveHostId($sites, $externalSiteId);
            if (! is_string($hostId) && ! is_int($hostId)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $consoleId = $this->normalizeRequiredSiteIdentifier($hostId);
            [$device, $monitor, $scopeException] = $this->wanObservationScope(
                $siteConfig,
                $consoleId,
                $externalSiteId,
            );
            if ($scopeException !== null) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            if ($device === null || $monitor === null) {
                return new ProviderSnapshotPage(items: [], nextCursor: $safeCursor);
            }

            $siteBaseUrl = self::BASE_URL.'/connector/consoles/'.rawurlencode($consoleId)
                .'/proxy/network/integration/v1/sites/'.rawurlencode($externalSiteId);
            $wanResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($siteBaseUrl.'/wans', ['offset' => 0, 'limit' => $limit]);
            if ($wanResponse->status() === 429) {
                return $this->deferredSnapshotPage($safeCursor, $wanResponse->header('Retry-After'));
            }
            if (! $wanResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($wanResponse->status());
            }
            $tunnelResponse = $this->providerRequest($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($siteBaseUrl.'/vpn/site-to-site-tunnels', ['offset' => 0, 'limit' => $limit]);
            if ($tunnelResponse->status() === 429) {
                return $this->deferredSnapshotPage($safeCursor, $tunnelResponse->header('Retry-After'));
            }
            if (! $tunnelResponse->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($tunnelResponse->status());
            }

            $configuration = [
                'schema' => 'unifi_network_site_configuration_v1',
                'site_to_site_vpn_tunnels' => $this->snapshotTunnels(
                    $this->snapshotPageItems($tunnelResponse->json(), $limit),
                ),
                'wan_interfaces' => $this->snapshotWanInterfaces(
                    $this->snapshotPageItems($wanResponse->json(), $limit),
                ),
            ];
            $fingerprint = hash('sha256', json_encode(
                $configuration,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            $nextCursor = self::SNAPSHOT_CURSOR_PREFIX.$fingerprint;
            if ($safeCursor !== null && hash_equals($safeCursor, $nextCursor)) {
                return new ProviderSnapshotPage(items: [], nextCursor: $safeCursor);
            }

            return new ProviderSnapshotPage(
                items: [[
                    'cursor' => $nextCursor,
                    'site_id' => (int) $siteConfig->site_id,
                    'device_id' => (int) $device->id,
                    'captured_at' => CarbonImmutable::now('UTC')->startOfSecond()->toIso8601String(),
                    'payload' => ['configuration' => $configuration],
                ]],
                nextCursor: $nextCursor,
            );
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $failure = IntegrationDiscoveryException::fromThrowable($exception);
            Log::error('UniFi configuration snapshot collection failed', SafeOperationalData::logContext([
                'site_id' => $siteConfig->site_id,
                'provider_connection_id' => $providerConnection->id,
                'error_category' => $failure->failureCategory(),
            ]));

            throw $failure;
        }
    }

    private function observationOffset(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $pattern = '/^'.preg_quote(self::OBSERVATION_CURSOR_PREFIX, '/').'(0|[1-9]\d{0,9})$/';
        if (preg_match($pattern, $cursor, $matches) !== 1) {
            throw new \InvalidArgumentException('UniFi observation request is invalid.');
        }

        $offset = (int) $matches[1];
        if ($offset > self::TOPOLOGY_CURSOR_MAX) {
            throw new \InvalidArgumentException('UniFi observation request is invalid.');
        }

        return $offset;
    }

    private function snapshotCursor(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        if (preg_match('/^'.preg_quote(self::SNAPSHOT_CURSOR_PREFIX, '/').'[a-f0-9]{64}$/', $cursor) !== 1) {
            throw new \InvalidArgumentException('UniFi configuration snapshot request is invalid.');
        }

        return $cursor;
    }

    private function deferredSnapshotPage(?string $cursor, mixed $retryAfter): ProviderSnapshotPage
    {
        return new ProviderSnapshotPage(
            items: [],
            nextCursor: $cursor,
            partial: true,
            retryAfterSeconds: $this->accessRetryAfter($retryAfter),
        );
    }

    /** @return list<array<string, mixed>> */
    private function snapshotPageItems(mixed $page, int $limit): array
    {
        if (! is_array($page) || array_is_list($page)
            || ! is_array($page['data'] ?? null) || ! array_is_list($page['data'])
            || count($page['data']) > $limit) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $offset = $this->boundedTopologyInteger($page['offset'] ?? null, self::TOPOLOGY_CURSOR_MAX);
        $reportedLimit = $this->boundedTopologyInteger($page['limit'] ?? null, self::NETWORK_API_MAX_PAGE_SIZE);
        $count = $this->boundedTopologyInteger($page['count'] ?? null, self::NETWORK_API_MAX_PAGE_SIZE);
        $total = $this->boundedTopologyInteger($page['totalCount'] ?? null, self::TOPOLOGY_CURSOR_MAX);
        if ($offset !== 0
            || $reportedLimit < 1
            || $reportedLimit > $limit
            || $count !== count($page['data'])
            || $total !== $count) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $page['data'];
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, string>> */
    private function snapshotWanInterfaces(array $items): array
    {
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $id = $this->normalizeRequiredSiteIdentifier($item['id'] ?? null);
            if (isset($seen[$id])) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $seen[$id] = true;
            $normalized[] = [
                'name' => $this->snapshotText($item['name'] ?? null, 255),
                'reference' => hash('sha256', $id),
            ];
        }
        usort($normalized, fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']));

        return $normalized;
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, string>> */
    private function snapshotTunnels(array $items): array
    {
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $id = $this->normalizeRequiredSiteIdentifier($item['id'] ?? null);
            if (isset($seen[$id])) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $seen[$id] = true;
            $metadata = $item['metadata'] ?? [];
            if (! is_array($metadata) || array_is_list($metadata)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $tunnel = [
                'name' => $this->snapshotText($item['name'] ?? null, 255),
            ];
            if (array_key_exists('origin', $metadata)) {
                $tunnel['origin'] = $this->snapshotText($metadata['origin'], 128);
            }
            $tunnel['reference'] = hash('sha256', $id);
            $tunnel['type'] = $this->snapshotText($item['type'] ?? null, 128);
            $normalized[] = $tunnel;
        }
        usort($normalized, fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']));

        return $normalized;
    }

    private function snapshotText(mixed $value, int $maximum): string
    {
        if (! is_string($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $value;
    }

    private function deferredObservationPage(?string $cursor, mixed $retryAfter): ProviderObservationPage
    {
        return new ProviderObservationPage(
            items: [],
            nextCursor: $cursor ?? self::OBSERVATION_CURSOR_PREFIX.'0',
            partial: true,
            retryAfterSeconds: $this->accessRetryAfter($retryAfter),
            exceptions: [[
                'code' => 'provider_rate_limited',
                'item_reference' => null,
            ]],
        );
    }

    private function observationConnectivity(mixed $value): string
    {
        if (! is_string($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $state = strtoupper(trim($value));
        if ($state === '' || strlen($state) > 64 || preg_match('/^[A-Z][A-Z0-9_]*$/', $state) !== 1) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return strtolower($state);
    }

    /**
     * @return array{
     *     0: ?Device,
     *     1: ?Monitor,
     *     2: null|array{code: string, item_reference: ?string}
     * }
     */
    private function observationScope(
        IntegrationSiteConfig $siteConfig,
        string $consoleId,
        string $providerEntityId,
    ): array {
        $reference = hash('sha256', $providerEntityId);
        $devices = Device::query()
            ->byProvider($this->provider())
            ->where('external_ref->provider_entity_id', $providerEntityId)
            ->get();
        if ($devices->isEmpty()) {
            return [null, null, ['code' => 'identity_unresolved', 'item_reference' => $reference]];
        }
        if ($devices->count() !== 1) {
            return [null, null, ['code' => 'identity_ambiguous', 'item_reference' => $reference]];
        }

        /** @var Device $device */
        $device = $devices->first();
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (\Throwable) {
            return [null, null, ['code' => 'site_scope_mismatch', 'item_reference' => $reference]];
        }
        if ($siteId !== (int) $siteConfig->site_id
            || strtolower((string) data_get($device->external_ref, 'source_app')) !== 'network'
            || (string) data_get($device->external_ref, 'host_id') !== $consoleId) {
            return [null, null, ['code' => 'site_scope_mismatch', 'item_reference' => $reference]];
        }

        $target = $this->runtime->monitorTargetFor($providerEntityId);
        $monitors = Monitor::query()
            ->with('profile')
            ->where('device_id', $device->id)
            ->where('kind', MonitorKind::Provider->value)
            ->where('target', $target)
            ->get();
        if ($monitors->count() !== 1) {
            return [null, null, [
                'code' => $monitors->isEmpty() ? 'monitor_unavailable' : 'monitor_ambiguous',
                'item_reference' => $reference,
            ]];
        }

        /** @var Monitor $monitor */
        $monitor = $monitors->first();
        $monitorConfig = is_array($monitor->config) ? $monitor->config : [];
        if (! $monitor->is_enabled
            || $monitor->profile === null
            || ! $monitor->profile->is_active
            || ($monitorConfig['provider'] ?? null) !== $this->provider()
            || ($monitorConfig['collection'] ?? null) !== 'device_status') {
            return [null, null, ['code' => 'monitor_unavailable', 'item_reference' => $reference]];
        }

        return [$device, $monitor, null];
    }

    /**
     * @return array{
     *     0: ?Device,
     *     1: ?Monitor,
     *     2: null|array{code: string, item_reference: ?string}
     * }
     */
    private function wanObservationScope(
        IntegrationSiteConfig $siteConfig,
        string $consoleId,
        string $externalSiteId,
    ): array {
        $target = $this->runtime->wanMonitorTargetFor($consoleId, $externalSiteId);
        $reference = hash('sha256', $consoleId.'|'.$externalSiteId);
        $monitors = Monitor::query()
            ->with(['device', 'profile'])
            ->where('kind', MonitorKind::Provider->value)
            ->where('target', $target)
            ->get();
        if ($monitors->isEmpty()) {
            return [null, null, null];
        }
        if ($monitors->count() !== 1) {
            return [null, null, ['code' => 'monitor_ambiguous', 'item_reference' => $reference]];
        }

        /** @var Monitor $monitor */
        $monitor = $monitors->first();
        $device = $monitor->device;
        $config = is_array($monitor->config) ? $monitor->config : [];
        if ($device === null
            || $device->provider !== $this->provider()
            || ! $monitor->is_enabled
            || $monitor->profile === null
            || ! $monitor->profile->is_active
            || ($config['provider'] ?? null) !== $this->provider()
            || ($config['collection'] ?? null) !== 'isp_metrics') {
            return [null, null, ['code' => 'monitor_unavailable', 'item_reference' => $reference]];
        }
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (\Throwable) {
            return [null, null, ['code' => 'site_scope_mismatch', 'item_reference' => $reference]];
        }
        if ($siteId !== (int) $siteConfig->site_id) {
            return [null, null, ['code' => 'site_scope_mismatch', 'item_reference' => $reference]];
        }

        return [$device, $monitor, null];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{
     *     0: null|array<string, mixed>,
     *     1: null|array{code: string, item_reference: ?string}
     * }
     */
    private function wanObservationItem(
        IntegrationSiteConfig $siteConfig,
        Device $device,
        Monitor $monitor,
        string $consoleId,
        string $externalSiteId,
        array $page,
        CarbonImmutable $collectedAt,
        string $cursor,
    ): array {
        $reference = hash('sha256', $consoleId.'|'.$externalSiteId);
        $entries = $page['data'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 10000) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $matches = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $hostId = $this->normalizeRequiredSiteIdentifier($entry['hostId'] ?? null);
            $siteId = $this->normalizeRequiredSiteIdentifier($entry['siteId'] ?? null);
            $metricType = $this->normalizeOptionalProjectedText($entry['metricType'] ?? null, 16);
            if ($hostId === $consoleId && $siteId === $externalSiteId && $metricType === '5m') {
                $matches[] = $entry;
            }
        }
        if ($matches === []) {
            return [null, ['code' => 'wan_metrics_unavailable', 'item_reference' => $reference]];
        }
        if (count($matches) !== 1) {
            return [null, ['code' => 'wan_metrics_ambiguous', 'item_reference' => $reference]];
        }

        $periods = $matches[0]['periods'] ?? null;
        if (! is_array($periods) || ! array_is_list($periods) || $periods === [] || count($periods) > 1000) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $latest = null;
        $seenTimes = [];
        foreach ($periods as $period) {
            if (! is_array($period)
                || ! is_string($period['metricTime'] ?? null)
                || strlen($period['metricTime']) > 64
                || ! is_array(data_get($period, 'data.wan'))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            try {
                $metricTime = CarbonImmutable::parse($period['metricTime'])->utc();
            } catch (\Throwable) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            if ($metricTime->isAfter($collectedAt->addMinutes(5))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $timeKey = $metricTime->toIso8601String();
            if (isset($seenTimes[$timeKey])) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $seenTimes[$timeKey] = true;
            if ($latest === null || $metricTime->isAfter($latest['time'])) {
                $latest = [
                    'time' => $metricTime,
                    'wan' => data_get($period, 'data.wan'),
                ];
            }
        }
        if ($latest === null) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $wan = $latest['wan'];
        $required = [
            'avgLatency',
            'download_kbps',
            'downtime',
            'maxLatency',
            'packetLoss',
            'upload_kbps',
            'uptime',
        ];
        if (! is_array($wan) || array_diff($required, array_keys($wan)) !== []) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $uptime = $this->observationNumber($wan['uptime'], 0, 100);
        $downtime = $this->observationNumber($wan['downtime'], 0, 86400);
        $packetLoss = $this->observationNumber($wan['packetLoss'], 0, 100);
        $averageLatency = $this->observationNumber($wan['avgLatency'], 0, 86400000);
        $maximumLatency = $this->observationNumber($wan['maxLatency'], 0, 86400000);
        $download = $this->observationNumber($wan['download_kbps'], 0, 1000000000000);
        $upload = $this->observationNumber($wan['upload_kbps'], 0, 1000000000000);
        $ispAsn = $this->normalizeOptionalProjectedText($wan['ispAsn'] ?? null, 64);
        $ispName = $this->normalizeOptionalProjectedText($wan['ispName'] ?? null, 255);
        $freshness = max(0, (int) floor($latest['time']->diffInSeconds($collectedAt, false)));
        $metrics = [
            'provider' => $this->provider(),
            'scope' => 'wan',
            'interval' => '5m',
            'uptime_percent' => $uptime,
            'downtime_seconds' => $downtime,
            'packet_loss_percent' => $packetLoss,
            'average_latency_ms' => $averageLatency,
            'maximum_latency_ms' => $maximumLatency,
            'download_kbps' => $download,
            'upload_kbps' => $upload,
        ];
        if ($ispAsn !== null) {
            $metrics['isp_asn'] = $ispAsn;
        }
        if ($ispName !== null) {
            $metrics['isp_name'] = $ispName;
        }
        $metrics['freshness_age_seconds'] = $freshness;
        [$state, $message] = $this->wanObservationState(
            $monitor,
            $uptime,
            $downtime,
            $packetLoss,
            $averageLatency,
            $freshness,
        );
        $sourceFingerprint = hash('sha256', json_encode([
            'host_id' => $consoleId,
            'site_id' => $externalSiteId,
            'metric_time' => $latest['time']->toIso8601String(),
            'metrics' => array_diff_key($metrics, ['freshness_age_seconds' => true]),
        ], JSON_THROW_ON_ERROR));

        return [[
            'cursor' => $cursor,
            'monitor_id' => (int) $monitor->id,
            'device_id' => (int) $device->id,
            'site_id' => (int) $siteConfig->site_id,
            'source_key' => 'unifi:wan:'.$sourceFingerprint,
            'state' => $state->value,
            'observed_at' => $latest['time']->toIso8601String(),
            'value' => $uptime,
            'unit' => 'percent',
            'latency_ms' => (int) round((float) $averageLatency),
            'message' => $message,
            'metrics' => $metrics,
        ], null];
    }

    /** @return array{MonitorState, string} */
    private function wanObservationState(
        Monitor $monitor,
        int|float $uptime,
        int|float $downtime,
        int|float $packetLoss,
        int|float $averageLatency,
        int $freshness,
    ): array {
        $config = is_array($monitor->config) ? $monitor->config : [];
        $failureUptime = $this->observationNumber($config['failure_uptime_percent'] ?? null, 0, 100);
        $failureDowntime = $this->observationNumber($config['failure_downtime_seconds'] ?? null, 1, 86400);
        $warningUptime = $this->observationNumber($config['warning_uptime_percent'] ?? null, 0, 100);
        $warningPacketLoss = $this->observationNumber($config['warning_packet_loss_percent'] ?? null, 0, 100);
        $warningLatency = $this->observationNumber($config['warning_average_latency_ms'] ?? null, 1, 86400000);

        if ($uptime < $failureUptime || $downtime >= $failureDowntime) {
            return [MonitorState::Failed, 'wan_unavailable'];
        }
        if ($freshness > max(1, (int) $monitor->profile->stale_after_seconds)) {
            return [MonitorState::Stale, 'provider_stale'];
        }
        if ($uptime < $warningUptime || $downtime > 0
            || $packetLoss >= $warningPacketLoss
            || $averageLatency >= $warningLatency) {
            return [MonitorState::Degraded, 'wan_degraded'];
        }

        return [MonitorState::Healthy, 'wan_healthy'];
    }

    /** @param null|array<string, mixed> $statistics @return array<string, mixed> */
    private function observationItem(
        IntegrationSiteConfig $siteConfig,
        Device $device,
        Monitor $monitor,
        string $providerEntityId,
        string $connectivity,
        ?array $statistics,
        CarbonImmutable $collectedAt,
        string $cursor,
    ): array {
        $metrics = [
            'provider' => $this->provider(),
            'connectivity' => $connectivity,
            'statistics_available' => $statistics !== null,
        ];
        $heartbeat = null;
        $statisticsMetrics = [];
        if ($statistics !== null) {
            [$heartbeat, $statisticsMetrics] = $this->observationStatistics($statistics, $collectedAt);
        }

        $freshness = $heartbeat === null
            ? null
            : max(0, (int) floor($heartbeat->diffInSeconds($collectedAt, false)));
        if ($freshness !== null) {
            $metrics['freshness_age_seconds'] = $freshness;
        }
        $metrics = [...$metrics, ...$statisticsMetrics];
        [$state, $value, $message] = $this->observationState(
            $connectivity,
            $freshness,
            max(1, (int) $monitor->profile->stale_after_seconds),
        );
        $sourceFingerprint = hash('sha256', json_encode([
            'provider_entity_id' => $providerEntityId,
            'connectivity' => $connectivity,
            'heartbeat' => $heartbeat?->toIso8601String(),
            'metrics' => array_diff_key($metrics, ['freshness_age_seconds' => true]),
        ], JSON_THROW_ON_ERROR));

        return [
            'cursor' => $cursor,
            'monitor_id' => (int) $monitor->id,
            'device_id' => (int) $device->id,
            'site_id' => (int) $siteConfig->site_id,
            'source_key' => 'unifi:health:'.$sourceFingerprint,
            'state' => $state->value,
            'observed_at' => $collectedAt->toIso8601String(),
            'value' => $value,
            'unit' => 'online',
            'latency_ms' => null,
            'message' => $message,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string, mixed>  $statistics
     * @return array{CarbonImmutable, array<string, int|float>}
     */
    private function observationStatistics(array $statistics, CarbonImmutable $collectedAt): array
    {
        $required = [
            'uptimeSec',
            'lastHeartbeatAt',
            'loadAverage1Min',
            'loadAverage5Min',
            'loadAverage15Min',
            'cpuUtilizationPct',
            'memoryUtilizationPct',
            'interfaces',
        ];
        if (array_diff($required, array_keys($statistics)) !== []
            || ! is_array($statistics['interfaces'])
            || ! is_string($statistics['lastHeartbeatAt'])
            || trim($statistics['lastHeartbeatAt']) === ''
            || strlen($statistics['lastHeartbeatAt']) > 64) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        try {
            $heartbeat = CarbonImmutable::parse((string) $statistics['lastHeartbeatAt'])->utc();
            if ($heartbeat->isAfter($collectedAt->addMinutes(5))) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            if (array_key_exists('nextHeartbeatAt', $statistics)) {
                if (! is_string($statistics['nextHeartbeatAt'])
                    || trim($statistics['nextHeartbeatAt']) === ''
                    || strlen($statistics['nextHeartbeatAt']) > 64) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                CarbonImmutable::parse($statistics['nextHeartbeatAt'])->utc();
            }
        } catch (IntegrationDiscoveryException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $metrics = [
            'uptime_seconds' => $this->observationNumber($statistics['uptimeSec'], 0, 3153600000, true),
            'cpu_utilization_percent' => $this->observationNumber($statistics['cpuUtilizationPct'], 0, 100),
            'memory_utilization_percent' => $this->observationNumber($statistics['memoryUtilizationPct'], 0, 100),
            'load_average_1m' => $this->observationNumber($statistics['loadAverage1Min'], 0, 1000000),
            'load_average_5m' => $this->observationNumber($statistics['loadAverage5Min'], 0, 1000000),
            'load_average_15m' => $this->observationNumber($statistics['loadAverage15Min'], 0, 1000000),
        ];
        if (array_key_exists('uplink', $statistics)) {
            $uplink = $statistics['uplink'];
            if (! is_array($uplink)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            if (array_key_exists('txRateBps', $uplink)) {
                $metrics['uplink_tx_bps'] = $this->observationNumber($uplink['txRateBps'], 0, 1000000000000000);
            }
            if (array_key_exists('rxRateBps', $uplink)) {
                $metrics['uplink_rx_bps'] = $this->observationNumber($uplink['rxRateBps'], 0, 1000000000000000);
            }
        }

        return [$heartbeat, $metrics];
    }

    private function observationNumber(
        mixed $value,
        int|float $minimum,
        int|float $maximum,
        bool $integer = false,
    ): int|float {
        if ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || $value < $minimum
            || $value > $maximum
            || ($integer && ! is_int($value))) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $value;
    }

    /** @return array{MonitorState, null|int, string} */
    private function observationState(string $connectivity, ?int $freshness, int $staleAfter): array
    {
        if (in_array($connectivity, ['offline', 'connection_interrupted', 'isolated'], true)) {
            return [MonitorState::Failed, 0, 'provider_offline'];
        }
        if ($connectivity === 'online' && $freshness !== null && $freshness > $staleAfter) {
            return [MonitorState::Stale, null, 'provider_stale'];
        }
        if ($connectivity === 'online') {
            return [MonitorState::Healthy, 1, 'provider_online'];
        }
        if (in_array($connectivity, [
            'pending_adoption',
            'updating',
            'getting_ready',
            'adopting',
            'deleting',
        ], true)) {
            return [MonitorState::Unknown, null, 'provider_state_transitional'];
        }

        return [MonitorState::Unknown, null, 'provider_status_unknown'];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array
    {
        $page = $this->collectEvents(
            $siteConfig,
            $providerConnection,
            $since ? CarbonImmutable::instance($since)->utc()->toIso8601String() : null,
            200,
        );

        return array_map(static function (array $event): array {
            $normalized = $event['normalized_payload'];

            return [
                'source_event_id' => $event['source_event_id'],
                'occurred_at' => Carbon::parse($event['occurred_at']),
                'event_type' => $event['event_type'],
                'summary' => $normalized['summary'] ?? null,
                'door_name' => $normalized['door_name'] ?? null,
                'user_name' => $normalized['actor_name'] ?? null,
                'direction' => $normalized['direction'] ?? null,
            ];
        }, $page->items);
    }

    public function collectEvents(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderEventPage {
        if ($siteConfig->provider !== $this->provider()
            || $providerConnection->provider !== $this->provider()
            || $limit < 1
            || $limit > 200) {
            throw new \InvalidArgumentException('UniFi Access event request is invalid.');
        }

        $accessSecret = IntegrationSiteSecret::query()
            ->where('site_id', $siteConfig->site_id)
            ->where('provider', $this->provider())
            ->where('capability', 'access_api')
            ->where('is_enabled', true)
            ->first();

        if (! $accessSecret || empty($accessSecret->base_url)) {
            throw new \RuntimeException('UniFi Access API credentials are unavailable for this Site.');
        }

        try {
            [$since, $until, $pageNumber] = $this->accessEventWindow($cursor);
            $apiKey = $this->secrets->site($accessSecret, 'api_key');
            $baseUrl = $this->accessBaseUrl($accessSecret->base_url);
            $url = $baseUrl.'/api/v1/developer/system/logs?'.http_build_query([
                'page_size' => $limit,
                'page_num' => $pageNumber,
            ], '', '&', PHP_QUERY_RFC3986);
            $response = $this->providerRequest()
                ->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($url, [
                    'topic' => 'door_openings',
                    'since' => $since->timestamp,
                    'until' => $until->timestamp,
                ]);

            if ($response->status() === 429) {
                return new ProviderEventPage(
                    items: [],
                    nextCursor: $this->encodeAccessEventCursor($since, $until, $pageNumber),
                    partial: true,
                    retryAfterSeconds: $this->accessRetryAfter($response->header('Retry-After')),
                );
            }
            if (! $response->successful()) {
                throw IntegrationDiscoveryException::forHttpStatus($response->status());
            }

            $payload = $response->json();
            $data = is_array($payload) ? ($payload['data'] ?? null) : null;
            $hits = is_array($data) ? ($data['hits'] ?? null) : null;
            $reportedPage = is_array($data) ? ($data['page'] ?? null) : null;
            $total = is_array($data) ? ($data['total'] ?? null) : null;
            if (($payload['code'] ?? null) !== 'SUCCESS'
                || ! is_array($data)
                || ! is_array($hits)
                || ! array_is_list($hits)
                || count($hits) > $limit
                || ! is_numeric($reportedPage)
                || (int) $reportedPage !== $pageNumber
                || ! is_numeric($total)
                || (int) $total < 0
                || (int) $total > self::ACCESS_EVENT_MAX_TOTAL
                || ($hits === [] && $pageNumber * $limit < (int) $total)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }

            $items = [];
            foreach ($hits as $hit) {
                if (! is_array($hit)) {
                    throw IntegrationDiscoveryException::invalidResponse();
                }
                $items[] = $this->normalizeAccessLogEntry($hit, (int) $siteConfig->site_id);
            }

            $nextCursor = $pageNumber * $limit < (int) $total
                ? $this->encodeAccessEventCursor($since, $until, $pageNumber + 1)
                : $until->toIso8601String();

            return new ProviderEventPage(items: $items, nextCursor: $nextCursor);
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $failure = IntegrationDiscoveryException::fromThrowable($exception);
            Log::warning('UniFi Access event collection failed', SafeOperationalData::logContext([
                'site_id' => $siteConfig->site_id,
                'provider_connection_id' => $providerConnection->id,
                'error_category' => $failure->failureCategory(),
            ]));

            throw $failure;
        }
    }

    /* ---------------------------------------------------------------
     * Private helpers
     * ------------------------------------------------------------- */

    private function topologyOffset(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/^(0|[1-9]\d{0,9})$/', $cursor) !== 1) {
            throw new \InvalidArgumentException('UniFi topology cursor is invalid.');
        }
        $offset = (int) $cursor;
        if ($offset > self::TOPOLOGY_CURSOR_MAX) {
            throw new \InvalidArgumentException('UniFi topology cursor is invalid.');
        }

        return $offset;
    }

    private function boundedTopologyInteger(mixed $value, int $maximum): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/^(0|[1-9]\d*)$/', (string) $value) !== 1) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $normalized = (int) $value;
        if ($normalized < 0 || $normalized > $maximum) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $normalized;
    }

    private function deferredTopologyPage(mixed $retryAfter): ProviderTopologyPage
    {
        $seconds = (is_int($retryAfter) || is_string($retryAfter))
            && preg_match('/^[1-9]\d{0,4}$/', (string) $retryAfter) === 1
            ? min(86400, (int) $retryAfter)
            : 60;

        return new ProviderTopologyPage(
            nodes: [],
            edges: [],
            partial: true,
            retryAfterSeconds: $seconds,
        );
    }

    /** @param array<string, mixed> $device @return array<string, mixed> */
    private function unifiTopologyNode(string $deviceId, array $device = []): array
    {
        $identity = [
            'provider' => $this->provider(),
            'provider_id' => $deviceId,
        ];
        $mac = $this->optionalTopologyMac($device['macAddress'] ?? null);
        if ($mac !== null) {
            $identity['mac_addresses'] = [$mac];
        }
        $address = $this->optionalTopologyAddress($device['ipAddress'] ?? null);
        if ($address !== null) {
            $identity['addresses'] = [$address];
        }
        $hostname = $this->optionalTopologyText($device['name'] ?? null, 255);
        if ($hostname !== null) {
            $identity['hostname'] = $hostname;
        }

        return [
            'key' => $this->unifiTopologyNodeKey($deviceId),
            'identity' => $identity,
        ];
    }

    private function unifiTopologyNodeKey(string $deviceId): string
    {
        return 'device:'.hash('sha256', $deviceId);
    }

    private function optionalTopologyMac(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $hex = strtolower((string) preg_replace('/[^a-fA-F0-9]/', '', trim($value)));
        if (strlen($hex) !== 12) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return implode(':', str_split($hex, 2));
    }

    private function optionalTopologyAddress(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return strtolower($value);
    }

    private function optionalTopologyText(mixed $value, int $maximum): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        return $value;
    }

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

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int} */
    private function accessEventWindow(?string $cursor): array
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($cursor === null) {
            return [$now->subSeconds(self::ACCESS_EVENT_DEFAULT_LOOKBACK_SECONDS), $now, 1];
        }

        if (str_starts_with($cursor, self::ACCESS_EVENT_CURSOR_PREFIX)) {
            $encoded = substr($cursor, strlen(self::ACCESS_EVENT_CURSOR_PREFIX));
            if ($encoded === '' || strlen($encoded) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
                throw new \InvalidArgumentException('UniFi Access event cursor is invalid.');
            }
            $padding = (4 - strlen($encoded) % 4) % 4;
            $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
            try {
                $state = is_string($decoded)
                    ? json_decode($decoded, true, 8, JSON_THROW_ON_ERROR)
                    : null;
            } catch (\JsonException) {
                $state = null;
            }
            if (! is_array($state)
                || array_keys($state) !== ['since', 'until', 'page']
                || ! is_int($state['since'])
                || ! is_int($state['until'])
                || ! is_int($state['page'])) {
                throw new \InvalidArgumentException('UniFi Access event cursor is invalid.');
            }
            $since = CarbonImmutable::createFromTimestampUTC($state['since']);
            $until = CarbonImmutable::createFromTimestampUTC($state['until']);
            $page = $state['page'];
        } else {
            try {
                $since = CarbonImmutable::parse($cursor)->utc()->startOfSecond();
            } catch (\Throwable $exception) {
                throw new \InvalidArgumentException('UniFi Access event cursor is invalid.', previous: $exception);
            }
            $until = $now;
            $page = 1;
        }

        if ($page < 1
            || $page > self::ACCESS_EVENT_MAX_PAGE
            || $since->timestamp > $until->timestamp
            || $until->timestamp > $now->addMinutes(5)->timestamp
            || $until->timestamp - $since->timestamp > self::ACCESS_EVENT_MAX_WINDOW_SECONDS) {
            throw new \InvalidArgumentException('UniFi Access event cursor is invalid.');
        }

        return [$since, $until, $page];
    }

    private function encodeAccessEventCursor(CarbonImmutable $since, CarbonImmutable $until, int $page): string
    {
        $json = json_encode([
            'since' => $since->timestamp,
            'until' => $until->timestamp,
            'page' => $page,
        ], JSON_THROW_ON_ERROR);

        return self::ACCESS_EVENT_CURSOR_PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function accessBaseUrl(mixed $candidate): string
    {
        $parts = is_string($candidate) ? parse_url($candidate) : false;
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('UniFi Access endpoint configuration is invalid.');
        }

        return rtrim($candidate, '/');
    }

    /** @param array<string, string> $headers */
    private function providerRequest(array $headers = []): PendingRequest
    {
        return $this->transport->request($headers);
    }

    private function accessRetryAfter(mixed $value): int
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9]\d{0,4}$/', (string) $value) === 1
            ? min(86400, (int) $value)
            : 60;
    }

    /** @return array<string, mixed> */
    private function normalizeAccessLogEntry(array $entry, int $siteId): array
    {
        $source = $entry['_source'] ?? null;
        $event = is_array($source) ? ($source['event'] ?? null) : null;
        $targets = is_array($source) ? ($source['target'] ?? []) : null;
        if (! is_array($source)
            || ! is_array($event)
            || ! is_array($targets)
            || ! array_is_list($targets)
            || count($targets) > 50) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $occurredAt = $this->parseAccessTimestamp($event['published'] ?? ($entry['@timestamp'] ?? null));
        $providerType = $this->normalizeOptionalProjectedText($event['type'] ?? null, 255);
        if ($occurredAt === null || $providerType === null) {
            throw IntegrationDiscoveryException::invalidResponse();
        }

        $actor = $source['actor'] ?? null;
        if ($actor !== null && ! is_array($actor)) {
            throw IntegrationDiscoveryException::invalidResponse();
        }
        $actorName = is_array($actor)
            ? $this->normalizeOptionalProjectedText($actor['display_name'] ?? null, 255)
            : null;
        $doorName = null;
        foreach ($targets as $target) {
            if (! is_array($target)) {
                throw IntegrationDiscoveryException::invalidResponse();
            }
            $targetType = $this->normalizeOptionalProjectedText($target['type'] ?? null, 64);
            if (strtolower((string) $targetType) === 'door') {
                $doorName = $this->normalizeOptionalProjectedText($target['display_name'] ?? null, 255);
                break;
            }
        }

        $result = $this->normalizeOptionalProjectedText($event['result'] ?? null, 64);
        $direction = $this->normalizeOptionalProjectedText(
            data_get($source, 'device_config.door_entry_method') ?? ($event['reason'] ?? null),
            64,
        );
        $displayMessage = $this->normalizeOptionalProjectedText($event['display_message'] ?? null, 512);
        $eventType = $this->accessEventType($providerType, $result, $displayMessage);
        $summary = $displayMessage ?? $this->formatAccessSummary($actorName, $doorName, $direction);
        $encoded = json_encode($entry, JSON_THROW_ON_ERROR);
        $providerId = $this->normalizeOptionalProjectedText($entry['_id'] ?? null, 255) ?? hash('sha256', $encoded);

        return [
            'site_id' => $siteId,
            'provider' => $this->provider(),
            'source_app' => 'access',
            'source_event_id' => 'access-log-'.hash('sha256', $siteId.'|'.$providerId),
            'occurred_at' => $occurredAt->utc()->toIso8601String(),
            'severity' => $this->accessEventSeverity($eventType, $result),
            'event_type' => $eventType,
            'normalized_payload' => array_filter([
                'summary' => $summary,
                'door_name' => $doorName,
                'actor_name' => $actorName,
                'direction' => $direction,
                'result' => $result,
                'provider_event_type' => $providerType,
            ], static fn (mixed $value): bool => $value !== null),
            'body_hash' => hash('sha256', $encoded),
        ];
    }

    private function accessEventType(string $providerType, ?string $result, ?string $message): string
    {
        $haystack = strtolower($providerType.' '.($result ?? '').' '.($message ?? ''));
        if (str_contains($haystack, 'forced') || str_contains($haystack, 'unauthorized open')) {
            return 'door_forced_open';
        }
        if (preg_match('/\b(blocked|denied|reject|failed|failure)\b/', $haystack) === 1) {
            return 'door_access_denied';
        }
        if ($providerType === 'access.door.unlock') {
            return 'door_opened';
        }

        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($providerType)), '_');

        return $normalized !== '' ? mb_substr($normalized, 0, 255) : 'access_event';
    }

    private function accessEventSeverity(string $eventType, ?string $result): string
    {
        if ($eventType === 'door_forced_open') {
            return 'critical';
        }

        return $eventType === 'door_access_denied'
            || in_array(strtoupper((string) $result), ['BLOCKED', 'DENIED', 'FAILED', 'FAILURE'], true)
            ? 'warn'
            : 'info';
    }

    private function formatAccessSummary(?string $userName, ?string $doorName, ?string $direction): string
    {
        $who = $userName ?: 'Unknown user';
        $where = $doorName ? " at {$doorName}" : '';
        $action = $direction ? strtoupper($direction) : 'ACCESS';

        return "{$action}: {$who}{$where}";
    }

    private function parseAccessTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $numeric = (int) $value;

                return $numeric > 1000000000000
                    ? CarbonImmutable::createFromTimestampMs($numeric, 'UTC')
                    : CarbonImmutable::createFromTimestampUTC($numeric);
            }

            return is_string($value) && strlen($value) <= 64
                ? CarbonImmutable::parse($value)->utc()
                : null;
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
