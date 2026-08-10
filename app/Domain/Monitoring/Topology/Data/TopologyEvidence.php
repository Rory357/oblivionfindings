<?php

namespace App\Domain\Monitoring\Topology\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class TopologyEvidence implements JsonSerializable
{
    private const array SOURCES = [
        'lldp',
        'cdp',
        'arp',
        'forwarding_table',
        'route',
        'provider',
    ];

    private const array KINDS = [
        'ethernet',
        'uplink',
        'observed_path',
        'route',
        'wireless',
        'logical',
    ];

    /**
     * @param  array<string, bool|float|int|string|null>  $evidence
     */
    public function __construct(
        public string $source,
        public ?int $fromDeviceId,
        public ?int $toDeviceId,
        public string $kind,
        public ?string $localPort,
        public ?string $remotePort,
        public float $confidence,
        public array $evidence,
        public ?int $fromCandidateId = null,
        public ?int $toCandidateId = null,
        public ?string $fromObservedIdentityHash = null,
        public ?string $toObservedIdentityHash = null,
        public ?CarbonImmutable $observedAt = null,
    ) {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Topology source is invalid.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Topology kind is invalid.');
        }
        if (! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Topology confidence is invalid.');
        }

        $this->validatePort($localPort);
        $this->validatePort($remotePort);
        $this->validateEndpoint($fromDeviceId, $fromCandidateId, $fromObservedIdentityHash);
        $this->validateEndpoint($toDeviceId, $toCandidateId, $toObservedIdentityHash);
        $this->validateEvidence($evidence);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $allowed = [
            'source', 'from_device_id', 'to_device_id', 'kind', 'local_port', 'remote_port',
            'confidence', 'evidence', 'from_candidate_id', 'to_candidate_id',
            'from_observed_identity_hash', 'to_observed_identity_hash', 'observed_at',
        ];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new InvalidArgumentException('Topology evidence payload is invalid.');
        }

        $observedAt = null;
        if (array_key_exists('observed_at', $payload) && $payload['observed_at'] !== null) {
            if (! is_string($payload['observed_at']) || strlen($payload['observed_at']) > 64) {
                throw new InvalidArgumentException('Topology evidence timestamp is invalid.');
            }
            try {
                $observedAt = CarbonImmutable::parse($payload['observed_at'])->utc();
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException('Topology evidence timestamp is invalid.', previous: $exception);
            }
        }

        if (! is_string($payload['source'] ?? null)
            || ! is_string($payload['kind'] ?? null)
            || ! is_int($payload['from_device_id'] ?? null) && ($payload['from_device_id'] ?? null) !== null
            || ! is_int($payload['to_device_id'] ?? null) && ($payload['to_device_id'] ?? null) !== null
            || ! is_int($payload['from_candidate_id'] ?? null) && ($payload['from_candidate_id'] ?? null) !== null
            || ! is_int($payload['to_candidate_id'] ?? null) && ($payload['to_candidate_id'] ?? null) !== null
            || ! is_array($payload['evidence'] ?? null)
            || ! is_int($payload['confidence'] ?? null) && ! is_float($payload['confidence'] ?? null)) {
            throw new InvalidArgumentException('Topology evidence payload is invalid.');
        }

        return new self(
            source: $payload['source'],
            fromDeviceId: $payload['from_device_id'] ?? null,
            toDeviceId: $payload['to_device_id'] ?? null,
            kind: $payload['kind'],
            localPort: self::optionalString($payload['local_port'] ?? null),
            remotePort: self::optionalString($payload['remote_port'] ?? null),
            confidence: (float) $payload['confidence'],
            evidence: $payload['evidence'],
            fromCandidateId: $payload['from_candidate_id'] ?? null,
            toCandidateId: $payload['to_candidate_id'] ?? null,
            fromObservedIdentityHash: self::optionalString($payload['from_observed_identity_hash'] ?? null),
            toObservedIdentityHash: self::optionalString($payload['to_observed_identity_hash'] ?? null),
            observedAt: $observedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'from_device_id' => $this->fromDeviceId,
            'to_device_id' => $this->toDeviceId,
            'kind' => $this->kind,
            'local_port' => $this->localPort,
            'remote_port' => $this->remotePort,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'from_candidate_id' => $this->fromCandidateId,
            'to_candidate_id' => $this->toCandidateId,
            'from_observed_identity_hash' => $this->fromObservedIdentityHash,
            'to_observed_identity_hash' => $this->toObservedIdentityHash,
            'observed_at' => $this->observedAt?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array{device_id: ?int, candidate_id: ?int, identity_hash: ?string} */
    public function fromEndpoint(): array
    {
        return [
            'device_id' => $this->fromDeviceId,
            'candidate_id' => $this->fromCandidateId,
            'identity_hash' => $this->fromObservedIdentityHash,
        ];
    }

    /** @return array{device_id: ?int, candidate_id: ?int, identity_hash: ?string} */
    public function toEndpoint(): array
    {
        return [
            'device_id' => $this->toDeviceId,
            'candidate_id' => $this->toCandidateId,
            'identity_hash' => $this->toObservedIdentityHash,
        ];
    }

    private function validatePort(?string $port): void
    {
        if ($port !== null
            && ($port === '' || strlen($port) > 128 || preg_match('/[\x00-\x1F\x7F]/', $port) === 1)) {
            throw new InvalidArgumentException('Topology port is invalid.');
        }
    }

    private function validateEndpoint(?int $deviceId, ?int $candidateId, ?string $identityHash): void
    {
        $values = array_filter([$deviceId, $candidateId, $identityHash], fn (mixed $value): bool => $value !== null);
        if (count($values) !== 1
            || ($deviceId !== null && $deviceId < 1)
            || ($candidateId !== null && $candidateId < 1)
            || ($identityHash !== null && preg_match('/^[a-f0-9]{64}$/', $identityHash) !== 1)) {
            throw new InvalidArgumentException('Topology endpoint is invalid.');
        }
    }

    /** @param array<string, mixed> $evidence */
    private function validateEvidence(array $evidence): void
    {
        if (($evidence !== [] && array_is_list($evidence)) || count($evidence) > 32) {
            throw new InvalidArgumentException('Topology evidence is invalid.');
        }
        foreach ($evidence as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || preg_match('/body|authorization|cookie|credential|password|secret|token|certificate|raw_/i', $key) === 1
                || ! is_scalar($value) && $value !== null
                || is_string($value) && strlen($value) > 512) {
                throw new InvalidArgumentException('Topology evidence is invalid.');
            }
        }
        if (strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > 8192) {
            throw new InvalidArgumentException('Topology evidence is invalid.');
        }
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Topology evidence payload is invalid.');
        }

        return $value;
    }
}
