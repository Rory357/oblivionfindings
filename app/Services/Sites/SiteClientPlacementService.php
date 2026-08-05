<?php

namespace App\Services\Sites;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\User;
use App\Services\Clients\ClientWorkerEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteClientPlacementService
{
    /**
     * @param  array{client_id: int, room_id?: int|null, service_context_id?: int|null, key_worker_id?: int|null}  $placement
     */
    public function place(Site $site, array $placement, User $actor): Client
    {
        return DB::transaction(function () use ($site, $placement, $actor) {
            $room = $this->resolveRoom(
                $site,
                (int) $placement['client_id'],
                $placement['room_id'] ?? null,
            );
            $client = Client::query()
                ->whereKey($placement['client_id'])
                ->whereNull('site_id')
                ->lockForUpdate()
                ->first();

            if (! $client) {
                throw ValidationException::withMessages([
                    'client_id' => 'This client is no longer available for placement.',
                ]);
            }

            $serviceContextId = $this->resolveServiceContextId(
                $site,
                $placement['service_context_id'] ?? null,
            );
            $keyWorkerId = $this->resolveKeyWorkerId(
                $site,
                $placement['key_worker_id'] ?? null,
            );

            $client->forceFill([
                'site_id' => $site->id,
                'room_id' => null,
                'service_context_id' => $serviceContextId,
                'key_worker_id' => $keyWorkerId,
            ])->save();

            if ($room) {
                $this->applyRoomAssignment($site, $room, $client, $actor);
            } else {
                $this->clearRoomAssignments($client, $actor);
            }

            return $client->refresh();
        });
    }

    /**
     * @param  array{assigned_from?: string|null, assigned_until?: string|null, notes?: string|null}  $assignment
     */
    public function assignRoom(
        Site $site,
        SiteHouseRoom $room,
        ?int $clientId,
        User $actor,
        array $assignment = [],
    ): SiteHouseRoom {
        return DB::transaction(function () use ($site, $room, $clientId, $actor, $assignment) {
            $lockedRoom = SiteHouseRoom::query()
                ->whereKey($room->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($clientId && (! $lockedRoom->is_active || ! $lockedRoom->is_assignable)) {
                throw ValidationException::withMessages([
                    'client_id' => 'This room is not available for client assignment.',
                ]);
            }

            $client = $clientId
                ? Client::query()
                    ->whereKey($clientId)
                    ->where('site_id', $site->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($clientId && ! $client) {
                throw ValidationException::withMessages([
                    'client_id' => 'Choose a client linked to this Site.',
                ]);
            }

            return $this->applyRoomAssignment($site, $lockedRoom, $client, $actor, $assignment);
        });
    }

    public function unlink(Site $site, Client $client, User $actor): Client
    {
        return DB::transaction(function () use ($site, $client, $actor) {
            $lockedClient = Client::query()
                ->whereKey($client->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->clearRoomAssignments($lockedClient, $actor);
            $lockedClient->forceFill([
                'site_id' => null,
                'room_id' => null,
            ])->save();

            return $lockedClient->refresh();
        });
    }

    private function resolveRoom(
        Site $site,
        int $clientId,
        mixed $roomId,
    ): ?SiteHouseRoom {
        if (! $roomId) {
            return null;
        }

        $room = SiteHouseRoom::query()
            ->whereKey((int) $roomId)
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where('is_assignable', true)
            ->where(fn ($query) => $query
                ->whereNull('assigned_client_id')
                ->orWhere('assigned_client_id', $clientId))
            ->lockForUpdate()
            ->first();

        if (! $room) {
            throw ValidationException::withMessages([
                'room_id' => 'This room is no longer available.',
            ]);
        }

        return $room;
    }

    private function resolveServiceContextId(
        Site $site,
        mixed $serviceContextId,
    ): ?int {
        if (! $serviceContextId) {
            return null;
        }

        $id = ServiceContext::query()
            ->availableToSite($site->id)
            ->whereKey((int) $serviceContextId)
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('site_id')
                ->orWhere('site_id', $site->id))
            ->value('id');

        if (! $id) {
            throw ValidationException::withMessages([
                'service_context_id' => 'This service context is no longer available.',
            ]);
        }

        return (int) $id;
    }

    /**
     * @param  array{assigned_from?: string|null, assigned_until?: string|null, notes?: string|null}  $assignment
     */
    private function applyRoomAssignment(
        Site $site,
        SiteHouseRoom $room,
        ?Client $client,
        User $actor,
        array $assignment = [],
    ): SiteHouseRoom {
        $previousClientId = $room->assigned_client_id;
        $today = now()->toDateString();

        if ($previousClientId && $previousClientId !== $client?->id) {
            $this->closeOpenHistory($room, (int) $previousClientId, $today, $actor);
            Client::query()
                ->whereKey($previousClientId)
                ->where('room_id', $room->id)
                ->lockForUpdate()
                ->update(['room_id' => null]);
        }

        if ($client) {
            $this->clearRoomAssignments($client, $actor, $room->id);
            $client->forceFill([
                'site_id' => $site->id,
                'room_id' => $room->id,
            ])->save();
        }

        $assignedFrom = $client
            ? ($assignment['assigned_from'] ?? $room->assigned_from?->toDateString() ?? $today)
            : null;
        $assignedUntil = $client ? ($assignment['assigned_until'] ?? null) : null;

        $room->forceFill([
            'assigned_client_id' => $client?->id,
            'assigned_from' => $assignedFrom,
            'assigned_until' => $assignedUntil,
        ])->save();

        if ($client && $client->id !== $previousClientId) {
            $room->history()->create([
                'client_id' => $client->id,
                'assigned_from' => $assignedFrom,
                'assigned_until' => $assignedUntil,
                'assigned_by_user_id' => $actor->id,
                'notes' => $assignment['notes'] ?? null,
            ]);
        }

        return $room->refresh();
    }

    private function resolveKeyWorkerId(Site $site, mixed $keyWorkerId): ?int
    {
        if (! $keyWorkerId) {
            return null;
        }

        $id = (int) $keyWorkerId;
        if (! app(ClientWorkerEligibility::class)->containsForSite($site->id, $id)) {
            throw ValidationException::withMessages([
                'key_worker_id' => 'This key worker is no longer eligible.',
            ]);
        }

        return (int) $id;
    }

    private function clearRoomAssignments(Client $client, User $actor, ?int $exceptRoomId = null): void
    {
        $rooms = SiteHouseRoom::query()
            ->where('assigned_client_id', $client->id)
            ->when($exceptRoomId, fn ($query, int $roomId) => $query->whereKeyNot($roomId))
            ->lockForUpdate()
            ->get();
        $today = now()->toDateString();

        foreach ($rooms as $room) {
            $this->closeOpenHistory($room, $client->id, $today, $actor);
            $room->forceFill([
                'assigned_client_id' => null,
                'assigned_from' => null,
                'assigned_until' => null,
            ])->save();
        }
    }

    private function closeOpenHistory(
        SiteHouseRoom $room,
        int $clientId,
        string $assignedUntil,
        User $actor,
    ): void {
        $room->history()
            ->where('client_id', $clientId)
            ->whereNull('assigned_until')
            ->latest('id')
            ->first()
            ?->update([
                'assigned_until' => $assignedUntil,
                'notes' => 'Placement changed by '.$actor->name.'.',
            ]);
    }
}
