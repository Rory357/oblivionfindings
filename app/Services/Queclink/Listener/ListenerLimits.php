<?php

namespace App\Services\Queclink\Listener;

use InvalidArgumentException;

/**
 * Fail-closed, operator-configurable bounds for the native TCP intake.
 */
final class ListenerLimits
{
    public readonly int $maxConnections;

    public readonly int $maxConnectionsPerSource;

    public readonly int $maxTrackedSources;

    public readonly int $connectionAttemptsPerWindow;

    public readonly int $connectionWindowSeconds;

    public readonly int $idleTimeoutSeconds;

    public readonly int $maxFrameBytes;

    public readonly int $maxBufferBytes;

    public readonly int $framesPerWindow;

    public readonly int $invalidFramesPerWindow;

    public readonly int $frameWindowSeconds;

    public function __construct()
    {
        $this->maxConnections = $this->configuredInt('max_connections', 256, 1, 4096);
        $this->maxConnectionsPerSource = $this->configuredInt(
            'max_connections_per_source',
            64,
            1,
            $this->maxConnections,
        );
        $this->maxTrackedSources = $this->configuredInt('max_tracked_sources', 4096, 64, 65536);
        $this->connectionAttemptsPerWindow = $this->configuredInt(
            'connection_attempts_per_window',
            120,
            1,
            10000,
        );
        $this->connectionWindowSeconds = $this->configuredInt('connection_window_seconds', 60, 1, 3600);
        $this->idleTimeoutSeconds = $this->configuredInt('idle_timeout_seconds', 900, 30, 86400);
        $this->maxFrameBytes = $this->configuredInt('max_frame_bytes', 16384, 256, 65535);
        $this->maxBufferBytes = $this->configuredInt(
            'max_buffer_bytes',
            32768,
            $this->maxFrameBytes,
            262144,
        );
        $this->framesPerWindow = $this->configuredInt('frames_per_window', 240, 1, 100000);
        $this->invalidFramesPerWindow = $this->configuredInt('invalid_frames_per_window', 20, 1, 10000);
        $this->frameWindowSeconds = $this->configuredInt('frame_window_seconds', 60, 1, 3600);
    }

    private function configuredInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = config("services.queclink.listener.{$key}", $default);

        if (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/', $value) !== 1)) {
            throw new InvalidArgumentException("Invalid Queclink listener limit: {$key}.");
        }

        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Invalid Queclink listener limit: {$key}.");
        }

        return $value;
    }
}
