<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Private wire issuer/parser. Callers must first lock/revalidate current actor,
 * canonical device/Site, enrolled collector incarnation, network and secret
 * reference revision. This class does not grant user or device authority. */
final class HikvisionDiscoveryContract
{
    public const OPERATION = 'discover_hikvision';

    public const TYPE = 'collector_hikvision_discovery';

    private const MAX_ID = 9007199254740991;

    public static function issue(
        MonitoringCollector $collector,
        array $binding,
        #[\SensitiveParameter] array $source,
        CarbonImmutable $issued,
        CarbonImmutable $expires,
        #[\SensitiveParameter] string $signingSecretKey,
    ): array {
        self::exact($binding, ['collector_uuid', 'site_id', 'device_id', 'discovery_scope_id', 'request_uuid', 'context_sha256', 'credential_binding_sha256']);
        self::uuid($binding['collector_uuid']);
        self::uuid($binding['request_uuid']);
        foreach (['site_id', 'device_id', 'discovery_scope_id'] as $key) {
            self::positive($binding[$key]);
        }
        foreach (['context_sha256', 'credential_binding_sha256'] as $key) {
            self::digest($binding[$key]);
        }
        if ($binding['collector_uuid'] !== $collector->collector_uuid || $binding['site_id'] !== (int) $collector->site_id
            || strlen($signingSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            self::invalid();
        }
        self::source($source);
        if ($issued->micro !== 0 || $expires->micro !== 0 || $expires->lte($issued) || $expires->gt($issued->addSeconds(15))) {
            self::invalid();
        }
        $sourceJson = self::json($source);
        $payload = [
            'version' => 1, 'type' => self::TYPE,
            ...$binding,
            'source_sha256' => hash('sha256', $sourceJson),
            'allowed_ip' => $source['allowed_ip'], 'allowed_port' => $source['allowed_port'],
            'tls_server_name' => $source['tls_server_name'], 'tls_pin' => $source['tls_pin'],
            'issued_at' => $issued->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        try {
            $raw = self::json($payload);
            $grant = ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached($raw, $signingSecretKey))];
            $lease = (new CollectorCredentialLeaseSealer)->seal(
                $collector, (string) $binding['device_id'], 'cctv.hikvision.discovery', $binding['request_uuid'],
                new CredentialLease($binding['request_uuid'], $expires, ['source_json' => $sourceJson], $issued),
            );

            return ['operation' => self::OPERATION, 'request_uuid' => $binding['request_uuid'], 'grant' => $grant, 'credential_lease' => $lease];
        } finally {
            sodium_memzero($sourceJson);
        }
    }

    public static function endpoint(array $endpoint): void
    {
        self::exact($endpoint, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin']);
        self::source([...$endpoint, 'username' => 'validation', 'password' => 'validation']);
    }

    private static function source(array $source): void
    {
        self::exact($source, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'username', 'password']);
        $ip = $source['allowed_ip'];
        $packed = is_string($ip) ? @inet_pton($ip) : false;
        if ($packed === false) {
            self::invalid();
        }
        // Normalize IPv4-mapped addresses for the same unspecified/multicast
        // denial as the worker, without rewriting the signed literal endpoint.
        $address = strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff"
            ? substr($packed, 12) : $packed;
        if ($address === str_repeat("\0", strlen($address))
            || (strlen($address) === 4 ? ord($address[0]) >= 224 && ord($address[0]) <= 239 : ord($address[0]) === 255)) {
            self::invalid();
        }
        self::positive($source['allowed_port']);
        if ($source['allowed_port'] > 65535) {
            self::invalid();
        }
        $name = $source['tls_server_name'];
        if (! is_string($name) || strlen($name) > 253 || (filter_var($name, FILTER_VALIDATE_IP) === false
            && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/', $name) !== 1)) {
            self::invalid();
        }
        self::digest($source['tls_pin']);
        foreach (['username' => 128, 'password' => 1024] as $key => $limit) {
            if (! is_string($source[$key]) || strlen($source[$key]) < 1 || strlen($source[$key]) > $limit || preg_match('/[^\x20-\x7e]/', $source[$key])) {
                self::invalid();
            }
        }
    }

    /** Parse observations only. Result commitment must independently revalidate
     * current authority/credential/network context and immutable request identity. */
    public static function result(array $value): array
    {
        self::exact($value, ['version', 'operation', 'request_uuid', 'device_id', 'context_sha256', 'credential_binding_sha256', 'observed_at', 'status', 'inventory', 'reason_code']);
        if ($value['version'] !== 1 || $value['operation'] !== self::OPERATION) {
            self::invalid();
        }
        self::uuid($value['request_uuid']);
        self::positive($value['device_id']);
        self::digest($value['context_sha256']);
        self::digest($value['credential_binding_sha256']);
        self::instant($value['observed_at']);
        if (strlen(self::json($value)) > 65536) {
            self::invalid();
        }
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
        self::exact($inventory, ['identity', 'channels']);
        if (! is_array($inventory['identity'])) {
            self::invalid();
        }
        self::exact($inventory['identity'], ['model', 'serial', 'device_id', 'firmware', 'firmware_build']);
        foreach ($inventory['identity'] as $key => $text) {
            self::observationText($text, $key !== 'firmware_build', 128);
        }
        if (! is_array($inventory['channels']) || ! array_is_list($inventory['channels']) || $inventory['channels'] === [] || count($inventory['channels']) > 256) {
            self::invalid();
        }
        $seen = [];
        foreach ($inventory['channels'] as $channel) {
            if (! is_array($channel)) {
                self::invalid();
            }
            self::exact($channel, ['id', 'input_id', 'name', 'codec', 'enabled', 'video_enabled', 'width', 'height', 'fps100', 'audio_enabled']);
            foreach (['id', 'input_id'] as $key) {
                if (! is_string($channel[$key]) || preg_match('/\A[1-9][0-9]{0,8}\z/', $channel[$key]) !== 1) {
                    self::invalid();
                }
            }
            if (isset($seen[$channel['id']])) {
                self::invalid();
            }
            $seen[$channel['id']] = true;
            self::observationText($channel['name'], false, 128);
            self::observationText($channel['codec'], true, 32);
            foreach (['enabled', 'video_enabled'] as $key) {
                if (! is_bool($channel[$key])) {
                    self::invalid();
                }
            }
            if (! is_bool($channel['audio_enabled']) && $channel['audio_enabled'] !== null) {
                self::invalid();
            }
            foreach (['width' => 16384, 'height' => 16384, 'fps100' => 12000] as $key => $limit) {
                self::positive($channel[$key]);
                if ($channel[$key] > $limit) {
                    self::invalid();
                }
            }
        }

        return $value;
    }

    private static function observationText(mixed $value, bool $required, int $max): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || strlen($value) > $max || ($required && $value === '') || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            self::invalid();
        }
    }

    private static function positive(mixed $value): void
    {
        if (! is_int($value) || $value < 1 || $value > self::MAX_ID) {
            self::invalid();
        }
    }

    private static function uuid(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
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
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value) !== 1) {
            self::invalid();
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            self::invalid();
        }
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

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('invalid_request');
    }
}
