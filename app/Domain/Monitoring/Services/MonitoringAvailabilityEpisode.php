<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;
use Throwable;

/** Bounded provenance for one confirmed outage, carried by canonical DeviceEvents. */
final class MonitoringAvailabilityEpisode
{
    public static function begin(Monitor $monitor, MonitorObservation $observation, int $siteId): array
    {
        $episode = [
            'version' => 1, 'monitor_id' => (int) $monitor->id, 'device_id' => (int) $monitor->device_id,
            'site_id' => $siteId, 'observation_id' => (int) $observation->id,
            'started_at' => $observation->observed_at->toIso8601String(),
        ];

        return [...$episode, 'key' => self::key($episode)];
    }

    public static function current(Monitor $monitor, int $siteId): ?array
    {
        return self::validate($monitor->availability_episode, (int) $monitor->device_id, $siteId, (int) $monitor->id);
    }

    public static function fromEvent(DeviceEvent $event, ?int $siteId = null): ?array
    {
        $payload = $event->payload ?? [];
        if ($event->source !== 'oblivion_monitoring' || ($payload['availability_episode_version'] ?? null) !== 1
            || ! is_int($payload['monitor_id'] ?? null) || ! is_int($payload['site_id'] ?? null)) {
            return null;
        }
        $episode = self::validate($payload['availability_episode'] ?? null, (int) $event->device_id,
            $siteId ?? $payload['site_id'], $payload['monitor_id']);
        if ($episode === null || $payload['site_id'] !== $episode['site_id'] || $event->occurred_at === null
            || CarbonImmutable::parse($episode['started_at'])->greaterThan($event->occurred_at)) {
            return null;
        }

        return $episode;
    }

    public static function recoveryFor(DeviceEvent $failure, int $siteId): ?DeviceEvent
    {
        $episode = self::fromEvent($failure, $siteId);
        if ($episode === null) {
            return null;
        }

        return DeviceEvent::query()->where('device_id', $failure->device_id)->where('event_type', 'online')
            ->where('source', 'oblivion_monitoring')->where('payload->availability_episode->key', $episode['key'])
            ->where('occurred_at', '>=', $failure->occurred_at)->orderBy('occurred_at')->orderBy('id')
            ->lazy(100)->first(fn (DeviceEvent $event): bool => (self::fromEvent($event, $siteId)['key'] ?? null) === $episode['key']
                && self::hasCanonicalObservations($event, $siteId));
    }

    /** Source capability is proved by immutable observations, never a payload label alone. */
    public static function hasCanonicalObservations(DeviceEvent $event, int $siteId): bool
    {
        $episode = self::fromEvent($event, $siteId);
        $observationId = data_get($event->payload, 'observation_id');
        if ($episode === null || ! is_int($observationId)
            || ! MonitorObservation::supportsProvenanceColumns()
            || ! in_array($event->event_type, ['offline', 'online'], true)
            || data_get($event->payload, 'to_state') !== ($event->event_type === 'offline' ? 'failed' : 'healthy')) {
            return false;
        }
        $monitor = Monitor::query()->whereKey($episode['monitor_id'])->where('device_id', $event->device_id)->first();
        if ($monitor === null || ! $monitor->affects_availability) {
            return false;
        }
        $observations = MonitorObservation::query()->where('monitor_id', $monitor->id)
            ->where('device_id', $event->device_id)->where('site_id', $siteId)
            ->whereIn('id', [$episode['observation_id'], $observationId])->get()->keyBy('id');
        $start = $observations->get($episode['observation_id']);
        $current = $observations->get($observationId);

        return $start !== null && $current !== null
            && $start->observed_at->toIso8601String() === $episode['started_at']
            && $current->observed_at->equalTo($event->occurred_at);
    }

    private static function validate(mixed $episode, int $deviceId, int $siteId, int $monitorId): ?array
    {
        if (! is_array($episode) || ($episode['version'] ?? null) !== 1) {
            return null;
        }
        foreach (['device_id', 'site_id', 'monitor_id', 'observation_id'] as $key) {
            if (! is_int($episode[$key] ?? null) || $episode[$key] < 1) {
                return null;
            }
        }
        if ($episode['device_id'] !== $deviceId || $episode['site_id'] !== $siteId || $episode['monitor_id'] !== $monitorId
            || ! is_string($episode['started_at'] ?? null) || ! is_string($episode['key'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/', $episode['started_at']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $episode['key']) !== 1) {
            return null;
        }
        try {
            if (CarbonImmutable::parse($episode['started_at'])->toIso8601String() !== $episode['started_at']) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return hash_equals(self::key($episode), $episode['key'])
            ? array_intersect_key($episode, array_flip(['version', 'monitor_id', 'device_id', 'site_id', 'observation_id', 'started_at', 'key']))
            : null;
    }

    private static function key(array $episode): string
    {
        return hash('sha256', json_encode(['native-availability-v1', $episode['monitor_id'], $episode['device_id'],
            $episode['site_id'], $episode['observation_id'], $episode['started_at']], JSON_THROW_ON_ERROR));
    }
}
