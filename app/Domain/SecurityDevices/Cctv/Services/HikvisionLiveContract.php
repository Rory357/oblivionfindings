<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use Carbon\CarbonImmutable;

/** Private wire issuer only. The domain caller owns fresh viewer/media permission,
 * active source/support and governed credential authority, immutable action
 * receipts and the lease's absolute lifetime. This cannot authorize a user. */
final class HikvisionLiveContract
{
    public const TYPE = 'collector_hikvision_live';

    public const CAPABILITY = 'cctv:hikvision:live:read_only';

    public const BINDING = ['collector_uuid', 'site_id', 'camera_id', 'discovery_scope_id', 'source_id', 'source_revision', 'source_fingerprint', 'binding_sha256', 'credential_binding_sha256', 'support_sha256', 'discovery_result_sha256', 'identity', 'channel', 'session_uuid', 'negotiation_uuid', 'generation', 'session_expires_at'];

    public const KEYS = ['version', 'type', 'capability', 'collector_uuid', 'site_id', 'camera_id', 'recorder_device_id', 'provider', 'source_kind', 'source_id', 'source_revision', 'source_fingerprint', 'binding_sha256', 'credential_binding_sha256', 'support_sha256', 'discovery_result_sha256', 'identity', 'channel', 'transport_profile', 'session_uuid', 'negotiation_uuid', 'generation', 'source_sha256', 'turn_sha256', 'offer_sha256', 'quality', 'video_codec', 'audio', 'discovery_scope_id', 'allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'issued_at', 'expires_at', 'session_expires_at'];

    public static function issue(MonitoringCollector $collector, array $binding, #[\SensitiveParameter] array $source, #[\SensitiveParameter] array $turn, string $offer, string $request, CarbonImmutable $issued, CarbonImmutable $expires, #[\SensitiveParameter] string $key, string $operation = 'open', ?string $sourceBytes = null, ?string $turnBytes = null): array
    {
        if (! in_array($operation, ['open', 'renew'], true)) {
            self::invalid();
        }
        CctvContract::exact($binding, self::BINDING);
        foreach (['collector_uuid', 'session_uuid', 'negotiation_uuid'] as $field) {
            CctvContract::uuid($binding[$field]);
        }
        CctvContract::uuid($request);
        foreach (['site_id', 'camera_id', 'discovery_scope_id', 'source_id', 'source_revision', 'generation'] as $field) {
            self::positive($binding[$field]);
        }
        foreach (['source_fingerprint', 'binding_sha256', 'credential_binding_sha256', 'support_sha256', 'discovery_result_sha256'] as $field) {
            self::digest($binding[$field]);
        }
        self::metadata($binding['identity'], $binding['channel']);
        self::source($source);
        self::turn($turn);
        CctvContract::relaySdp($offer, $turn, 'recvonly');
        $absolute = self::instant($binding['session_expires_at']);
        $ice = self::instant($turn['expires_at']);
        if ($collector->collector_uuid !== $binding['collector_uuid'] || (int) $collector->site_id !== $binding['site_id'] || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || $issued->micro !== 0 || $expires->micro !== 0 || $expires->lte($issued) || $expires->gt($issued->addSeconds(15)) || $expires->gt($absolute) || $expires->gt($ice) || $ice->gt($absolute) || $absolute->gt($issued->addHour())) {
            self::invalid();
        }
        $sourceJson = self::original($source, $sourceBytes);
        $turnJson = self::original($turn, $turnBytes);
        try {
            $payload = ['version' => 1, 'type' => self::TYPE, 'capability' => self::CAPABILITY, ...$binding, 'recorder_device_id' => null, 'provider' => 'hikvision', 'source_kind' => 'direct_camera', 'transport_profile' => HikvisionSourcePolicy::PROFILE, 'source_sha256' => hash('sha256', $sourceJson), 'turn_sha256' => hash('sha256', $turnJson), 'offer_sha256' => hash('sha256', $offer), 'quality' => 'selected_channel', 'video_codec' => 'H264', 'audio' => false, 'allowed_ip' => $source['allowed_ip'], 'allowed_port' => $source['allowed_port'], 'tls_server_name' => $source['tls_server_name'], 'tls_pin' => $source['tls_pin'], 'issued_at' => $issued->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z')];
            CctvContract::exact($payload, self::KEYS);
            $bytes = CctvContract::json($payload);
            $grant = ['payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $key))];
            $result = ['operation' => $operation, 'request_uuid' => $request, 'grant' => $grant];
            if ($operation === 'open') {
                $result['credential_lease'] = (new CollectorCredentialLeaseSealer)->seal($collector, (string) $binding['camera_id'], 'cctv.hikvision.live', $binding['session_uuid'], new CredentialLease($request, $expires, ['source_json' => $sourceJson, 'turn_json' => $turnJson, 'offer_sdp' => $offer], $issued));
            }

            return $result;
        } finally {
            sodium_memzero($sourceJson);
            sodium_memzero($turnJson);
        }
    }

    private static function original(array $value, ?string $bytes): string
    {
        if ($bytes === null) {
            return CctvContract::json($value);
        }
        if (strlen($bytes) > 16384 || json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) !== $value) {
            self::invalid();
        }

        return $bytes;
    }

    public static function metadata(mixed $identity, mixed $channel): void
    {
        if (! is_array($identity) || ! is_array($channel)) {
            self::invalid();
        }
        CctvContract::exact($identity, ['model', 'serial', 'device_id', 'firmware', 'firmware_build']);
        foreach ($identity as $field => $value) {
            self::observationText($value, $field !== 'firmware_build', 128);
        }
        CctvContract::exact($channel, ['id', 'input_id', 'name', 'codec', 'enabled', 'video_enabled', 'width', 'height', 'fps100', 'audio_enabled']);
        foreach (['id', 'input_id'] as $field) {
            if (! is_string($channel[$field]) || preg_match('/\A[1-9][0-9]{0,8}\z/', $channel[$field]) !== 1) {
                self::invalid();
            }
        }
        self::observationText($channel['name'], false, 128);
        if (! in_array($channel['codec'], ['H264', 'H.264'], true) || $channel['enabled'] !== true || $channel['video_enabled'] !== true || (! is_bool($channel['audio_enabled']) && $channel['audio_enabled'] !== null)) {
            self::invalid();
        }
        foreach (['width' => 16384, 'height' => 16384, 'fps100' => 12000] as $field => $max) {
            self::positive($channel[$field]);
            if ($channel[$field] > $max) {
                self::invalid();
            }
        }
    }

    private static function source(array $source): void
    {
        CctvContract::exact($source, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'username', 'password']);
        HikvisionDiscoveryContract::endpoint(array_intersect_key($source, array_flip(['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'])));
        foreach (['username' => 128, 'password' => 1024] as $field => $max) {
            if (! is_string($source[$field]) || strlen($source[$field]) < 1 || strlen($source[$field]) > $max || preg_match('/[^\x20-\x7e]/', $source[$field])) {
                self::invalid();
            }
        }
    }

    private static function turn(array $turn): void
    {
        CctvContract::exact($turn, ['urls', 'username', 'credential', 'expires_at', 'relay_cidrs', 'relay_port_min', 'relay_port_max']);
        if (! is_array($turn['urls']) || ! array_is_list($turn['urls']) || count($turn['urls']) < 1 || count($turn['urls']) > 4 || ! is_array($turn['relay_cidrs']) || ! array_is_list($turn['relay_cidrs']) || count($turn['relay_cidrs']) < 1 || count($turn['relay_cidrs']) > 16) {
            self::invalid();
        }
        foreach ($turn['urls'] as $url) {
            CctvContract::text($url, 1, 512);
            if (! preg_match('/\Aturns?:[^\/?#@]+\?transport=(udp|tcp)\z/', $url)) {
                self::invalid();
            }
        }
        foreach ($turn['relay_cidrs'] as $cidr) {
            CctvContract::text($cidr, 1, 128);
            $parts = explode('/', $cidr);
            $ip = inet_pton($parts[0]);
            if (count($parts) !== 2 || $ip === false || ! ctype_digit($parts[1]) || (int) $parts[1] > strlen($ip) * 8) {
                self::invalid();
            }
        }
        CctvContract::text($turn['username'], 1, 256);
        CctvContract::text($turn['credential'], 1, 1024);
        self::positive($turn['relay_port_min']);
        self::positive($turn['relay_port_max']);
        if ($turn['relay_port_min'] > $turn['relay_port_max'] || $turn['relay_port_max'] > 65535) {
            self::invalid();
        }
    }

    private static function observationText(mixed $text, bool $required, int $max): void
    {
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || strlen($text) > $max || ($required && $text === '') || preg_match('/[\p{Cc}\p{Cf}]/u', $text)) {
            self::invalid();
        }
    }

    private static function positive(mixed $value): void
    {
        CctvContract::positive($value);
        if ($value > 9007199254740991) {
            self::invalid();
        }
    }

    private static function digest(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            self::invalid();
        }
    }

    private static function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            self::invalid();
        }
        $instant = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        if (! $instant || $instant->format('Y-m-d\TH:i:s\Z') !== $value) {
            self::invalid();
        }

        return $instant;
    }

    private static function invalid(): never
    {
        throw new \InvalidArgumentException('Live media unavailable.');
    }
}
