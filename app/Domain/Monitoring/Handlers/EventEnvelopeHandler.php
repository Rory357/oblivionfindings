<?php

namespace App\Domain\Monitoring\Handlers;

use App\Domain\Monitoring\Contracts\RuntimeEnvelopeHandler;
use App\Domain\Monitoring\Data\MetricSample;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\MetricIngestService;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Services\Integration\ProviderEventProjector;
use Carbon\CarbonImmutable;
use Throwable;

final class EventEnvelopeHandler implements RuntimeEnvelopeHandler
{
    private const array SNMP_KEYS = [
        'event_family', 'site_id', 'source_address', 'version', 'request_id', 'trap_oid',
        'uptime_ticks', 'system_name', 'if_index', 'if_name', 'varbind_count', 'event_type', 'severity',
    ];

    private const array SYSLOG_KEYS = [
        'event_family', 'site_id', 'source_address', 'format', 'facility', 'severity_code',
        'hostname', 'app', 'process_id', 'message_id', 'message', 'occurred_at', 'raw_hash',
        'structured_data',
    ];

    private const array FLOW_METRIC_KEYS = [
        'event_family', 'site_id', 'source_address', 'protocol_family', 'source_id', 'sequence', 'buckets',
    ];

    private const array FLOW_HEALTH_KEYS = [
        'event_family', 'site_id', 'source_address', 'protocol_family', 'source_id', 'reason',
        'expected_sequence', 'actual_sequence', 'gap_count', 'event_type', 'severity',
    ];

    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly ProviderEventProjector $providerEvents,
        private readonly MetricIngestService $metrics,
    ) {}

    public function handle(RuntimeEnvelope $envelope, ?int $trustedSiteId = null): void
    {
        $family = $envelope->payload['event_family'] ?? null;
        if (! is_string($family)) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }

        switch ($family) {
            case 'snmp_trap':
                $this->handleSnmp($envelope, $trustedSiteId);

                return;
            case 'syslog':
                $this->handleSyslog($envelope, $trustedSiteId);

                return;
            case 'flow_metric':
                $this->handleFlowMetric($envelope, $trustedSiteId);

                return;
            case 'flow_health':
                $this->handleFlowHealth($envelope, $trustedSiteId);

                return;
            case 'provider_event':
                $this->providerEvents->project($envelope->payload, $trustedSiteId);

                return;
            default:
                throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
    }

    private function handleSnmp(RuntimeEnvelope $envelope, ?int $trustedSiteId): void
    {
        $this->assertAllowedKeys($envelope->payload, self::SNMP_KEYS);
        [$siteId, $sourceAddress] = $this->scope($envelope->payload, $trustedSiteId);
        $version = $envelope->payload['version'] ?? null;
        $requestId = $envelope->payload['request_id'] ?? null;
        $trapOid = $envelope->payload['trap_oid'] ?? null;
        $uptimeTicks = $envelope->payload['uptime_ticks'] ?? null;
        $varbindCount = $envelope->payload['varbind_count'] ?? null;
        $eventType = $envelope->payload['event_type'] ?? null;
        $severity = $envelope->payload['severity'] ?? null;
        if (! is_string($version) || ! in_array($version, ['v1', 'v2c', 'v3'], true)
            || ! is_int($requestId) || $requestId < 0
            || ! is_string($trapOid) || preg_match('/^\d+(?:\.\d+){2,127}$/', $trapOid) !== 1
            || ($uptimeTicks !== null && (! is_int($uptimeTicks) || $uptimeTicks < 0))
            || ! is_int($varbindCount) || $varbindCount < 1 || $varbindCount > 128
            || ! is_string($eventType) || ! in_array($eventType, ['offline', 'online', 'config_changed', 'signal', 'tamper'], true)
            || ! is_string($severity) || ! in_array($severity, ['info', 'warning', 'critical'], true)) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
        $systemName = $this->optionalString($envelope->payload['system_name'] ?? null, 255);
        $ifName = $this->optionalString($envelope->payload['if_name'] ?? null, 255);
        $ifIndex = $envelope->payload['if_index'] ?? null;
        if ($ifIndex !== null && (! is_int($ifIndex) || $ifIndex < 1)) {
            throw new RuntimePayloadInvalid('Event interface reference is invalid.');
        }
        $device = $this->device($siteId, $sourceAddress);

        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'payload' => array_filter([
                'trap_oid' => $trapOid,
                'uptime_ticks' => $uptimeTicks,
                'system_name' => $systemName,
                'if_index' => $ifIndex,
                'if_name' => $ifName,
                'request_id' => $requestId,
                'snmp_version' => $version,
                'varbind_count' => $varbindCount,
                'source_address' => $sourceAddress,
            ], fn (mixed $value): bool => $value !== null),
            'source' => 'oblivion_snmp',
            'occurred_at' => $envelope->occurredAt,
        ]);
    }

    private function handleSyslog(RuntimeEnvelope $envelope, ?int $trustedSiteId): void
    {
        $this->assertAllowedKeys($envelope->payload, self::SYSLOG_KEYS);
        [$siteId, $sourceAddress] = $this->scope($envelope->payload, $trustedSiteId);
        $format = $envelope->payload['format'] ?? null;
        $facility = $envelope->payload['facility'] ?? null;
        $severityCode = $envelope->payload['severity_code'] ?? null;
        $rawHash = $envelope->payload['raw_hash'] ?? null;
        $occurredAt = $envelope->payload['occurred_at'] ?? null;
        $message = $this->requiredString($envelope->payload['message'] ?? null, 4096, true);
        if (! is_string($format) || ! in_array($format, ['rfc3164', 'rfc5424'], true)
            || ! is_int($facility) || $facility < 0 || $facility > 23
            || ! is_int($severityCode) || $severityCode < 0 || $severityCode > 7
            || ! is_string($rawHash) || preg_match('/^[a-f0-9]{64}$/', $rawHash) !== 1
            || ! is_string($occurredAt)) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
        try {
            CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
        $structuredData = $this->structuredData($envelope->payload['structured_data'] ?? null);
        $hostname = $this->optionalString($envelope->payload['hostname'] ?? null, 255);
        $app = $this->optionalString($envelope->payload['app'] ?? null, 48);
        $processId = $this->optionalString($envelope->payload['process_id'] ?? null, 128);
        $messageId = $this->optionalString($envelope->payload['message_id'] ?? null, 32);

        // Informational/debug events remain in the signed delivery ledger for the
        // later log-retention consumer but do not flood DeviceEvent/Control Room.
        if ($severityCode > 4) {
            return;
        }
        $device = $this->device($siteId, $sourceAddress);
        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'signal',
            'severity' => $severityCode <= 2 ? 'critical' : 'warning',
            'payload' => array_filter([
                'syslog_format' => $format,
                'facility' => $facility,
                'severity_code' => $severityCode,
                'hostname' => $hostname,
                'app' => $app,
                'process_id' => $processId,
                'message_id' => $messageId,
                'message' => $message,
                'structured_data' => $structuredData === [] ? null : $structuredData,
                'raw_hash' => $rawHash,
                'source_address' => $sourceAddress,
            ], fn (mixed $value): bool => $value !== null),
            'source' => 'oblivion_syslog',
            'occurred_at' => CarbonImmutable::parse($occurredAt),
        ]);
    }

    private function handleFlowMetric(RuntimeEnvelope $envelope, ?int $trustedSiteId): void
    {
        $this->assertAllowedKeys($envelope->payload, self::FLOW_METRIC_KEYS);
        [$siteId, $sourceAddress] = $this->scope($envelope->payload, $trustedSiteId);
        $this->flowIdentity($envelope->payload);
        $buckets = $envelope->payload['buckets'] ?? null;
        if (! is_array($buckets) || ! array_is_list($buckets) || $buckets === [] || count($buckets) > 256) {
            throw new RuntimePayloadInvalid('Flow metric buckets are invalid.');
        }
        foreach ($buckets as $bucket) {
            if (! is_array($bucket) || array_is_list($bucket)
                || array_diff(array_keys($bucket), [
                    'application', 'bucket_start', 'bytes', 'direction', 'flow_count',
                    'input_interface', 'output_interface', 'packets', 'protocol',
                ]) !== []
                || ! is_string($bucket['application'] ?? null) || strlen($bucket['application']) > 32
                || ! is_string($bucket['bucket_start'] ?? null)
                || ! is_int($bucket['bytes'] ?? null) || $bucket['bytes'] < 0
                || ! in_array($bucket['direction'] ?? null, ['ingress', 'egress', 'unknown'], true)
                || ! is_int($bucket['flow_count'] ?? null) || $bucket['flow_count'] < 1
                || ! is_int($bucket['packets'] ?? null) || $bucket['packets'] < 0
                || ! is_int($bucket['protocol'] ?? null) || $bucket['protocol'] < 0 || $bucket['protocol'] > 255
                || ! $this->optionalNonNegativeInt($bucket['input_interface'] ?? null)
                || ! $this->optionalNonNegativeInt($bucket['output_interface'] ?? null)) {
                throw new RuntimePayloadInvalid('Flow metric buckets are invalid.');
            }
            try {
                CarbonImmutable::parse($bucket['bucket_start'])->utc();
            } catch (Throwable) {
                throw new RuntimePayloadInvalid('Flow metric buckets are invalid.');
            }
        }
        $device = $this->device($siteId, $sourceAddress);
        $source = sprintf(
            'oblivion_flow:%s:%d',
            $envelope->payload['protocol_family'],
            $envelope->payload['source_id'],
        );
        foreach ($buckets as $bucket) {
            $dimensions = array_filter([
                'application' => $bucket['application'],
                'direction' => $bucket['direction'],
                'protocol' => $bucket['protocol'],
                'input_interface' => $bucket['input_interface'] ?? null,
                'output_interface' => $bucket['output_interface'] ?? null,
                'source_id' => $envelope->payload['source_id'],
            ], fn (mixed $value): bool => $value !== null);
            $bucketStart = CarbonImmutable::parse($bucket['bucket_start'])->utc();
            foreach ([
                ['flow.bytes', $bucket['bytes'], 'bytes'],
                ['flow.packets', $bucket['packets'], 'packets'],
                ['flow.count', $bucket['flow_count'], 'flows'],
            ] as [$metric, $value, $unit]) {
                $this->metrics->writeForDevice($device, $siteId, new MetricSample(
                    metric: $metric,
                    value: $value,
                    unit: $unit,
                    dimensions: $dimensions,
                    observedAt: $bucketStart,
                    source: $source,
                ));
            }
        }
    }

    private function handleFlowHealth(RuntimeEnvelope $envelope, ?int $trustedSiteId): void
    {
        $this->assertAllowedKeys($envelope->payload, self::FLOW_HEALTH_KEYS);
        [$siteId, $sourceAddress] = $this->scope($envelope->payload, $trustedSiteId);
        $this->flowIdentity($envelope->payload);
        $reason = $envelope->payload['reason'] ?? null;
        $expected = $envelope->payload['expected_sequence'] ?? null;
        $actual = $envelope->payload['actual_sequence'] ?? null;
        $gap = $envelope->payload['gap_count'] ?? null;
        $eventType = $envelope->payload['event_type'] ?? null;
        $severity = $envelope->payload['severity'] ?? null;
        if (! is_string($reason) || ! in_array($reason, ['sequence_gap', 'sequence_out_of_order', 'exporter_reset'], true)
            || ($expected !== null && (! is_int($expected) || $expected < 0 || $expected > 0xFFFFFFFF))
            || ! is_int($actual) || $actual < 0 || $actual > 0xFFFFFFFF
            || ! is_int($gap) || $gap < 0
            || ! is_string($eventType) || ! in_array($eventType, ['signal', 'config_changed'], true)
            || ! is_string($severity) || ! in_array($severity, ['info', 'warning'], true)) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
        $device = $this->device($siteId, $sourceAddress);
        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'payload' => array_filter([
                'protocol_family' => $envelope->payload['protocol_family'],
                'source_id' => $envelope->payload['source_id'],
                'reason' => $reason,
                'expected_sequence' => $expected,
                'actual_sequence' => $actual,
                'gap_count' => $gap,
                'source_address' => $sourceAddress,
            ], fn (mixed $value): bool => $value !== null),
            'source' => 'oblivion_flow',
            'occurred_at' => $envelope->occurredAt,
        ]);
    }

    /** @param array<string|int, mixed> $payload */
    private function flowIdentity(array $payload): void
    {
        $family = $payload['protocol_family'] ?? null;
        $sourceId = $payload['source_id'] ?? null;
        $sequence = $payload['sequence'] ?? $payload['actual_sequence'] ?? null;
        if (! is_string($family) || ! in_array($family, ['netflow-v5', 'netflow-v9', 'ipfix', 'sflow-v5'], true)
            || ! is_int($sourceId) || $sourceId < 0 || $sourceId > 0xFFFFFFFF
            || ! is_int($sequence) || $sequence < 0 || $sequence > 0xFFFFFFFF) {
            throw new RuntimePayloadInvalid('Flow event identity is invalid.');
        }
    }

    /** @param array<string|int, mixed> $payload
     * @return array{int, string}
     */
    private function scope(array $payload, ?int $trustedSiteId): array
    {
        $siteId = $payload['site_id'] ?? null;
        $sourceAddress = $payload['source_address'] ?? null;
        if (! is_int($siteId) || $siteId < 1 || ! is_string($sourceAddress)) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
        if ($trustedSiteId !== null && $trustedSiteId !== $siteId) {
            throw new RuntimeSiteScopeViolation('Event site does not match trusted routing context.');
        }
        try {
            $sourceAddress = $this->cidrs->canonicalAddress($sourceAddress);
        } catch (Throwable) {
            throw new RuntimePayloadInvalid('Event source address is invalid.');
        }

        return [$siteId, $sourceAddress];
    }

    private function device(int $siteId, string $sourceAddress): Device
    {
        $devices = Device::query()
            ->where('ip_address', $sourceAddress)
            ->whereIn('status', [
                DeviceStatus::Active->value,
                DeviceStatus::Degraded->value,
                DeviceStatus::Offline->value,
            ])
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->filter(function (Device $device) use ($siteId): bool {
                try {
                    return $this->siteResolver->resolve((int) $device->id) === $siteId;
                } catch (Throwable) {
                    return false;
                }
            })
            ->values();
        if ($devices->count() !== 1) {
            throw new RuntimeScopeViolation('Event source does not resolve to one canonical Device.');
        }

        return $devices->first();
    }

    /** @param array<string|int, mixed> $payload
     * @param  list<string>  $allowed
     */
    private function assertAllowedKeys(array $payload, array $allowed): void
    {
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new RuntimePayloadInvalid('Event envelope payload is invalid.');
        }
    }

    /** @return array<string, array<string, string>> */
    private function structuredData(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || array_is_list($value) || count($value) > 32) {
            throw new RuntimePayloadInvalid('Syslog structured data is invalid.');
        }
        foreach ($value as $id => $parameters) {
            if (! is_string($id) || preg_match('/^[A-Za-z0-9@_.-]{1,32}$/', $id) !== 1
                || ! is_array($parameters) || array_is_list($parameters) || count($parameters) > 64) {
                throw new RuntimePayloadInvalid('Syslog structured data is invalid.');
            }
            foreach ($parameters as $name => $parameter) {
                if (! is_string($name) || preg_match('/^[A-Za-z0-9@_.-]{1,32}$/', $name) !== 1
                    || ! is_string($parameter) || strlen($parameter) > 255
                    || preg_match('/[\x00-\x1f\x7f]/', $parameter) === 1) {
                    throw new RuntimePayloadInvalid('Syslog structured data is invalid.');
                }
            }
        }

        return $value;
    }

    private function optionalNonNegativeInt(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }

    private function optionalString(mixed $value, int $maximum): ?string
    {
        return $value === null ? null : $this->requiredString($value, $maximum);
    }

    private function requiredString(mixed $value, int $maximum, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && $value === '') || strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new RuntimePayloadInvalid('Event evidence string is invalid.');
        }

        return $value;
    }
}
