<?php

namespace Oblivion\Collector\Media;

use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use RuntimeException;

/** Private adapter for a future centrally authorized discovery operation.
 * No polling endpoint or public/manual vendor URL is introduced here. */
final readonly class HikvisionDiscoveryExchange
{
    public function __construct(private CredentialLeaseDecryptor $decryptor, private EnvelopeVerifier $verifier) {}

    public function frame(array $operation, array $identity, DateTimeImmutable $now): string
    {
        self::exact($operation, ['operation', 'request_uuid', 'grant', 'credential_lease']);
        if ($operation['operation'] !== 'discover_hikvision' || ! is_array($operation['grant'])) {
            self::invalid();
        }
        self::exact($operation['grant'], ['payload', 'signature']);
        $grant = $this->verifier->verify(json_encode($operation['grant'], JSON_THROW_ON_ERROR));
        self::exact($grant, ['version', 'type', 'collector_uuid', 'site_id', 'device_id', 'discovery_scope_id', 'request_uuid', 'context_sha256', 'credential_binding_sha256', 'source_sha256', 'allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'issued_at', 'expires_at']);
        if ($grant['version'] !== 1 || $grant['type'] !== 'collector_hikvision_discovery'
            || $grant['collector_uuid'] !== ($identity['collector_id'] ?? null) || $grant['site_id'] !== ($identity['site_id'] ?? null)
            || $grant['request_uuid'] !== $operation['request_uuid'] || ! is_int($grant['device_id']) || $grant['device_id'] < 1) {
            self::invalid();
        }
        $issued = self::instant($grant['issued_at']);
        $expires = self::instant($grant['expires_at']);
        if ($issued > $now || $expires <= $now || $expires > $issued->modify('+15 seconds')) {
            self::invalid();
        }
        $lease = $operation['credential_lease'];
        if (! is_array($lease)) {
            self::invalid();
        }
        self::exact($lease, ['version', 'collector_id', 'site_id', 'device_id', 'protocol', 'target', 'expires_at', 'sealed_material']);
        if ($lease['version'] !== 1 || $lease['collector_id'] !== $grant['collector_uuid'] || $lease['site_id'] !== $grant['site_id']
            || $lease['device_id'] !== (string) $grant['device_id'] || $lease['protocol'] !== 'cctv.hikvision.discovery'
            || $lease['target'] !== $grant['request_uuid'] || new DateTimeImmutable($lease['expires_at']) != $expires) {
            self::invalid();
        }
        $material = $this->decryptor->open(['credential_lease' => $lease], $now);
        try {
            self::exact($material, ['source_json']);
            $source = $material['source_json'];
            if (! is_string($source) || strlen($source) > 8192 || ! is_string($grant['source_sha256']) || ! hash_equals($grant['source_sha256'], hash('sha256', $source))) {
                self::invalid();
            }
            $decoded = json_decode($source, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                self::invalid();
            }
            self::exact($decoded, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'username', 'password']);
            foreach (['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'] as $key) {
                if ($decoded[$key] !== $grant[$key]) {
                    self::invalid();
                }
            }
            $wire = json_encode(['version' => 1, 'operation' => 'discover_hikvision', 'request_uuid' => $operation['request_uuid'], 'grant' => $operation['grant']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            // Preserve the exact signed bytes from the sealed lease.
            $wire = substr($wire, 0, -1).',"source":'.$source.'}';
            if (strlen($wire) > 65536) {
                self::invalid();
            }

            return $wire;
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret)) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);
        }
    }

    /** Central commitment still owns fresh authorization, replay and durability. */
    public function exchange(array $operation, array $identity, $input, $output, DateTimeImmutable $now): array
    {
        $wire = $this->frame($operation, $identity, $now);
        try {
            PrivateFrames::write($output, $wire);
        } finally {
            sodium_memzero($wire);
        }
        $grant = $this->verifier->verify(json_encode($operation['grant'], JSON_THROW_ON_ERROR));
        for ($i = 0; $i < 8; $i++) {
            $reply = PrivateFrames::read($input);
            if (($reply['operation'] ?? null) !== 'discover_hikvision') {
                // An active video watchdog may emit a terminal event meanwhile.
                self::exact($reply, ['version', 'request_uuid', 'session_uuid', 'generation', 'status', 'answer', 'reason_code']);
                if ($reply['version'] !== 1) {
                    self::invalid();
                }

                continue;
            }
            self::exact($reply, ['version', 'operation', 'request_uuid', 'device_id', 'context_sha256', 'credential_binding_sha256', 'observed_at', 'status', 'inventory', 'reason_code']);
            if ($reply['version'] !== 1 || $reply['request_uuid'] !== $grant['request_uuid'] || $reply['device_id'] !== $grant['device_id']
                || $reply['context_sha256'] !== $grant['context_sha256'] || $reply['credential_binding_sha256'] !== $grant['credential_binding_sha256']) {
                self::invalid();
            }
            $observed = self::instant($reply['observed_at']);
            if ($observed < self::instant($grant['issued_at']) || $observed >= self::instant($grant['expires_at'])
                || ! in_array($reply['status'], ['observed', 'failed'], true)
                || ($reply['status'] === 'observed' ? ! is_array($reply['inventory']) || $reply['reason_code'] !== null : $reply['inventory'] !== null || ! in_array($reply['reason_code'], ['source_unavailable', 'authority_lost', 'capacity_unavailable'], true))) {
                self::invalid();
            }

            return $reply;
        }
        self::invalid();
    }

    private static function instant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value) !== 1) {
            self::invalid();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            self::invalid();
        }

        return $date;
    }

    private static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new RuntimeException('Discovery authority unavailable.');
    }
}
