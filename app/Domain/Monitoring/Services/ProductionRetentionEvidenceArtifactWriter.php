<?php

namespace App\Domain\Monitoring\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class ProductionRetentionEvidenceArtifactWriter
{
    public function __construct(private readonly ?string $attestationPublicKey = null) {}

    private const array ALLOWED_REPORT_KEYS = [
        'schema',
        'artifact_id',
        'classification',
        'a05_release_evidence',
        'status',
        'started_at_utc',
        'completed_at_utc',
        'endpoints',
        'endpoint_attestation',
        'execution',
        'integrity',
        'errors',
    ];

    private const array ALLOWED_ENDPOINT_KEYS = [
        'business_store',
        'time_series_store',
        'health',
    ];

    private const array ALLOWED_EXECUTION_KEYS = [
        'raw_to_hourly_chain_count',
        'hourly_to_daily_chain_count',
        'tombstone_count',
        'raw_tombstone_count',
        'hourly_tombstone_count',
        'privacy_tombstone_count',
        'held_record_count',
        'coverage_verified_count',
        'occupied_buckets_verified_count',
        'coverage_blocked_count',
        'reconciled_deletion_intent_count',
        'unresolved_deletion_intent_count',
    ];

    private const array ALLOWED_INTEGRITY_KEYS = [
        'tombstone_lineage_gap_count',
        'deleted_range_gap_count',
        'legal_hold_gap_count',
        'business_reference_gap_count',
        'pointer_gap_count',
        'timeseries_reference_gap_count',
    ];

    /** @return array{filename: string, sha256_filename: string, sha256: string} */
    public function write(
        string $directory,
        array $report,
        ?string $attestationPublicKey = null,
        ?callable $beforeCommit = null,
    ): array {
        $directory = $this->eligibleDirectory($directory);
        $directoryIdentity = $this->directoryIdentity($directory);
        $this->assertValueFreeContract($report, $attestationPublicKey);

        $artifactId = (string) ($report['artifact_id'] ?? '');
        if (! Str::isUuid($artifactId)) {
            throw new RuntimeException('Evidence artifact identifier is invalid.');
        }

        try {
            $payload = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException('Evidence report cannot be encoded.', previous: $exception);
        }

        try {
            $stamp = (new DateTimeImmutable((string) $report['started_at_utc']))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Ymd\THis\Z');
        } catch (Throwable $exception) {
            throw new RuntimeException('Evidence report timestamp is invalid.', previous: $exception);
        }
        $filename = "monitoring-retention-{$stamp}-{$artifactId}.json";
        $checksumFilename = $filename.'.sha256';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $checksumPath = $directory.DIRECTORY_SEPARATOR.$checksumFilename;

        $artifactCreated = false;
        $checksumCreated = false;
        try {
            $this->writeExclusive($path, $payload);
            $artifactCreated = true;
            $checksum = hash('sha256', $payload);
            $this->writeExclusive($checksumPath, $checksum.'  '.$filename.PHP_EOL);
            $checksumCreated = true;
            if ($beforeCommit !== null) {
                $beforeCommit();
            }
            $this->assertDirectoryIdentity($directory, $directoryIdentity);
            $this->assertPublished($path, $payload);
            $this->assertPublished($checksumPath, $checksum.'  '.$filename.PHP_EOL);
        } catch (Throwable $exception) {
            if ($checksumCreated) {
                $this->removeCreated($checksumPath, $exception);
            }
            if ($artifactCreated) {
                $this->removeCreated($path, $exception);
            }

            throw new RuntimeException('Evidence artifact could not be created.', previous: $exception);
        }

        return [
            'filename' => $filename,
            'sha256_filename' => $checksumFilename,
            'sha256' => $checksum,
        ];
    }

    public function validateDirectory(string $directory): void
    {
        $this->eligibleDirectory($directory);
    }

