<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the one-to-one seam between canonical physical spaces and the
 * residential occupancy extension.
 */
class SitePhysicalRoomService
{
    public function __construct(
        private readonly SiteClientPlacementService $placements,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createResidentialRoom(Site $site, array $attributes): SiteHouseRoom
    {
        return DB::transaction(function () use ($site, $attributes): SiteHouseRoom {
            $site = $this->lockSite($site);
            $attributes = Arr::only($attributes, ['name', 'notes', 'is_active', 'is_assignable', 'sort_order']);
            $name = $this->normaliseDisplayName((string) ($attributes['name'] ?? ''));
            $this->assertNamePresent($name);
            $this->assertResidentialNameAvailable($site, $name);
            $requestedSortOrder = array_key_exists('sort_order', $attributes)
                ? (int) $attributes['sort_order']
                : null;

            $canonical = $this->availableCanonicalNameMatch($site, $name);
            if ($canonical === null) {
                $this->assertCanonicalNameAvailable($site, $name);
                $canonical = SiteRoom::query()->create([
                    'site_id' => $site->id,
                    'name' => $name,
                    'sort_order' => $requestedSortOrder ?? $this->nextSortOrder($site),
                ]);
            }
            if ($requestedSortOrder !== null) {
                $canonical->update(['sort_order' => $requestedSortOrder]);
            }
            $attributes['sort_order'] = $requestedSortOrder ?? (int) $canonical->sort_order;

            $room = SiteHouseRoom::query()->create([
                ...$attributes,
                'site_id' => $site->id,
                'site_room_id' => $canonical->id,
                'name' => $name,
            ]);
            SiteRoom::query()
                ->where('linked_room_type', 'house_room')
                ->where('linked_room_id', $room->id)
                ->whereKeyNot($canonical->id)
                ->update(['linked_room_type' => null, 'linked_room_id' => null]);
            $canonical->update([
                'linked_room_type' => 'house_room',
                'linked_room_id' => $room->id,
            ]);

            return $room->fresh(['canonicalRoom']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateResidentialRoom(
        Site $site,
        SiteHouseRoom $room,
        array $attributes,
        ?User $actor = null,
    ): SiteHouseRoom {
        return DB::transaction(function () use ($site, $room, $attributes, $actor): SiteHouseRoom {
            $site = $this->lockSite($site);
            $room = $this->lockResidentialRoom($site, $room);
            $canonical = $this->lockCanonicalForResidential($site, $room);
            $attributes = Arr::only($attributes, ['name', 'notes', 'is_active', 'is_assignable', 'sort_order']);

            if (array_key_exists('name', $attributes)) {
                $name = $this->normaliseDisplayName((string) $attributes['name']);
                $this->assertNamePresent($name);
                $this->assertResidentialNameAvailable($site, $name, $room->id);
                $this->assertCanonicalNameAvailable($site, $name, $canonical->id);
                $attributes['name'] = $name;
                $canonical->update(['name' => $name]);
            }

            if (array_key_exists('sort_order', $attributes)) {
                $canonical->update(['sort_order' => (int) $attributes['sort_order']]);
            }

            if (array_key_exists('is_assignable', $attributes)
                && ! (bool) $attributes['is_assignable']
                && $room->assigned_client_id !== null) {
                abort_unless($actor instanceof User, 409, 'An actor is required to close the current room placement.');
                $room = $this->placements->assignRoom($site, $room, null, $actor);
            }

            $room->update($attributes);

            return $room->fresh(['canonicalRoom']);
        }, 3);
    }

    public function deactivateResidentialRoom(Site $site, SiteHouseRoom $room, User $actor): SiteHouseRoom
    {
        return DB::transaction(function () use ($site, $room, $actor): SiteHouseRoom {
            $room = $this->lockResidentialRoom($this->lockSite($site), $room);
            $this->lockCanonicalForResidential($site, $room);
            if ($room->assigned_client_id !== null) {
                $room = $this->placements->assignRoom($site, $room, null, $actor);
            }
            $room->update(['is_active' => false]);

            return $room->fresh(['canonicalRoom']);
        }, 3);
    }

    public function restoreResidentialRoom(Site $site, SiteHouseRoom $room): SiteHouseRoom
    {
        return DB::transaction(function () use ($site, $room): SiteHouseRoom {
            $room = $this->lockResidentialRoom($this->lockSite($site), $room);
            $this->lockCanonicalForResidential($site, $room);
            $room->update(['is_active' => true]);

            return $room->fresh(['canonicalRoom']);
        }, 3);
    }

    /** @param array<int, int> $orderedIds */
    public function reorderResidentialRooms(Site $site, array $orderedIds): void
    {
        DB::transaction(function () use ($site, $orderedIds): void {
            $site = $this->lockSite($site);
            $rooms = SiteHouseRoom::query()
                ->where('site_id', $site->id)
                ->whereKey($orderedIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            abort_unless($rooms->count() === count($orderedIds), 404);

            foreach ($orderedIds as $index => $roomId) {
                $room = $rooms->get((int) $roomId);
                $canonical = $this->lockCanonicalForResidential($site, $room);
                $sortOrder = $index + 1;
                $room->update(['sort_order' => $sortOrder]);
                $canonical->update(['sort_order' => $sortOrder]);
            }
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $rooms */
    public function syncResidentialRooms(Site $site, array $rooms, User $actor): void
    {
        DB::transaction(function () use ($site, $rooms, $actor): void {
            $keepIds = [];
            foreach ($rooms as $attributes) {
                $name = $this->normaliseDisplayName((string) ($attributes['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $payload = [
                    'name' => $name,
                    'notes' => $attributes['notes'] ?? null,
                    'is_active' => true,
                ];
                if (array_key_exists('is_assignable', $attributes)) {
                    $payload['is_assignable'] = (bool) $attributes['is_assignable'];
                }

                $room = ! empty($attributes['id'])
                    ? SiteHouseRoom::query()
                        ->where('site_id', $site->id)
                        ->whereKey((int) $attributes['id'])
                        ->first()
                    : SiteHouseRoom::query()
                        ->where('site_id', $site->id)
                        ->where('name', $name)
                        ->first();

                $room = $room
                    ? $this->updateResidentialRoom($site, $room, $payload, $actor)
                    : $this->createResidentialRoom($site, $payload);
                $keepIds[] = (int) $room->id;
            }

            SiteHouseRoom::query()
                ->where('site_id', $site->id)
                ->whereNotIn('id', $keepIds)
                ->where('is_active', true)
                ->get()
                ->each(fn (SiteHouseRoom $room) => $this->deactivateResidentialRoom($site, $room, $actor));
        }, 3);
    }

    public function createCanonicalRoom(Site $site, string $name): SiteRoom
    {
        return DB::transaction(function () use ($site, $name): SiteRoom {
            $site = $this->lockSite($site);
            $name = $this->normaliseDisplayName($name);
            $this->assertNamePresent($name);
            $this->assertCanonicalNameAvailable($site, $name);

            return SiteRoom::query()->create([
                'site_id' => $site->id,
                'name' => $name,
                'sort_order' => $this->nextSortOrder($site),
            ]);
        }, 3);
    }

    public function renameCanonicalRoom(Site $site, SiteRoom $room, string $name): SiteRoom
    {
        return DB::transaction(function () use ($site, $room, $name): SiteRoom {
            $site = $this->lockSite($site);
            $room = $this->lockCanonicalRoom($site, $room->id);
            $name = $this->normaliseDisplayName($name);
            $this->assertNamePresent($name);
            $residential = SiteHouseRoom::query()
                ->where('site_room_id', $room->id)
                ->lockForUpdate()
                ->first();

            if ($residential) {
                $this->updateResidentialRoom($site, $residential, ['name' => $name]);

                return $room->fresh();
            }

            $this->assertCanonicalNameAvailable($site, $name, $room->id);
            $room->update(['name' => $name]);

            return $room->fresh();
        }, 3);
    }

    /** @param array<int, array{id: int, sort_order: int}> $rooms */
    public function reorderCanonicalRooms(Site $site, array $rooms): void
    {
        DB::transaction(function () use ($site, $rooms): void {
            $site = $this->lockSite($site);
            $roomIds = collect($rooms)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $canonicalRooms = SiteRoom::query()
                ->where('site_id', $site->id)
                ->whereKey($roomIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            abort_unless($canonicalRooms->count() === count($roomIds), 404);

            foreach ($rooms as $roomData) {
                $room = $canonicalRooms->get((int) $roomData['id']);
                $sortOrder = (int) $roomData['sort_order'];
                $room->update(['sort_order' => $sortOrder]);
                SiteHouseRoom::query()
                    ->where('site_room_id', $room->id)
                    ->update(['sort_order' => $sortOrder]);
            }
        }, 3);
    }

    public function deleteCanonicalRoom(Site $site, SiteRoom $room): void
    {
        DB::transaction(function () use ($site, $room): void {
            $room = $this->lockCanonicalRoom($this->lockSite($site), $room->id);
            abort_if(
                SiteHouseRoom::query()->where('site_room_id', $room->id)->exists(),
                409,
                'Residential rooms must be deactivated from the Site Rooms workspace.',
            );
            abort_if(
                DeviceAssignment::query()
                    ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                    ->where('assignable_id', $room->id)
                    ->exists(),
                409,
                'This room has Device assignment history and must be retained.',
            );
            abort_if(
                Asset::query()->where('site_room_id', $room->id)->exists(),
                409,
                'Move assets out of this room before deleting it.',
            );

            foreach (['location_hardware', 'integration_events', 'integration_alerts', 'control_room_alerts'] as $table) {
                if (Schema::hasTable($table)
                    && Schema::hasColumn($table, 'room_id')
                    && DB::table($table)->where('room_id', $room->id)->exists()) {
                    abort(409, 'This room has operational history and must be retained.');
                }
            }

            $room->delete();
        }, 3);
    }

    public function placeAsset(Site $site, SiteHouseRoom $room, Asset $asset): Asset
    {
        return DB::transaction(function () use ($site, $room, $asset): Asset {
            $site = $this->lockSite($site);
            $room = $this->lockResidentialRoom($site, $room);
            $canonical = $this->lockCanonicalForResidential($site, $room);
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $asset->site_id === (int) $site->id, 422, 'Asset belongs to another site.');
            $asset->update([
                'room_id' => $room->id,
                'site_room_id' => $canonical->id,
            ]);

            return $asset->fresh();
        }, 3);
    }

    public function removeAsset(Site $site, SiteHouseRoom $room, Asset $asset): Asset
    {
        return DB::transaction(function () use ($site, $room, $asset): Asset {
            $room = $this->lockResidentialRoom($this->lockSite($site), $room);
            $canonical = $this->lockCanonicalForResidential($site, $room);
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $asset->site_id === (int) $site->id, 404);
            abort_unless((int) $asset->room_id === (int) $room->id, 404);
            abort_unless($asset->site_room_id === null || (int) $asset->site_room_id === (int) $canonical->id, 409);
            $asset->update(['room_id' => null, 'site_room_id' => null]);

            return $asset->fresh();
        }, 3);
    }

    private function lockCanonicalForResidential(Site $site, SiteHouseRoom $room): SiteRoom
    {
        if ($room->site_room_id !== null) {
            return SiteRoom::query()
                ->whereKey($room->site_room_id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $legacy = SiteRoom::query()
            ->where('site_id', $site->id)
            ->where('linked_room_type', 'house_room')
            ->where('linked_room_id', $room->id)
            ->whereDoesntHave('residentialRoom')
            ->lockForUpdate()
            ->get();
        $canonical = $legacy->count() === 1 ? $legacy->first() : null;

        if ($canonical === null) {
            $canonical = $this->availableCanonicalNameMatch($site, $room->name);
        }
        if ($canonical === null) {
            $this->assertCanonicalNameAvailable($site, $room->name);
            $canonical = SiteRoom::query()->create([
                'site_id' => $site->id,
                'name' => $room->name,
                'sort_order' => $room->sort_order,
            ]);
        }

        SiteRoom::query()
            ->where('linked_room_type', 'house_room')
            ->where('linked_room_id', $room->id)
            ->whereKeyNot($canonical->id)
            ->update(['linked_room_type' => null, 'linked_room_id' => null]);
        $canonical->update(['linked_room_type' => 'house_room', 'linked_room_id' => $room->id]);
        $room->update(['site_room_id' => $canonical->id]);

        return $canonical;
    }

    private function availableCanonicalNameMatch(Site $site, string $name): ?SiteRoom
    {
        $matches = SiteRoom::query()
            ->where('site_id', $site->id)
            ->whereNull('linked_room_type')
            ->whereNull('linked_room_id')
            ->whereDoesntHave('residentialRoom')
            ->lockForUpdate()
            ->get()
            ->filter(fn (SiteRoom $room): bool => $this->normaliseKey($room->name) === $this->normaliseKey($name));

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'name' => 'More than one canonical room matches this residential room. Resolve the room records before linking them.',
            ]);
        }

        return $matches->first();
    }

    private function assertResidentialNameAvailable(Site $site, string $name, ?int $ignoreId = null): void
    {
        $exists = SiteHouseRoom::query()
            ->where('site_id', $site->id)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['id', 'name'])
            ->contains(fn (SiteHouseRoom $room): bool => $this->normaliseKey($room->name) === $this->normaliseKey($name));
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A room with this name already exists at the Site.']);
        }
    }

    private function assertCanonicalNameAvailable(Site $site, string $name, ?int $ignoreId = null): void
    {
        $exists = SiteRoom::query()
            ->where('site_id', $site->id)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['id', 'name'])
            ->contains(fn (SiteRoom $room): bool => $this->normaliseKey($room->name) === $this->normaliseKey($name));
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A physical room with this name already exists at the Site.']);
        }
    }

    private function lockSite(Site $site): Site
    {
        return Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
    }

    private function lockResidentialRoom(Site $site, SiteHouseRoom $room): SiteHouseRoom
    {
        return SiteHouseRoom::query()
            ->whereKey($room->id)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCanonicalRoom(Site $site, int $roomId): SiteRoom
    {
        return SiteRoom::query()
            ->whereKey($roomId)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function nextSortOrder(Site $site): int
    {
        return ((int) SiteRoom::query()->where('site_id', $site->id)->max('sort_order')) + 1;
    }

    private function normaliseDisplayName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    private function assertNamePresent(string $name): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A room name is required.']);
        }
    }

    private function normaliseKey(string $name): string
    {
        return Str::lower($this->normaliseDisplayName($name));
    }
}
