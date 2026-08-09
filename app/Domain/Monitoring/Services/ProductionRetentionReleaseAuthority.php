<?php

namespace App\Domain\Monitoring\Services;

use App\Support\Monitoring\StrictJsonObjectDecoder;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class ProductionRetentionReleaseAuthority
{
    public const string AUTHORITY_PATH = '/etc/oblivion/monitoring-retention-release-authority.json';

    private const int MAXIMUM_AUTHORITY_BYTES = 16_384;

    private const array AUTHORITY_KEYS = [
        'attestation_public_key_base64',
        'evidence_class',
        'key_reference',
        'release_revision',
        'schema_version',
        'valid_from_utc',
        'valid_until_utc',
    ];

    /**
     * The production gate deliberately has no caller-selectable authority path.
     *
     * @return array{release_revision: string, key_reference: string, public_key: string}
     */
    public function loadInstalled(?CarbonImmutable $now = null): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || is_link(self::AUTHORITY_PATH)) {
            throw new RuntimeException('Production retention release authority is invalid.');
        }

        $before = @lstat(self::AUTHORITY_PATH);
        if (! is_array($before)) {
            throw new RuntimeException('Production retention release authority is invalid.');
        }

        $handle = @fopen(self::AUTHORITY_PATH, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Production retention release authority is invalid.');
        }

        try {
            $opened = @fstat($handle);
            $after = @lstat(self::AUTHORITY_PATH);
            if (! is_array($opened) || ! is_array($after)) {
                throw new RuntimeException('Production retention release authority is invalid.');
            }

            $size = $opened['size'] ?? null;
            if (! is_int($size) || $size < 2 || $size > self::MAXIMUM_AUTHORITY_BYTES) {
                throw new RuntimeException('Production retention release authority is invalid.');
            }

            $rawAuthority = stream_get_contents($handle, self::MAXIMUM_AUTHORITY_BYTES + 1);
            if (! is_string($rawAuthority) || strlen($rawAuthority) !== $size) {
                throw new RuntimeException('Production retention release authority is invalid.');
            }

            $mode = $opened['mode'] ?? null;

            return $this->verifyRecord(
                $rawAuthority,
                [
                    'is_regular_file' => is_int($mode) && ($mode & 0170000) === 0100000,
                    'is_symlink' => (($before['mode'] ?? 0) & 0170000) === 0120000
                        || (($after['mode'] ?? 0) & 0170000) === 0120000,
                    'mode' => $mode,
                    'owner_uid' => $opened['uid'] ?? null,
                    'stable_identity' => $this->sameFile($before, $opened)
                        && $this->sameFile($opened, $after),
                ],
                $now,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Production retention release authority is invalid.', previous: $exception);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Injectable metadata keeps the protected-file contract testable without root.
     * Release callers must use loadInstalled().
     *
     * @param  array<string, mixed>  $metadata
     * @return array{release_revision: string, key_reference: string, public_key: string}
     */
    public function verifyRecord(
        string $rawAuthority,
        array $metadata,
        ?CarbonImmutable $now = null,
    ): array {
        if (! $this->metadataIsProtected($metadata)) {
            throw new RuntimeException('Production retention release authority is invalid.');
        }

        try {
            $authority = (new StrictJsonObjectDecoder)->decode($rawAuthority, 8);
        } catch (Throwable $exception) {
            throw new RuntimeException('Production retention release authority is invalid.', previous: $exception);
        }
        $keys = array_keys($authority);
        sort($keys, SORT_STRING);
        $expected = self::AUTHORITY_KEYS;
        sort($expected, SORT_STRING);

        $publicKey = base64_decode((string) ($authority['attestation_public_key_base64'] ?? ''), true);
        $keyReference = $authority['key_reference'] ?? null;
        $releaseRevision = $authority['release_revision'] ?? null;
        if ($keys !== $expected
            || ($authority['schema_version'] ?? null) !== 1
            || ($authority['evidence_class'] ?? null) !== 'monitoring_production_retention_release_authority_v1'
            || ! is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! is_string($keyReference)
            || ! hash_equals('ATTEST-'.substr(hash('sha256', $publicKey), 0, 32), $keyReference)
            || ! is_string($releaseRevision)
            || preg_match('/\A[a-f0-9]{40}\z/', $releaseRevision) !== 1) {
            throw new RuntimeException('Production retention release authority is invalid.');
        }

        try {
            $validFrom = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                (string) ($authority['valid_from_utc'] ?? ''),
                'UTC',
            );
            $validUntil = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                (string) ($authority['valid_until_utc'] ?? ''),
                'UTC',
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Production retention release authority is invalid.', previous: $exception);
        }
        $now ??= CarbonImmutable::now('UTC');
        if (! $validFrom instanceof CarbonImmutable
            || ! $validUntil instanceof CarbonImmutable
            || $validFrom->format('Y-m-d\TH:i:s\Z') !== ($authority['valid_from_utc'] ?? null)
            || $validUntil->format('Y-m-d\TH:i:s\Z') !== ($authority['valid_until_utc'] ?? null)
            || ! $validFrom->lt($validUntil)
            || $validFrom->diffInHours($validUntil, true) > 24
            || $now->lt($validFrom)
            || $now->gt($validUntil)) {
            throw new RuntimeException('Production retention release authority is invalid.');
        }

        return [
            'release_revision' => $releaseRevision,
            'key_reference' => $keyReference,
            'public_key' => $publicKey,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function metadataIsProtected(array $metadata): bool
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        $expected = ['is_regular_file', 'is_symlink', 'mode', 'owner_uid', 'stable_identity'];
        sort($expected, SORT_STRING);
        $mode = $metadata['mode'] ?? null;

        return $keys === $expected
            && ($metadata['is_regular_file'] ?? null) === true
            && ($metadata['is_symlink'] ?? null) === false
            && ($metadata['stable_identity'] ?? null) === true
            && ($metadata['owner_uid'] ?? null) === 0
            && is_int($mode)
            && ($mode & 0022) === 0;
    }

    /** @param array<string|int, mixed> $left @param array<string|int, mixed> $right */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && is_int($left['dev'])
            && is_int($left['ino'])
            && is_int($right['dev'])
            && is_int($right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }
}
