<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class CentralRuntimeReleaseAuthorityVerifier
{
    public const string AUTHORITY_PATH = '/etc/oblivion/monitoring-central-runtime-release-authority.json';

    private const int MAXIMUM_AUTHORITY_BYTES = 32_768;

    private const int MAXIMUM_AUTHORITY_SECONDS = 86_400;

    private const array AUTHORITY_KEYS = [
        'application_path_sha256',
        'authority_reference',
        'environment_reference_sha256',
        'evidence_class',
        'health_url_sha256',
        'not_after',
        'not_before',
        'release_revision',
        'schema_version',
        'supervisor_configuration_sha256',
        'watchdog_attestation_public_key_sha256',
    ];

    /** @return array<string, int|string> */
    public function loadInstalled(?DateTimeImmutable $now = null): array
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
            throw new RuntimeException('Central runtime release authority is invalid.', previous: $exception);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, int|string>
     */
    public function verifyRecord(
        string $rawAuthority,
        array $metadata,
        ?DateTimeImmutable $now = null,
    ): array {
        if (! $this->protectedMetadata($metadata)) {
            $this->refuse();
        }

        try {
            $authority = (new StrictJsonObjectDecoder)->decode($rawAuthority, 16);
        } catch (Throwable $exception) {
            throw new RuntimeException('Central runtime release authority is invalid.', previous: $exception);
        }
        if (! $this->exactKeys($authority, self::AUTHORITY_KEYS)) {
            $this->refuse();
        }

        $notBefore = $this->utc($authority['not_before'] ?? null);
        $notAfter = $this->utc($authority['not_after'] ?? null);
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        if (($authority['schema_version'] ?? null) !== 1
            || ($authority['evidence_class'] ?? null) !== 'monitoring_central_runtime_release_authority_v1'
            || ! $this->matches($authority['authority_reference'] ?? null, '/\AAUTHORITY-[a-f0-9]{32}\z/')
            || ! $this->sha($authority['release_revision'] ?? null, 40)
            || ! $this->sha($authority['environment_reference_sha256'] ?? null)
            || ! $this->sha($authority['application_path_sha256'] ?? null)
            || ! $this->sha($authority['health_url_sha256'] ?? null)
            || ! $this->sha($authority['supervisor_configuration_sha256'] ?? null)
            || ! $this->sha($authority['watchdog_attestation_public_key_sha256'] ?? null)
            || $notBefore === null
            || $notAfter === null
            || $notBefore >= $notAfter
            || $notAfter->getTimestamp() - $notBefore->getTimestamp() > self::MAXIMUM_AUTHORITY_SECONDS
            || $now < $notBefore
            || $now > $notAfter) {
            $this->refuse();
        }

        return [
            'application_path_sha256' => $authority['application_path_sha256'],
            'authority_reference' => $authority['authority_reference'],
            'authority_sha256' => hash('sha256', $rawAuthority),
            'environment_reference_sha256' => $authority['environment_reference_sha256'],
            'health_url_sha256' => $authority['health_url_sha256'],
            'not_after_epoch' => $notAfter->getTimestamp(),
            'not_before_epoch' => $notBefore->getTimestamp(),
            'release_revision' => $authority['release_revision'],
            'supervisor_configuration_sha256' => $authority['supervisor_configuration_sha256'],
            'watchdog_attestation_public_key_sha256' => $authority['watchdog_attestation_public_key_sha256'],
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
        throw new RuntimeException('Central runtime release authority is invalid.');
    }
}
