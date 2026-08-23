<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Models\ProviderCapabilityException;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Data\ProviderEventPage;
use App\Services\Integration\Data\ProviderObservationPage;
use App\Services\Integration\Data\ProviderSnapshotPage;
use App\Services\Integration\Exceptions\CapabilityUnavailable;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Support\SafeOperationalData;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PullProviderCapability implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 120;

    /** Keep one delayed cadence/retry job per provider, Site and capability. */
    public int $uniqueFor = 90_000;

    /** @param class-string $capability */
    public function __construct(
        public readonly string $provider,
        public readonly int $siteId,
        public readonly string $capability = ObservationCollectionCapability::class,
    ) {
        $this->onQueue((string) config('monitoring.queues.provider', 'monitoring-provider'));
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->scopeKey()))
            ->releaseAfter(60)
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(
        IntegrationAdapterRegistry $registry,
        MonitoringOutboxPublisher $outbox,
        ?ConfigurationSnapshotService $snapshots = null,
    ): void {
        if ($this->siteId < 1 || ! in_array($this->capability, [
            EventCollectionCapability::class,
            ObservationCollectionCapability::class,
            SnapshotCollectionCapability::class,
        ], true)) {
            Log::warning('Provider capability pull was rejected.', [
                'provider' => $this->provider,
                'site_id' => $this->siteId,
                'reason_code' => 'unsupported_capability',
            ]);

            return;
        }

        try {
            $capability = $registry->capability($this->provider, $this->capability);
        } catch (CapabilityUnavailable) {
            Log::info('Provider capability is not enabled.', [
                'provider' => $this->provider,
                'site_id' => $this->siteId,
                'capability' => $this->capability,
            ]);

            return;
        }

        if (! $capability instanceof EventCollectionCapability
            && ! $capability instanceof ObservationCollectionCapability
            && ! $capability instanceof SnapshotCollectionCapability) {
            return;
        }

        $siteConfig = IntegrationSiteConfig::query()
            ->forProvider($this->provider)
            ->active()
            ->where('site_id', $this->siteId)
            ->whereNotNull('mapped_external_site_id')
            ->where('mapped_external_site_id', '<>', '')
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->first();
        $connection = IntegrationProviderConnection::query()
            ->forProvider($this->provider)
            ->connected()
            ->first();

        if ($siteConfig === null || $connection === null) {
            Log::info('Provider capability scope is unavailable.', [
                'provider' => $this->provider,
                'site_id' => $this->siteId,
            ]);

            return;
        }

        $cursor = ProviderCapabilityCursor::query()->firstOrCreate([
            'site_id' => $this->siteId,
            'provider' => $this->provider,
            'capability' => $this->capability,
        ]);
        $manifest = $registry->manifest($this->provider);
        $now = now();

        if ($cursor->retry_not_before?->isFuture()) {
            $this->releaseWhenQueued(max(1, (int) ceil($now->diffInSeconds($cursor->retry_not_before, false))));

            return;
        }

        if ($cursor->last_completed_at !== null) {
            $nextAllowed = $cursor->last_completed_at->addSeconds($manifest->minimumIntervalSeconds);
            if ($nextAllowed->isFuture()) {
                $this->releaseWhenQueued(max(1, (int) ceil($now->diffInSeconds($nextAllowed, false))));

                return;
            }
        }

        $cursor->forceFill(['last_started_at' => $now])->save();

        try {
            if ($this->capability === EventCollectionCapability::class
                && $capability instanceof EventCollectionCapability) {
                $page = $capability->collectEvents(
                    $siteConfig,
                    $connection,
                    $cursor->cursor,
                    min($manifest->pageLimit, $manifest->backfillLimit),
                );
                if (! $this->collectionScopeStillUsable((int) $connection->id, (int) $siteConfig->id)) {
                    $this->recordUnavailableDuringCollection($cursor);

                    return;
                }
                [$persistedCursor, $scopeExceptions] = $this->persistEventPage($page, $outbox);
                $exceptions = $scopeExceptions;
            } elseif ($this->capability === ObservationCollectionCapability::class
                && $capability instanceof ObservationCollectionCapability) {
                $page = $capability->collectObservations(
                    $siteConfig,
                    $connection,
                    $cursor->cursor,
                    min($manifest->pageLimit, $manifest->backfillLimit),
                );
                if (! $this->collectionScopeStillUsable((int) $connection->id, (int) $siteConfig->id)) {
                    $this->recordUnavailableDuringCollection($cursor);

                    return;
                }
                [$persistedCursor, $scopeExceptions] = $this->persistPage($page, $outbox);
                $exceptions = [...$page->exceptions, ...$scopeExceptions];
            } elseif ($this->capability === SnapshotCollectionCapability::class
                && $capability instanceof SnapshotCollectionCapability) {
                $page = $capability->collectSnapshots(
                    $siteConfig,
                    $connection,
                    $cursor->cursor,
                    min($manifest->pageLimit, $manifest->backfillLimit),
                );
                if (! $this->collectionScopeStillUsable((int) $connection->id, (int) $siteConfig->id)) {
                    $this->recordUnavailableDuringCollection($cursor);

                    return;
                }
                [$persistedCursor, $scopeExceptions] = $this->persistSnapshotPage(
                    $page,
                    $capability,
                    $snapshots ?? app(ConfigurationSnapshotService::class),
                );
                $exceptions = $scopeExceptions;
            } else {
                Log::warning('Provider capability registration does not match the requested contract.', [
                    'provider' => $this->provider,
                    'site_id' => $this->siteId,
                    'capability' => $this->capability,
                ]);

                return;
            }

            $safeCursor = $page->partial || $scopeExceptions !== []
                ? $persistedCursor
                : ($page->nextCursor ?? $persistedCursor);

            DB::transaction(function () use ($cursor, $page, $safeCursor, $exceptions): void {
                /** @var ProviderCapabilityCursor $locked */
                $locked = ProviderCapabilityCursor::query()->lockForUpdate()->findOrFail($cursor->id);
                $locked->forceFill([
                    'cursor' => $safeCursor ?? $locked->cursor,
                    'last_completed_at' => now(),
                    'last_failed_at' => null,
                    'last_partial_at' => $page->partial || $page->retryAfterSeconds !== null || $exceptions !== [] ? now() : null,
                    'retry_not_before' => $page->retryAfterSeconds !== null
                        ? now()->addSeconds($page->retryAfterSeconds)
                        : null,
                    'exception_count' => $locked->exception_count + count($exceptions),
                ])->save();

                foreach ($exceptions as $exception) {
                    ProviderCapabilityException::query()->create([
                        'site_id' => $this->siteId,
                        'provider' => $this->provider,
                        'capability' => $this->capability,
                        'code' => $exception['code'],
                        'item_reference' => $exception['item_reference'],
                        'occurred_at' => now(),
                    ]);
                }
            }, 3);

            if ($page->retryAfterSeconds !== null) {
                $this->releaseWhenQueued($page->retryAfterSeconds);
            }
        } catch (Throwable $exception) {
            $this->recordFailedAttempt($cursor);
            Log::error('Provider capability pull failed.', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'site_id' => $this->siteId,
                'capability' => $this->capability,
                'error_category' => SafeOperationalData::failureCategory($exception),
            ]));

            throw $exception;
        }
    }

    private function recordFailedAttempt(
        ProviderCapabilityCursor $cursor,
        string $code = 'provider_collection_failed',
    ): void
    {
        try {
            DB::transaction(function () use ($code, $cursor): void {
                /** @var ProviderCapabilityCursor $locked */
                $locked = ProviderCapabilityCursor::query()->lockForUpdate()->findOrFail($cursor->id);
                $locked->forceFill([
                    'last_failed_at' => now(),
                    'exception_count' => $locked->exception_count + 1,
                ])->save();
                ProviderCapabilityException::query()->create([
                    'site_id' => $this->siteId,
                    'provider' => $this->provider,
                    'capability' => $this->capability,
                    'code' => $code,
                    'item_reference' => null,
                    'occurred_at' => now(),
                ]);
            }, 3);
        } catch (Throwable $recordingFailure) {
            Log::warning('Provider capability failure evidence could not be recorded.', SafeOperationalData::logContext([
                'provider' => $this->provider,
                'site_id' => $this->siteId,
                'capability' => $this->capability,
                'error_category' => SafeOperationalData::failureCategory($recordingFailure),
            ]));
        }
    }

    private function collectionScopeStillUsable(int $connectionId, int $siteConfigId): bool
    {
        $connectionUsable = IntegrationProviderConnection::query()
            ->whereKey($connectionId)
            ->forProvider($this->provider)
            ->connected()
            ->exists();

        return $connectionUsable
            && IntegrationSiteConfig::query()
                ->whereKey($siteConfigId)
                ->forProvider($this->provider)
                ->active()
                ->where('site_id', $this->siteId)
                ->whereNotNull('mapped_external_site_id')
                ->where('mapped_external_site_id', '<>', '')
                ->whereHas('site', fn ($site) => $site
                    ->where('is_active', true)
                    ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                    ->whereNull('archived_at'))
                ->exists();
    }

    private function recordUnavailableDuringCollection(ProviderCapabilityCursor $cursor): void
    {
        Log::info('Provider capability result was discarded after its collection scope became unavailable.', [
            'provider' => $this->provider,
            'site_id' => $this->siteId,
            'capability' => $this->capability,
        ]);
        $this->recordFailedAttempt($cursor, 'collection_scope_unavailable');
    }

    /**
     * @return array{0: ?string, 1: list<array{code: string, item_reference: ?string}>}
     */
    private function persistPage(ProviderObservationPage $page, MonitoringOutboxPublisher $outbox): array
    {
        $lastSafeCursor = null;
        $exceptions = [];

        foreach ($page->items as $item) {
            if ($item['site_id'] !== $this->siteId) {
                $exceptions[] = [
                    'code' => 'site_scope_mismatch',
                    'item_reference' => hash('sha256', (string) $item['source_key']),
                ];

                break;
            }

            $payload = $item;
            unset($payload['cursor']);
            $outbox->stage(
                type: RuntimeMessageType::Observation,
                stream: (string) config('monitoring.queues.checks', 'monitoring-checks'),
                source: "provider:{$this->provider}:site:{$this->siteId}:observations",
                idempotencyKey: 'observation:'.hash('sha256', (string) $item['source_key']),
                payload: $payload,
            );
            $lastSafeCursor = $item['cursor'];
        }

        return [$lastSafeCursor, $exceptions];
    }

    /**
     * @return array{0: ?string, 1: list<array{code: string, item_reference: ?string}>}
     */
    private function persistEventPage(ProviderEventPage $page, MonitoringOutboxPublisher $outbox): array
    {
        $allowedKeys = [
            'site_id',
            'provider',
            'source_app',
            'source_event_id',
            'occurred_at',
            'severity',
            'event_type',
            'normalized_payload',
            'body_hash',
        ];

        foreach ($page->items as $item) {
            $reference = hash('sha256', is_scalar($item['source_event_id'] ?? null)
                ? (string) $item['source_event_id']
                : json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            $siteId = $item['site_id'] ?? null;
            $normalized = $item['normalized_payload'] ?? null;
            if (array_diff(array_keys($item), $allowedKeys) !== []
                || count($item) !== count($allowedKeys)
                || ! is_int($siteId)
                || $siteId !== $this->siteId
                || ($item['provider'] ?? null) !== $this->provider
                || ! $this->boundedEventText($item['source_app'] ?? null, 64)
                || ! $this->boundedEventText($item['source_event_id'] ?? null, 255)
                || ! $this->boundedEventText($item['event_type'] ?? null, 255)
                || ! in_array($item['severity'] ?? null, ['info', 'warn', 'critical'], true)
                || ! is_string($item['occurred_at'] ?? null)
                || strlen($item['occurred_at']) > 64
                || ! is_array($normalized)
                || ! $this->safeEventValue($normalized, 0)
                || ! is_string($item['body_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $item['body_hash']) !== 1) {
                return [null, [[
                    'code' => is_int($siteId) && $siteId !== $this->siteId
                        ? 'site_scope_mismatch'
                        : 'item_invalid',
                    'item_reference' => $reference,
                ]]];
            }

            try {
                CarbonImmutable::parse($item['occurred_at'])->utc();
            } catch (Throwable) {
                return [null, [[
                    'code' => 'item_invalid',
                    'item_reference' => $reference,
                ]]];
            }
        }

        foreach ($page->items as $item) {
            $payload = ['event_family' => 'provider_event', ...$item];
            $outbox->stage(
                type: RuntimeMessageType::Event,
                stream: (string) config('monitoring.queues.events', 'monitoring-events'),
                source: "provider:{$this->provider}:site:{$this->siteId}:events",
                idempotencyKey: 'provider-event:'.hash('sha256', $this->provider.'|'.$item['source_event_id']),
                payload: $payload,
            );
        }

        return [$page->nextCursor, []];
    }

    private function boundedEventText(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $maximum;
    }

    private function safeEventValue(mixed $value, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }

        if (is_array($value)) {
            if (count($value) > 100) {
                return false;
            }

            foreach ($value as $key => $child) {
                if (is_string($key)
                    && (strlen($key) > 64
                        || preg_match('/password|secret|token|credential|authorization|cookie|^raw_/i', $key) === 1)) {
                    return false;
                }
                if (! $this->safeEventValue($child, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_bool($value) || is_int($value) || is_float($value)
            || (is_string($value) && mb_strlen($value) <= 1024);
    }

    /**
     * @return array{0: ?string, 1: list<array{code: string, item_reference: ?string}>}
     */
    private function persistSnapshotPage(
        ProviderSnapshotPage $page,
        SnapshotCollectionCapability $capability,
        ConfigurationSnapshotService $snapshots,
    ): array {
        $lastSafeCursor = null;
        $exceptions = [];

        foreach ($page->items as $item) {
            $cursor = $item['cursor'] ?? null;
            $siteId = $item['site_id'] ?? null;
            $deviceId = $item['device_id'] ?? null;
            $capturedAt = $item['captured_at'] ?? null;
            $payload = $item['payload'] ?? null;
            $reference = hash('sha256', is_string($cursor) ? $cursor : json_encode([
                'site_id' => $siteId,
                'device_id' => $deviceId,
            ], JSON_THROW_ON_ERROR));

            if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 2048
                || ! is_int($siteId) || $siteId !== $this->siteId
                || ! is_int($deviceId) || $deviceId < 1
                || ! is_string($capturedAt)
                || ! is_array($payload) || array_is_list($payload)) {
                $exceptions[] = [
                    'code' => $siteId !== $this->siteId ? 'site_scope_mismatch' : 'item_invalid',
                    'item_reference' => $reference,
                ];

                break;
            }

            try {
                $captured = CarbonImmutable::parse($capturedAt)->utc();
                $device = Device::query()->findOrFail($deviceId);
                $snapshots->captureFromProvider(
                    $capability,
                    $device,
                    $this->siteId,
                    $this->provider,
                    $payload,
                    $captured,
                );
            } catch (\InvalidArgumentException|ModelNotFoundException) {
                $exceptions[] = [
                    'code' => 'item_invalid',
                    'item_reference' => $reference,
                ];

                break;
            }
            $lastSafeCursor = $cursor;
        }

        return [$lastSafeCursor, $exceptions];
    }

    private function releaseWhenQueued(int $seconds): void
    {
        if ($this->job !== null) {
            $this->release($seconds);
        }
    }

    private function scopeKey(): string
    {
        return hash('sha256', $this->provider.':'.$this->siteId.':'.$this->capability);
    }

    public function uniqueId(): string
    {
        return $this->scopeKey();
    }
}
