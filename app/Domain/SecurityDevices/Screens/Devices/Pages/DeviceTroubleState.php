<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A network device that's down (drawing pg-sdr-17): the page's own "something is wrong" state,
 * not another route. It says plainly what Oblivion knows: when it stopped answering, which hub
 * keeps trying, its last reply, the devices behind it (grey "Can't check", never red) and who
 * the alert rule tells. Acknowledge, assign and quiet time come with the Alerts menu (Phase D).
 */
final class DeviceTroubleState
{
    private const int BEHIND_LIMIT = 12;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly DeviceObservations $observations,
        private readonly DeviceChecksTab $checks,
    ) {}

    /**
     * @param  Collection<int, Monitor>  $monitors  enabled checks, with profile and collector loaded
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, DeviceKind $kind, DeviceEntry $entry, Collection $monitors, ?CarbonImmutable $custody, bool $canSeeEvents): array
    {
        $availability = DeviceObservations::availabilityMonitors($monitors);
        $failing = $availability->first(fn (Monitor $monitor): bool => $monitor->current_state?->value === 'failed') ?? $availability->first();
        $since = $entry->offlineSince ?? $failing?->last_state_changed_at;
        $since = $since === null ? null : CarbonImmutable::parse($since);
        $hub = $failing?->collector instanceof MonitoringCollector
            && in_array((int) $failing->collector->site_id, $this->access->accessibleSiteIds($viewer), true)
                ? CoveringHubResolver::label($failing->collector)
                : ($failing?->collector_id === null ? 'The Oblivion server' : 'Its hub');

        $behind = $this->behind($viewer, $device, $monitors);
        $missed = $since === null ? ['count' => 0, 'first_at' => null, 'last_at' => null] : $this->observations->missedSince($monitors, $since);

        return [
            'since' => $since?->toIso8601String(),
            'checked_by' => $hub,
            'last_try_at' => $entry->lastCheckedAt?->toIso8601String(),
            'every_seconds' => $failing?->profile?->interval_seconds === null ? null : (int) $failing->profile->interval_seconds,
            'last_reply' => $this->observations->lastReply($monitors, $custody),
            'missed' => $missed,
            'behind' => $behind['items'],
            'behind_count' => $behind['count'],
            'next' => $kind === DeviceKind::Router
                ? 'Most likely power or the internet line at '.($entry->siteName ?? 'the Site').': ask someone there to check the router’s lights.'
                : 'Most likely its power or cable at '.($entry->siteName ?? 'the Site').': ask someone there to check its lights.',
            'told' => $entry->siteId === null ? null : $this->checks->told($device, $entry->siteName),
            'events' => ! $canSeeEvents || $since === null
                ? []
                : $this->access
                    ->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
                    ->where('occurred_at', '>=', $since->subMinutes(5))
                    ->oldest('occurred_at')
                    ->limit(10)
                    ->get(['id', 'event_type', 'severity', 'occurred_at', 'source'])
                    ->map(fn (DeviceEvent $event): array => DevicePageWords::event($event))
                    ->values()
                    ->all(),
        ];
    }

    /**
     * The devices behind it: checks that went grey because of it, and devices wired to it.
     * Only devices the viewer may see are named or counted.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    private function behind(User $viewer, Device $device, Collection $monitors): array
    {
        $ids = $monitors->pluck('id')->all();
        $suppressed = $ids === []
            ? collect()
            : Monitor::query()
                ->whereIn('root_cause_monitor_id', $ids)
                ->where('is_enabled', true)
                ->pluck('device_id');
        $wired = $device->childRelationships()->pluck('child_device_id');
        $deviceIds = $suppressed->merge($wired)->filter()->map(fn (mixed $id): int => (int) $id)->unique()->reject(fn (int $id): bool => $id === (int) $device->id)->values();
        if ($deviceIds->isEmpty()) {
            return ['items' => [], 'count' => 0];
        }

        $entries = $this->directory->entries($viewer, null, $deviceIds->all())->sortBy(fn (DeviceEntry $entry): string => mb_strtolower($entry->name()))->values();

        return [
            'count' => $entries->count(),
            'items' => $entries->take(self::BEHIND_LIMIT)->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'kind' => $entry->kind->value,
                'model' => $entry->model(),
                'state' => $entry->state->value,
                'last_seen_at' => $entry->lastSeenAt()?->toIso8601String(),
                'href' => '/security-devices/devices/'.$entry->id(),
            ])->values()->all(),
        ];
    }
}
