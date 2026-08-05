<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccessControlWorkspacePresenter
{
    private const LIST_LIMIT = 100;

    private const HISTORY_LIMIT = 50;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function present(User $viewer, Builder $deviceScope): array
    {
        $canView = $viewer->canDo('securityDevices.accessControl.view');
        $canManage = $viewer->canDo('securityDevices.accessControl.manage');
        if (! $canView) {
            return $this->restricted($canManage);
        }

        $siteIds = $this->access->accessibleSiteIds($viewer);
        $deviceOptions = $this->deviceOptions($deviceScope, $siteIds);
        $visibleDeviceIds = $deviceOptions->pluck('id')->all();
        $schedules = AccessControlSchedule::query()
            ->whereIn('site_id', $siteIds)
            ->with('site:id,name')
            ->withCount(['credentials as active_credentials_count' => fn (Builder $query) => $query->where('status', 'active')])
            ->orderBy('site_id')
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get();
        $credentials = AccessControlCredential::query()
            ->whereIn('site_id', $siteIds)
            ->with([
                'site:id,name',
                'schedule:id,name',
                'devices' => fn ($query) => $query
                    ->whereKey($visibleDeviceIds)
                    ->select(['devices.id', 'devices.name']),
            ])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $holderLabels = $this->holderLabels($viewer, $credentials);
        $activeCredentialCount = AccessControlCredential::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->count();
        $activeScheduleCount = AccessControlSchedule::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->count();
        $coveredDoorCount = Device::query()
            ->whereKey($visibleDeviceIds)
            ->whereHas('accessControlCredentials', fn (Builder $query) => $query
                ->whereIn('access_control_credentials.site_id', $siteIds)
                ->where('access_control_credentials.status', 'active'))
            ->count();

        return [
            'restricted' => false,
            'canManage' => $canManage,
            'summary' => [
                'activeCredentials' => $activeCredentialCount,
                'activeSchedules' => $activeScheduleCount,
                'coveredDoors' => $coveredDoorCount,
            ],
            'sites' => Site::query()->whereKey($siteIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => $site->name])->values(),
            'deviceOptions' => $deviceOptions,
            'holderOptions' => $this->holderOptions($viewer),
            'schedules' => $schedules->map(fn (AccessControlSchedule $schedule): array => [
                'id' => (int) $schedule->id,
                'siteId' => (int) $schedule->site_id,
                'siteName' => $schedule->site?->name ?? 'Unknown Site',
                'name' => $schedule->name,
                'days' => $schedule->days ?? [],
                'startsAt' => $schedule->starts_at,
                'endsAt' => $schedule->ends_at,
                'timezone' => $schedule->timezone,
                'isActive' => (bool) $schedule->is_active,
                'activeCredentials' => (int) $schedule->active_credentials_count,
            ])->values(),
            'credentials' => $credentials->map(fn (AccessControlCredential $credential): array => [
                'id' => (int) $credential->id,
                'siteId' => (int) $credential->site_id,
                'siteName' => $credential->site?->name ?? 'Unknown Site',
                'label' => $credential->label,
                'holderType' => $credential->holder_type,
                'holderLabel' => $holderLabels->get($credential->holder_type.':'.$credential->holder_id, 'Restricted holder'),
                'referenceKey' => $credential->reference_key,
                'status' => $credential->status,
                'scheduleName' => $credential->schedule?->name ?? 'Schedule unavailable',
                'devices' => $credential->devices->map(fn (Device $device): array => [
                    'id' => (int) $device->id,
                    'name' => $device->name,
                    'href' => "/security-devices/devices/{$device->id}",
                ])->values(),
                'validFrom' => $credential->valid_from?->toIso8601String(),
                'validUntil' => $credential->valid_until?->toIso8601String(),
                'revokedAt' => $credential->revoked_at?->toIso8601String(),
                'revocationReason' => $credential->revocation_reason,
            ])->values(),
            'history' => $this->history($credentials, $schedules),
        ];
    }

    private function restricted(bool $canManage): array
    {
        return [
            'restricted' => true,
            'canManage' => $canManage,
            'summary' => ['activeCredentials' => 0, 'activeSchedules' => 0, 'coveredDoors' => 0],
            'sites' => [],
            'deviceOptions' => [],
            'holderOptions' => [],
            'schedules' => [],
            'credentials' => [],
            'history' => [],
        ];
    }

    /** @param list<int> $siteIds */
    private function deviceOptions(Builder $deviceScope, array $siteIds): Collection
    {
        return (clone $deviceScope)
            ->where('category', 'access_control')
            ->with(['assignments' => fn ($query) => $query->active()->where('assigned_at', '<=', now())])
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (Device $device) use ($siteIds): ?array {
                $siteId = $this->siteIdForDevice($device);
                if ($siteId === null || ! in_array($siteId, $siteIds, true)) {
                    return null;
                }

                return [
                    'id' => (int) $device->id,
                    'siteId' => $siteId,
                    'name' => $device->name,
                ];
            })
            ->filter()
            ->values();
    }

    private function siteIdForDevice(Device $device): ?int
    {
        $assignment = $device->assignments->first();
        if (! $assignment instanceof DeviceAssignment) {
            return null;
        }
        if ($assignment->assignable_type === DeviceAssignment::TARGET_SITE) {
            return (int) $assignment->assignable_id;
        }
        if ($assignment->assignable_type === DeviceAssignment::TARGET_ROOM) {
            $siteId = SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id');

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        return null;
    }

    private function holderOptions(User $viewer): Collection
    {
        $staff = $this->access->assignableStaff($viewer)
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'type' => AccessControlCredential::HOLDER_STAFF,
                'label' => $user->name,
                'siteIds' => collect([
                    $user->hrEmployeeProfile?->primary_site_id,
                    ...($user->hrEmployeeProfile?->secondary_site_ids ?? []),
                ])->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            ]);
        $clients = $this->access->assignableClients($viewer)
            ->take(self::LIST_LIMIT)
            ->map(fn (Client $client): array => [
                'id' => (int) $client->id,
                'type' => AccessControlCredential::HOLDER_CLIENT,
                'label' => $client->full_name,
                'siteIds' => [(int) $client->site_id],
            ]);

        return $staff->concat($clients)->values();
    }

    private function holderLabels(User $viewer, Collection $credentials): Collection
    {
        $staffIds = $credentials->where('holder_type', AccessControlCredential::HOLDER_STAFF)->pluck('holder_id');
        $clientIds = $credentials->where('holder_type', AccessControlCredential::HOLDER_CLIENT)->pluck('holder_id');
        $staff = $staffIds->isEmpty()
            ? collect()
            : $this->access->assignableStaff($viewer)->whereKey($staffIds)->pluck('name', 'id')
                ->mapWithKeys(fn (string $name, mixed $id): array => ['staff:'.$id => $name]);
        $clients = $clientIds->isEmpty()
            ? collect()
            : Client::query()->whereKey($this->access->authorizedClientIds($viewer))->whereKey($clientIds)->get()
                ->mapWithKeys(fn (Client $client): array => ['client:'.$client->id => $client->full_name]);

        return $staff->merge($clients);
    }

    private function history(Collection $credentials, Collection $schedules): Collection
    {
        if ($credentials->isEmpty() && $schedules->isEmpty()) {
            return collect();
        }

        return AuditLog::query()
            ->where(function (Builder $query) use ($credentials, $schedules): void {
                if ($credentials->isNotEmpty()) {
                    $query->where(fn (Builder $branch) => $branch
                        ->where('auditable_type', AccessControlCredential::class)
                        ->whereIn('auditable_id', $credentials->modelKeys()));
                }
                if ($schedules->isNotEmpty()) {
                    $method = $credentials->isNotEmpty() ? 'orWhere' : 'where';
                    $query->{$method}(fn (Builder $branch) => $branch
                        ->where('auditable_type', AccessControlSchedule::class)
                        ->whereIn('auditable_id', $schedules->modelKeys()));
                }
            })
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'id' => (int) $entry->id,
                'action' => match ($entry->action) {
                    'access_control.schedule.created' => 'Schedule created',
                    'access_control.credential.issued' => 'Credential issued',
                    'access_control.credential.revoked' => 'Credential revoked',
                    default => str_replace(['access_control.', '_', '.'], ['', ' ', ' '], $entry->action),
                },
                'actor' => $entry->user?->name ?? 'System',
                'occurredAt' => $entry->created_at?->toIso8601String(),
            ]);
    }
}
