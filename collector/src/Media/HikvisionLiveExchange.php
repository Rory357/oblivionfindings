<?php

namespace Oblivion\Collector\Media;

use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use RuntimeException;

/** Distinct live authority, never the discovery grant. Central authorization,
 * result commitment and durable retries remain with the canonical domain owner. */
final readonly class HikvisionLiveExchange
{
    public const PROFILE = 'hikvision_rtsp_https_digest_v1';

    private const KEYS = ['version', 'type', 'capability', 'collector_uuid', 'site_id', 'camera_id', 'recorder_device_id', 'provider', 'source_kind', 'source_id', 'source_revision', 'source_fingerprint', 'binding_sha256', 'credential_binding_sha256', 'support_sha256', 'discovery_result_sha256', 'identity', 'channel', 'transport_profile', 'session_uuid', 'negotiation_uuid', 'generation', 'source_sha256', 'turn_sha256', 'offer_sha256', 'quality', 'video_codec', 'audio', 'discovery_scope_id', 'allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'issued_at', 'expires_at', 'session_expires_at'];

    public function __construct(private CredentialLeaseDecryptor $decryptor, private EnvelopeVerifier $verifier) {}

    public function frame(array $operation, array $identity, DateTimeImmutable $now): string
    {
        $kind = $operation['operation'] ?? null;
        self::exact($operation, $kind === 'open' ? ['operation', 'request_uuid', 'grant', 'credential_lease'] : ['operation', 'request_uuid', 'grant']);
        if (! in_array($kind, ['open', 'renew'], true) || ! is_array($operation['grant'])) {
            self::invalid();
        }
        self::exact($operation['grant'], ['payload', 'signature']);
        foreach (['payload', 'signature'] as $key) {
            $encoded = $operation['grant'][$key];
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($decoded) || base64_encode($decoded) !== $encoded || strlen($decoded) > 16384) {
                self::invalid();
            }
        }
        $g = $this->verifier->verify(json_encode($operation['grant'], JSON_THROW_ON_ERROR));
        self::exact($g, self::KEYS);
        if ($g['version'] !== 1 || $g['type'] !== 'collector_hikvision_live' || $g['capability'] !== 'cctv:hikvision:live:read_only' || $g['provider'] !== 'hikvision' || $g['source_kind'] !== 'direct_camera' || $g['recorder_device_id'] !== null || $g['transport_profile'] !== 'hikvision_rtsp_https_digest_v1' || $g['quality'] !== 'selected_channel' || $g['video_codec'] !== 'H264' || $g['audio'] !== false || $g['collector_uuid'] !== ($identity['collector_id'] ?? null) || $g['site_id'] !== ($identity['site_id'] ?? null)) {
            self::invalid();
        }
        foreach (['site_id', 'camera_id', 'discovery_scope_id', 'source_id', 'source_revision', 'generation'] as $key) {
            if (! is_int($g[$key]) || $g[$key] < 1 || $g[$key] > 9007199254740991) {
                self::invalid();
            }
        }
        foreach (['collector_uuid', 'session_uuid', 'negotiation_uuid'] as $key) {
            self::uuid($g[$key]);
        }
        self::uuid($operation['request_uuid']);
        foreach (['source_fingerprint', 'binding_sha256', 'credential_binding_sha256', 'support_sha256', 'discovery_result_sha256', 'source_sha256', 'turn_sha256', 'offer_sha256', 'tls_pin'] as $key) {
            if (! is_string($g[$key]) || preg_match('/\A[0-9a-f]{64}\z/', $g[$key]) !== 1) {
                self::invalid();
            }
        }
        $issued = self::instant($g['issued_at']);
        $expires = self::instant($g['expires_at']);
        $absolute = self::instant($g['session_expires_at']);
        if ($issued > $now || $expires <= $now || $expires > $issued->modify('+15 seconds') || $expires > $absolute || $absolute > $issued->modify('+1 hour')) {
            self::invalid();
        }
        $wire = json_encode(['version' => 1, 'operation' => $kind, 'request_uuid' => $operation['request_uuid'], 'grant' => $operation['grant']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($kind === 'renew') {
            return $wire;
        }
        $lease = $operation['credential_lease'];
        if (! is_array($lease)) {
            self::invalid();
        }
        self::exact($lease, ['version', 'collector_id', 'site_id', 'device_id', 'protocol', 'target', 'expires_at', 'sealed_material']);
        if ($lease['version'] !== 1 || $lease['collector_id'] !== $g['collector_uuid'] || $lease['site_id'] !== $g['site_id'] || $lease['device_id'] !== (string) $g['camera_id'] || $lease['protocol'] !== 'cctv.hikvision.live' || $lease['target'] !== $g['session_uuid'] || new DateTimeImmutable($lease['expires_at']) != $expires) {
            self::invalid();
        }
        $material = $this->decryptor->open(['credential_lease' => $lease], $now);
        try {
            self::exact($material, ['source_json', 'turn_json', 'offer_sdp']);
            foreach (['source_json' => 'source_sha256', 'turn_json' => 'turn_sha256', 'offer_sdp' => 'offer_sha256'] as $key => $digest) {
                if (! is_string($material[$key]) || ! hash_equals($g[$digest], hash('sha256', $material[$key]))) {
                    self::invalid();
                }
            }
            $source = json_decode($material['source_json'], true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($source)) {
                self::invalid();
            }
            self::exact($source, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'username', 'password']);
            foreach (['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'] as $key) {
                if ($source[$key] !== $g[$key]) {
                    self::invalid();
                }
            }
            $turn = json_decode($material['turn_json'], true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($turn) || self::instant($turn['expires_at']) < $expires || self::instant($turn['expires_at']) > $absolute) {
                self::invalid();
            }
            // Keep the exact original signed source/TURN bytes after unsealing.
            $wire = substr($wire, 0, -1).',"source":'.$material['source_json'].',"turn":'.$material['turn_json'].',"offer":'.json_encode(['type' => 'offer', 'sdp' => $material['offer_sdp']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'}';
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

    /** Accept only the requested peer/generation. The central domain still owns
     * relay SDP validation, current authority and immutable result commitment. */
    public function result(array $reply, array $operation): array
    {
        self::exact($reply, ['version', 'request_uuid', 'session_uuid', 'generation', 'status', 'answer', 'reason_code']);
        $g = $this->verifier->verify(json_encode($operation['grant'], JSON_THROW_ON_ERROR));
        if (($g['type'] ?? null) !== 'collector_hikvision_live' || $reply['version'] !== 1 || $reply['request_uuid'] !== $operation['request_uuid'] || $reply['session_uuid'] !== $g['session_uuid'] || $reply['generation'] !== $g['generation']) {
            self::invalid();
        }
        if ($reply['status'] === 'ready') {
            if ($reply['reason_code'] !== null || ! is_array($reply['answer'])) {
                self::invalid();
            }
            self::exact($reply['answer'], ['type', 'sdp']);
            if ($reply['answer']['type'] !== 'answer' || ! is_string($reply['answer']['sdp']) || strlen($reply['answer']['sdp']) < 50 || strlen($reply['answer']['sdp']) > 32768) {
                self::invalid();
            }
        } elseif ($reply['status'] === 'negotiating') {
            if ($reply['answer'] !== null || $reply['reason_code'] !== null) {
                self::invalid();
            }
        } elseif (in_array($reply['status'], ['failed', 'expired', 'stopped'], true)) {
            if ($reply['answer'] !== null || ! in_array($reply['reason_code'], ['worker_unavailable', 'authority_lost', 'source_unavailable', 'transport_unavailable', 'negotiation_failed', 'unsupported_codec', 'capacity_unavailable', 'lease_expired', 'stopped'], true)) {
                self::invalid();
            }
        } else {
            self::invalid();
        }

        return $reply;
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

    private static function uuid(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            self::invalid();
        }
    }

    private static function instant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)) {
            self::invalid();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            self::invalid();
        }

        return $date;
    }

    private static function invalid(): never
    {
        throw new RuntimeException('Live media unavailable.');
    }
}
