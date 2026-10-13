<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Recorder observations only. Identity/channel status cannot grant a camera
 * association, activate media, follow a returned address or change equipment. */
final class HikvisionRecorderDiscoveryContract
{
    public const OPERATION = 'discover_hikvision_recorder';

    public const TYPE = 'collector_hikvision_recorder_discovery';

    public const PROFILE = 'hikvision_recorder_isapi_https_v2';

    public const CAPABILITY = 'cctv:hikvision:recorder:discovery:read_only';

    public const PROTOCOL = 'cctv.hikvision.recorder.discovery';

    public const RESOURCE_SET = 'recorder_identity_streams_input_status_v2';

    public const GRANT = ['version', 'type', 'capability', 'source_kind', 'resource_set', 'collector_uuid', 'site_id', 'device_id', 'discovery_scope_id', 'request_uuid', 'context_sha256', 'credential_binding_sha256', 'source_sha256', 'allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'issued_at', 'expires_at'];

    public static function issue(MonitoringCollector $collector, array $binding, #[\SensitiveParameter] array $source, CarbonImmutable $issued, CarbonImmutable $expires, #[\SensitiveParameter] string $secret): array
    {
        CctvContract::exact($binding, ['collector_uuid', 'site_id', 'device_id', 'discovery_scope_id', 'request_uuid', 'context_sha256', 'credential_binding_sha256']);
        CctvContract::uuid($binding['collector_uuid']);
        CctvContract::uuid($binding['request_uuid']);
        foreach (['site_id', 'device_id', 'discovery_scope_id'] as $key) {
            CctvContract::positive($binding[$key]);
        }
        foreach (['context_sha256', 'credential_binding_sha256'] as $key) {
            self::digest($binding[$key]);
        }
        CctvContract::exact($source, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'username', 'password']);
        HikvisionDiscoveryContract::endpoint(array_intersect_key($source, array_flip(['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'])));
        foreach (['username' => 128, 'password' => 1024] as $key => $max) {
            if (! is_string($source[$key]) || $source[$key] === '' || strlen($source[$key]) > $max || preg_match('/[^\x20-\x7e]/', $source[$key])) {
                self::invalid();
            }
        }
        if ($binding['collector_uuid'] !== $collector->collector_uuid || $binding['site_id'] !== (int) $collector->site_id || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || $issued->micro !== 0 || $expires->micro !== 0 || $expires->lte($issued) || $expires->gt($issued->addSeconds(15))) {
            self::invalid();
        }
        $sourceJson = self::json($source);
        try {
            $payload = ['version' => 2, 'type' => self::TYPE, 'capability' => self::CAPABILITY, 'source_kind' => 'recorder', 'resource_set' => self::RESOURCE_SET, ...$binding,
                'source_sha256' => hash('sha256', $sourceJson), 'allowed_ip' => $source['allowed_ip'], 'allowed_port' => $source['allowed_port'], 'tls_server_name' => $source['tls_server_name'], 'tls_pin' => $source['tls_pin'],
                'issued_at' => $issued->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z')];
            $raw = self::json($payload);
            $lease = (new CollectorCredentialLeaseSealer)->seal($collector, (string) $binding['device_id'], self::PROTOCOL, $binding['request_uuid'], new CredentialLease($binding['request_uuid'], $expires, ['source_json' => $sourceJson], $issued));

            return ['operation' => self::OPERATION, 'request_uuid' => $binding['request_uuid'], 'grant' => ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached($raw, $secret))], 'credential_lease' => $lease];
        } finally {
            sodium_memzero($sourceJson);
        }
    }

