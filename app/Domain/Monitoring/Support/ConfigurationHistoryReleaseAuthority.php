<?php

namespace App\Domain\Monitoring\Support;

use App\Support\Monitoring\StrictJsonObjectDecoder;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class ConfigurationHistoryReleaseAuthority
{
    public const string AUTHORITY_PATH = '/etc/oblivion/monitoring-configuration-history-release-authority.json';

    private const int MAXIMUM_AUTHORITY_BYTES = 32_768;

    private const int MAXIMUM_AUTHORITY_SECONDS = 86_400;

    private const array AUTHORITY_KEYS = [
        'authority_reference',
        'browser_attestation_public_key_base64',
        'evidence_acl_reference',
        'evidence_class',
        'hmac_key_sha256',
        'production_attestation_public_key_base64',
        'release_revision',
        'restored_environment_reference_sha256',
        'schema_version',
        'valid_from_utc',
        'valid_until_utc',
    ];

    /** @return array<string, mixed> */
    public function loadInstalled(?CarbonImmutable $now = null): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || is_link(self::AUTHORITY_PATH)) {
            $this->refuse();
        }

        $before = @lstat(self::AUTHORITY_PATH);
        $handle = @fopen(self::AUTHORITY_PATH, 'rb');
        if (! is_array($before) || $handle === false) {
            $this->refuse();
        }

        try {
            $opened = @fstat($handle);
            $after = @lstat(self::AUTHORITY_PATH);
            $size = is_array($opened) ? ($opened['size'] ?? null) : null;
            if (! is_array($opened)
                || ! is_array($after)
                || ! is_int($size)
                || $size < 2
                || $size > self::MAXIMUM_AUTHORITY_BYTES) {
                $this->refuse();
            }
            $raw = stream_get_contents($handle, self::MAXIMUM_AUTHORITY_BYTES + 1);
            $read = @fstat($handle);
            $final = @lstat(self::AUTHORITY_PATH);
            if (! is_string($raw)
                || strlen($raw) !== $size
                || ! is_array($read)
                || ! is_array($final)) {
                $this->refuse();
            }

            $mode = $opened['mode'] ?? null;

            return $this->verifyRecord($raw, [
                'is_regular_file' => is_int($mode) && ($mode & 0170000) === 0100000,
                'is_symlink' => (($before['mode'] ?? 0) & 0170000) === 0120000
                    || (($after['mode'] ?? 0) & 0170000) === 0120000,
                'mode' => $mode,
                'owner_uid' => $opened['uid'] ?? null,
                'stable_identity' => $this->sameFile($before, $opened)
                    && $this->sameFile($opened, $after)
                    && $this->sameFile($after, $read)
                    && $this->sameFile($read, $final),
            ], $now);
        } catch (Throwable $exception) {
            throw new RuntimeException('Configuration history release authority is invalid.', previous: $exception);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function verifyRecord(
        string $rawAuthority,
        array $metadata,
        ?CarbonImmutable $now = null,
    ): array {
        if (! $this->protectedMetadata($metadata)) {
            $this->refuse();
        }

        try {
            $authority = (new StrictJsonObjectDecoder)->decode($rawAuthority, 16);
        } catch (Throwable $exception) {
            throw new RuntimeException('Configuration history release authority is invalid.', previous: $exception);
        }
        if (! $this->exactKeys($authority, self::AUTHORITY_KEYS)) {
            $this->refuse();
        }

        $productionKey = base64_decode(
            (string) ($authority['production_attestation_public_key_base64'] ?? ''),
            true,
        );
        $browserKey = base64_decode(
            (string) ($authority['browser_attestation_public_key_base64'] ?? ''),
            true,
        );
        $validFrom = $this->utc($authority['valid_from_utc'] ?? null);
        $validUntil = $this->utc($authority['valid_until_utc'] ?? null);
        $now ??= CarbonImmutable::now('UTC');
        if (($authority['schema_version'] ?? null) !== 1
            || ($authority['evidence_class'] ?? null) !== 'monitoring_configuration_history_release_authority_v1'
            || ! $this->matches($authority['authority_reference'] ?? null, '/\AAUTHORITY-[a-f0-9]{32}\z/')
            || ! $this->matches($authority['evidence_acl_reference'] ?? null, '/\AACL-[a-f0-9]{32}\z/')
            || ! $this->sha($authority['release_revision'] ?? null, 40)
            || ! $this->sha($authority['restored_environment_reference_sha256'] ?? null)
            || ! $this->sha($authority['hmac_key_sha256'] ?? null)
            || ! is_string($productionKey)
            || strlen($productionKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! is_string($browserKey)
            || strlen($browserKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || hash_equals($productionKey, $browserKey)
            || $validFrom === null
            || $validUntil === null
            || ! $validFrom->lt($validUntil)
            || $validFrom->diffInSeconds($validUntil, true) > self::MAXIMUM_AUTHORITY_SECONDS
            || $now->lt($validFrom)
            || $now->gt($validUntil)) {
            $this->refuse();
        }

        return [
            'authority_reference' => $authority['authority_reference'],
            'authority_sha256' => hash('sha256', $rawAuthority),
            'browser_public_key' => $browserKey,
            'evidence_acl_reference' => $authority['evidence_acl_reference'],
            'hmac_key_sha256' => $authority['hmac_key_sha256'],
            'production_public_key' => $productionKey,
            'release_revision' => $authority['release_revision'],
            'restored_environment_reference_sha256' => $authority['restored_environment_reference_sha256'],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function protectedMetadata(array $metadata): bool
    {
        return $this->exactKeys($metadata, [
            'is_regular_file',
            'is_symlink',
            'mode',
            'owner_uid',
            'stable_identity',
        ])
            && ($metadata['is_regular_file'] ?? null) === true
            && ($metadata['is_symlink'] ?? null) === false
            && ($metadata['stable_identity'] ?? null) === true
            && ($metadata['owner_uid'] ?? null) === 0
            && is_int($metadata['mode'] ?? null)
            && ($metadata['mode'] & 0022) === 0;
    }

    /** @param array<string|int, mixed> $left @param array<string|int, mixed> $right */
    private function sameFile(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! array_key_exists($key, $left)
                || ! array_key_exists($key, $right)
                || $left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function utc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');

            return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d\TH:i:s\Z') === $value
                ? $parsed
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sha(mixed $value, int $length = 64): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function refuse(): never
    {
        throw new RuntimeException('Configuration history release authority is invalid.');
    }
}
