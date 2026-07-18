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
            $organizationId = $site->tenant_id ?? $actor->organization_id;
            $client = Client::query()
                ->whereKey($placement['client_id'])
                ->whereNull('site_id')
                ->when(
                    $organizationId !== null,
                    fn ($query) => $query->where('organization_id', $organizationId),
                )
                ->lockForUpdate()
                ->first();

            if (! $client) {
                throw ValidationException::withMessages([
                    'client_id' => 'This client is no longer available for placement.',
                ]);
            }

            $room = $this->resolveRoom(
                $site,
                $client,
                $placement['room_id'] ?? null,
                $organizationId,
            );
            $serviceContextId = $this->resolveServiceContextId(
                $site,
                $placement['service_context_id'] ?? null,
                $organizationId,
            );
            $keyWorkerId = $this->resolveKeyWorkerId(
                $placement['key_worker_id'] ?? null,
                $organizationId,
            );

            $this->clearRoomAssignments($client, $actor);

            $client->forceFill([
                'site_id' => $site->id,
                'room_id' => $room?->id,
                'service_context_id' => $serviceContextId,
                'key_worker_id' => $keyWorkerId,
            ])->save();

            if ($room) {
                $today = now()->toDateString();
                $room->forceFill([
                    'assigned_client_id' => $client->id,
                    'assigned_from' => $today,
                    'assigned_until' => null,
                ])->save();
                $room->history()->create([
                    'tenant_id' => $site->tenant_id,
                    'client_id' => $client->id,
                    'assigned_from' => $today,
                    'assigned_by_user_id' => $actor->id,
                ]);
            }

            return $client->refresh();
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
        Client $client,
        mixed $roomId,
        ?int $organizationId,
    ): ?SiteHouseRoom {
        if (! $roomId) {
            return null;
        }

        $room = SiteHouseRoom::query()
            ->whereKey((int) $roomId)
            ->where('site_id', $site->id)
            ->when(
                $organizationId !== null,
                fn ($query) => $query->where('tenant_id', $organizationId),
            )
            ->where('is_active', true)
            ->where('is_assignable', true)
            ->where(fn ($query) => $query
                ->whereNull('assigned_client_id')
                ->orWhere('assigned_client_id', $client->id))
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
        ?int $organizationId,
    ): ?int {
        if (! $serviceContextId) {
            return null;
        }

        $id = ServiceContext::query()
            ->forOrganization($organizationId)
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

    private function resolveKeyWorkerId(mixed $keyWorkerId, ?int $organizationId): ?int
    {
        if (! $keyWorkerId) {
            return null;
        }

        $id = app(ClientWorkerEligibility::class)
            ->queryForOrganization($organizationId)
            ->whereKey((int) $keyWorkerId)
            ->value('id');

        if (! $id) {
            throw ValidationException::withMessages([
                'key_worker_id' => 'This key worker is no longer eligible.',
            ]);
        }

        return (int) $id;
    }

    private function clearRoomAssignments(Client $client, User $actor): void
    {
        $rooms = SiteHouseRoom::query()
            ->where('assigned_client_id', $client->id)
            ->lockForUpdate()
            ->get();
        $today = now()->toDateString();

        foreach ($rooms as $room) {
            $room->history()
                ->where('client_id', $client->id)
                ->whereNull('assigned_until')
                ->latest('id')
                ->first()
                ?->update([
                    'assigned_until' => $today,
                    'notes' => 'Placement changed by '.$actor->name.'.',
                ]);
            $room->forceFill([
                'assigned_client_id' => null,
                'assigned_from' => null,
                'assigned_until' => null,
            ])->save();
        }
    }
}
