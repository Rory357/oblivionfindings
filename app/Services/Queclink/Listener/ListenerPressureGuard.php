<?php

namespace App\Services\Queclink\Listener;

/**
 * In-process admission and frame-pressure accounting for the TCP listener.
 * Source addresses are retained only as one-way fingerprints in bounded state.
 */
final class ListenerPressureGuard
{
    /** @var array<string, array{window_started_at: float, attempts: int, last_seen_at: float}> */
    private array $sourceWindows = [];

    public function __construct(private readonly ListenerLimits $limits) {}

    public function connectionRejection(
        string $remoteAddress,
        int $activeConnections,
        int $activeConnectionsForSource,
        float $now,
    ): ?string {
        $this->prune($now);
        $source = $this->sourceFingerprint($remoteAddress);

        if (! isset($this->sourceWindows[$source])) {
            if (count($this->sourceWindows) >= $this->limits->maxTrackedSources) {
                return 'source_tracking_limit';
            }

            $this->sourceWindows[$source] = [
                'window_started_at' => $now,
                'attempts' => 0,
                'last_seen_at' => $now,
            ];
        }

        if (($now - $this->sourceWindows[$source]['window_started_at']) >= $this->limits->connectionWindowSeconds) {
            $this->sourceWindows[$source]['window_started_at'] = $now;
            $this->sourceWindows[$source]['attempts'] = 0;
        }

        $this->sourceWindows[$source]['attempts']++;
        $this->sourceWindows[$source]['last_seen_at'] = $now;

        if ($activeConnections >= $this->limits->maxConnections) {
            return 'connection_limit';
        }

        if ($activeConnectionsForSource >= $this->limits->maxConnectionsPerSource) {
            return 'source_connection_limit';
        }

        if ($this->sourceWindows[$source]['attempts'] > $this->limits->connectionAttemptsPerWindow) {
            return 'source_rate_limit';
        }

        return null;
    }

    public function frameRejection(ConnectionState $state, float $now): ?string
    {
        $this->resetFrameWindowWhenExpired($state, $now);
        $state->framesInWindow++;

        return $state->framesInWindow > $this->limits->framesPerWindow
            ? 'frame_rate_limit'
            : null;
    }

    public function invalidFrameRejection(ConnectionState $state, float $now): ?string
    {
        $this->resetFrameWindowWhenExpired($state, $now);
        $state->invalidFramesInWindow++;

        return $state->invalidFramesInWindow > $this->limits->invalidFramesPerWindow
            ? 'invalid_frame_limit'
            : null;
    }

    public function isIdle(ConnectionState $state, float $now): bool
    {
        return ($now - $state->lastActivityAt) >= $this->limits->idleTimeoutSeconds;
    }

    public function sourceFingerprint(string $remoteAddress): string
    {
        $source = $this->sourceAddress($remoteAddress);

        return hash('sha256', $source);
    }

    public function prune(float $now): void
    {
        $expiry = max($this->limits->connectionWindowSeconds, $this->limits->idleTimeoutSeconds);

        foreach ($this->sourceWindows as $source => $window) {
            if (($now - $window['last_seen_at']) >= $expiry) {
                unset($this->sourceWindows[$source]);
            }
        }
    }

    private function resetFrameWindowWhenExpired(ConnectionState $state, float $now): void
    {
        if (($now - $state->frameWindowStartedAt) < $this->limits->frameWindowSeconds) {
            return;
        }

        $state->frameWindowStartedAt = $now;
        $state->framesInWindow = 0;
        $state->invalidFramesInWindow = 0;
    }

    private function sourceAddress(string $remoteAddress): string
    {
        if (preg_match('/^\[([^]]+)](?::\d+)?$/', $remoteAddress, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(.+):(\d+)$/', $remoteAddress, $matches) === 1) {
            return $matches[1];
        }

        return $remoteAddress !== '' ? $remoteAddress : 'unresolved';
    }
}
