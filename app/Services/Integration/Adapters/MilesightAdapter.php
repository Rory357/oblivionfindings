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
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use App\Services\Integration\Data\ProviderObservationPage;
use App\Services\Integration\Data\ProviderWebhookRequest;
use App\Services\Integration\Data\VerifiedProviderEvent;
use App\Services\Integration\Data\VerifiedProviderEventBatch;
use App\Services\Integration\Data\VerifiedWebhookBinding;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\Integration\Exceptions\WebhookRejected;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use App\Services\Integration\MilesightOperationalBridgeService;
use App\Services\Integration\SyncResult;
use App\Support\SafeOperationalData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Milesight integration adapter.
 *
 * Primary focus is the LoRaWAN Cloud + Development Platform. Milesight IP
 * cameras and on-prem gateway bridges are later phases.
 */
class MilesightAdapter implements ConnectionHealthCapability, DeviceSyncCapability, IntegrationAdapterInterface, InventoryDiscoveryCapability, ObservationCollectionCapability, WebhookVerificationCapability
{
    public const PROVIDER_SLUG = 'milesight';

    /**
     * Default base URL for the Milesight Development Platform.
     * Operators can override via IntegrationProviderConnection.config['base_url']
     * only when its exact host is in the deployment allowlist.
     */
    private const DEFAULT_BASE_URL = 'https://mdp-api.milesight.com';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 100;

    private const OBSERVATION_CURSOR_PREFIX = 'milesight-status-v1:';

    public function __construct(
        private readonly MilesightOperationalBridgeService $bridge,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly RuntimeEnvelopeCodec $codec,
        private readonly IntegrationSecretMaterialService $secrets,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER_SLUG;
    }

    public function capabilities(): array
    {
        return [
            ConnectionHealthCapability::class,
            InventoryDiscoveryCapability::class,
            DeviceSyncCapability::class,
            ObservationCollectionCapability::class,
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
                IntegrationSecretManager::PURPOSE_WEBHOOK,
                'webhook_secret',
            );
        } catch (\Throwable) {
            throw new WebhookRejected('credentials_unavailable');
        }
        if (! is_string($secret) || strlen($secret) < 16 || strlen($secret) > 4096) {
            throw new WebhookRejected('credentials_unavailable');
        }

