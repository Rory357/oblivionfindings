<?php

namespace App\Domain\SecurityDevices\Console\Moves;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceGroupMember;
use App\Domain\SecurityDevices\Moves\RoomNames;
use App\Domain\SecurityDevices\Moves\RoomPlacement;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * S&D data move "Device groups → rooms and filters" (D8; plan: "Each group becomes a filter; room-like
 * names suggest a room. Tables kept read-only for one release; dropped only after your yes").
 *
 * A group already is a filter: Devices › "Any group" reads device_groups as they are (S&D rebuild C6),
 * so nothing is copied. This move only places members into a room when the group's name names exactly
 * one room at that member's Site and the member is at the Site with no room. The group tables are only
 * read; they are never changed or dropped here. The count a future drop would remove is printed.
 */
final class MoveDeviceGroupsCommand extends DataMoveCommand
{
    protected $signature = 'security-devices:moves:device-groups
        {--apply : Place the suggested members in rooms. Without it, a dry run that changes nothing}
        {--actor= : The user id every change is recorded against (needed with --apply)}
        {--force : Let --apply run in production}';

    protected $description = 'S&D data move: show the filter each device group becomes and place members in a room its name names (dry run unless --apply)';

    private const string MOVE = 'device_groups';

    public function handle(RoomPlacement $placement, SecurityDevicesAccessService $access): int
    {
        $actor = null;
        if ($this->applying() && ! ($actor = $this->actorForApply()) instanceof User) {
            return self::FAILURE;
        }

        $groups = DeviceGroup::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);
        $membership = DeviceGroupMember::query()
            ->whereIn('device_group_id', $groups->pluck('id'))
            ->orderBy('id')
            ->get(['device_group_id', 'device_id']);
        $members = $membership->groupBy('device_group_id');
        $devices = Device::query()
            ->whereIn('id', $membership->pluck('device_id')->unique()->values())
            ->whereNotIn('status', [DeviceStatus::Decommissioned->value, DeviceStatus::Lost->value])
            ->with(['assignments' => fn ($assignments) => $assignments->active()])
            ->get()
            ->keyBy('id');
        $places = $devices->map(fn (Device $device): array => RoomPlacement::where($device));
        $siteIds = $places->pluck('site_id')->filter()->unique()->values();
        $siteNames = Site::query()->whereIn('id', $siteIds)->pluck('name', 'id');
        /** @var Collection<int, Collection<int, SiteRoom>> $rooms */
        $rooms = SiteRoom::query()->whereIn('site_id', $siteIds)->orderBy('id')->get(['id', 'site_id', 'name'])->groupBy('site_id');

        $groupRows = [];
        /** @var array<int, array<int, array{group: DeviceGroup, room: SiteRoom}>> $suggested device id => room id => the group that names it */
        $suggested = [];
        foreach ($groups as $group) {
            $live = collect($members->get($group->id, collect()))
                ->pluck('device_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $devices->has($id))
                ->values();
            $sites = $live->map(fn (int $id): ?int => $places[$id]['site_id'])->filter()->unique()
                ->map(fn (int $id): string => (string) ($siteNames[$id] ?? 'Site #'.$id))->values()->all();

            $roomHints = [];
            foreach ($live as $deviceId) {
                $where = $places[$deviceId];
                if ($where['type'] !== DeviceAssignment::TARGET_SITE) {
                    continue;
                }
                $room = RoomNames::onlyRoomIn((string) $group->name, $rooms->get($where['site_id'], collect()));
                if ($room === null || $devices[$deviceId]->status === DeviceStatus::Quarantined) {
                    continue;
                }
                $suggested[$deviceId][(int) $room->id] = ['group' => $group, 'room' => $room];
                $roomHints[(string) $room->name] = ($roomHints[(string) $room->name] ?? 0) + 1;
            }

            $groupRows[] = [
                $group->id,
                (string) $group->name,
                $live->count(),
                $sites === [] ? '—' : implode(', ', $sites),
                'Devices › Any group: '.$group->name.' (/security-devices/devices?group='.$group->id.')',
                $roomHints === [] ? '—' : implode(', ', array_map(fn (string $room, int $count): string => "{$room}: {$count} without a room", array_keys($roomHints), $roomHints)),
            ];
        }

        $toPlace = [];
        $placeRows = [];
        $conflicts = [];
        foreach ($suggested as $deviceId => $byRoom) {
            $device = $devices[$deviceId];
            $site = (string) ($siteNames[$places[$deviceId]['site_id']] ?? '');
            if (count($byRoom) > 1) {
                $conflicts[] = [$deviceId, self::deviceLabel($device), $site, implode(', ', array_map(fn (array $hint): string => $hint['group']->name.' → '.$hint['room']->name, $byRoom))];

                continue;
            }
            $hint = array_values($byRoom)[0];
            $toPlace[$deviceId] = $hint['room'];
            $placeRows[] = [$deviceId, self::deviceLabel($device), (string) $hint['group']->name, $site, (string) $hint['room']->name];
        }

        $groupCount = DeviceGroup::withTrashed()->count();
        $deletedGroups = DeviceGroup::onlyTrashed()->count();
        $memberRows = DeviceGroupMember::query()->count();

        $this->components->info('Device groups → rooms and filters'.($this->applying() ? '' : ' (dry run)'));
        $this->line('  Groups: '.$groups->count().' · each one is already a filter on Devices (“Any group”)');
        $this->line('  Members to place in a room: '.count($toPlace).' · members two groups point to different rooms: '.count($conflicts));
        $this->line("  The group tables stay as they are (read-only for one release). A future drop, after Stephan’s yes, would remove {$groupCount} groups ({$deletedGroups} already deleted) and {$memberRows} member rows.");

        $this->section('Groups and the filter each becomes', ['ID', 'Group', 'Devices', 'Sites', 'Filter', 'Room its name names'], $groupRows);
        $this->section('Members to place in a room', ['ID', 'Device', 'Group', 'Site', 'Room'], $placeRows);
        $this->section('Left alone: groups point to different rooms', ['ID', 'Device', 'Site', 'Groups'], $conflicts);

        if (! $actor instanceof User) {
            $this->dryRunFooter(count($toPlace), 'place '.count($toPlace).' members in rooms');

            return self::SUCCESS;
        }

        $placed = 0;
        $skipped = [];
        $canPlace = $actor->canDo('securityDevices.devices.assign');
        foreach ($toPlace as $deviceId => $room) {
            if (! $canPlace) {
                $skipped[] = [$deviceId, 'The --actor can’t place devices (securityDevices.devices.assign)'];

                continue;
            }
            try {
                if (! $access->visibleDevices($actor)->whereKey($deviceId)->exists()) {
                    $skipped[] = [$deviceId, 'The --actor can’t see this device'];

                    continue;
                }
                if ($placement->place($devices[$deviceId], $room, $actor, self::MOVE) instanceof DeviceAssignment) {
                    $placed++;
                } else {
                    $skipped[] = [$deviceId, 'It has moved since, so it was left alone'];
                }
            } catch (Throwable $exception) {
                $skipped[] = [$deviceId, $exception instanceof \InvalidArgumentException || $exception instanceof \UnexpectedValueException
                    ? 'The placement code refused it: '.$exception->getMessage()
                    : 'It couldn’t be placed ('.class_basename($exception).')'];
            }
        }

        $this->newLine();
        $this->components->info("Done: placed {$placed} members in rooms. Each change is in the audit log. The group tables were not changed.");
        $this->section('Left alone', ['ID', 'Why'], $skipped);

        return self::SUCCESS;
    }
}
