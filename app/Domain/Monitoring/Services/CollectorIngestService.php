<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Models\CollectorCheckpoint;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\SecurityDevices\Management\Services\CollectorCommandResultService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CollectorIngestService
{
    public function __construct(
        private RuntimeEnvelopeHandlerRegistry $handlers,
        private DiscoveryRunner $discovery,
        private CollectorCommandResultService $commands,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{acknowledged_ids: list<string>, acknowledged_source_sequence: int}
     */
    public function ingest(MonitoringCollector $collector, array $items): array
    {
        $maximum = max(1, min(1000, (int) config('monitoring.collector.maximum_upload_items', 1000)));
        if ($items === [] || count($items) > $maximum || ! array_is_list($items)) {
            throw new DomainException('Collector upload batch is invalid.');
        }

        return DB::transaction(function () use ($collector, $items): array {
            $locked = MonitoringCollector::query()->whereKey($collector->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null || $locked->status === 'revoked') {
                throw new DomainException('Collector is unavailable.');
            }
            $checkpoint = CollectorCheckpoint::query()->where('collector_id', $locked->id)->lockForUpdate()->first();
            $checkpoint ??= CollectorCheckpoint::query()->create(['collector_id' => $locked->id]);
            $acknowledged = (int) $checkpoint->acknowledged_source_sequence;
            $highestSeen = (int) $checkpoint->highest_seen_source_sequence;
            $acknowledgedIds = [];
            $lastClockDrift = $locked->last_clock_drift_seconds;

            foreach ($items as $item) {
                [$id, $sequence, $createdAt, $payload] = $this->validateItem($item);
                $highestSeen = max($highestSeen, $sequence);
                $lastClockDrift = CarbonImmutable::now('UTC')->diffInSeconds($createdAt, false) * -1;
                if ($sequence <= $acknowledged) {
                    $acknowledgedIds[] = $id;

                    continue;
                }
                $expected = $acknowledged + 1;
                if ($sequence !== $expected) {
                    $checkpoint->forceFill([
                        'highest_seen_source_sequence' => $highestSeen,
                        'gap_from' => $expected,
                        'gap_to' => $sequence - 1,
                        'last_gap_at' => now(),
                        'last_error_code' => 'collector_sequence_gap',
                    ])->save();
                    $locked->forceFill([
                        'highest_seen_source_sequence' => $highestSeen,
                        'gap_count' => $sequence - $expected,
                        'last_clock_drift_seconds' => $lastClockDrift,
                    ])->save();

                    break;
                }

                try {
                    $this->project($locked, $sequence, $id, $payload);
                } catch (RuntimePayloadInvalid|RuntimeScopeViolation|RuntimeSiteScopeViolation|ModelNotFoundException|DomainException $exception) {
                    $this->deadLetter($locked, $sequence, $id, $item, $this->reasonCode($exception));
                }
                $acknowledged = $sequence;
                $acknowledgedIds[] = $id;
                $checkpoint->forceFill([
                    'acknowledged_source_sequence' => $acknowledged,
                    'highest_seen_source_sequence' => $highestSeen,
                    'gap_from' => null,
                    'gap_to' => null,
                    'last_item_at' => $createdAt,
                    'last_acknowledged_at' => now(),
                    'last_error_code' => null,
                ])->save();
            }

            $gapCount = $checkpoint->gap_from === null
                ? 0
                : max(0, (int) $checkpoint->gap_to - (int) $checkpoint->gap_from + 1);
            $locked->forceFill([
                'acknowledged_source_sequence' => $acknowledged,
                'highest_seen_source_sequence' => $highestSeen,
                'gap_count' => $gapCount,
                'last_clock_drift_seconds' => $lastClockDrift,
            ])->save();

            return [
                'acknowledged_ids' => $acknowledgedIds,
                'acknowledged_source_sequence' => $acknowledged,
            ];
        }, 3);
    }

    /** @return array{string, int, CarbonImmutable, array<string, mixed>} */
    private function validateItem(mixed $item): array
    {
        $maximumBytes = max(4096, min(2_097_152, (int) config('monitoring.collector.maximum_item_bytes', 2_097_152)));
        if (! is_array($item) || array_is_list($item)) {
            throw new DomainException('Collector upload item is invalid.');
        }
        $encoded = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $id = $item['id'] ?? null;
        $sequence = $item['source_sequence'] ?? null;
        $createdAt = $item['created_at'] ?? null;
        $payload = $item['payload'] ?? null;
        if (strlen($encoded) > $maximumBytes || ! is_string($id)
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $id) !== 1
            || ! is_int($sequence) || $sequence < 1
            || ! is_string($createdAt) || ! is_array($payload) || array_is_list($payload)) {
            throw new DomainException('Collector upload item is invalid.');
        }
        try {
            $created = CarbonImmutable::parse($createdAt)->utc();
        } catch (\Throwable) {
            throw new DomainException('Collector upload item timestamp is invalid.');
        }
        $now = CarbonImmutable::now('UTC');
        $maximumAge = max(3600, min(691_200, (int) config('monitoring.collector.maximum_backlog_age_seconds', 691_200)));
        if ($created->gt($now->addMinutes(5)) || $created->lt($now->subSeconds($maximumAge))) {
            throw new DomainException('Collector upload item timestamp is outside the accepted window.');
        }

        return [$id, $sequence, $created, $payload];
    }

    /** @param array<string, mixed> $payload */
    private function project(MonitoringCollector $collector, int $sequence, string $id, array $payload): void
    {
        $itemType = $payload['item_type'] ?? 'observation';
        if ($itemType === 'discovery_result') {
            $this->discovery->recordCollectorResult($collector, $payload);

            return;
        }
        if ($itemType === 'command_result') {
            $this->commands->record($collector, $sequence, $payload);

            return;
        }
        if ($itemType !== 'observation') {
            throw new RuntimePayloadInvalid('Collector item type is invalid.');
        }

        $monitorId = $this->positiveIntegerString($payload['check_id'] ?? null);
        $deviceId = $this->positiveIntegerString($payload['device_id'] ?? null);
        $monitor = Monitor::query()->with(['collector', 'device.assignments'])->findOrFail($monitorId);
        if ((int) $monitor->collector_id !== (int) $collector->id || (int) $monitor->device_id !== $deviceId
            || ! hash_equals((string) $monitor->target, (string) ($payload['target'] ?? ''))
            || $this->protocol($monitor) !== ($payload['protocol'] ?? null)) {
            throw new RuntimeScopeViolation('Collector item is outside its canonical monitor scope.');
        }
        $state = MonitorState::tryFrom((string) ($payload['state'] ?? ''));
        $observedAt = $payload['observed_at'] ?? null;
        if (! in_array($state, [
            MonitorState::Healthy,
            MonitorState::Degraded,
            MonitorState::Failed,
            MonitorState::Unknown,
        ], true) || ! is_string($observedAt)) {
            throw new RuntimePayloadInvalid('Collector observation is invalid.');
        }
        try {
            $observed = CarbonImmutable::parse($observedAt)->utc();
        } catch (\Throwable) {
            throw new RuntimePayloadInvalid('Collector observation time is invalid.');
        }
        $now = CarbonImmutable::now('UTC');
        $maximumAge = max(3600, min(691_200, (int) config('monitoring.collector.maximum_backlog_age_seconds', 691_200)));
        if ($observed->gt($now->addMinutes(5)) || $observed->lt($now->subSeconds($maximumAge))) {
            throw new RuntimePayloadInvalid('Collector observation time is outside the accepted window.');
        }
        $metrics = $payload['metrics'] ?? [];
        if (! is_array($metrics) || array_is_list($metrics) || count($metrics) > 32
            || array_any($metrics, function (mixed $value, mixed $key): bool {
                return ! is_string($key)
                    || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1
                    || preg_match('/body|authorization|cookie|credential|password|secret|token|certificate|raw/i', $key) === 1
                    || (! is_scalar($value) && $value !== null)
                    || (is_string($value) && strlen($value) > 256);
            })) {
            throw new RuntimePayloadInvalid('Collector observation metrics are invalid.');
        }
        $reason = $payload['reason_code'] ?? null;
        if (! is_string($reason) || preg_match('/\A[a-z0-9_]{1,96}\z/', $reason) !== 1) {
            throw new RuntimePayloadInvalid('Collector observation reason is invalid.');
        }
        $duration = $payload['duration_ms'] ?? null;
        if (! is_int($duration) || $duration < 0 || $duration > 3_600_000) {
            throw new RuntimePayloadInvalid('Collector observation duration is invalid.');
        }
        $envelope = RuntimeEnvelope::new(
            type: RuntimeMessageType::Observation,
            source: 'collector:'.$collector->collector_uuid,
            sequence: $sequence,
            idempotencyKey: $id,
            payload: [
                'monitor_id' => $monitorId,
                'device_id' => $deviceId,
                'site_id' => (int) $collector->site_id,
                'collector_uuid' => (string) $collector->collector_uuid,
                'source_key' => "collector:{$collector->collector_uuid}:{$sequence}",
                'state' => $state->value,
                'observed_at' => $observed->format(DATE_ATOM),
                'latency_ms' => $duration,
                'message' => $reason,
                'metrics' => [...$metrics, 'protocol' => (string) $payload['protocol'], 'reason_code' => $reason],
            ],
        );
        $this->handlers->for(RuntimeMessageType::Observation)->handle($envelope, (int) $collector->site_id);
    }

    private function positiveIntegerString(mixed $value): int
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/', $value) !== 1) {
            throw new RuntimePayloadInvalid('Collector canonical identity is invalid.');
        }

        return (int) $value;
    }

    private function protocol(Monitor $monitor): ?string
    {
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind : MonitorKind::tryFrom((string) $monitor->kind);

        return match ($kind) {
            MonitorKind::Icmp => 'icmp',
            MonitorKind::Tcp => 'tcp',
            MonitorKind::Dns => 'dns',
            MonitorKind::Http => strtolower((string) parse_url((string) data_get($monitor->config, 'url'), PHP_URL_SCHEME)),
            MonitorKind::Tls => 'tls',
            MonitorKind::Snmp, MonitorKind::SnmpInterface => 'snmp',
            MonitorKind::SshInventory => 'ssh',
            MonitorKind::WinRmInventory => 'winrm',
            default => null,
        };
    }

    /** @param array<string, mixed> $item */
    private function deadLetter(
        MonitoringCollector $collector,
        int $sequence,
        string $id,
        array $item,
        string $reason,
    ): void {
        MonitoringDeadLetter::query()->create([
            'message_id' => (string) Str::orderedUuid(),
            'consumer' => 'collector-intake',
            'source' => 'collector:'.$collector->collector_uuid,
            'sequence' => $sequence,
            'idempotency_key' => $id,
            'reason_code' => $reason,
            'reason_message' => 'Collector item failed canonical scope or payload validation.',
            'envelope_bytes' => json_encode([
                'id' => $id,
                'source_sequence' => $sequence,
                'created_at' => $item['created_at'] ?? null,
                'item_type' => data_get($item, 'payload.item_type', 'observation'),
                'check_id' => data_get($item, 'payload.check_id'),
                'device_id' => data_get($item, 'payload.device_id'),
                'protocol' => data_get($item, 'payload.protocol'),
                'run_id' => data_get($item, 'payload.run_id'),
                'target_fingerprint' => is_string(data_get($item, 'payload.target'))
                    ? hash('sha256', (string) data_get($item, 'payload.target'))
                    : null,
                'payload_hash' => hash('sha256', json_encode(
                    $item['payload'] ?? null,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
                'reason_code' => $reason,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'site_id' => (int) $collector->site_id,
        ]);
    }

    private function reasonCode(\Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RuntimeSiteScopeViolation => 'site_scope_violation',
            $exception instanceof RuntimeScopeViolation, $exception instanceof ModelNotFoundException => 'collector_scope_violation',
            default => 'collector_payload_invalid',
        };
    }
}
