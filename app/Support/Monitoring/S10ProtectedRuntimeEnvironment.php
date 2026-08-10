<?php

namespace App\Support\Monitoring;

use RuntimeException;
use Throwable;

final class S10ProtectedRuntimeEnvironment
{
    public const string ENVIRONMENT_PATH = '/etc/oblivion/security-devices-s10-release-runtime.json';

    public const string CONFIG_CACHE_BYPASS_PATH = '/run/oblivion-s10-release-no-config-cache.php';

    private const int MAXIMUM_ENVIRONMENT_BYTES = 131_072;

    private const int MAXIMUM_ENVIRONMENT_KEYS = 256;

    private const int MAXIMUM_VALUE_BYTES = 32_768;

    private const array FORBIDDEN_KEYS = [
        'ALL_PROXY',
        'APP_CONFIG_CACHE',
        'BASH_ENV',
        'BASHOPTS',
        'CDPATH',
        'CURL_CA_BUNDLE',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_LIBRARY_PATH',
        'ENV',
        'GLOBIGNORE',
        'HTTP_PROXY',
        'HTTPS_PROXY',
        'LD_LIBRARY_PATH',
        'LD_PRELOAD',
        'LIBPATH',
        'NO_PROXY',
        'PATH',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
        'PYTHONHOME',
        'PYTHONPATH',
        'SHELLOPTS',
        'SHLIB_PATH',
        'SSL_CERT_DIR',
        'SSL_CERT_FILE',
    ];

    /** @return array<string, string> */
    public function loadInstalled(string $expectedSha256, string $phpBinary): array
    {
        if (PHP_OS_FAMILY !== 'Linux'
            || is_link(self::ENVIRONMENT_PATH)
            || file_exists(self::CONFIG_CACHE_BYPASS_PATH)
            || is_link(self::CONFIG_CACHE_BYPASS_PATH)) {
            $this->refuse();
        }
        $before = @lstat(self::ENVIRONMENT_PATH);
        $handle = @fopen(self::ENVIRONMENT_PATH, 'rb');
        if (! is_array($before) || $handle === false) {
            $this->refuse();
        }

        try {
            $opened = @fstat($handle);
            $after = @lstat(self::ENVIRONMENT_PATH);
            $size = is_array($opened) ? ($opened['size'] ?? null) : null;
            if (! is_array($opened)
                || ! is_array($after)
                || ! is_int($size)
                || $size < 2
                || $size > self::MAXIMUM_ENVIRONMENT_BYTES) {
                $this->refuse();
            }
            $raw = stream_get_contents($handle, self::MAXIMUM_ENVIRONMENT_BYTES + 1);
            $read = @fstat($handle);
            $final = @lstat(self::ENVIRONMENT_PATH);
            $mode = $opened['mode'] ?? null;
            if (! is_string($raw)
                || strlen($raw) !== $size
                || ! is_array($read)
                || ! is_array($final)) {
                $this->refuse();
            }

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
            ], $expectedSha256, $phpBinary);
        } catch (Throwable $exception) {
            throw new RuntimeException('S10 protected runtime environment is invalid.', previous: $exception);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, string> */
    public function verifyRecord(
        string $rawEnvironment,
        array $metadata,
        string $expectedSha256,
        string $phpBinary,
    ): array {
        try {
            if (! $this->protectedMetadata($metadata)
                || preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1
                || ! hash_equals($expectedSha256, hash('sha256', $rawEnvironment))
                || ! str_starts_with($phpBinary, '/')
                || str_contains($phpBinary, "\0")) {
                $this->refuse();
            }
            $environment = (new StrictJsonObjectDecoder)->decode($rawEnvironment, 8);
            if (count($environment) < 4 || count($environment) > self::MAXIMUM_ENVIRONMENT_KEYS) {
                $this->refuse();
            }
            foreach ($environment as $key => $value) {
                if (preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/', $key) !== 1
                    || ! is_string($value)
                    || strlen($value) > self::MAXIMUM_VALUE_BYTES
                    || str_contains($value, "\0")
                    || str_starts_with($key, 'GIT_')
                    || str_starts_with($key, 'BASH_FUNC_')
                    || in_array($key, self::FORBIDDEN_KEYS, true)) {
                    $this->refuse();
                }
            }
            if (($environment['APP_ENV'] ?? null) !== 'production'
                || ! in_array($environment['APP_DEBUG'] ?? null, ['0', 'false'], true)
                || ($environment['DB_CONNECTION'] ?? null) !== 'mysql'
                || ($environment['MONITORING_COLLECTOR_REPLAY_STORE'] ?? null) !== 'redis'
                || ! is_string($environment['APP_KEY'] ?? null)
                || $environment['APP_KEY'] === '') {
                $this->refuse();
            }

            return [
                ...$environment,
                'APP_CONFIG_CACHE' => self::CONFIG_CACHE_BYPASS_PATH,
                'PATH' => S10ProcessEnvironment::SYSTEM_PATH,
                S10ProcessEnvironment::PHP_BINARY_VARIABLE => $phpBinary,
                'GIT_OPTIONAL_LOCKS' => '0',
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('S10 protected runtime environment is invalid.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function protectedMetadata(array $metadata): bool
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);

        return $keys === ['is_regular_file', 'is_symlink', 'mode', 'owner_uid', 'stable_identity']
            && ($metadata['is_regular_file'] ?? null) === true
            && ($metadata['is_symlink'] ?? null) === false
            && ($metadata['stable_identity'] ?? null) === true
            && ($metadata['owner_uid'] ?? null) === 0
            && is_int($metadata['mode'] ?? null)
            && ($metadata['mode'] & 0777) === 0600;
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

    private function refuse(): never
    {
        throw new RuntimeException('S10 protected runtime environment is invalid.');
    }
}