        $signature = $request->header('X-Msc-Request-Signature');
        $webhookUuid = $request->header('X-Msc-Webhook-Uuid');
        $timestamp = $request->header('X-Msc-Request-Timestamp');
        $nonce = $request->header('X-Msc-Request-Nonce');
        if (! is_string($signature) || preg_match('/^[a-f0-9]{64}$/i', $signature) !== 1
            || ! is_string($webhookUuid) || preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $webhookUuid) !== 1
            || ! is_string($timestamp) || preg_match('/^\d{10}$/', $timestamp) !== 1
            || abs($request->receivedAt->timestamp - (int) $timestamp) > 60
            || ! is_string($nonce) || preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $nonce) !== 1) {
            throw new WebhookRejected('authentication');
        }

        $expected = hash_hmac('sha256', $timestamp.$nonce, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new WebhookRejected('signature');
        }

        try {
            $decoded = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookRejected('payload', 422);
        }
        if (! is_array($decoded)) {
            throw new WebhookRejected('payload', 422);
        }

        $items = array_is_list($decoded) ? $decoded : [$decoded];
        if ($items === [] || count($items) > 100) {
            throw new WebhookRejected('payload', 422);
        }

        $events = [];
        $ignoredCount = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new WebhookRejected('payload', 422);
            }

            $providerEventType = strtoupper((string) $this->boundedWebhookText($item['eventType'] ?? null, 64));
            if (in_array($providerEventType, ['TASK_DATA', 'WEBHOOK_TEST', 'SYSTEM_MESSAGES'], true)) {
                $ignoredCount++;

                continue;
            }
            if ($providerEventType !== 'DEVICE_DATA') {
                throw new WebhookRejected('payload', 422);
            }

            $events[] = $this->verifiedDeviceEvent($item, $request);
        }

        $sourceIdentities = array_map(
            static fn (VerifiedProviderEvent $event): string => $event->sourceEventId,
            $events,
        );
        if (count($sourceIdentities) !== count(array_unique($sourceIdentities))) {
            throw new WebhookRejected('payload', 422);
        }

        $this->reserveWebhookNonce($connection, $webhookUuid, $nonce);

        return new VerifiedProviderEventBatch(
            events: $events,
            ignoredCount: $ignoredCount,
            acknowledgementStatus: 200,
        );
    }

    /** @param array<string, mixed> $item */
    private function verifiedDeviceEvent(array $item, ProviderWebhookRequest $request): VerifiedProviderEvent
    {
        $sourceEventId = $this->boundedWebhookText($item['eventID'] ?? $item['eventId'] ?? null, 255);
        $data = $item['data'] ?? null;
        $profile = is_array($data) ? ($data['deviceProfile'] ?? null) : null;
        $providerDeviceId = is_array($profile)
            ? $this->boundedWebhookText($profile['deviceId'] ?? null, 255)
            : null;
        $reportedType = is_array($data)
            ? strtoupper((string) $this->boundedWebhookText($data['type'] ?? null, 32))
            : '';
        if (! is_array($data) || ! is_array($profile) || $providerDeviceId === null
            || ! in_array($reportedType, ['ONLINE', 'OFFLINE', 'PROPERTY', 'EVENT', 'SERVICE'], true)) {
            throw new WebhookRejected('payload', 422);
        }

        $devices = Device::query()
            ->byProvider(self::PROVIDER_SLUG)
            ->where('external_ref->provider_entity_id', $providerDeviceId)
            ->limit(2)
            ->get();
        if ($devices->count() !== 1) {
            throw new WebhookRejected('site_identity', 404);
        }
        $device = $devices->sole();
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (\Throwable) {
            throw new WebhookRejected('site_identity', 404);
        }

        $externalRef = is_array($device->external_ref) ? $device->external_ref : [];
        $applicationId = $this->boundedWebhookText($externalRef['application_id'] ?? null, 255);
        $siteConfigs = $applicationId === null ? collect() : IntegrationSiteConfig::query()
            ->forProvider(self::PROVIDER_SLUG)
            ->active()
            ->where('site_id', $siteId)
            ->where('mapped_external_site_id', $applicationId)
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

        $reportedValues = $this->safeReportedValues($data['payload'] ?? []);
        $tslId = $this->boundedWebhookText($data['tslID'] ?? $data['tslId'] ?? null, 100);
        [$eventType, $severity] = $this->classifyReportedEvent($reportedType, $tslId, $reportedValues);
        $occurredAt = $this->milesightOccurredAt(
            $data['ts'] ?? $item['eventCreatedTime'] ?? null,
            $request->receivedAt,
        );

        try {
            $eventHash = hash('sha256', $this->codec->canonicalPayloadBytes($item));
        } catch (\UnexpectedValueException) {
            throw new WebhookRejected('payload', 422);
        }
        if ($sourceEventId === null) {
            $sourceEventId = 'oblivion-fallback-v1:'.hash('sha256', self::PROVIDER_SLUG.'|'.$eventHash);
        } elseif (str_starts_with($sourceEventId, 'oblivion-fallback-v1:')) {
            throw new WebhookRejected('payload', 422);
        }

        $deviceName = mb_substr((string) $device->name, 0, 255);

        return new VerifiedProviderEvent(
            siteId: $siteId,
            sourceApp: 'milesight-development-platform',
            sourceEventId: $sourceEventId,
            occurredAt: $occurredAt,
            severity: $severity,
            eventType: $eventType,
            normalizedPayload: array_filter([
                'summary' => mb_substr($deviceName.' reported '.str_replace('_', ' ', $eventType), 0, 1000),
                'device' => $deviceName,
                'canonical_device_id' => (int) $device->id,
                'reported_type' => strtolower($reportedType),
                'tsl_id' => $tslId,
                'reported_values' => $reportedValues === [] ? null : $reportedValues,
            ], static fn (mixed $value): bool => $value !== null),
            bodyHash: $eventHash,
            binding: new VerifiedWebhookBinding(
                siteConfigId: (int) $siteConfig->id,
                externalSiteId: $applicationId,
                canonicalDeviceId: (int) $device->id,
                providerEntityId: $providerDeviceId,
            ),
        );
    }

    /** @param array<string|int, mixed> $values @return array<string|int, mixed> */
    private function safeReportedValues(array $values, int $depth = 0): array
    {
        if ($depth > 3 || count($values) > 50) {
            throw new WebhookRejected('payload', 422);
        }

        $safe = [];
        foreach ($values as $key => $value) {
            if (is_string($key)
                && (strlen($key) > 64
                    || preg_match('/password|secret|token|credential|authorization|cookie|^raw_/i', $key) === 1)) {
                throw new WebhookRejected('payload', 422);
            }

            if (is_array($value)) {
                $safe[$key] = $this->safeReportedValues($value, $depth + 1);
            } elseif ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value) && mb_strlen($value) <= 512) {
                $safe[$key] = $value;
            } else {
                throw new WebhookRejected('payload', 422);
            }
        }

        return $safe;
    }

    /** @param array<string|int, mixed> $reportedValues @return array{string, string} */
    private function classifyReportedEvent(string $reportedType, ?string $tslId, array $reportedValues): array
    {
        if ($reportedType === 'OFFLINE') {
            return ['device_offline', 'warn'];
        }
        if ($reportedType === 'ONLINE') {
            return ['device_online', 'info'];
        }
        if ($reportedType === 'PROPERTY') {
            return ['sensor_property_report', 'info'];
        }
        if ($reportedType === 'SERVICE') {
            return ['device_service_report', 'info'];
        }

        $candidate = $tslId
            ?? $this->boundedWebhookText($reportedValues['alarm'] ?? $reportedValues['event'] ?? $reportedValues['type'] ?? null, 100)
            ?? 'device_event';
        $identifier = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $candidate), '_'));
        $identifier = mb_substr($identifier !== '' ? $identifier : 'device_event', 0, 100);
        $eventType = match ($identifier) {
            'fall', 'fall_alarm' => 'fall_detected',
            'bed_exited', 'bed_exit_alarm' => 'bed_exit',
            'leak', 'leak_detected' => 'water_leak',
            'low_battery', 'low_battery_alarm' => 'battery_low',
            'panic', 'panic_button' => 'panic_alarm',
            'sos' => 'sos_triggered',
            default => $identifier,
        };
        $severity = preg_match('/fall|bed_exit|panic|sos|emergency|duress/', $eventType) === 1
            ? 'critical'
            : (preg_match('/leak|battery|tamper|alarm|offline|fault|failure/', $eventType) === 1 ? 'warn' : 'info');

        return [$eventType, $severity];
    }

    private function milesightOccurredAt(mixed $value, CarbonImmutable $receivedAt): CarbonImmutable
    {
        if (! is_numeric($value)) {
            throw new WebhookRejected('payload', 422);
        }

        $numeric = (float) $value;
        $seconds = $numeric > 1_000_000_000_000 ? $numeric / 1000 : $numeric;
        try {
            $occurredAt = CarbonImmutable::createFromTimestamp($seconds, 'UTC');
        } catch (\Throwable) {
            throw new WebhookRejected('payload', 422);
        }
        if ($occurredAt->lt($receivedAt->subSeconds(
            (int) config('integration-capabilities.webhook.maximum_event_age_seconds', 86400),
        )) || $occurredAt->gt($receivedAt->addSeconds(
            (int) config('integration-capabilities.webhook.maximum_skew_seconds', 300),
        ))) {
            throw new WebhookRejected('payload', 422);
        }

        return $occurredAt;
    }

    private function reserveWebhookNonce(
        IntegrationProviderConnection $connection,
        string $webhookUuid,
        string $nonce,
    ): void {
        $replayStore = (string) (config('integration-capabilities.webhook.replay_store') ?: config('cache.default'));
        $replayDriver = config("cache.stores.{$replayStore}.driver");
        $allowLocalReplay = app()->environment('testing')
            && (bool) config('integration-capabilities.webhook.allow_local_replay_store_for_tests', false);
        if ($replayDriver !== 'redis' && ! $allowLocalReplay) {
            throw new WebhookRejected('replay_store_unavailable', 503);
        }

        $cacheKey = 'monitoring:milesight-webhook-replay:'.hash(
            'sha256',
            $connection->id.':'.$webhookUuid.':'.$nonce,
        );
        try {
            $reserved = Cache::store($replayStore)
                ->add($cacheKey, true, (int) config('integration-capabilities.webhook.replay_ttl_seconds', 600));
        } catch (\Throwable) {
            throw new WebhookRejected('replay_store_unavailable', 503);
        }

        if (! $reserved) {
            throw new WebhookRejected('replay');
        }
    }

    private function boundedWebhookText(mixed $value, int $maximum): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' && mb_strlen($text) <= $maximum ? $text : null;
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        try {
            return $this->accessToken($connection) !== null;
        } catch (\Throwable $e) {
            Log::info('Milesight testConnection failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return false;
        }
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        $applications = [];

        foreach ($this->inventory($connection) as $device) {
            $externalId = $this->scalarString(data_get($device, 'application.applicationId'));
            if ($externalId === null) {
                continue;
            }
            if (mb_strlen($externalId) > 255) {
                throw new \RuntimeException('Milesight application identity was invalid.');
            }

            $name = $this->scalarString(data_get($device, 'application.applicationName'))
                ?? 'Milesight application '.substr($externalId, -8);
            $name = mb_substr($name, 0, 255);
            if (! isset($applications[$externalId])) {
                $applications[$externalId] = [
                    'external_id' => $externalId,
                    'name' => $name,
                    'type' => 'application',
                    'meta' => ['device_count' => 0],
                ];
            }
            $applications[$externalId]['meta']['device_count']++;
        }

        return collect($applications)
            ->sortBy(fn (array $application): string => strtolower($application['name']))
            ->values()
            ->all();
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        $mappedApplicationId = $this->scalarString($siteConfig->mapped_external_site_id);
        if ($mappedApplicationId === null) {
            return new SyncResult(error: 'Milesight application mapping is required before device sync.');
        }

        $result = new SyncResult;

        try {
            foreach ($this->inventory($providerConnection) as $payload) {
                if ($this->scalarString(data_get($payload, 'application.applicationId')) !== $mappedApplicationId) {
                    continue;
                }

                $result->processed++;

                try {
                    if (! $this->deviceSyncScopeStillUsable(
                        (int) $providerConnection->id,
                        (int) $siteConfig->id,
                        (int) $siteConfig->site_id,
                        $mappedApplicationId,
                    )) {
                        $result->error = SafeOperationalData::failureSummary();

                        return $result;
                    }
                    $synced = $this->bridge->syncInventoryDevice($siteConfig, $payload);
                    $synced['created'] ? $result->created++ : $result->updated++;
                } catch (\Throwable $e) {
                    $result->errored++;
                    Log::warning('Milesight inventory item could not be reconciled', SafeOperationalData::logContext([
                        'site_id' => $siteConfig->site_id,
                        'error_category' => SafeOperationalData::failureCategory($e),
                    ]));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Milesight inventory sync failed', SafeOperationalData::logContext([
                'site_id' => $siteConfig->site_id,
                'provider_connection_id' => $providerConnection->id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            $result->error = SafeOperationalData::failureSummary();
        }

        return $result;
    }

    private function deviceSyncScopeStillUsable(
        int $connectionId,
        int $siteConfigId,
        int $siteId,
        string $mappedApplicationId,
    ): bool {
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
                ->where('mapped_external_site_id', $mappedApplicationId)
                ->whereRaw("TRIM(`mapped_external_site_id`) <> ''")
                ->whereHas('site', fn ($site) => $site
                    ->where('is_active', true)
                    ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                    ->whereNull('archived_at'))
                ->exists();
    }

    public function collectObservations(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderObservationPage {
        $mappedApplicationId = $this->scalarString($siteConfig->mapped_external_site_id);
        if ($siteConfig->provider !== $this->provider()
            || $providerConnection->provider !== $this->provider()
            || $mappedApplicationId === null
            || mb_strlen($mappedApplicationId) > 255
            || $limit < 1
            || $limit > self::PAGE_SIZE) {
            throw new \InvalidArgumentException('Milesight observation request is invalid.');
        }
        $offset = $this->observationOffset($cursor);

        try {
            $payloads = array_values(array_filter(
                iterator_to_array($this->inventory($providerConnection), false),
                fn (array $payload): bool => $this->scalarString(
                    data_get($payload, 'application.applicationId'),
                ) === $mappedApplicationId,
            ));
        } catch (ProviderRateLimited $exception) {
            return new ProviderObservationPage(
                items: [],
                nextCursor: $cursor,
                partial: true,
                retryAfterSeconds: $exception->retryAfterSeconds,
                exceptions: [[
                    'code' => 'provider_rate_limited',
                    'item_reference' => null,
                ]],
            );
        }

        usort($payloads, fn (array $left, array $right): int => strcmp(
            $this->sortableObservationIdentity($left),
            $this->sortableObservationIdentity($right),
        ));

        $total = count($payloads);
        if ($total === 0) {
            return new ProviderObservationPage(
                items: [],
                nextCursor: self::OBSERVATION_CURSOR_PREFIX.'0',
            );
        }
        if ($offset >= $total) {
            $offset = 0;
        }

        $items = [];
        $exceptions = [];
        $collectedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $slice = array_slice($payloads, $offset, $limit);
        $nextOffset = $offset;

        foreach ($slice as $relativeIndex => $payload) {
            $absoluteIndex = $offset + $relativeIndex;
            $candidateNextOffset = $absoluteIndex + 1 >= $total ? 0 : $absoluteIndex + 1;
            $itemCursor = self::OBSERVATION_CURSOR_PREFIX.$candidateNextOffset;
            [$item, $exception] = $this->observationItem(
                $siteConfig,
                $payload,
                $collectedAt,
                $itemCursor,
            );

            if ($exception !== null && count($exceptions) < 100) {
                $exceptions[] = $exception;

                break;
            }
            if ($item !== null) {
                $items[] = $item;
                $nextOffset = $candidateNextOffset;
            }
        }

        return new ProviderObservationPage(
            items: $items,
            nextCursor: self::OBSERVATION_CURSOR_PREFIX.$nextOffset,
            partial: $exceptions !== [],
            exceptions: $exceptions,
        );
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array
    {
        return [];
    }

    private function resolveBaseUrl(IntegrationProviderConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $candidate = trim((string) ($config['base_url'] ?? self::DEFAULT_BASE_URL));

        $parts = parse_url($candidate);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $allowedHosts = array_values(array_filter(
            config('integration-capabilities.milesight.allowed_hosts', []),
            static fn (mixed $allowedHost): bool => is_string($allowedHost) && $allowedHost !== '',
        ));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1
            || ! in_array($host, $allowedHosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            throw new \RuntimeException('Milesight endpoint configuration is invalid.');
        }

        return 'https://'.$host;
    }

    private function accessToken(IntegrationProviderConnection $connection): ?string
    {
        $baseUrl = $this->resolveBaseUrl($connection);
        $clientSecret = $this->providerSecret($connection);
        $config = is_array($connection->config) ? $connection->config : [];
        $clientId = $this->scalarString($config['client_id'] ?? null);
        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        $response = $this->providerRequest()
            ->asForm()
            ->acceptJson()
            ->timeout(10)
            ->post($baseUrl.'/oauth/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited($this->retryAfterSeconds($response->header('Retry-After')));
        }

        if (! $response->successful()) {
            return null;
        }

        $accessToken = $this->scalarString($response->json('data.access_token'));

        return $accessToken !== null && strlen($accessToken) <= 16_384 ? $accessToken : null;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function inventory(IntegrationProviderConnection $connection): \Generator
    {
        $accessToken = $this->accessToken($connection);
        if ($accessToken === null) {
            throw new \RuntimeException('Milesight authentication failed.');
        }

        $baseUrl = $this->resolveBaseUrl($connection);
        $pageNumber = 1;

        while ($pageNumber <= self::MAX_PAGES) {
            $response = $this->providerRequest()
                ->withToken($accessToken)
                ->acceptJson()
                ->timeout(20)
                ->post($baseUrl.'/device/openapi/v1/devices/search', [
                    'pageSize' => self::PAGE_SIZE,
                    'pageNumber' => $pageNumber,
                ]);

            if ($response->status() === 429) {
                throw new ProviderRateLimited($this->retryAfterSeconds($response->header('Retry-After')));
            }

            if (! $response->successful()) {
                throw new \RuntimeException('Milesight inventory request failed.');
            }

            $page = $response->json('data');
            $content = is_array($page) ? ($page['content'] ?? null) : null;
            $total = is_array($page) ? ($page['total'] ?? null) : null;
            $returnedPage = is_array($page) ? ($page['pageNumber'] ?? null) : null;
            if (! is_array($content)
                || ! array_is_list($content)
                || count($content) > self::PAGE_SIZE
                || ! is_numeric($total)
                || (int) $total < 0
                || (int) $total > self::PAGE_SIZE * self::MAX_PAGES
                || ($returnedPage !== null && (! is_numeric($returnedPage) || (int) $returnedPage !== $pageNumber))) {
                throw new \RuntimeException('Milesight inventory response was invalid.');
            }

            foreach ($content as $item) {
                if (! is_array($item)) {
                    throw new \RuntimeException('Milesight inventory item was invalid.');
                }

                yield $item;
            }

            if ($content === [] || $pageNumber * self::PAGE_SIZE >= (int) $total) {
                return;
            }

            $pageNumber++;
        }

        throw new \RuntimeException('Milesight inventory exceeded the bounded pagination limit.');
    }

    private function observationOffset(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $pattern = '/^'.preg_quote(self::OBSERVATION_CURSOR_PREFIX, '/').'(0|[1-9]\d{0,4})$/';
        if (preg_match($pattern, $cursor, $matches) !== 1) {
            throw new \InvalidArgumentException('Milesight observation request is invalid.');
        }

        $offset = (int) $matches[1];
        if ($offset > self::PAGE_SIZE * self::MAX_PAGES) {
            throw new \InvalidArgumentException('Milesight observation request is invalid.');
        }

        return $offset;
    }

    private function sortableObservationIdentity(array $payload): string
    {
        return $this->scalarString($payload['deviceId'] ?? null)
            ?? hash('sha256', json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @return array{
     *     0: null|array<string, mixed>,
     *     1: null|array{code: string, item_reference: ?string}
     * }
     */
    private function observationItem(
        IntegrationSiteConfig $siteConfig,
        array $payload,
        CarbonImmutable $collectedAt,
        string $cursor,
    ): array {
        $providerEntityId = $this->scalarString($payload['deviceId'] ?? null);
        $reference = hash('sha256', $providerEntityId
            ?? json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        if ($providerEntityId === null || mb_strlen($providerEntityId) > 255) {
            return [null, ['code' => 'observation_invalid', 'item_reference' => $reference]];
        }

        $devices = Device::query()
            ->byProvider($this->provider())
            ->where('external_ref->provider_entity_id', $providerEntityId)
            ->get();
        if ($devices->isEmpty()) {
            return [null, ['code' => 'identity_unresolved', 'item_reference' => $reference]];
        }
        if ($devices->count() !== 1) {
            return [null, ['code' => 'identity_ambiguous', 'item_reference' => $reference]];
        }

        /** @var Device $device */
        $device = $devices->first();
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (\Throwable) {
            return [null, ['code' => 'site_scope_mismatch', 'item_reference' => $reference]];
        }
        if ($siteId !== (int) $siteConfig->site_id
            || $this->scalarString(data_get($device->external_ref, 'application_id'))
                !== $this->scalarString($siteConfig->mapped_external_site_id)) {
            return [null, ['code' => 'site_scope_mismatch', 'item_reference' => $reference]];
        }

        $target = $this->bridge->monitorTargetFor($providerEntityId);
        $monitors = Monitor::query()
            ->with('profile')
            ->where('device_id', $device->id)
            ->where('kind', MonitorKind::Provider->value)
            ->where('target', $target)
            ->get();
        if ($monitors->count() !== 1) {
            return [null, [
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
            return [null, ['code' => 'monitor_unavailable', 'item_reference' => $reference]];
        }

        $lastUpdate = $this->observationTimestamp($payload['lastUpdateTime'] ?? null, $collectedAt);
        if ($lastUpdate === null) {
            return [null, ['code' => 'observation_invalid', 'item_reference' => $reference]];
        }
        $freshness = max(0, (int) floor($lastUpdate->diffInSeconds($collectedAt, false)));
        $connectivity = strtolower($this->scalarString($payload['connectStatus'] ?? null) ?? 'unknown');
        [$state, $value, $message] = $this->observationState(
            $connectivity,
            $freshness,
            max(1, (int) $monitor->profile->stale_after_seconds),
        );
        $battery = $this->observationBattery($payload['electricity'] ?? null);
        $metrics = [
            'provider' => $this->provider(),
            'connectivity' => $connectivity,
        ];
        if ($battery !== null) {
            $metrics['battery_percent'] = $battery;
        }
        $metrics['freshness_age_seconds'] = $freshness;
        $sourceFingerprint = hash('sha256', implode('|', [
            $providerEntityId,
            $connectivity,
            $lastUpdate->toIso8601String(),
            $battery === null ? 'none' : (string) $battery,
        ]));

        return [[
            'cursor' => $cursor,
            'monitor_id' => (int) $monitor->id,
            'device_id' => (int) $device->id,
            'site_id' => $siteId,
            'source_key' => 'milesight:status:'.$sourceFingerprint,
            'state' => $state->value,
            'observed_at' => $collectedAt->toIso8601String(),
            'value' => $value,
            'unit' => 'online',
            'latency_ms' => null,
            'message' => $message,
            'metrics' => $metrics,
        ], null];
    }

    private function observationTimestamp(mixed $value, CarbonImmutable $collectedAt): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (float) $value;
                $parsed = CarbonImmutable::createFromTimestampUTC(
                    $timestamp > 1_000_000_000_000 ? $timestamp / 1000 : $timestamp,
                );
            } else {
                $parsed = CarbonImmutable::parse((string) $value)->utc();
            }
        } catch (\Throwable) {
            return null;
        }

        return $parsed->isAfter($collectedAt->addMinutes(5)) ? null : $parsed;
    }

    /** @return array{MonitorState, null|int, string} */
    private function observationState(string $connectivity, int $freshness, int $staleAfter): array
    {
        if (in_array(strtoupper($connectivity), ['OFFLINE', 'DISCONNECT', 'DISCONNECTED'], true)) {
            return [MonitorState::Failed, 0, 'provider_offline'];
        }
        if ($freshness > $staleAfter) {
            return [MonitorState::Stale, null, 'provider_stale'];
        }
        if (strtoupper($connectivity) === 'ONLINE') {
            return [MonitorState::Healthy, 1, 'provider_online'];
        }

        return [MonitorState::Unknown, null, 'provider_status_unknown'];
    }

    private function observationBattery(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }
        $battery = (float) $value;
        if (! is_finite($battery) || $battery < 0 || $battery > 100) {
            return null;
        }
        $rounded = round($battery, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    private function retryAfterSeconds(mixed $value): int
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9]\d{0,4}$/', (string) $value) === 1
            ? min(86400, (int) $value)
            : 300;
    }

    private function providerRequest(): PendingRequest
    {
        return Http::withoutRedirecting();
    }

    private function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function providerSecret(IntegrationProviderConnection $connection): ?string
    {
        try {
            return $this->secrets->application(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'client_secret',
            );
        } catch (\Throwable $e) {
            Log::warning('Milesight governed secret is unavailable', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return null;
        }
    }
}
