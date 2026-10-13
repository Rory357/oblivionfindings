<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Services\CidrMatcher;
use InvalidArgumentException;

final class CctvContract
{
    public const TERMINAL = ['stopped', 'expired', 'revoked', 'failed'];

    public const REASONS = ['stopped', 'browser_closed', 'source_unavailable', 'source_changed', 'unsupported_version', 'unsupported_codec', 'worker_unavailable', 'authority_lost', 'lease_expired', 'session_expired', 'capacity_unavailable', 'negotiation_failed', 'transport_unavailable'];

    public static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    public static function positive(mixed $value, bool $zero = false): int
    {
        if (! is_int($value) || $value < ($zero ? 0 : 1) || $value > PHP_INT_MAX) {
            self::invalid();
        }

        return $value;
    }

    public static function uuid(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            self::invalid();
        }

        return $value;
    }

    public static function text(mixed $value, int $min, int $max): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || trim($value) !== $value || mb_strlen($value) < $min || mb_strlen($value) > $max || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            self::invalid();
        }

        return $value;
    }

    public static function providerId(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $value) !== 1) {
            self::invalid();
        }

        return $value;
    }

    public static function sourceInput(array $value): array
    {
        self::exact($value, ['expected_actor_id', 'request_key', 'expected_revision', 'recorder_device_id', 'collector_id', 'discovery_scope_id', 'console_id', 'provider_camera_id', 'stream_address', 'stream_port', 'tls_server_name', 'tls_pin', 'purpose']);
        foreach (['expected_actor_id', 'recorder_device_id', 'collector_id', 'discovery_scope_id', 'stream_port'] as $key) {
            self::positive($value[$key]);
        }
        self::positive($value['expected_revision'], true);
        self::uuid($value['request_key']);
        self::providerId($value['console_id']);
        self::providerId($value['provider_camera_id']);
        if ($value['stream_port'] > 65535 || ! is_string($value['stream_address']) || filter_var($value['stream_address'], FILTER_VALIDATE_IP) === false || strtolower((string) inet_ntop(inet_pton($value['stream_address']))) !== $value['stream_address']) {
            self::invalid();
        }
        if (! is_string($value['tls_server_name']) || strlen($value['tls_server_name']) > 253 || strtolower($value['tls_server_name']) !== $value['tls_server_name'] || filter_var($value['tls_server_name'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            self::invalid();
        }
        if (! is_string($value['tls_pin']) || preg_match('/\A[0-9a-f]{64}\z/', $value['tls_pin']) !== 1) {
            self::invalid();
        }
        self::text($value['purpose'], 10, 500);

        return $value;
    }

    /** Closed ordered JSON bytes must survive sealing without re-encoding. */
    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function hash(array $value): string
    {
        $canonical = $value;
        ksort($canonical);

        return hash('sha256', self::json($canonical));
    }

    public static function sdp(mixed $value, string $direction): string
    {
        if (! is_string($value) || strlen($value) < 50 || strlen($value) > 32768 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || ! in_array($direction, ['recvonly', 'sendonly'], true)) {
            self::invalid();
        }
        $lines = preg_split('/\r?\n/', $value);
        $media = [];
        $maps = [];
        $candidates = 0;
        $directionCount = 0;
        $fingerprints = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'm=')) {
                if (! preg_match('/\Am=video [1-9][0-9]* UDP\/TLS\/RTP\/SAVPF ((?:[0-9]+ ?)+)\z/', $line, $match)) {
                    self::invalid();
                }
                $media[] = array_map('intval', explode(' ', trim($match[1])));
            }
            if (str_starts_with($line, 'a=fingerprint:')) {
                if (preg_match('/\Aa=fingerprint:sha-256 (?:[0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2}\z/', $line) !== 1) {
                    self::invalid();
                }
                $fingerprints++;
            }
            if (str_starts_with($line, 'a=rtpmap:')) {
                if (! preg_match('/\Aa=rtpmap:([0-9]+) H264\/90000\z/i', $line, $match)) {
                    self::invalid();
                }
                $maps[] = (int) $match[1];
            }
            if (preg_match('/\Aa=(sendrecv|recvonly|sendonly|inactive)\z/', $line, $match)) {
                if ($match[1] !== $direction) {
                    self::invalid();
                }
                $directionCount++;
            }
            if (str_starts_with($line, 'a=candidate:')) {
                if (! preg_match('/\Aa=candidate:[^\s]+ [12] (?:udp|tcp) [0-9]+ [^\s]+ [0-9]+ typ relay(?: .*)?\z/i', $line)) {
                    self::invalid();
                }
                $candidates++;
            }
            if (str_starts_with($line, 'a=control:') || str_starts_with($line, 'a=remote-candidates:') || str_starts_with($line, 'a=sctp') || str_starts_with($line, 'a=ssrc:') && $direction === 'recvonly') {
                self::invalid();
            }
        }
        if (count($media) !== 1 || $fingerprints !== 1 || $directionCount !== 1 || $candidates < 1 || $candidates > 32 || ! in_array('a=rtcp-mux', $lines, true)) {
            self::invalid();
        }
        // Chrome can offer several H264 profiles after gathering completes
        // without serialising an end-of-candidates attribute. Candidate type,
        // media, codec and DTLS checks above still apply to the original bytes.
        $payloads = $media[0];
        sort($payloads);
        sort($maps);
        if ($payloads !== $maps || count($payloads) < 1 || count($payloads) > 16 || array_any($payloads, fn ($payload) => $payload < 0 || $payload > 127) || count(array_unique($payloads)) !== count($payloads)) {
            self::invalid();
        }

        return $value;
    }

    public static function relay(array $turn): void
    {
        self::exact($turn, ['urls', 'username', 'credential', 'expires_at', 'relay_cidrs', 'relay_port_min', 'relay_port_max']);
        if (! is_array($turn['relay_cidrs']) || ! array_is_list($turn['relay_cidrs']) || count($turn['relay_cidrs']) < 1 || count($turn['relay_cidrs']) > 8
            || ! is_int($turn['relay_port_min']) || ! is_int($turn['relay_port_max']) || $turn['relay_port_min'] < 1024 || $turn['relay_port_max'] > 65535 || $turn['relay_port_max'] < $turn['relay_port_min'] || $turn['relay_port_max'] - $turn['relay_port_min'] > 32767) {
            self::invalid();
        }
        $matcher = new CidrMatcher;
        foreach ($turn['relay_cidrs'] as $cidr) {
            if (! is_string($cidr) || strlen($cidr) > 64) {
                self::invalid();
            }
            try {
                $matcher->assertValidCidr($cidr);
            } catch (InvalidArgumentException) {
                self::invalid();
            }
            $prefix = (int) substr($cidr, strpos($cidr, '/') + 1);
            if ($prefix < (str_contains($cidr, ':') ? 120 : 24)) {
                self::invalid();
            }
        }
    }

    public static function relaySdp(string $sdp, array $turn, string $direction): void
    {
        self::sdp($sdp, $direction);
        self::relay($turn);
        $matcher = new CidrMatcher;
        foreach (preg_split('/\r?\n/', $sdp) as $line) {
            if (str_starts_with($line, 'c=')) {
                if (! preg_match('/\Ac=IN IP[46] ([^\s]+)\z/', $line, $match) || filter_var($match[1], FILTER_VALIDATE_IP) === false) {
                    self::invalid();
                }
                if (! in_array($match[1], ['0.0.0.0', '::'], true)) {
                    $allowed = false;
                    foreach ($turn['relay_cidrs'] as $cidr) {
                        if ($matcher->contains($cidr, $match[1])) {
                            $allowed = true;
                        }
                    }
                    if (! $allowed) {
                        self::invalid();
                    }
                }
            }
            if (str_starts_with($line, 'a=rtcp:')) {
                if (! preg_match('/\Aa=rtcp:([0-9]+)(?: IN IP[46] ([^\s]+))?\z/', $line, $match)) {
                    self::invalid();
                }
                if ((int) $match[1] === 9 && (! isset($match[2]) || in_array($match[2], ['0.0.0.0', '::'], true))) {
                    continue;
                }
                if (! isset($match[2]) || filter_var($match[2], FILTER_VALIDATE_IP) === false || (int) $match[1] < $turn['relay_port_min'] || (int) $match[1] > $turn['relay_port_max']) {
                    self::invalid();
                }
                $allowed = false;
                foreach ($turn['relay_cidrs'] as $cidr) {
                    if ($matcher->contains($cidr, $match[2])) {
                        $allowed = true;
                    }
                }
                if (! $allowed) {
                    self::invalid();
                }
            }
            if (! str_starts_with($line, 'a=candidate:')) {
                continue;
            }
            $parts = explode(' ', $line);
            $related = [];
            foreach (array_slice($parts, 8) as $index => $part) {
                if (! in_array($part, ['raddr', 'rport'], true)) {
                    continue;
                }
                if (isset($related[$part])) {
                    self::invalid();
                }
                $metadata = $parts[$index + 9] ?? null;
                if ($part === 'raddr' ? (! is_string($metadata) || filter_var($metadata, FILTER_VALIDATE_IP) === false) : (! is_string($metadata) || preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/', $metadata) !== 1 || (int) $metadata > 65535)) {
                    self::invalid();
                }
                $related[$part] = true;
            }
            $address = $parts[4] ?? null;
            $port = $parts[5] ?? null;
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false || ! is_string($port) || preg_match('/\A[1-9][0-9]{0,4}\z/', $port) !== 1 || (int) $port < $turn['relay_port_min'] || (int) $port > $turn['relay_port_max']) {
                self::invalid();
            }
            $allowed = false;
            foreach ($turn['relay_cidrs'] as $cidr) {
                if ($matcher->contains($cidr, $address)) {
                    $allowed = true;
                }
            }
            if (! $allowed) {
                self::invalid();
            }
        }
    }

    public static function locator(mixed $url, array $configuration): string
    {
        if (! is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F]/', $url)) {
            self::invalid();
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'rtsps' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ! isset($parts['host'], $parts['port'], $parts['path']) || $parts['path'] === '/') {
            self::invalid();
        }
        $host = trim($parts['host'], '[]');
        if ($host !== $configuration['stream_address'] && $host !== $configuration['tls_server_name'] || $parts['port'] !== $configuration['stream_port']) {
            self::invalid();
        }

        return $url;
    }

    public static function network(array $cidrs, array $exclusions, array $ports, string $address, int $port): bool
    {
        $matcher = new CidrMatcher;
        try {
            if (! in_array($port, $ports['tcp'] ?? [], true) && ! in_array($port, $ports['tls'] ?? [], true)) {
                return false;
            }
            $inside = false;
            foreach ($cidrs as $cidr) {
                if ($matcher->contains($cidr, $address)) {
                    $inside = true;
                }
            }
            if (! $inside) {
                return false;
            }
            foreach ($exclusions as $exclusion) {
                if (! is_string($exclusion)) {
                    return false;
                }
                if ($matcher->contains(str_contains($exclusion, '/') ? $exclusion : $exclusion.(str_contains($exclusion, ':') ? '/128' : '/32'), $address)) {
                    return false;
                }
            }

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('invalid_request');
    }
}
