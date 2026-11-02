<?php

namespace App\Domain\SecurityDevices\Console\Moves;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Moves\DeviceTypeEvidence;
use App\Domain\SecurityDevices\Moves\RoomNames;
use App\Domain\SecurityDevices\Moves\RoomPlacement;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\DeviceFieldOwnershipService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * S&D data move "Devices: kind, room and Site" (plan §Moving today's data: "Kind, room and Site filled
 * by a one-off script with a dry run that prints counts first").
 *
 * Kind is worked out at runtime (DeviceKind::of) from domain, category and subcategory, so the only
 * thing this move ever sets is an empty subcategory, and only from clear evidence (DeviceTypeEvidence).
 * A device whose kind isn't "Other" but disagrees with clear evidence is listed for a person, never
 * changed (that would overwrite a type someone set).
 * A room is suggested only when the device's name names exactly one room of its Site, and only for a
 * device that is at the Site with no room. The Site itself is never guessed: devices without one are
 * listed for a person. Placing reuses the placement code Sites › Hardware uses (RoomPlacement).
 */
final class MoveDevicesCommand extends DataMoveCommand
{
    protected $signature = 'security-devices:moves:devices
        {--apply : Set the suggested types and rooms. Without it, a dry run that changes nothing}
        {--actor= : The user id every change is recorded against (needed with --apply)}
        {--force : Let --apply run in production}';

    protected $description = 'S&D data move: list devices with kind Other, no Site or no room, and fill the clear ones (dry run unless --apply)';

    private const string MOVE = 'devices';

    public function handle(
        DeviceTypeEvidence $evidence,
        RoomPlacement $placement,
        DeviceFieldOwnershipService $fields,
        SecurityDevicesAccessService $access,
    ): int {
        $actor = null;
        if ($this->applying() && ! ($actor = $this->actorForApply()) instanceof User) {
            return self::FAILURE;
        }

        $devices = Device::query()
            ->whereNotIn('status', [DeviceStatus::Decommissioned->value, DeviceStatus::Lost->value])
            ->with(['assignments' => fn ($assignments) => $assignments->active()])
            ->orderBy('id')
            ->get();
        $places = $devices->mapWithKeys(fn (Device $device): array => [(int) $device->id => RoomPlacement::where($device)]);
        $siteIds = $places->pluck('site_id')->filter()->unique()->values();
        $siteNames = Site::query()->whereIn('id', $siteIds)->pluck('name', 'id');
        /** @var Collection<int, Collection<int, SiteRoom>> $rooms */
        $rooms = SiteRoom::query()->whereIn('site_id', $siteIds)->orderBy('id')->get(['id', 'site_id', 'name'])->groupBy('site_id');

        $other = [];
        $mismatch = [];
        $noSite = [];
        $noRoom = [];
        $typeChanges = [];
        $roomChanges = [];
        $withSomeone = 0;

        foreach ($devices as $device) {
            $where = $places[(int) $device->id];
            $name = self::shownName($device, $where['type']);
            $siteName = $where['site_id'] !== null ? (string) ($siteNames[$where['site_id']] ?? 'Site #'.$where['site_id']) : '—';

            $kind = DeviceKind::of($device);
            $found = $evidence->for($device);
            if ($kind !== DeviceKind::Other) {
                // Listed only: changing a kind that isn't "Other" would overwrite a type someone set.
                if ($found['kind'] !== null && $found['kind'] !== $kind) {
                    $mismatch[] = [$device->id, $name, $siteName, $kind->label().' ('.self::type($device).')', $found['source'].' says '.$found['kind']->label()];
                }
            } else {
                $current = trim((string) $device->subcategory);
                if ($current === '' && $found['subcategory'] !== null) {
                    $typeChanges[(int) $device->id] = $found['subcategory'];
                    $suggestion = 'Set type to '.$found['subcategory'].' → '.$found['note'];
                } elseif ($current !== '') {
                    $suggestion = 'Already has a type ('.$current.'), so it is left alone. '.($found['kind'] !== null ? $found['note'] : 'Change it on the device page if it’s wrong.');
                } else {
                    $suggestion = $found['note'];
                }
                $other[] = [$device->id, $name, $siteName, self::type($device), $suggestion];
            }

            if ($where['site_id'] === null) {
                $noSite[] = [$device->id, $name, $where['type'] === null ? 'Not placed anywhere' : 'With a person or vehicle, no Site'];

                continue;
            }

            if (in_array($where['type'], [DeviceAssignment::TARGET_STAFF, DeviceAssignment::TARGET_CLIENT, DeviceAssignment::TARGET_VEHICLE], true)) {
                $withSomeone++;

                continue;
            }

            if ($where['type'] !== DeviceAssignment::TARGET_SITE) {
                continue;
            }

            $siteRooms = $rooms->get($where['site_id'], collect());
            $mentioned = RoomNames::mentionedIn((string) $device->name, $siteRooms);
            if ($siteRooms->isEmpty()) {
                $note = 'Its Site has no rooms yet';
            } elseif ($mentioned === []) {
                $note = 'Its name names no room';
            } elseif (count($mentioned) > 1) {
                $note = 'Its name names more than one room ('.implode(', ', array_map(fn (SiteRoom $room): string => (string) $room->name, $mentioned)).')';
            } elseif ($device->status === DeviceStatus::Quarantined) {
                $note = 'Quarantined, so it can’t be placed';
            } else {
                $roomChanges[(int) $device->id] = $mentioned[0];
                $note = 'Place in '.$mentioned[0]->name;
            }
            $noRoom[] = [$device->id, $name, $siteName, $note];
        }

        $this->components->info('Devices: kind, room and Site'.($this->applying() ? '' : ' (dry run)'));
        $this->line('  Devices checked (not removed): '.$devices->count());
        $this->line('  Kind “Other”: '.count($other).' · type to set: '.count($typeChanges));
        $this->line('  Kind the evidence disagrees with (listed only): '.count($mismatch));
        $this->line('  No Site: '.count($noSite).' (listed for a person; a Site is never guessed)');
        $this->line('  At a Site with no room: '.count($noRoom).' · room to set: '.count($roomChanges));
        $this->line('  With a person or vehicle (rooms don’t apply): '.$withSomeone);

        $this->section('Kind “Other”', ['ID', 'Device', 'Site', 'Type now', 'What happens'], $other);
        $this->section('Kind the evidence disagrees with: a person checks the type (not changed)', ['ID', 'Device', 'Site', 'Kind now', 'Evidence'], $mismatch);
        $this->section('No Site', ['ID', 'Device', 'Why'], $noSite);
        $this->section('At a Site with no room', ['ID', 'Device', 'Site', 'What happens'], $noRoom);

        if (! $actor instanceof User) {
            $this->dryRunFooter(count($typeChanges) + count($roomChanges), 'set '.count($typeChanges).' types and '.count($roomChanges).' rooms');

            return self::SUCCESS;
        }

        $byId = $devices->keyBy('id');
        $set = 0;
        $placed = 0;
        $skipped = [];

        foreach ($typeChanges as $deviceId => $subcategory) {
            try {
                $changed = DB::transaction(function () use ($deviceId, $subcategory, $fields, $actor): bool {
                    $locked = Device::query()->whereKey($deviceId)->lockForUpdate()->first();
                    if (! $locked instanceof Device || trim((string) $locked->subcategory) !== '') {
                        return false;
                    }
                    $updated = $fields->updateFromLocal($locked, ['subcategory' => $subcategory], $actor, null, null);
                    AuditLogger::logOrFail('security_devices.data_move.'.self::MOVE.'.type_set', $updated, [
                        'actor_id' => (int) $actor->id,
                        'fields' => ['subcategory'],
                    ]);

                    return true;
                }, 3);
                if ($changed) {
                    $set++;
                } else {
                    $skipped[] = [$deviceId, 'Type', 'It has a type now, so it was left alone'];
                }
            } catch (Throwable $exception) {
                $skipped[] = [$deviceId, 'Type', self::reason($exception)];
            }
        }

        $canPlace = $actor->canDo('securityDevices.devices.assign');
        foreach ($roomChanges as $deviceId => $room) {
            if (! $canPlace) {
                $skipped[] = [$deviceId, 'Room', 'The --actor can’t place devices (securityDevices.devices.assign)'];

                continue;
            }
            try {
                if (! $access->visibleDevices($actor)->whereKey($deviceId)->exists()) {
                    $skipped[] = [$deviceId, 'Room', 'The --actor can’t see this device'];

                    continue;
                }
                if ($placement->place($byId[$deviceId], $room, $actor, self::MOVE) instanceof DeviceAssignment) {
                    $placed++;
                } else {
                    $skipped[] = [$deviceId, 'Room', 'It has moved since, so it was left alone'];
                }
            } catch (Throwable $exception) {
                $skipped[] = [$deviceId, 'Room', self::reason($exception)];
            }
        }

        $this->newLine();
        $this->components->info("Done: set {$set} types and placed {$placed} devices in rooms. Each change is in the audit log.");
        $this->section('Left alone', ['ID', 'Change', 'Why'], $skipped);

        return self::SUCCESS;
    }

    /** Device names only; a device that is or ever was with a resident, or is a care tracker, is not named. */
    private static function shownName(Device $device, ?string $placement): string
    {
        return $placement === DeviceAssignment::TARGET_CLIENT ? 'Device #'.$device->id.' (with a resident, not named)' : self::deviceLabel($device);
    }

    private static function type(Device $device): string
    {
        $parts = array_filter([(string) $device->domain, (string) $device->category, (string) $device->subcategory], fn (string $part): bool => trim($part) !== '');

        return $parts === [] ? '—' : implode(' › ', $parts);
    }

    private static function reason(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => 'The --actor may not change this device',
            $exception instanceof \InvalidArgumentException, $exception instanceof \UnexpectedValueException => 'The placement code refused it: '.$exception->getMessage(),
            default => 'It couldn’t be changed ('.class_basename($exception).')',
        };
    }
}
