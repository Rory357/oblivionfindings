<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class S10ReleaseAuthorityVerifier
{
    public const AUTHORITY_PATH = '/etc/oblivion/security-devices-s10-release-authority.json';

    private const int MAXIMUM_AUTHORITY_BYTES = 32_768;

    private const int MAXIMUM_AUTHORITY_SECONDS = 86_400;

    private const array AUTHORITY_KEYS = [
        'authority_reference',
        'environment_reference_sha256',
        'evidence_class',
        'not_after',
        'not_before',
        'release_revision',
        'schema_version',
    ];

    /**
     * Release verification deliberately has no caller-selected authority path.
     *
     * @return array{
     *     valid: bool,
     *     authority_reference: ?string,
     *     authority_sha256: ?string,
     *     environment_reference_sha256: ?string,
     *     release_revision: ?string
     * }
     */
    public function verifyInstalled(DateTimeImmutable $verifiedAt): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || is_link(self::AUTHORITY_PATH)) {
            return $this->invalid();
        }

        $before = @lstat(self::AUTHORITY_PATH);
        if (! is_array($before)) {
            return $this->invalid();
        }

        $handle = @fopen(self::AUTHORITY_PATH, 'rb');
        if ($handle === false) {
            return $this->invalid();
        }

        try {
            $opened = @fstat($handle);
            $after = @lstat(self::AUTHORITY_PATH);
            if (! is_array($opened) || ! is_array($after)) {
                return $this->invalid();
            }

            $size = $opened['size'] ?? null;
            if (! is_int($size) || $size < 1 || $size > self::MAXIMUM_AUTHORITY_BYTES) {
                return $this->invalid();
            }

            $rawAuthority = stream_get_contents($handle, self::MAXIMUM_AUTHORITY_BYTES + 1);
            if (! is_string($rawAuthority) || strlen($rawAuthority) !== $size) {
                return $this->invalid();
            }

            $mode = $opened['mode'] ?? null;
            $metadata = [
                'is_regular_file' => is_int($mode) && ($mode & 0170000) === 0100000,
                'is_symlink' => (($before['mode'] ?? 0) & 0170000) === 0120000
                    || (($after['mode'] ?? 0) & 0170000) === 0120000,
                'mode' => $mode,
                'owner_uid' => $opened['uid'] ?? null,
                'stable_identity' => $this->sameFile($before, $opened)
                    && $this->sameFile($opened, $after),
            ];

            return $this->verifyRecord($rawAuthority, $metadata, $verifiedAt);
        } catch (Throwable) {
            return $this->invalid();
        } finally {
            fclose($handle);
        }
    }

    /**
     * Injectable metadata keeps the protected-file contract testable without
     * root. Release callers must use verifyInstalled().
     *
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     valid: bool,
     *     authority_reference: ?string,
     *     authority_sha256: ?string,
     *     environment_reference_sha256: ?string,
     *     release_revision: ?string
     * }
     */
    public function verifyRecord(
        string $rawAuthority,
        array $metadata,
        DateTimeImmutable $verifiedAt,
    ): array {
        try {
            if (! $this->metadataIsProtected($metadata)) {
                return $this->invalid();
            }

            $authority = (new StrictJsonObjectDecoder)->decode($rawAuthority);
            if (! $this->hasExactKeys($authority, self::AUTHORITY_KEYS)) {
                return $this->invalid();
            }

            $authorityReference = $authority['authority_reference'] ?? null;
            $environmentReference = $authority['environment_reference_sha256'] ?? null;
            $releaseRevision = $authority['release_revision'] ?? null;
            $notBefore = $this->utc($authority['not_before'] ?? null);
            $notAfter = $this->utc($authority['not_after'] ?? null);
            $verifiedAt = $verifiedAt->setTimezone(new DateTimeZone('UTC'));

            $valid = ($authority['schema_version'] ?? null) === 1
                && ($authority['evidence_class'] ?? null) === 'security_devices_s10_release_authority_v1'
                && is_string($authorityReference)
                && preg_match('/\AAUTHORITY-[0-9a-f]{32}\z/', $authorityReference) === 1
                && is_string($releaseRevision)
                && preg_match('/\A[0-9a-f]{40}\z/', $releaseRevision) === 1
                && is_string($environmentReference)
                && preg_match('/\A[0-9a-f]{64}\z/', $environmentReference) === 1
                && $notBefore !== null
                && $notAfter !== null
                && $notBefore < $notAfter
                && ($notAfter->getTimestamp() - $notBefore->getTimestamp()) <= self::MAXIMUM_AUTHORITY_SECONDS
                && $verifiedAt >= $notBefore
                && $verifiedAt <= $notAfter;

            return $valid
                ? [
                    'valid' => true,
                    'authority_reference' => $authorityReference,
                    'authority_sha256' => hash('sha256', $rawAuthority),
                    'environment_reference_sha256' => $environmentReference,
                    'release_revision' => $releaseRevision,
                ]
                : $this->invalid();
        } catch (Throwable) {
            return $this->invalid();
        }
    }

    /** @param array<int, array<string, mixed>> $snapshots */
    public function identitiesRemainPinned(array $snapshots): bool
    {
        if (count($snapshots) !== 4) {
            return false;
        }

        foreach (['authority_reference', 'authority_sha256', 'environment_reference_sha256', 'release_revision'] as $key) {
            $pinned = $snapshots[0][$key] ?? null;
            if (! is_string($pinned)) {
                return false;
            }
            foreach ($snapshots as $snapshot) {
                $current = $snapshot[$key] ?? null;
                if (($snapshot['valid'] ?? null) !== true
                    || ! is_string($current)
                    || ! hash_equals($pinned, $current)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function metadataIsProtected(array $metadata): bool
    {
        if (! $this->hasExactKeys($metadata, [
            'is_regular_file',
            'is_symlink',
            'mode',
            'owner_uid',
            'stable_identity',
        ])) {
            return false;
        }

        $mode = $metadata['mode'] ?? null;

        return ($metadata['is_regular_file'] ?? null) === true
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

    /** @param array<string, mixed> $value */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function utc(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }

        try {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                $value,
                new DateTimeZone('UTC'),
            );
            $errors = DateTimeImmutable::getLastErrors();

            return $parsed instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format('Y-m-d\TH:i:s\Z') === $value
                    ? $parsed
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     valid: false,
     *     authority_reference: null,
     *     authority_sha256: null,
     *     environment_reference_sha256: null,
     *     release_revision: null
     * }
     */
    private function invalid(): array
    {
        return [
            'valid' => false,
            'authority_reference' => null,
            'authority_sha256' => null,
            'environment_reference_sha256' => null,
            'release_revision' => null,
        ];
    }
}