    public static function result(array $value): array
    {
        CctvContract::exact($value, ['version', 'operation', 'request_uuid', 'device_id', 'context_sha256', 'credential_binding_sha256', 'observed_at', 'status', 'inventory', 'reason_code']);
        if ($value['version'] !== 2 || $value['operation'] !== self::OPERATION || strlen(self::json($value)) > 65536) {
            self::invalid();
        }
        CctvContract::uuid($value['request_uuid']);
        CctvContract::positive($value['device_id']);
        self::digest($value['context_sha256']);
        self::digest($value['credential_binding_sha256']);
        self::instant($value['observed_at']);
        if ($value['status'] === 'failed') {
            if ($value['inventory'] !== null || ! in_array($value['reason_code'], ['source_unavailable', 'authority_lost', 'capacity_unavailable'], true)) {
                self::invalid();
            }

            return $value;
        }
        if ($value['status'] !== 'observed' || $value['reason_code'] !== null || ! is_array($value['inventory'])) {
            self::invalid();
        }
        $inventory = $value['inventory'];
        CctvContract::exact($inventory, ['identity', 'channels', 'inputs', 'response_sha256']);
        if (! is_array($inventory['identity'])) {
            self::invalid();
        }
        CctvContract::exact($inventory['identity'], ['model', 'serial', 'device_id', 'firmware', 'firmware_build']);
        foreach ($inventory['identity'] as $key => $text) {
            self::text($text, $key !== 'firmware_build', 128);
        }
        self::list($inventory['channels'], false);
        $seen = [];
        foreach ($inventory['channels'] as $channel) {
            if (! is_array($channel)) {
                self::invalid();
            }
            CctvContract::exact($channel, ['id', 'input_id', 'input_kind', 'name', 'codec', 'enabled', 'video_enabled', 'width', 'height', 'fps100', 'audio_enabled']);
            foreach (['id', 'input_id'] as $key) {
                self::identifier($channel[$key]);
            }
            if (isset($seen[$channel['id']]) || ! in_array($channel['input_kind'], ['digital_proxy', 'local_video'], true)) {
                self::invalid();
            }
            $seen[$channel['id']] = true;
            self::text($channel['name'], false, 128);
            self::text($channel['codec'], true, 32);
            foreach (['enabled', 'video_enabled'] as $key) {
                if (! is_bool($channel[$key])) {
                    self::invalid();
                }
            }
            if (! is_bool($channel['audio_enabled']) && $channel['audio_enabled'] !== null) {
                self::invalid();
            }
            foreach (['width' => 16384, 'height' => 16384, 'fps100' => 12000] as $key => $max) {
                CctvContract::positive($channel[$key], $key === 'fps100');
                if ($channel[$key] > $max) {
                    self::invalid();
                }
            }
        }
        self::list($inventory['inputs'], true);
        $seen = [];
        foreach ($inventory['inputs'] as $input) {
            if (! is_array($input)) {
                self::invalid();
            }
            CctvContract::exact($input, ['id', 'proxy_protocol', 'addressing_type', 'address', 'management_port', 'source_input_id', 'online', 'camera_identity', 'association']);
            self::identifier($input['id']);
            if (isset($seen[$input['id']]) || ! in_array($input['addressing_type'], ['ipaddress', 'hostname'], true) || $input['camera_identity'] !== null || $input['association'] !== 'unverified') {
                self::invalid();
            }
            $seen[$input['id']] = true;
            self::text($input['proxy_protocol'], true, 32);
            if ($input['address'] !== null) {
                self::text($input['address'], true, 253);
                if ($input['addressing_type'] === 'ipaddress' ? filter_var($input['address'], FILTER_VALIDATE_IP) === false : preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/', $input['address']) !== 1) {
                    self::invalid();
                }
            }
            if ($input['management_port'] !== null) {
                CctvContract::positive($input['management_port'], true);
                if ($input['management_port'] > 65535) {
                    self::invalid();
                }
            }
            if ($input['source_input_id'] !== null && (! is_string($input['source_input_id']) || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/', $input['source_input_id']) !== 1)) {
                self::invalid();
            }
            if ($input['online'] !== null && ! is_bool($input['online'])) {
                self::invalid();
            }
        }
        if (! is_array($inventory['response_sha256'])) {
            self::invalid();
        }
        CctvContract::exact($inventory['response_sha256'], ['device_info', 'streaming_proxy', 'input_status']);
        foreach ($inventory['response_sha256'] as $hash) {
            self::digest($hash);
        }

        return $value;
    }

    private static function list(mixed $value, bool $empty): void
    {
        if (! is_array($value) || ! array_is_list($value) || (! $empty && $value === []) || count($value) > 256) {
            self::invalid();
        }
    }

    private static function identifier(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,8}\z/', $value) !== 1) {
            self::invalid();
        }
    }

    private static function text(mixed $value, bool $required, int $max): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || strlen($value) > $max || ($required && $value === '') || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            self::invalid();
        }
    }

    private static function digest(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            self::invalid();
        }
    }

    private static function instant(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            self::invalid();
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (! $time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            self::invalid();
        }
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('invalid_request');
    }
}
