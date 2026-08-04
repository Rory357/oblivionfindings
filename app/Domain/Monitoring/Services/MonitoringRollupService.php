<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class MonitoringRollupService
{
    /** @return array{state: MonitorState, counts: array<string, int>, monitor_count: int} */
    public function device(Device $device, DateTimeInterface $at): array
    {
        $states = $device->monitors()
            ->with('profile')
            ->where('is_enabled', true)
            ->get()
            ->map(fn (Monitor $monitor): ?MonitorState => $this->stateAt($monitor, $at))
            ->filter()
            ->values();

        return $this->summarise($states->all(), 'monitor_count');
    }

    /** @return array{state: MonitorState, counts: array<string, int>, device_count: int} */
    public function site(Site $site, DateTimeInterface $at): array
    {
        $states = Device::query()
            ->whereHas('monitors')
            ->get()
            ->filter(function (Device $device) use ($site): bool {
                try {
                    return app(CanonicalDeviceSiteResolver::class)->resolve((int) $device->id) === (int) $site->id;
                } catch (Throwable) {
                    return false;
                }
            })
            ->map(fn (Device $device): MonitorState => $this->device($device, $at)['state'])
            ->values();

        return $this->summarise($states->all(), 'device_count');
    }

    /** @return array{state: MonitorState, counts: array<string, int>, site_count: int} */
    public function estate(DateTimeInterface $at): array
    {
        $states = Site::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->get()
            ->map(fn (Site $site): MonitorState => $this->site($site, $at)['state'])
            ->values();

        return $this->summarise($states->all(), 'site_count');
    }

    private function stateAt(Monitor $monitor, DateTimeInterface $at): ?MonitorState
    {
        $underlying = $monitor->effective_state === MonitorState::Suppressed
            ? $monitor->current_state
            : $monitor->effective_state;

        if ($underlying === MonitorState::NotApplicable) {
            return null;
        }

        if (! $underlying->isFailure()) {
            $staleAfter = max(1, (int) $monitor->profile->stale_after_seconds);
            if ($monitor->last_observation_at !== null) {
                $last = CarbonImmutable::instance($monitor->last_observation_at);
                $now = CarbonImmutable::instance($at);
                if ($last->diffInSeconds($now, false) > $staleAfter) {
                    return MonitorState::Stale;
                }
            }
        }

        return $underlying;
    }

    /**
     * @param  list<MonitorState>  $states
     * @return array{state: MonitorState, counts: array<string, int>, monitor_count?: int, device_count?: int, site_count?: int}
     */
    private function summarise(array $states, string $countKey): array
    {
        $precedence = [
            MonitorState::Failed->value => 7,
            MonitorState::Degraded->value => 6,
            MonitorState::Stale->value => 5,
            MonitorState::Unknown->value => 4,
            MonitorState::Pending->value => 3,
            MonitorState::Healthy->value => 2,
            MonitorState::Suppressed->value => 1,
        ];
        $counts = [];
        foreach ($states as $state) {
            $counts[$state->value] = ($counts[$state->value] ?? 0) + 1;
        }

        $state = collect($states)
            ->sortByDesc(fn (MonitorState $candidate): int => $precedence[$candidate->value] ?? 0)
            ->first() ?? MonitorState::Unknown;

        return ['state' => $state, 'counts' => $counts, $countKey => count($states)];
    }
}
