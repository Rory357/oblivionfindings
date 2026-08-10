<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use InvalidArgumentException;

final readonly class InventoryResult
{
    private const array STATUSES = [
        'ok', 'partial', 'authentication_failed', 'host_key_mismatch', 'certificate_mismatch',
        'timeout', 'response_too_large', 'protocol_error', 'transport_unavailable',
    ];

    /** @param array<string, int|float|string|bool|null> $facts */
    public function __construct(
        public string $status,
        public array $facts,
        public ?int $latencyMs,
        public int $completedOperations,
        public int $failedOperations,
    ) {
        if (! in_array($status, self::STATUSES, true)
            || ($latencyMs !== null && $latencyMs < 0)
            || $completedOperations < 0 || $failedOperations < 0
            || count($facts) > 64) {
            throw new InvalidArgumentException('Inventory result is invalid.');
        }
        foreach ($facts as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || preg_match('/body|authorization|cookie|credential|password|secret|token|certificate|raw_/i', $key) === 1
                || (! is_scalar($value) && $value !== null)
                || (is_string($value) && (strlen($value) > 512 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1))) {
                throw new InvalidArgumentException('Inventory result contains unsafe facts.');
            }
        }
    }

    /** @param array<string, int|float|string|bool|null> $facts */
    public static function collected(array $facts, int $latencyMs, int $completed, int $failed = 0): self
    {
        return new self($failed === 0 ? 'ok' : 'partial', $facts, $latencyMs, $completed, $failed);
    }

    public static function failure(string $status, ?int $latencyMs = null, int $completed = 0, int $failed = 1): self
    {
        return new self($status, [], $latencyMs, $completed, $failed);
    }
}
