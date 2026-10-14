<?php

namespace Oblivion\Collector\Enrollment;

use DateTimeImmutable;
use Oblivion\Collector\Http\HttpsCentralApi;
use RuntimeException;

final class InstallationProfile
{
    public const MAX_BYTES = 16384;

    public static function read(string $path, string $platform): array
    {
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['nlink'] ?? 1) !== 1
            || $stat['size'] > self::MAX_BYTES || is_link($path)
            || (PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('The installation profile must be a private, ordinary file.');
        }
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Installation profile is unavailable.');
        }
        try {
            $current = fstat($stream);
            if (! is_array($current) || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev']) {
                throw new RuntimeException('Installation profile changed while opening.');
            }
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            if (! is_string($bytes)) {
                throw new RuntimeException('Installation profile is unreadable.');
            }

            return self::decode($bytes, $platform);
        } finally {
            if (isset($bytes) && is_string($bytes)) {
                sodium_memzero($bytes);
            }
            fclose($stream);
        }
    }

    /** Flat, exact schema: duplicate names, nested values and coercion are refused. */
    public static function decode(string $bytes, string $platform): array
    {
        if (strlen($bytes) > self::MAX_BYTES || ! json_validate($bytes, 4)) {
            throw new RuntimeException('Installation profile is invalid or too large.');
        }
        $p = self::flatObject($bytes);
        $fields = ['version', 'component', 'platform', 'deployment_url', 'server_url', 'server_public_key',
            'tls_public_key_pin', 'organisation', 'site_id', 'site_label', 'collector_uuid', 'enrolment_token', 'expires_at'];
        if (count($p) !== count($fields) || array_diff($fields, array_keys($p)) !== []
            || ! in_array($platform, ['windows', 'linux'], true) || $p['platform'] !== $platform
            || $p['version'] !== 1 || $p['component'] !== 'site'
            || ! is_int($p['site_id']) || $p['site_id'] < 1 || $p['site_id'] > 999999999999999) {
            throw new RuntimeException('Installation profile is unsupported or belongs to another platform.');
        }
        foreach (array_diff($fields, ['version', 'site_id']) as $field) {
            if (! is_string($p[$field])) {
                throw new RuntimeException('Installation profile fields have invalid types.');
            }
        }
        foreach (['organisation', 'site_label'] as $field) {
            if ($p[$field] === '' || trim($p[$field]) !== $p[$field] || preg_match('/[\p{Cc}\p{Cf}]/u', $p[$field])
                || preg_match_all('/./us', $p[$field]) > 300) {
                throw new RuntimeException('Installation profile label is invalid.');
            }
        }
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $p['collector_uuid'])
            || ! preg_match('/\Aofc_enrol_[A-Za-z0-9_-]{64}\z/', $p['enrolment_token'])) {
            throw new RuntimeException('Installation profile identity is invalid.');
        }
        $key = base64_decode($p['server_public_key'], true);
        $pin = str_starts_with($p['tls_public_key_pin'], 'sha256//') ? base64_decode(substr($p['tls_public_key_pin'], 8), true) : false;
        if (! is_string($key) || strlen($key) !== 32 || base64_encode($key) !== $p['server_public_key']
            || ! is_string($pin) || strlen($pin) !== 32 || 'sha256//'.base64_encode($pin) !== $p['tls_public_key_pin']) {
            throw new RuntimeException('Installation profile authority is invalid.');
        }
        foreach (['deployment_url', 'server_url'] as $field) {
            if (str_ends_with($p[$field], '/')) {
                throw new RuntimeException('Installation profile origin is not canonical.');
            }
            new HttpsCentralApi($p[$field], $p['tls_public_key_pin']);
        }
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $p['expires_at'])) {
            throw new RuntimeException('Installation profile expiry is invalid.');
        }
        $expiry = new DateTimeImmutable($p['expires_at']);
        $errors = DateTimeImmutable::getLastErrors();
        if ((is_array($errors) && ($errors['warning_count'] || $errors['error_count']))
            || $expiry <= new DateTimeImmutable || $expiry > (new DateTimeImmutable)->modify('+31 minutes')) {
            throw new RuntimeException('Installation profile has expired or requires review.');
        }

        return $p;
    }

    private static function flatObject(string $bytes): array
    {
        $length = strlen($bytes);
        $offset = 0;
        $skip = static function () use ($bytes, $length, &$offset): void {
            while ($offset < $length && str_contains(" \t\r\n", $bytes[$offset])) {
                $offset++;
            }
        };
        $string = static function () use ($bytes, $length, &$offset): string {
            $start = $offset++;
            while ($offset < $length) {
                if ($bytes[$offset] === '\\') {
                    $offset += 2;
                } elseif ($bytes[$offset++] === '"') {
                    return json_decode(substr($bytes, $start, $offset - $start), true, 4, JSON_THROW_ON_ERROR);
                }
            }
            throw new RuntimeException('Installation profile string is incomplete.');
        };
        $skip();
        if (($bytes[$offset++] ?? '') !== '{') {
            throw new RuntimeException('Installation profile must be an object.');
        }
        $result = [];
        while (true) {
            $skip();
            if (($bytes[$offset] ?? '') === '}') {
                $offset++;
                break;
            }
            if (($bytes[$offset] ?? '') !== '"') {
                throw new RuntimeException('Installation profile key is invalid.');
            }
            $key = $string();
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('Installation profile contains duplicate fields.');
            }
            $skip();
            if (($bytes[$offset++] ?? '') !== ':') {
                throw new RuntimeException('Installation profile separator is invalid.');
            }
            $skip();
            if (($bytes[$offset] ?? '') === '"') {
                $value = $string();
            } else {
                $start = $offset;
                while ($offset < $length && ! str_contains(',}', $bytes[$offset])) {
                    $offset++;
                }
                $value = json_decode(substr($bytes, $start, $offset - $start), true, 4, JSON_THROW_ON_ERROR);
                if (! is_int($value)) {
                    throw new RuntimeException('Installation profile contains unsupported values.');
                }
            }
            $result[$key] = $value;
            $skip();
            if (($bytes[$offset] ?? '') === ',') {
                $offset++;

                continue;
            }
            if (($bytes[$offset] ?? '') !== '}') {
                throw new RuntimeException('Installation profile is incomplete.');
            }
        }
        $skip();
        if ($offset !== $length) {
            throw new RuntimeException('Installation profile has trailing content.');
        }

        return $result;
    }
}
