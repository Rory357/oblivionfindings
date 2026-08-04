<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class SshInventoryTransport
{
    private const int MAX_QUERY_SECONDS = 15;

    private const int MAX_OUTPUT_BYTES = 1_048_576;

    public function __construct(private readonly SshConnectionFactory $connections) {}

    public function collect(
        AuthorizedProbeTarget $target,
        CredentialLease $lease,
        InventoryQuery $query,
        string $expectedFingerprint,
    ): InventoryResult {
        if ($target->scheme !== 'ssh' || $target->addresses === [] || $query->platform !== 'linux') {
            throw new RuntimeException('SSH inventory requires an approved SSH target and Linux profile.');
        }
        if (preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $expectedFingerprint) !== 1) {
            throw new RuntimeException('SSH host-key fingerprint is invalid.');
        }

        $lastFailure = InventoryResult::failure('transport_unavailable');
        foreach ($target->addresses as $address) {
            $connection = null;
            $material = [];
            try {
                $connection = $this->connections->connect($target, $address);
                $actualFingerprint = $connection->fingerprint();
                if (! hash_equals($expectedFingerprint, $actualFingerprint)) {
                    return InventoryResult::failure('host_key_mismatch');
                }

                $material = $lease->material();
                $this->validateMaterial($material);
                if (! $connection->authenticate($material)) {
                    return InventoryResult::failure('authentication_failed');
                }

                return $this->run($connection, $query, min($target->maxResponseBytes, self::MAX_OUTPUT_BYTES));
            } catch (RuntimeException $exception) {
                if (str_contains($exception->getMessage(), 'Credential lease')) {
                    throw $exception;
                }
                $lastFailure = InventoryResult::failure(
                    str_contains(strtolower($exception->getMessage()), 'timeout') ? 'timeout' : 'transport_unavailable',
                );
            } catch (Throwable) {
                $lastFailure = InventoryResult::failure('transport_unavailable');
            } finally {
                if ($connection instanceof SshConnection) {
                    $connection->close();
                }
                $this->clear($material);
            }
        }

        return $lastFailure;
    }

    private function run(SshConnection $connection, InventoryQuery $query, int $maximumBytes): InventoryResult
    {
        if ($maximumBytes < 1) {
            return InventoryResult::failure('response_too_large');
        }

        $facts = [];
        $completed = 0;
        $failed = 0;
        $latency = 0;
        foreach ($query->operations as $operation) {
            /** @var list<string> $operation */
            $response = $connection->execute($operation, self::MAX_QUERY_SECONDS, $maximumBytes);
            $latency += max(0, $response->latencyMs);
            if ($response->timedOut) {
                return InventoryResult::failure('timeout', $latency, $completed, $failed + 1);
            }
            if ($response->truncated || strlen($response->output) > $maximumBytes) {
                return InventoryResult::failure('response_too_large', $latency, $completed, $failed + 1);
            }
            if ($response->exitStatus !== 0) {
                $failed++;

                continue;
            }

            $parsed = $this->parse($operation[0], $response->output);
            if ($parsed === null) {
                $failed++;

                continue;
            }
            $facts = [...$facts, ...$parsed];
            $completed++;
        }

        return InventoryResult::collected($facts, $latency, $completed, $failed);
    }

    /** @return array<string, int|float|string|bool|null>|null */
    private function parse(string $executable, string $output): ?array
    {
        return match ($executable) {
            'uname' => $this->parseUname($output),
            'uptime' => $this->parseBootTime($output),
            'df' => $this->parseDisk($output),
            'systemctl' => $this->parseFailedServices($output),
            default => null,
        };
    }

    /** @return array{os_name: string}|null */
    private function parseUname(string $output): ?array
    {
        $value = trim($output);
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return ['os_name' => $value];
    }

    /** @return array{boot_time: string}|null */
    private function parseBootTime(string $output): ?array
    {
        try {
            $boot = CarbonImmutable::parse(trim($output), 'UTC');
        } catch (Throwable) {
            return null;
        }

        return ['boot_time' => $boot->utc()->toISOString()];
    }

    /** @return array{disk_bytes_total: int, disk_bytes_free: int, disk_usage_percent_max: int, volume_count: int}|null */
    private function parseDisk(string $output): ?array
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        if (count($lines) < 2 || count($lines) > 1025) {
            return null;
        }

        $total = 0;
        $free = 0;
        $maximumUsage = 0;
        $volumes = 0;
        foreach (array_slice($lines, 1) as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            if (count($columns) < 6
                || preg_match('/^\d+$/', $columns[1]) !== 1
                || preg_match('/^\d+$/', $columns[3]) !== 1
                || preg_match('/^(\d{1,3})%$/', $columns[4], $usage) !== 1) {
                return null;
            }
            $bytes = filter_var($columns[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $available = filter_var($columns[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $percent = (int) $usage[1];
            if (! is_int($bytes) || ! is_int($available) || $percent > 100
                || $bytes > PHP_INT_MAX - $total || $available > PHP_INT_MAX - $free) {
                return null;
            }
            $total += $bytes;
            $free += $available;
            $maximumUsage = max($maximumUsage, $percent);
            $volumes++;
        }

        return $volumes > 0 ? [
            'disk_bytes_total' => $total,
            'disk_bytes_free' => $free,
            'disk_usage_percent_max' => $maximumUsage,
            'volume_count' => $volumes,
        ] : null;
    }

    /** @return array{failed_service_count: int}|null */
    private function parseFailedServices(string $output): ?array
    {
        $lines = array_values(array_filter(
            preg_split('/\r?\n/', trim($output)) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));
        if (count($lines) > 10_000) {
            return null;
        }

        return ['failed_service_count' => count($lines)];
    }

    /** @param array<string, scalar|null> $material */
    private function validateMaterial(array $material): void
    {
        $allowed = ['username', 'password', 'private_key', 'private_key_passphrase'];
        $username = $material['username'] ?? null;
        $password = $material['password'] ?? null;
        $privateKey = $material['private_key'] ?? null;
        if (array_diff(array_keys($material), $allowed) !== []
            || ! is_string($username) || $username === '' || strlen($username) > 255
            || preg_match('/[\x00-\x20\x7f]/', $username) === 1
            || (is_string($password) === is_string($privateKey))
            || (is_string($password) && ($password === '' || strlen($password) > 4096))
            || (is_string($privateKey) && ($privateKey === '' || strlen($privateKey) > 131_072))
            || (isset($material['private_key_passphrase']) && ! is_string($material['private_key_passphrase']))) {
            throw new RuntimeException('SSH credential material is invalid.');
        }
    }

    /** @param array<string, scalar|null> $material */
    private function clear(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                function_exists('sodium_memzero') ? sodium_memzero($value) : $value = str_repeat("\0", strlen($value));
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
