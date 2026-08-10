<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use UnexpectedValueException;

/** Canonical picker data shared by governed IT workspaces. */
final class ItLinkedContextOptions
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly UserSiteAccessService $siteAccess,
        private readonly CanonicalDeviceSiteResolver $deviceSites,
    ) {}

    /** @return array<int, array{id: int, name: string}> */
    public function agents(User $viewer, ?ItTicket $ticket = null): array
    {
        return ($ticket
            ? ItStaffDirectory::agentsForTicket($ticket)
            : ItStaffDirectory::agentsForSharedSites($viewer))
            ->sortBy('name')
            ->map(fn (User $agent): array => ['id' => $agent->id, 'name' => $agent->name])
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    public function services(): array
    {
        return ItService::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (ItService $service): array => ['id' => $service->id, 'name' => $service->name])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    public function teams(): array
    {
        return ItTeam::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (ItTeam $team): array => ['id' => $team->id, 'name' => $team->name])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    public function sites(User $viewer): array
    {
        return Site::query()
            ->whereIn('id', $this->workAccess->approvedSiteIds($viewer))
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name])
            ->all();
    }

    /** @return array<int, array{id: int, name: string, uid: string, site_id: int|null}> */
    public function devices(User $viewer, ?ItTicket $ticket = null): array
    {
        if (! $viewer->canDo('securityDevices.devices.view')) {
            return [];
        }

        return $this->deviceAccess->visibleDevices($viewer)
            ->with(['assignments' => fn ($query) => $query
                ->active()
                ->where('assigned_at', '<=', now())
                ->orderBy('id'), 'activeAssetLinks' => fn ($query) => $query->orderBy('id')])
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(function (Device $device): array {
                try {
                    $siteId = $this->deviceSites->resolveLoadedForContext($device);
                } catch (UnexpectedValueException) {
                    $siteId = null;
                }

                return [
                    'id' => $device->id,
                    'name' => $device->name,
                    'uid' => $device->device_uid,
                    'site_id' => $siteId,
                ];
            })
            ->when(
                $ticket?->site_id !== null && ! $ticket->is_organisation_wide,
                fn ($devices) => $devices->where('site_id', (int) $ticket->site_id),
            )
            ->take(100)
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    public function alerts(User $viewer): array
    {
        if (! $viewer->canDo('controlRoom.alerts.view')) {
            return [];
        }

        $query = ControlRoomAlert::query()->latest('id')->limit(100);
        $this->siteAccess->applyAlertScope($query, $viewer);

        return $query->get()
            ->map(fn (ControlRoomAlert $alert): array => [
                'id' => $alert->id,
                'name' => ($alert->reference_number ?: 'Alert '.$alert->id).' · '.$alert->alert_type,
            ])
            ->all();
    }

    /** @param list<string> $workTypes @return array<int, array<string, mixed>> */
    public function tickets(User $viewer, array $workTypes, ?int $exceptId = null): array
    {
        return $this->workAccess->applyViewScope(ItTicket::query(), $viewer)
            ->whereIn('work_type', $workTypes)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (ItTicket $ticket): array => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'workflow_state' => $ticket->workflow_state,
                'href' => "/it/tickets/{$ticket->id}",
            ])
            ->all();
    }
}
