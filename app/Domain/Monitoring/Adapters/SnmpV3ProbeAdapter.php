<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Protocols\Snmp\SnmpCompatibilityAuthorizer;
use App\Domain\Monitoring\Protocols\Snmp\SnmpInterfaceSample;
use App\Domain\Monitoring\Protocols\Snmp\SnmpPollResult;
use App\Domain\Monitoring\Protocols\Snmp\SnmpQuery;
use App\Domain\Monitoring\Protocols\Snmp\SnmpSensorSample;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTopologyParser;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTopologyParseResult;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTransport;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTransportResult;
use Carbon\CarbonImmutable;
use RuntimeException;

final class SnmpV3ProbeAdapter implements ProbeAdapter
{
    private const array FAILURE_REASONS = [
        'authentication_failed' => 'snmp_authentication_failed',
        'privacy_failed' => 'snmp_privacy_failed',
        'timeout' => 'snmp_timeout',
        'transport_unavailable' => 'snmp_transport_unavailable',
        'walk_limit_exceeded' => 'snmp_walk_limit_exceeded',
    ];

    public function __construct(
        private readonly CredentialLeaseProvider $credentials,
        private readonly SnmpTransport $transport,
        private readonly SnmpCompatibilityAuthorizer $compatibility,
        private readonly SnmpTopologyParser $topology,
    ) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::Snmp;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        $poll = $this->poll($context);

