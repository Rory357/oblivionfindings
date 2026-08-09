<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class LoadSoakReleaseAuthorityVerifier
{
    public const AUTHORITY_PATH = '/etc/oblivion/monitoring-load-soak-authority.json';

    private const int MAXIMUM_AUTHORITY_BYTES = 65_536;

    private const array AUTHORITY_KEYS = [
        'attestation_public_key_sha256',
        'attestation_sha256',
        'authority_reference',
        'environment_reference_sha256',
        'evidence_class',
        'not_after',
        'not_before',
        'release_revision',
        'schema_version',
        'source_sha256',
    ];

    /**
     * Release verification deliberately has no caller-selectable authority path.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $attestation
     * @return array{valid: bool, authority_reference: ?string, public_key_sha256: ?string}
     */
    public function verifyInstalled(
        string $sourceSha256,
        string $attestationSha256,
        array $evidence,
        array $attestation,
        string $publicKeyBase64,
        DateTimeImmutable $verifiedAt,
    ): array {
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

            $sameFile = $this->sameFile($before, $opened)
                && $this->sameFile($opened, $after);
            $mode = $opened['mode'] ?? null;
            $metadata = [
                'is_regular_file' => is_int($mode) && ($mode & 0170000) === 0100000,
                'is_symlink' => (($before['mode'] ?? 0) & 0170000) === 0120000
                    || (($after['mode'] ?? 0) & 0170000) === 0120000,
                'mode' => $mode,
                'owner_uid' => $opened['uid'] ?? null,
                'stable_identity' => $sameFile,
            ];

            return $this->verifyRecord(
                $rawAuthority,
                $metadata,
                $sourceSha256,
                $attestationSha256,
                $evidence,
                $attestation,
                $publicKeyBase64,
                $verifiedAt,
            );
        } catch (Throwable) {
            return $this->invalid();
        } finally {
            fclose($handle);
        }
    }

    /**
     * Injectable metadata keeps protected-file behavior testable without root.
     * Release callers must use verifyInstalled().
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $attestation
     * @return array{valid: bool, authority_reference: ?string, public_key_sha256: ?string}
     */
    public function verifyRecord(
        string $rawAuthority,
        array $metadata,
        string $sourceSha256,
        string $attestationSha256,
        array $evidence,
        array $attestation,
        string $publicKeyBase64,
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

            $publicKey = base64_decode(trim($publicKeyBase64), true);
            if (! is_string($publicKey)
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return $this->invalid();
            }
            $publicKeySha256 = hash('sha256', $publicKey);

            $authorityReference = $authority['authority_reference'] ?? null;
            $expectedPublicKeySha256 = $authority['attestation_public_key_sha256'] ?? null;
            $releaseRevision = $authority['release_revision'] ?? null;
            $environmentReferenceSha256 = $authority['environment_reference_sha256'] ?? null;
            $notBefore = $this->utc($authority['not_before'] ?? null);
            $notAfter = $this->utc($authority['not_after'] ?? null);
            $verifiedAt = $verifiedAt->setTimezone(new DateTimeZone('UTC'));

            $valid = ($authority['schema_version'] ?? null) === 1
                && ($authority['evidence_class'] ?? null) === 'monitoring_load_soak_release_authority_v1'
                && is_string($authorityReference)
                && preg_match('/\AAUTHORITY-[0-9a-f]{32}\z/', $authorityReference) === 1
                && $this->sha256($expectedPublicKeySha256)
                && hash_equals($expectedPublicKeySha256, $publicKeySha256)
                && $this->sha1($releaseRevision)
                && $releaseRevision === ($evidence['release_revision'] ?? null)
                && $releaseRevision === ($attestation['release_revision'] ?? null)
                && $this->sha256($environmentReferenceSha256)
                && $environmentReferenceSha256 === ($evidence['environment_fingerprint'] ?? null)
                && $environmentReferenceSha256 === ($attestation['environment_fingerprint'] ?? null)
                && $this->sha256($sourceSha256)
                && ($authority['source_sha256'] ?? null) === $sourceSha256
                && $this->sha256($attestationSha256)
                && ($authority['attestation_sha256'] ?? null) === $attestationSha256
                && $notBefore !== null
                && $notAfter !== null
                && $notBefore < $notAfter
                && $verifiedAt >= $notBefore
                && $verifiedAt <= $notAfter;

            return $valid
                ? [
                    'valid' => true,
                    'authority_reference' => $authorityReference,
                    'public_key_sha256' => $publicKeySha256,
                ]
                : $this->invalid();
        } catch (Throwable) {
            return $this->invalid();
        }
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

    private function sha1(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1;
    }

    private function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
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

    /** @return array{valid: false, authority_reference: null, public_key_sha256: null} */
    private function invalid(): array
    {
        return [
            'valid' => false,
            'authority_reference' => null,
            'public_key_sha256' => null,
        ];
    }
}
