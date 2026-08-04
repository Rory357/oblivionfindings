<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class SnmpTopologyObservation implements JsonSerializable
{
    private const array SOURCES = ['lldp', 'cdp', 'arp', 'forwarding_table', 'route'];

    private const array KINDS = ['ethernet', 'uplink', 'observed_path', 'route'];

    /** @param array<string, bool|float|int|string|null> $evidence */
    public function __construct(
        public string $source,
        public string $kind,
        public ?string $localPort,
        public ?string $remotePort,
        public float $confidence,
        public DiscoveredIdentity $remoteIdentity,
        public array $evidence,
        public CarbonImmutable $observedAt,
    ) {
        if (! in_array($source, self::SOURCES, true)
            || ! in_array($kind, self::KINDS, true)
            || ! is_finite($confidence)
            || $confidence < 0
            || $confidence > 1
            || $remoteIdentity->evidence() === []) {
            throw new InvalidArgumentException('SNMP topology observation is invalid.');
        }
        foreach ([$localPort, $remotePort] as $port) {
            if ($port !== null
                && ($port === '' || strlen($port) > 128 || preg_match('/[\x00-\x1F\x7F]/', $port) === 1)) {
                throw new InvalidArgumentException('SNMP topology port is invalid.');
            }
        }
        if (($evidence !== [] && array_is_list($evidence)) || count($evidence) > 16) {
            throw new InvalidArgumentException('SNMP topology evidence is invalid.');
        }
        foreach ($evidence as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || preg_match('/body|authorization|cookie|credential|password|secret|token|certificate|raw_/i', $key) === 1
                || ! is_scalar($value) && $value !== null
                || is_string($value) && strlen($value) > 256) {
                throw new InvalidArgumentException('SNMP topology evidence is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $allowed = [
            'source', 'kind', 'local_port', 'remote_port', 'confidence', 'remote_identity',
            'evidence', 'observed_at',
        ];
        if (array_diff(array_keys($payload), $allowed) !== []
            || ! is_string($payload['source'] ?? null)
            || ! is_string($payload['kind'] ?? null)
            || ! is_int($payload['confidence'] ?? null) && ! is_float($payload['confidence'] ?? null)
            || ! is_array($payload['remote_identity'] ?? null)
            || ! is_array($payload['evidence'] ?? null)
            || ! is_string($payload['observed_at'] ?? null)
            || strlen($payload['observed_at']) > 64) {
            throw new InvalidArgumentException('SNMP topology observation payload is invalid.');
        }
        $identity = $payload['remote_identity'];
        $identityAllowed = [
            'provider', 'provider_id', 'serial_number', 'hardware_id', 'mac_addresses',
            'certificate_fingerprint', 'hostname', 'addresses', 'fingerprint',
        ];
        if (array_diff(array_keys($identity), $identityAllowed) !== []) {
            throw new InvalidArgumentException('SNMP topology observation payload is invalid.');
        }

        try {
            $observedAt = CarbonImmutable::parse($payload['observed_at'])->utc();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('SNMP topology observation timestamp is invalid.', previous: $exception);
        }

        return new self(
            source: $payload['source'],
            kind: $payload['kind'],
            localPort: self::optionalString($payload['local_port'] ?? null),
            remotePort: self::optionalString($payload['remote_port'] ?? null),
            confidence: (float) $payload['confidence'],
            remoteIdentity: new DiscoveredIdentity(
                provider: self::optionalString($identity['provider'] ?? null),
                providerId: self::optionalString($identity['provider_id'] ?? null),
                serialNumber: self::optionalString($identity['serial_number'] ?? null),
                hardwareId: self::optionalString($identity['hardware_id'] ?? null),
                macAddresses: self::stringList($identity['mac_addresses'] ?? []),
                certificateFingerprint: self::optionalString($identity['certificate_fingerprint'] ?? null),
                hostname: self::optionalString($identity['hostname'] ?? null),
                addresses: self::stringList($identity['addresses'] ?? []),
                fingerprint: self::optionalString($identity['fingerprint'] ?? null),
            ),
            evidence: $payload['evidence'],
            observedAt: $observedAt,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'kind' => $this->kind,
            'local_port' => $this->localPort,
            'remote_port' => $this->remotePort,
            'confidence' => $this->confidence,
            'remote_identity' => $this->remoteIdentity->snapshot(),
            'evidence' => $this->evidence,
            'observed_at' => $this->observedAt->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)
            || collect($value)->contains(fn (mixed $item): bool => ! is_string($item))) {
            throw new InvalidArgumentException('SNMP topology identity is invalid.');
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('SNMP topology value is invalid.');
        }

        return $value;
    }
}
