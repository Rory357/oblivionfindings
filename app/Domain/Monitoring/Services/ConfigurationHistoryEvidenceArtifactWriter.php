<?php

namespace App\Domain\Monitoring\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ConfigurationHistoryEvidenceArtifactWriter
{
    private const array REPORT_KEYS = [
        'all_verified',
        'checked_at',
        'checks',
        'evidence_fingerprint',
        'verified_capacity_boundary_points',
        'verified_mysql_rows',
        'verified_snapshot_payloads',
    ];

    /** @return array{filename: string, sha256_filename: string, sha256: string} */
    public function write(string $directory, array $report, array $releaseIdentity, callable $beforeCommit): array
    {
        $directory = $this->eligibleDirectory($directory);
        $directoryIdentity = $this->identity($directory);
        $this->assertVerifiedReport($report);
        $this->assertReleaseIdentity($releaseIdentity);

        $artifactId = (string) Str::orderedUuid();
        $document = [
            'schema_version' => 1,
            'evidence_class' => 'monitoring-configuration-history-release-evidence-v1',
            'artifact_id' => $artifactId,
            'a10_release_evidence' => true,
            'publication' => 'collision_safe_exclusive_create',
            'worm_receipt_verified' => false,
            ...$releaseIdentity,
            ...$report,
        ];
        $payload = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        $stamp = (new DateTimeImmutable((string) $report['checked_at']))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
        $filename = "monitoring-configuration-history-{$stamp}-{$artifactId}.json";
        $checksumFilename = $filename.'.sha256';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $checksumPath = $directory.DIRECTORY_SEPARATOR.$checksumFilename;
        $artifactCreated = false;
        $checksumCreated = false;

        try {
            $this->writeExclusive($path, $payload);
            $artifactCreated = true;
            $sha256 = hash('sha256', $payload);
            $this->writeExclusive($checksumPath, $sha256.'  '.$filename.PHP_EOL);
            $checksumCreated = true;
            $beforeCommit();
            $this->assertIdentity($directory, $directoryIdentity);
            $this->assertPublished($path, $payload);
            $this->assertPublished($checksumPath, $sha256.'  '.$filename.PHP_EOL);
        } catch (Throwable $exception) {
            if ($checksumCreated) {
                $this->removeCreated($checksumPath, $exception);
            }
            if ($artifactCreated) {
                $this->removeCreated($path, $exception);
            }

            throw new RuntimeException('Configuration history evidence artifact could not be created.', previous: $exception);
        }

        return [
            'filename' => $filename,
            'sha256_filename' => $checksumFilename,
            'sha256' => $sha256,
        ];
    }

    public function validateDirectory(string $directory): void
    {
        $this->eligibleDirectory($directory);
    }

    private function eligibleDirectory(string $directory): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $directory) !== 1) {
            throw new RuntimeException('Evidence output directory must be absolute.');
        }
        $resolved = realpath($directory);
        $container = Container::getInstance();
        $base = $container->bound('path.base') ? realpath((string) $container->make('path.base')) : false;
        if (! is_string($resolved) || ! is_dir($resolved) || ! is_writable($resolved)
            || (is_string($base) && $this->within($resolved, $base))) {
            throw new RuntimeException('Evidence output directory is unavailable or inside the release checkout.');
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            $identity = $this->identity($resolved);
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : false;
            if (($identity['mode'] & 0777) !== 0700 || ! is_int($uid) || $identity['uid'] !== $uid) {
                throw new RuntimeException('Evidence output directory must be private to the service account.');
            }
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function assertVerifiedReport(array $report): void
    {
        $keys = array_keys($report);
        sort($keys);
        $expected = self::REPORT_KEYS;
        sort($expected);
        if ($keys !== $expected
            || ($report['all_verified'] ?? null) !== true
            || ! is_string($report['checked_at'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($report['evidence_fingerprint'] ?? '')) !== 1
            || ! is_array($report['checks'] ?? null)
            || $report['checks'] === []
            || collect($report['checks'])->contains(fn (mixed $state): bool => $state !== 'verified')
            || ($report['verified_mysql_rows'] ?? null) !== 4
            || ($report['verified_snapshot_payloads'] ?? null) !== 2
            || ($report['verified_capacity_boundary_points'] ?? null) !== 2) {
            throw new RuntimeException('Only a complete value-free A10 report may be published.');
        }
        try {
            new DateTimeImmutable($report['checked_at']);
        } catch (Throwable $exception) {
            throw new RuntimeException('Evidence report timestamp is invalid.', previous: $exception);
        }
    }

    private function assertReleaseIdentity(array $identity): void
    {
        $expected = [
            'backup_manifest_sha256',
            'release_revision',
            'restore_artifact_sha256',
            'restore_authority_reference',
            'restore_authority_sha256',
            'restored_environment_reference_sha256',
        ];
        $keys = array_keys($identity);
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || preg_match('/^[a-f0-9]{40}$/', (string) ($identity['release_revision'] ?? '')) !== 1
            || preg_match('/^AUTHORITY-[a-f0-9]{32}$/', (string) ($identity['restore_authority_reference'] ?? '')) !== 1) {
            throw new RuntimeException('A10 release identity is invalid.');
        }
        foreach ([
            'backup_manifest_sha256',
            'restore_artifact_sha256',
            'restore_authority_sha256',
            'restored_environment_reference_sha256',
        ] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($identity[$field] ?? '')) !== 1) {
                throw new RuntimeException('A10 release identity is invalid.');
            }
        }
    }

    /** @return array<string, int> */
    private function identity(string $path): array
    {
        $identity = @lstat($path);
        if (! is_array($identity)) {
            throw new RuntimeException('Evidence path identity is unavailable.');
        }

        return $identity;
    }

    /** @param array<string, int> $expected */
    private function assertIdentity(string $path, array $expected): void
    {
        $current = $this->identity($path);
        foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
            if (($current[$key] ?? null) !== ($expected[$key] ?? null)) {
                throw new RuntimeException('Evidence output directory identity changed during publication.');
            }
        }
    }

    private function writeExclusive(string $path, string $payload): void
    {
        $stream = @fopen($path, 'xb');
        if (! is_resource($stream)) {
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
            while ($offset < strlen($payload)) {
                $written = fwrite($stream, substr($payload, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Evidence artifact write failed.');
                }
                $offset += $written;
            }
            if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new RuntimeException('Evidence artifact could not be durably flushed.');
            }
            $writtenIdentity = @fstat($stream);
            $publishedIdentity = @lstat($path);
            if (! is_array($writtenIdentity) || ! is_array($publishedIdentity)
                || ($writtenIdentity['size'] ?? null) !== strlen($payload)) {
                throw new RuntimeException('Evidence artifact write was incomplete.');
            }
            foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
                if (($writtenIdentity[$key] ?? null) !== ($publishedIdentity[$key] ?? null)) {
                    throw new RuntimeException('Evidence artifact identity changed during publication.');
                }
            }
        } catch (Throwable $exception) {
            fclose($stream);
            if (is_file($path)) {
                @unlink($path);
            }
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
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

    private function removeCreated(string $path, Throwable $original): void
    {
        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException('Evidence artifact cleanup failed.', previous: $original);
        }
    }

    private function within(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $parent = rtrim(str_replace('\\', '/', $parent), '/').'/';

        return str_starts_with(strtolower($path), strtolower($parent));
    }
}
