<?php

namespace App\Domain\SecurityDevices\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "I'm still running" marks from the long-running S&D listeners (alarm receiver, MQTT sensors), so the
 * server readiness check can show whether supervisor is keeping them alive (Phase A, items A3 and A4).
 */
final class ListenerHeartbeat
{
    public const string SIA_DC09 = 'sia_dc09';

    public const string MQTT_SENSORS = 'mqtt_sensors';

    /** How often a running listener writes its mark. */
    public const int BEAT_EVERY_SECONDS = 30;

    private const string CACHE_PREFIX = 'security-devices:listener-heartbeat:';

    public function beat(string $listener): void
    {
        try {
            Cache::put(self::CACHE_PREFIX.$listener, CarbonImmutable::now('UTC')->toIso8601String(), 3600);
        } catch (Throwable) {
            // A cache outage must never stop the listener itself.
        }
    }

    public function lastBeat(string $listener): ?CarbonImmutable
    {
        try {
            $raw = Cache::get(self::CACHE_PREFIX.$listener);

            return is_string($raw) && $raw !== '' ? CarbonImmutable::parse($raw) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
