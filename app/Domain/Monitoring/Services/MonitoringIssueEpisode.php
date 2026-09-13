<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;
use Throwable;

/** Bounded provenance for a confirmed availability or technical-check issue. */
final class MonitoringIssueEpisode
{
    public const FAILURE_TYPES = ['offline', 'monitor_failed'];

    public const RECOVERY_TYPES = ['online', 'monitor_recovered'];

    public const EVENT_TYPES = [...self::FAILURE_TYPES, ...self::RECOVERY_TYPES];

    public static function field(string $eventType): string
    {
        return in_array($eventType, ['monitor_failed', 'monitor_recovered'], true)
            ? 'condition_episode' : 'availability_episode';
    }

    public static function isNative(DeviceEvent $event): bool
    {
        return self::field($event->event_type) === 'condition_episode'
            || data_get($event->payload, 'availability_episode_version') !== null;
    }

    public static function begin(Monitor $monitor, MonitorObservation $observation, int $siteId): array
    {
        $episode = [
            'version' => 1, 'monitor_id' => (int) $monitor->id, 'device_id' => (int) $monitor->device_id,
            'site_id' => $siteId, 'observation_id' => (int) $observation->id,
            'started_at' => $observation->observed_at->toIso8601String(),
        ];
        if (! $monitor->affects_availability) {
            $episode['condition_kind'] = $monitor->kind->value;
        }

        return [...$episode, 'key' => self::key($episode)];
    }

    public static function current(Monitor $monitor, int $siteId): ?array
    {
        $field = $monitor->affects_availability ? 'availability_episode' : 'condition_episode';

        return self::validate($monitor->{$field}, (int) $monitor->device_id, $siteId, (int) $monitor->id,
            $field === 'condition_episode');
    }

    public static function fromEvent(DeviceEvent $event, ?int $siteId = null): ?array
    {
        $payload = $event->payload ?? [];
        $field = self::field($event->event_type);
        if (! in_array($event->event_type, self::EVENT_TYPES, true)
            || $event->source !== 'oblivion_monitoring' || ($payload[$field.'_version'] ?? null) !== 1
            || ! is_int($payload['monitor_id'] ?? null) || ! is_int($payload['site_id'] ?? null)) {
            return null;
        }
        $episode = self::validate($payload[$field] ?? null, (int) $event->device_id,
            $siteId ?? $payload['site_id'], $payload['monitor_id'], $field === 'condition_episode');
        if ($episode === null || $payload['site_id'] !== $episode['site_id'] || $event->occurred_at === null
            || CarbonImmutable::parse($episode['started_at'])->greaterThan($event->occurred_at)) {
            return null;
        }

        return $episode;
    }

    public static function recoveryFor(DeviceEvent $failure, int $siteId): ?DeviceEvent
    {
        if (! in_array($failure->event_type, self::FAILURE_TYPES, true)) {
            return null;
        }
        $episode = self::fromEvent($failure, $siteId);
        if ($episode === null) {
            if (self::isNative($failure)) {
                return null;
            }

            return DeviceEvent::query()->where('device_id', $failure->device_id)->where('event_type', 'online')
                ->where('source', 'oblivion_monitoring')->where('occurred_at', '>=', $failure->occurred_at)
                ->orderBy('occurred_at')->orderBy('id')->lazy(100)
                ->first(fn (DeviceEvent $recovery): bool => self::legacyFailureForRecovery($recovery, $siteId)?->id === $failure->id);
        }

        $field = self::field($failure->event_type);

        return DeviceEvent::query()->where('device_id', $failure->device_id)
            ->where('event_type', $failure->event_type === 'offline' ? 'online' : 'monitor_recovered')
            ->where('source', 'oblivion_monitoring')->where('payload->'.$field.'->key', $episode['key'])
            ->where('occurred_at', '>=', $failure->occurred_at)->orderBy('occurred_at')->orderBy('id')
            ->lazy(100)->first(fn (DeviceEvent $event): bool => (self::fromEvent($event, $siteId)['key'] ?? null) === $episode['key']
                && self::hasCanonicalObservations($event, $siteId));
    }

