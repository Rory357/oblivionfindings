<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Management\Data\CommandObservationFreshness;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CommandObservationFreshnessService
{
    public function inspect(Device $device, ?CarbonImmutable $now = null): CommandObservationFreshness
    {
        $now ??= CarbonImmutable::now('UTC');
        $monitors = Monitor::query()
            ->where('device_id', $device->id)
            ->where('is_enabled', true)
            ->with('profile:id,stale_after_seconds')
            ->get();
        $latestMonitor = $monitors
            ->filter(fn (Monitor $monitor): bool => $monitor->last_observation_at !== null)
            ->sortByDesc(fn (Monitor $monitor): int => $monitor->last_observation_at->getTimestamp())
            ->first();
        $lastMonitorObservation = $latestMonitor?->last_observation_at === null
            ? null
            : CarbonImmutable::instance($latestMonitor->last_observation_at);
        $deviceObservedAt = $device->last_seen_at === null
            ? null
            : CarbonImmutable::instance($device->last_seen_at);
        $observedAt = Collection::make([$lastMonitorObservation, $deviceObservedAt])
            ->filter()
            ->sortDesc()
            ->first();
        $source = $observedAt !== null
            && $lastMonitorObservation !== null
            && $observedAt->equalTo($lastMonitorObservation)
                ? 'native_monitoring'
                : 'device_registry';
        $staleAfterSeconds = $source === 'native_monitoring' && $latestMonitor instanceof Monitor
            ? $this->monitorStaleAfterSeconds($latestMonitor)
            : max(30, (int) config('security_devices.command_observation_stale_after_seconds', 900));
        $hasStaleMonitor = $monitors->contains(
            fn (Monitor $monitor): bool => $this->monitorIsStale($monitor, $now),
        );
        $state = match (true) {
            $hasStaleMonitor => 'stale',
            $observedAt === null => 'never_observed',
            $observedAt->lessThan($now->subSeconds($staleAfterSeconds)) => 'stale',
            default => 'fresh',
        };

        return new CommandObservationFreshness(
            state: $state,
            observedAt: $observedAt,
            staleAfterSeconds: $staleAfterSeconds,
            source: $source,
        );
    }

    private function monitorStaleAfterSeconds(Monitor $monitor): int
    {
        $threshold = $monitor->profile?->stale_after_seconds;

        return is_numeric($threshold) && (int) $threshold > 0
            ? (int) $threshold
            : max(30, (int) config('security_devices.command_observation_stale_after_seconds', 900));
    }

    private function monitorIsStale(Monitor $monitor, CarbonImmutable $now): bool
    {
        if ($monitor->current_state?->value === 'stale') {
            return true;
        }

        return $monitor->last_observation_at === null
            || $monitor->last_observation_at->lt($now->subSeconds($this->monitorStaleAfterSeconds($monitor)));
    }
}
