<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\DevicesIndexPresenter;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The Site page's Rooms tab (S&D Phase F, drawing pg-sdr-12d): the viewer's devices at the Site,
 * grouped by the room they're placed in, with the reading that matters for each.
 *
 * Rooms are the Site's own rooms from Sites & locations (site_rooms); a device is in a room when its
 * current placement is that room (device_assignments, assignable_type "room"). Nothing new is
 * stored. A resident is named beside their room only to someone who may view that client, and then
 * by first or preferred name only (the same rule as the device record).
 */
final class SiteRoomsPresenter
{
    /** Device lines on a room's card before "and N more". */
    private const int LINES = 4;

    /**
     * @param  Collection<int, DeviceEntry>  $entries  the viewer's devices at the Site
     * @return array<string, mixed>
     */
    public function present(User $viewer, Site $site, Collection $entries): array
    {
        $rooms = SiteRoom::query()->where('site_id', $site->id)->ordered()->orderBy('name')
            ->with(['residentialRoom' => fn ($room) => $room->select(['id', 'site_room_id', 'assigned_client_id', 'assigned_until', 'is_active'])
                ->with('assignedClient')])
            ->get(['id', 'site_id', 'name', 'sort_order']);
        $byRoom = [];
        $unplaced = [];
        foreach ($entries as $entry) {
            $roomId = $entry->device->assignments
                ->first(fn (DeviceAssignment $assignment): bool => $assignment->assignable_type === DeviceAssignment::TARGET_ROOM)
                ?->assignable_id;
            if ($roomId !== null && $rooms->contains('id', (int) $roomId)) {
                $byRoom[(int) $roomId][] = $entry;
            } else {
                $unplaced[] = $entry;
            }
        }

        $items = $rooms
            ->filter(fn (SiteRoom $room): bool => isset($byRoom[(int) $room->id]))
            ->map(fn (SiteRoom $room): array => $this->card($viewer, $room, collect($byRoom[(int) $room->id])))
            ->values()
            ->all();

        return [
            'items' => $items,
            'room_count' => $rooms->count(),
            'unplaced' => $unplaced === [] ? null : [
                'count' => count($unplaced),
                'names' => array_map(fn (DeviceEntry $entry): string => $entry->name(), array_slice($unplaced, 0, 6)),
                'more' => max(0, count($unplaced) - 6),
            ],
            // Rooms are added and renamed in Sites & locations, for those who may open this Site there
            // (Sites' own policy: all Sites in Security & devices doesn't widen Sites & locations).
            'profile_href' => Gate::forUser($viewer)->allows('view', $site) ? '/sites/'.$site->id : null,
        ];
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function card(User $viewer, SiteRoom $room, Collection $entries): array
    {
        $entries = $entries->sortBy(fn (DeviceEntry $entry): string => $entry->sortKey())->values();
        $resident = $this->resident($viewer, $room);
        $facts = [];
        foreach ($entries as $entry) {
            array_push($facts, ...$this->lines($entry));
        }
        $shown = array_slice($facts, 0, self::LINES);
        if (count($facts) > self::LINES) {
            $shown[] = ['tone' => 'none', 'text' => 'and '.(count($facts) - self::LINES).' more', 'href' => null];
        }
        $count = $entries->count();

        return [
            'key' => 'room-'.$room->id,
            'name' => $resident !== null ? $room->name.' · '.$resident : (string) $room->name,
            'sub' => $count.' '.($count === 1 ? 'device' : 'devices'),
            'flag' => self::flag($entries),
            'facts' => $shown,
        ];
    }

    /**
     * One line per device, problems first, plus a low battery: "Room sensor: **21.4 °C** · CO₂ 620 ppm".
     *
     * @return list<array{tone: string, text: string, href: ?string}>
     */
    private function lines(DeviceEntry $entry): array
    {
        $href = '/security-devices/devices/'.$entry->id();
        $tone = match ($entry->state) {
            DeviceState::Online => 'success',
            DeviceState::Problem => 'warning',
            DeviceState::Offline => 'critical',
            default => 'none',
        };
        $readings = $entry->state === DeviceState::Online && $entry->kind === DeviceKind::Sensor ? DevicesIndexPresenter::sensorReadings($entry) : [];
        $text = $readings !== []
            ? $entry->name().': **'.array_shift($readings).'**'.($readings !== [] ? ' · '.implode(' · ', array_slice($readings, 0, 2)) : '')
            : $entry->name().' · '.mb_strtolower($entry->state->label());
        $lines = [['tone' => $tone, 'text' => $text, 'href' => $href]];
        if ($entry->batteryLow()) {
            $lines[] = ['tone' => 'warning', 'text' => $entry->name().' battery **'.$entry->battery().'%**', 'href' => $href];
        }

        return $lines;
    }

    /**
     * The room's one flag: the worst thing in it, or "All online".
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{tone: string, text: string}
     */
    private static function flag(Collection $entries): array
    {
        $offline = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Offline)->count();
        $watched = $entries->reject(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::NotWatched);

        return match (true) {
            $offline > 0 => ['tone' => 'critical', 'text' => $offline.' offline'],
            $entries->contains(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Problem) => ['tone' => 'warning', 'text' => 'Needs attention'],
            $entries->contains(fn (DeviceEntry $entry): bool => $entry->batteryLow()) => ['tone' => 'warning', 'text' => 'Battery low'],
            $entries->contains(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck) => ['tone' => 'neutral', 'text' => 'Can’t check'],
            $watched->isEmpty() => ['tone' => 'neutral', 'text' => 'Not watched'],
            default => ['tone' => 'success', 'text' => 'All online'],
        };
    }

    /** The resident of a bedroom, by first or preferred name, only to someone who may view them. */
    private function resident(User $viewer, SiteRoom $room): ?string
    {
        $house = $room->residentialRoom;
        $client = $house?->assignedClient;
        if ($house === null || ! $house->is_active || ! $client instanceof Client
            || ($house->assigned_until !== null && $house->assigned_until->endOfDay()->isPast())) {
            return null;
        }
        if (! Gate::forUser($viewer)->allows('view', $client)) {
            return null;
        }
        $name = trim((string) ($client->preferred_name ?: $client->first_name));

        return $name !== '' ? $name : null;
    }
}