    /** Historical sources have no observation tuple; bind recovery to one preceding canonical fault. */
    public static function legacyFailureForRecovery(DeviceEvent $recovery, int $siteId): ?DeviceEvent
    {
        $key = data_get($recovery->payload, 'monitor_correlation_key');
        if ($recovery->source !== 'oblivion_monitoring' || $recovery->event_type !== 'online'
            || $recovery->occurred_at === null || data_get($recovery->payload, 'availability_episode_version') !== null
            || (data_get($recovery->payload, 'site_id') !== null && data_get($recovery->payload, 'site_id') !== $siteId)
            || ($key !== null && (! is_string($key) || preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1))
            || ($key === null && data_get($recovery->payload, 'legacy_monitoring_recovery') !== true)) {
            return null;
        }

        $previous = DeviceEvent::query()->where('device_id', $recovery->device_id)
            ->where('source', $recovery->source)->whereIn('event_type', ['offline', 'online'])
            ->where(function ($query) use ($recovery): void {
                $query->where('occurred_at', '<', $recovery->occurred_at)
                    ->orWhere(fn ($sameTime) => $sameTime->where('occurred_at', $recovery->occurred_at)->where('id', '<', $recovery->id));
            })
            ->when($key === null, fn ($query) => $query->whereNull('payload->monitor_correlation_key'),
                fn ($query) => $query->where('payload->monitor_correlation_key', $key))
            ->orderByDesc('occurred_at')->orderByDesc('id')->first();

        if (! $previous || $previous->event_type !== 'offline'
            || data_get($previous->payload, 'availability_episode_version') !== null
            || (data_get($previous->payload, 'site_id') !== null && data_get($previous->payload, 'site_id') !== $siteId)) {
            return null;
        }

        return $previous;
    }

    /** Source capability is proved by immutable observations, never a payload label alone. */
    public static function hasCanonicalObservations(DeviceEvent $event, int $siteId): bool
    {
        $episode = self::fromEvent($event, $siteId);
        $observationId = data_get($event->payload, 'observation_id');
        if ($episode === null || ! is_int($observationId)
            || ! MonitorObservation::supportsProvenanceColumns()
            || ! in_array($event->event_type, self::EVENT_TYPES, true)
            || data_get($event->payload, 'to_state') !== (in_array($event->event_type, self::FAILURE_TYPES, true) ? 'failed' : 'healthy')) {
            return false;
        }
        $monitor = Monitor::query()->whereKey($episode['monitor_id'])->where('device_id', $event->device_id)->first();
        $technical = self::field($event->event_type) === 'condition_episode';
        if ($monitor === null || $monitor->affects_availability === $technical
            || ($technical && ($episode['condition_kind'] ?? null) !== $monitor->kind->value)) {
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

    private static function validate(mixed $episode, int $deviceId, int $siteId, int $monitorId, bool $technical = false): ?array
    {
        if (! is_array($episode) || ($episode['version'] ?? null) !== 1) {
            return null;
        }
        if ($technical ? (! is_string($episode['condition_kind'] ?? null)
            || MonitorKind::tryFrom($episode['condition_kind']) === null)
            : array_key_exists('condition_kind', $episode)) {
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
            ? array_intersect_key($episode, array_flip(['version', 'monitor_id', 'device_id', 'site_id', 'observation_id', 'started_at', 'key', 'condition_kind']))
            : null;
    }

    private static function key(array $episode): string
    {
        if (isset($episode['condition_kind'])) {
            return hash('sha256', json_encode(['native-condition-v1', $episode['monitor_id'], $episode['device_id'],
                $episode['site_id'], $episode['observation_id'], $episode['started_at'], $episode['condition_kind']], JSON_THROW_ON_ERROR));
        }

        return hash('sha256', json_encode(['native-availability-v1', $episode['monitor_id'], $episode['device_id'],
            $episode['site_id'], $episode['observation_id'], $episode['started_at']], JSON_THROW_ON_ERROR));
    }
}