        return $this->observationFor($poll, $context->config);
    }

    /** @param array<string, mixed> $config */
    public function observationFor(SnmpPollResult $poll, array $config): ProtocolObservation
    {
        $ifIndex = $this->positiveInteger($config['if_index'] ?? $config['interface_index'] ?? null);
        if ($ifIndex !== null) {
            $previous = is_array($config['previous_metrics'] ?? null)
                ? $config['previous_metrics']
                : [];

            return $poll->interfaceObservation($ifIndex, $previous);
        }
        $sensorIndex = $this->positiveInteger($config['sensor_index'] ?? null);
        if ($sensorIndex !== null) {
            return $poll->sensorObservation($sensorIndex);
        }

        return $poll->summary;
    }

    public function poll(AuthorisedProbeContext $context): SnmpPollResult
    {
        $version = strtolower(trim((string) ($context->config['version'] ?? 'v3')));
        if (! in_array($version, ['v1', 'v2c', 'v3'], true)) {
            throw new RuntimeException('SNMP version is invalid.');
        }
        $reference = $context->config['credential_reference'] ?? null;
        if (! is_string($reference)
            || preg_match('/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/', $reference) !== 1
            || str_contains($reference, '://')) {
            throw new RuntimeException('SNMP credential reference is invalid.');
        }

        $capabilities = ['snmp:v3:auth_priv'];
        if ($version !== 'v3') {
            $this->compatibility->authorize(
                $context->siteId,
                $context->deviceId,
                $version,
                $reference,
            );
            $capabilities = ["snmp:{$version}:compatibility"];
        }

        $lease = $this->credentials->acquire($context->siteId, $reference, $capabilities);
        $transport = $this->transport->poll(
            $context->target,
            $lease,
            SnmpQuery::inventory($version),
        );

        return $this->normalise($context, $transport);
    }

    private function normalise(AuthorisedProbeContext $context, SnmpTransportResult $transport): SnmpPollResult
    {
        $observedAt = CarbonImmutable::now('UTC');
        if ($transport->status !== 'ok') {
            $reason = self::FAILURE_REASONS[$transport->status] ?? 'snmp_transport_failed';
            $summary = new ProtocolObservation(
                state: MonitorState::Failed,
                observedAt: $observedAt,
                value: null,
                unit: null,
                latencyMs: null,
                reasonCode: $reason,
                evidence: [
                    'protocol_version' => strtolower((string) ($context->config['version'] ?? 'v3')),
                    'transport_status' => $transport->status,
                ],
            );

            return new SnmpPollResult($summary, [], [], null, 0, $observedAt);
        }

        $values = $transport->varbinds;
        $uptimeTicks = $this->integer($values['1.3.6.1.2.1.1.3.0'] ?? null) ?? 0;
        $systemName = $this->identifier($values['1.3.6.1.2.1.1.5.0'] ?? null, 255);
        $objectId = $this->oidValue($values['1.3.6.1.2.1.1.2.0'] ?? null);
        $serial = $this->firstString($values, '1.3.6.1.2.1.47.1.1.1.1.11');
        $manufacturer = $this->firstString($values, '1.3.6.1.2.1.47.1.1.1.1.12');
        $model = $this->firstString($values, '1.3.6.1.2.1.47.1.1.1.1.13');
        $interfaces = $this->interfaces($values);
        $sensors = $this->sensors($values);
        try {
            $topology = $this->topology->parse(
                $values,
                $transport->completedOptionalWalkRoots,
                $observedAt,
            );
            $topologyStatus = $topology->completedSources === [] ? 'unavailable' : 'complete';
        } catch (\Throwable) {
            $topology = new SnmpTopologyParseResult([], []);
            $topologyStatus = 'invalid';
        }
        $fingerprintParts = array_values(array_filter([
            'snmp',
            $objectId,
            $model === null ? null : strtolower($model),
        ]));
        $identity = new DiscoveredIdentity(
            provider: 'snmp',
            providerId: null,
            serialNumber: $serial,
            hardwareId: null,
            macAddresses: [],
            certificateFingerprint: null,
            hostname: $systemName,
            addresses: array_values($context->target->addresses),
            fingerprint: count($fingerprintParts) > 1 ? implode(':', $fingerprintParts) : null,
        );
        $state = $transport->partial ? MonitorState::Degraded : MonitorState::Healthy;
        $summary = new ProtocolObservation(
            state: $state,
            observedAt: $observedAt,
            value: $transport->latencyMs,
            unit: 'ms',
            latencyMs: $transport->latencyMs,
            reasonCode: $transport->partial ? 'partial_oid_response' : 'snmp_poll_complete',
            evidence: [
                'protocol_version' => strtolower((string) ($context->config['version'] ?? 'v3')),
                'system_name' => $systemName,
                'system_object_id' => $objectId,
                'manufacturer' => $manufacturer,
                'model' => $model,
                'serial_number' => $serial,
                'uptime_seconds' => round($uptimeTicks / 100, 2),
                'interface_count' => count($interfaces),
                'sensor_count' => count($sensors),
                'partial_walk' => $transport->partial,
                'topology_collection_status' => $topologyStatus,
                'topology_source_count' => count($topology->completedSources),
                'topology_link_count' => count($topology->observations),
            ],
        );

        return new SnmpPollResult(
            $summary,
            $interfaces,
            $sensors,
            $identity,
            $uptimeTicks,
            $observedAt,
            $topology->observations,
            $topology->completedSources,
        );
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpInterfaceSample> */
    private function interfaces(array $values): array
    {
        $indexes = array_keys($this->column($values, '1.3.6.1.2.1.2.2.1.1'));
        $samples = [];
        foreach ($indexes as $index) {
            $ifIndex = $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.1', $index));
            if ($ifIndex === null || $ifIndex < 1) {
                continue;
            }
            $name = $this->identifier(
                $this->at($values, '1.3.6.1.2.1.31.1.1.1.1', $index)
                    ?? $this->at($values, '1.3.6.1.2.1.2.2.1.2', $index),
                255,
            ) ?? "if-{$ifIndex}";
            $speedHighMbps = $this->integer($this->at($values, '1.3.6.1.2.1.31.1.1.1.15', $index));
            $speed = $speedHighMbps !== null && $speedHighMbps > 0
                ? min(PHP_INT_MAX, $speedHighMbps * 1_000_000)
                : ($this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.5', $index)) ?? 0);
            $highIn = $this->integer($this->at($values, '1.3.6.1.2.1.31.1.1.1.6', $index));
            $highOut = $this->integer($this->at($values, '1.3.6.1.2.1.31.1.1.1.10', $index));
            $samples[] = new SnmpInterfaceSample(
                ifIndex: $ifIndex,
                name: $name,
                alias: $this->identifier($this->at($values, '1.3.6.1.2.1.31.1.1.1.18', $index), 255),
                adminStatus: $this->interfaceStatus($this->at($values, '1.3.6.1.2.1.2.2.1.7', $index)),
                operStatus: $this->interfaceStatus($this->at($values, '1.3.6.1.2.1.2.2.1.8', $index)),
                speedBps: max(0, $speed),
                inOctets: max(0, $highIn ?? $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.10', $index)) ?? 0),
                outOctets: max(0, $highOut ?? $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.16', $index)) ?? 0),
                counterBits: $highIn !== null && $highOut !== null ? 64 : 32,
                inErrors: max(0, $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.14', $index)) ?? 0),
                outErrors: max(0, $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.20', $index)) ?? 0),
                inDiscards: max(0, $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.13', $index)) ?? 0),
                outDiscards: max(0, $this->integer($this->at($values, '1.3.6.1.2.1.2.2.1.19', $index)) ?? 0),
                discontinuityTicks: max(0, $this->integer($this->at($values, '1.3.6.1.2.1.31.1.1.1.19', $index)) ?? 0),
            );
        }

        usort($samples, fn (SnmpInterfaceSample $left, SnmpInterfaceSample $right): int => $left->ifIndex <=> $right->ifIndex);

        return $samples;
    }

    /** @param array<string, int|float|string|bool|null> $values @return list<SnmpSensorSample> */
    private function sensors(array $values): array
    {
        $samples = [];
        foreach ($this->column($values, '1.3.6.1.2.1.99.1.1.1.4') as $index => $rawValue) {
            $sensorIndex = filter_var($index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $value = $this->integer($rawValue);
            $typeCode = $this->integer($this->at($values, '1.3.6.1.2.1.99.1.1.1.1', $index));
            $scale = $this->integer($this->at($values, '1.3.6.1.2.1.99.1.1.1.2', $index));
            $precision = $this->integer($this->at($values, '1.3.6.1.2.1.99.1.1.1.3', $index));
            if ($sensorIndex === false || $value === null || $typeCode === null || $scale === null || $precision === null) {
                continue;
            }
            [$type, $unit] = $this->sensorType($typeCode);
            $exponent = $this->sensorScaleExponent($scale) - $precision;
            $normalised = (float) ($value * (10 ** $exponent));
            if (! is_finite($normalised)) {
                continue;
            }
            $samples[] = new SnmpSensorSample(
                index: (int) $sensorIndex,
                name: $this->identifier(
                    $this->at($values, '1.3.6.1.2.1.47.1.1.1.1.7', $index),
                    255,
                ) ?? "sensor-{$sensorIndex}",
                type: $type,
                value: round($normalised, max(0, min(9, $precision))),
                unit: $unit,
                status: match ($this->integer($this->at($values, '1.3.6.1.2.1.99.1.1.1.5', $index))) {
                    1 => 'ok',
                    2 => 'unavailable',
                    3 => 'nonoperational',
                    default => 'unknown',
                },
            );
        }

        return $samples;
    }

    /** @return array{string, string} */
    private function sensorType(int $type): array
    {
        return match ($type) {
            1 => ['other', 'unit'],
            2 => ['unknown', 'unit'],
            3 => ['voltage_ac', 'volts_ac'],
            4 => ['voltage_dc', 'volts_dc'],
            5 => ['current', 'amperes'],
            6 => ['power', 'watts'],
            7 => ['frequency', 'hertz'],
            8 => ['temperature', 'celsius'],
            9 => ['relative_humidity', 'percent'],
            10 => ['rpm', 'rpm'],
            11 => ['airflow', 'cubic_metres_per_minute'],
            12 => ['truth_value', 'boolean'],
            default => ['vendor_specific', 'unit'],
        };
    }

    private function sensorScaleExponent(int $scale): int
    {
        return match ($scale) {
            1 => -24,
            2 => -21,
            3 => -18,
            4 => -15,
            5 => -12,
            6 => -9,
            7 => -6,
            8 => -3,
            9 => 0,
            10 => 3,
            11 => 6,
            12 => 9,
            13 => 12,
            14 => 15,
            15 => 18,
            16 => 21,
            17 => 24,
            default => 0,
        };
    }

    /** @param array<string, int|float|string|bool|null> $values @return array<string, int|float|string|bool|null> */
    private function column(array $values, string $root): array
    {
        $column = [];
        $prefix = $root.'.';
        foreach ($values as $oid => $value) {
            if (str_starts_with($oid, $prefix)) {
                $index = substr($oid, strlen($prefix));
                if (preg_match('/^\d+$/', $index) === 1) {
                    $column[$index] = $value;
                }
            }
        }

        return $column;
    }

    /** @param array<string, int|float|string|bool|null> $values */
    private function at(array $values, string $root, string|int $index): int|float|string|bool|null
    {
        return $values[$root.'.'.$index] ?? null;
    }

    /** @param array<string, int|float|string|bool|null> $values */
    private function firstString(array $values, string $root): ?string
    {
        foreach ($this->column($values, $root) as $value) {
            $string = $this->identifier($value, 255);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function interfaceStatus(mixed $value): string
    {
        return match ($this->integer($value)) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'not_present',
            7 => 'lower_layer_down',
            default => 'unknown',
        };
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX) {
            return (int) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('/(?:\(|^|:\s*)(\d+)\)?\s*$/', trim($value), $match) !== 1) {
            return null;
        }

        return filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: ($match[1] === '0' ? 0 : null);
    }

    private function oidValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/^OID:\s*/i', '', trim($value)) ?? '';
        $value = ltrim($value, '.');

        return preg_match('/^\d+(?:\.\d+)+$/', $value) === 1 ? $value : null;
    }

    private function identifier(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value, " \t\n\r\0\x0B\"");
        $value = preg_replace('/^(?:STRING|HEX-STRING|OID):\s*/i', '', $value) ?? '';
        $value = strtolower(trim($value, " \t\n\r\0\x0B\""));
        if ($value === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
