<?php

namespace App\Domain\SecurityDevices\Moves;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Integration\UnifiOperationalBridgeService;
use Illuminate\Support\Facades\DB;

/**
 * Where a device is today, and the one way the data moves put a device into a room.
 *
 * Placing goes through the code that already creates placements, exactly as Sites › Hardware does
 * (SiteHardwareController::assignRoom): a UniFi device through UnifiOperationalBridgeService's
 * syncRoomAssignment (which writes only Oblivion's own placement and calls nothing in UniFi), every
 * other device through DeviceRegistryService::placeWithinSite. The Site placement it replaces is
 * kept as history, so nothing is lost.
 *
 * A device is only ever moved from "at the Site, no room" into a room at that same Site. One that is
 * already in a room, or with a person or a vehicle, is never touched.
 */
final class RoomPlacement
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly UnifiOperationalBridgeService $unifi,
    ) {}

    /**
     * Where the device is now, from its current placement.
     *
     * @return array{type: ?string, site_id: ?int, room_id: ?int}
     */
    public static function where(Device $device): array
    {
        $assignment = $device->relationLoaded('assignments')
            ? $device->assignments->whereNull('released_at')->sortByDesc('assigned_at')->first()
            : $device->assignments()->active()->orderByDesc('assigned_at')->orderByDesc('id')->first();
        if (! $assignment instanceof DeviceAssignment) {
            return ['type' => null, 'site_id' => null, 'room_id' => null];
        }

        $type = (string) $assignment->assignable_type;
        $siteId = $assignment->custody_site_id !== null ? (int) $assignment->custody_site_id : null;
        if ($type === DeviceAssignment::TARGET_SITE) {
            $siteId = (int) $assignment->assignable_id;
        }

        return [
            'type' => $type,
            'site_id' => $siteId,
            'room_id' => $type === DeviceAssignment::TARGET_ROOM ? (int) $assignment->assignable_id : null,
        ];
    }

    /**
     * Put the device into the room, when it is still at the room's Site with no room. Returns the new
     * placement, or null when the device has moved since (so a second run changes nothing).
     */
    public function place(Device $device, SiteRoom $room, User $actor, string $move): ?DeviceAssignment
    {
        return DB::transaction(function () use ($device, $room, $actor, $move): ?DeviceAssignment {
            $siteId = (int) $room->site_id;
            $current = DeviceAssignment::query()
                ->where('device_id', $device->id)
                ->active()
                ->lockForUpdate()
                ->get();
            if ($current->count() !== 1
                || $current->first()->assignable_type !== DeviceAssignment::TARGET_SITE
                || (int) $current->first()->assignable_id !== $siteId) {
                return null;
            }

            $assignment = strtolower(trim((string) $device->provider)) === 'unifi'
                ? $this->unifi->syncRoomAssignment($device, $room, (int) $actor->id, $siteId)
                : $this->registry->placeWithinSite($device, $siteId, (int) $room->id, (int) $actor->id);

            AuditLogger::logOrFail("security_devices.data_move.{$move}.room_placed", $assignment, [
                'actor_id' => (int) $actor->id,
                'fields' => ['assignable_type', 'assignable_id'],
                'after' => ['assigned_at' => $assignment->assigned_at],
            ]);

            return $assignment;
        }, 3);
    }
}
