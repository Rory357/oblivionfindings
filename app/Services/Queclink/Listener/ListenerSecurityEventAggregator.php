<?php

namespace App\Services\Queclink\Listener;

/**
 * Bounded, value-free pressure-event aggregation for the long-running listener.
 */
final class ListenerSecurityEventAggregator
{
    private const FLUSH_INTERVAL_SECONDS = 60.0;

    private const ALLOWED_CATEGORIES = [
        'buffer_limit',
        'connection_limit',
        'frame_limit',
        'frame_rate_limit',
        'idle_timeout',
        'invalid_direction',
        'invalid_frame',
        'invalid_frame_limit',
        'source_connection_limit',
        'source_rate_limit',
        'source_tracking_limit',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    private ?float $windowStartedAt = null;

    public function record(string $category, float $now): void
    {
        $category = in_array($category, self::ALLOWED_CATEGORIES, true)
            ? $category
            : 'invalid_frame';

        $this->windowStartedAt ??= $now;
        $this->counts[$category] = ($this->counts[$category] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function drain(float $now, bool $force = false): array
    {
        if ($this->counts === []) {
            return [];
        }

        if (! $force
            && $this->windowStartedAt !== null
            && ($now - $this->windowStartedAt) < self::FLUSH_INTERVAL_SECONDS) {
            return [];
        }

        $counts = $this->counts;
        ksort($counts);
        $this->counts = [];
        $this->windowStartedAt = null;

        return $counts;
    }
}
