<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a device's history is the viewer's to see: the times it sat at one of their Sites, under a
 * kind of assignment they may see (S&D v13, pg-sdr-2f and 3e). The same rule as
 * SecurityDevicesAccessService::applyTemporalEventCustodyScope, worked out for one device in PHP so
 * it also covers removed devices (whose rows that scope leaves out) and history kept outside
 * device_events (audit lines, alerts, quiet times). A moment is visible when an allowed custody
 * window covers it and no other custody window does. Nothing outside a window is shown.
 */
final class DeviceCustody
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * The assignment kinds whose history the viewer may see (mirrors the access service).
     *
     * @return list<string>
     */
    public static function targetTypes(User $viewer): array
    {
        $types = [DeviceAssignment::TARGET_SITE, DeviceAssignment::TARGET_ROOM];
        if ($viewer->canDo('clients.viewAny')) {
            $types[] = DeviceAssignment::TARGET_CLIENT;
        }
        if ($viewer->canDo('staff.viewAny') && $viewer->canDo('hazards.view')) {
            $types[] = DeviceAssignment::TARGET_STAFF;
        }
        if ($viewer->canDo('fleet.viewAny') || $viewer->canDo('assets.viewAny')) {
            $types[] = DeviceAssignment::TARGET_VEHICLE;
        }

        return $types;
    }

    /**
     * @return array{allowed: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>, denied: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>}
     */
    public function windows(User $viewer, int $deviceId): array
    {
        $sites = $this->access->accessibleSiteIds($viewer);
        $types = self::targetTypes($viewer);
        $allowed = [];
        $denied = [];
        DeviceAssignment::query()
            ->where('device_id', $deviceId)
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->get(['id', 'assignable_type', 'custody_site_id', 'assigned_at', 'released_at'])
            ->each(function (DeviceAssignment $assignment) use ($sites, $types, &$allowed, &$denied): void {
                if ($assignment->assigned_at === null) {
                    return;
                }
                $window = [
                    CarbonImmutable::parse($assignment->assigned_at),
                    $assignment->released_at === null ? null : CarbonImmutable::parse($assignment->released_at),
                    (int) $assignment->custody_site_id,
                ];
                $ok = $assignment->custody_site_id !== null
                    && in_array((int) $assignment->custody_site_id, $sites, true)
                    && in_array((string) $assignment->assignable_type, $types, true);
                if ($ok) {
                    $allowed[] = $window;
                } else {
                    $denied[] = $window;
                }
            });

        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /** @param array{allowed: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>, denied: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>} $windows */
    public static function covers(array $windows, ?CarbonInterface $at): bool
    {
        if ($at === null) {
            return false;
        }
        $inside = static fn (array $window): bool => $window[0]->lessThanOrEqualTo($at) && ($window[1] === null || $window[1]->greaterThan($at));
        foreach ($windows['denied'] as $window) {
            if ($inside($window)) {
                return false;
            }
        }
        foreach ($windows['allowed'] as $window) {
            if ($inside($window)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The earliest moment the viewer's history starts, for bounding queries. Null: nothing visible.
     *
     * @param  array{allowed: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>, denied: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>}  $windows
     */
    public static function earliest(array $windows): ?CarbonImmutable
    {
        $starts = array_map(static fn (array $window): CarbonImmutable => $window[0], $windows['allowed']);

        return $starts === [] ? null : min($starts);
    }

    /**
     * The Sites the viewer saw this device at.
     *
     * @param  array{allowed: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>, denied: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int}>}  $windows
     * @return list<int>
     */
    public static function siteIds(array $windows): array
    {
        return array_values(array_unique(array_map(static fn (array $window): int => $window[2], $windows['allowed'])));
    }
}