    private function eligibleDirectory(string $directory): string
    {
        if (! $this->isAbsolutePath($directory)) {
            throw new RuntimeException('Evidence output directory must be absolute.');
        }

        $resolved = realpath($directory);
        $container = Container::getInstance();
        $configuredBase = $container->bound('path.base') ? (string) $container->make('path.base') : null;
        $base = $configuredBase === null ? false : realpath($configuredBase);
        if ($resolved === false || ! is_dir($resolved) || ! is_writable($resolved)) {
            throw new RuntimeException('Evidence output directory is unavailable.');
        }
        if ($base !== false && $this->isWithin($resolved, $base)) {
            throw new RuntimeException('Evidence output directory must be outside the release checkout.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $identity = $this->directoryIdentity($resolved);
            $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
            if (($identity['mode'] & 0777) !== 0700
                || ! is_int($effectiveUid)
                || $identity['uid'] !== $effectiveUid) {
                throw new RuntimeException('Evidence output directory must be private to the service account.');
            }
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1;
    }

    private function isWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $parent = rtrim(str_replace('\\', '/', $parent), '/').'/';

        return str_starts_with(strtolower($path), strtolower($parent));
    }

    private function assertValueFreeContract(array $report, ?string $attestationPublicKey): void
    {
        $keys = array_keys($report);
        sort($keys);
        $allowed = self::ALLOWED_REPORT_KEYS;
        sort($allowed);
        if ($keys !== $allowed
            || ($report['schema'] ?? null) !== 'monitoring-production-retention-v1'
            || ($report['classification'] ?? null) !== 'production_real_endpoints'
            || ! is_bool($report['a05_release_evidence'] ?? null)
            || ! in_array($report['status'] ?? null, ['verified', 'failed'], true)
            || ! is_array($report['endpoints'] ?? null)
            || ! is_array($report['endpoint_attestation'] ?? null)
            || ! is_array($report['execution'] ?? null)
            || ! is_array($report['integrity'] ?? null)
            || ! is_array($report['errors'] ?? null)) {
            throw new RuntimeException('Evidence report violates the value-free schema.');
        }

        $verified = $report['status'] === 'verified';
        if ($report['a05_release_evidence'] !== $verified
            || ($report['errors'] === []) !== $verified
            || $report['endpoints'] !== [
                'business_store' => 'mysql',
                'time_series_store' => 'influxdb',
                'health' => 'verified',
            ]) {
            throw new RuntimeException('Evidence report violates the release classification contract.');
        }

        foreach ([
            [$report['endpoints'], self::ALLOWED_ENDPOINT_KEYS],
            [$report['execution'], self::ALLOWED_EXECUTION_KEYS],
            [$report['integrity'], self::ALLOWED_INTEGRITY_KEYS],
        ] as [$actual, $expected]) {
            $actualKeys = array_keys($actual);
            sort($actualKeys);
            sort($expected);
            if ($actualKeys !== $expected) {
                throw new RuntimeException('Evidence report contains a prohibited key.');
            }
        }

        foreach ([...array_values($report['execution']), ...array_values($report['integrity'])] as $count) {
            if (! is_int($count) || $count < 0) {
                throw new RuntimeException('Evidence report count is invalid.');
            }
        }
        foreach ($report['errors'] as $error) {
            if (! is_string($error) || preg_match('/^[a-z0-9][a-z0-9_:-]{0,127}$/', $error) !== 1) {
                throw new RuntimeException('Evidence report error code is invalid.');
            }
        }

        try {
            $started = new DateTimeImmutable((string) ($report['started_at_utc'] ?? ''));
            $completed = new DateTimeImmutable((string) ($report['completed_at_utc'] ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('Evidence report time range is invalid.', previous: $exception);
        }
        if ($started >= $completed) {
            throw new RuntimeException('Evidence report time range is invalid.');
        }

        $attestation = $report['endpoint_attestation'];
        try {
            (new ProductionRetentionEndpointAttestation)->verify(
                $attestation,
                [
                    'mysql_endpoint_sha256' => (string) ($attestation['mysql_endpoint_sha256'] ?? ''),
                    'influx_scope_sha256' => (string) ($attestation['influx_scope_sha256'] ?? ''),
                    'influx_tls_certificate_sha256' => (string) ($attestation['influx_tls_certificate_sha256'] ?? ''),
                ],
                (string) ($attestation['release_revision'] ?? ''),
                null,
                $this->resolvedAttestationPublicKey($attestationPublicKey),
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Evidence report endpoint attestation is invalid.', previous: $exception);
        }
        if (! hash_equals((string) $report['artifact_id'], (string) ($attestation['run_id'] ?? ''))) {
            throw new RuntimeException('Evidence report run attestation does not match.');
        }

        if ($verified) {
            $execution = $report['execution'];
            $integrity = $report['integrity'];
            if ($execution['raw_to_hourly_chain_count'] < 1
                || $execution['hourly_to_daily_chain_count'] < 1
                || $execution['tombstone_count'] < 2
                || $execution['raw_tombstone_count'] < 1
                || $execution['hourly_tombstone_count'] < 1
                || $execution['privacy_tombstone_count'] < 1
                || $execution['held_record_count'] < 1
                || $execution['coverage_verified_count'] !== $execution['raw_tombstone_count'] + $execution['hourly_tombstone_count']
                || $execution['occupied_buckets_verified_count'] < $execution['coverage_verified_count']
                || $execution['coverage_blocked_count'] !== 0
                || $execution['unresolved_deletion_intent_count'] !== 0
                || collect($integrity)->contains(fn (int $count): bool => $count !== 0)) {
                throw new RuntimeException('Verified evidence report does not prove the retention contract.');
            }
        }
    }

    private function writeExclusive(string $path, string $payload): void
    {
        if (file_exists($path)) {
            throw new RuntimeException('Evidence artifact name collision.');
        }
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Evidence artifact name collision.');
        }

        try {
            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, 0600)) {
                throw new RuntimeException('Evidence artifact permissions could not be restricted.');
            }
            $opened = @fstat($stream);
            if (! is_array($opened)
                || (($opened['mode'] ?? 0) & 0170000) !== 0100000
                || (DIRECTORY_SEPARATOR !== '\\' && (
                    (($opened['mode'] ?? 0) & 0777) !== 0600
                    || ($opened['uid'] ?? null) !== posix_geteuid()
                ))) {
                throw new RuntimeException('Evidence artifact permissions could not be restricted.');
            }
            $offset = 0;
            $length = strlen($payload);
            while ($offset < $length) {
                $written = fwrite($stream, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Evidence artifact write failed.');
                }
                $offset += $written;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Evidence artifact flush failed.');
            }
            if (function_exists('fsync') && ! fsync($stream)) {
                throw new RuntimeException('Evidence artifact sync failed.');
            }
            $written = @fstat($stream);
            $published = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
                if (! is_array($written)
                    || ! is_array($published)
                    || ! array_key_exists($key, $opened)
                    || ! array_key_exists($key, $written)
                    || ! array_key_exists($key, $published)
                    || $opened[$key] !== $written[$key]
                    || $written[$key] !== $published[$key]) {
                    throw new RuntimeException('Evidence artifact identity changed during publication.');
                }
            }
            foreach (['size', 'mtime'] as $key) {
                if (! is_array($written)
                    || ! is_array($published)
                    || ! array_key_exists($key, $written)
                    || ! array_key_exists($key, $published)
                    || $written[$key] !== $published[$key]) {
                    throw new RuntimeException('Evidence artifact identity changed during publication.');
                }
            }
            if (($written['size'] ?? null) !== $length) {
                throw new RuntimeException('Evidence artifact write was incomplete.');
            }
        } catch (Throwable $exception) {
            fclose($stream);
            if (is_file($path)) {
                @unlink($path);
            }
            if (is_file($path)) {
                throw new RuntimeException('Evidence artifact partial-write cleanup failed.', previous: $exception);
            }

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

    }

    /** @return array{dev: int, ino: int, mode: int, uid: int} */
    private function directoryIdentity(string $directory): array
    {
        $metadata = @lstat($directory);
        if (! is_array($metadata)
            || (($metadata['mode'] ?? 0) & 0170000) !== 0040000
            || ! is_int($metadata['dev'] ?? null)
            || ! is_int($metadata['ino'] ?? null)
            || ! is_int($metadata['mode'] ?? null)
            || ! is_int($metadata['uid'] ?? null)) {
            throw new RuntimeException('Evidence output directory identity is invalid.');
        }

        return [
            'dev' => $metadata['dev'],
            'ino' => $metadata['ino'],
            'mode' => $metadata['mode'],
            'uid' => $metadata['uid'],
        ];
    }

    /** @param array{dev: int, ino: int, mode: int, uid: int} $expected */
    private function assertDirectoryIdentity(string $directory, array $expected): void
    {
        if ($this->directoryIdentity($directory) !== $expected) {
            throw new RuntimeException('Evidence output directory changed during publication.');
        }
    }

    private function assertPublished(string $path, string $expectedPayload): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Evidence artifact identity changed after final verification.');
        }
        $before = @lstat($path);
        $stream = @fopen($path, 'rb');
        if (! is_array($before) || ! is_resource($stream)) {
            throw new RuntimeException('Evidence artifact identity changed after final verification.');
        }
        try {
            $opened = @fstat($stream);
            $contents = stream_get_contents($stream, strlen($expectedPayload) + 1);
            $read = @fstat($stream);
            $final = @lstat($path);
            if (! is_array($opened) || ! is_array($read) || ! is_array($final)
                || ! is_string($contents)
                || ! hash_equals($expectedPayload, $contents)
                || (($opened['mode'] ?? 0) & 0170000) !== 0100000
                || (DIRECTORY_SEPARATOR !== '\\' && (
                    (($opened['mode'] ?? 0) & 0777) !== 0600
                    || ($opened['uid'] ?? null) !== posix_geteuid()
                ))) {
                throw new RuntimeException('Evidence artifact identity changed after final verification.');
            }
            foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
                if (($before[$key] ?? null) !== ($opened[$key] ?? null)
                    || ($opened[$key] ?? null) !== ($read[$key] ?? null)
                    || ($read[$key] ?? null) !== ($final[$key] ?? null)) {
                    throw new RuntimeException('Evidence artifact identity changed after final verification.');
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function removeCreated(string $path, Throwable $cause): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
        if (is_file($path)) {
            throw new RuntimeException('Evidence artifact partial-write cleanup failed.', previous: $cause);
        }
    }

    private function resolvedAttestationPublicKey(?string $attestationPublicKey): string
    {
        if (is_string($attestationPublicKey)
            && strlen($attestationPublicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $attestationPublicKey;
        }
        if (is_string($this->attestationPublicKey)
            && strlen($this->attestationPublicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $this->attestationPublicKey;
        }

        throw new RuntimeException('Production endpoint attestation public key is unavailable.');
    }
}
