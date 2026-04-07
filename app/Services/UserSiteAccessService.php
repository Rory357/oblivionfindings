<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetShiftHandover;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserSiteAccessService
{
    public const DEFAULT_MESSAGE = 'You are not authorized to access records for this site.';

    /**
     * @param  array<int, string>  $bypassPermissions
     * @return array<int, int>
     */
    public function accessibleSiteIds(?User $user, array $bypassPermissions = []): array
    {
        if (! $user || $this->canBypass($user, $bypassPermissions)) {
            return [];
        }

        $user->loadMissing('hrEmployeeProfile');

        $profile = $user->hrEmployeeProfile;
        $secondarySiteIds = is_array($profile?->secondary_site_ids)
            ? $profile->secondary_site_ids
            : [];

        return collect([
            $profile?->primary_site_id,
            $user->getAttribute('site_id'),
            ...$secondarySiteIds,
        ])
            ->filter(fn ($siteId) => filled($siteId))
            ->map(fn ($siteId) => (int) $siteId)
            ->filter(fn (int $siteId) => $siteId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function canBypass(?User $user, array $bypassPermissions = []): bool
    {
        if (! $user) {
            return false;
        }

        foreach ($bypassPermissions as $permission) {
            if ($user->canDo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessSiteId(
        ?User $user,
        ?int $siteId,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        if ($this->canBypass($user, $bypassPermissions)) {
            return;
        }

        $allowedSiteIds = $this->accessibleSiteIds($user, $bypassPermissions);

        if (! $siteId || ! in_array((int) $siteId, $allowedSiteIds, true)) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessClientId(
        ?User $user,
        ?int $clientId,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        if (! $clientId) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        $siteId = Client::query()->whereKey($clientId)->value('site_id');

        $this->assertCanAccessSiteId(
            $user,
            $siteId ? (int) $siteId : null,
            $bypassPermissions,
            $message,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessShift(
        ?User $user,
        Shift $shift,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        $this->assertCanAccessSiteId(
            $user,
            $this->shiftSiteId($shift),
            $bypassPermissions,
            $message,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessTimesheet(
        ?User $user,
        Timesheet $timesheet,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        $this->assertCanAccessSiteId(
            $user,
            $this->timesheetSiteId($timesheet),
            $bypassPermissions,
            $message,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessHandover(
        ?User $user,
        ShiftHandover $handover,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        if ($this->canBypass($user, $bypassPermissions)) {
            return;
        }

        $allowedSiteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        $handoverSiteIds = $this->handoverSiteIds($handover);

        if ($handoverSiteIds === [] || collect($handoverSiteIds)->intersect($allowedSiteIds)->isEmpty()) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessAlert(
        ?User $user,
        ControlRoomAlert $alert,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        if ($this->canBypass($user, $bypassPermissions)) {
            return;
        }

        $siteId = $alert->site_id
            ?: $alert->client?->site_id
            ?: data_get($alert->context, 'shift_context.site.id')
            ?: data_get($alert->context, 'site.id');

        $this->assertCanAccessSiteId(
            $user,
            $siteId ? (int) $siteId : null,
            $bypassPermissions,
            $message,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyShiftScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyShiftScopeForSiteIds($query, $siteIds);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyTimesheetScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn('shift_site_id', $siteIds)
                ->orWhereHas('shift', fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds))
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHandoverScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereHas('outgoingShift', fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds))
                ->orWhereHas('incomingShift', fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds))
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyClientScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('site_id', $siteIds);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applySiteScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $siteIds);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyStaffScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteIds) {
            $profileQuery->where(function (Builder $siteQuery) use ($siteIds) {
                $siteQuery->whereIn('primary_site_id', $siteIds);

                foreach ($siteIds as $siteId) {
                    $siteQuery->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyAlertScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn('site_id', $siteIds)
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
        });
    }

    public function shiftSiteId(Shift $shift): ?int
    {
        $shift->loadMissing('client:id,site_id');

        $siteId = $shift->site_id ?: $shift->client?->site_id;

        return $siteId ? (int) $siteId : null;
    }

    public function timesheetSiteId(Timesheet $timesheet): ?int
    {
        $timesheet->loadMissing(['shift.client:id,site_id', 'client:id,site_id']);

        $siteId = $timesheet->shift_site_id
            ?: $timesheet->shift?->site_id
            ?: $timesheet->shift?->client?->site_id
            ?: $timesheet->client?->site_id;

        return $siteId ? (int) $siteId : null;
    }

    /**
     * @return array<int, int>
     */
    public function handoverSiteIds(ShiftHandover $handover): array
    {
        $handover->loadMissing([
            'outgoingShift.client:id,site_id',
            'incomingShift.client:id,site_id',
            'client:id,site_id',
        ]);

        return collect([
            $handover->outgoingShift?->site_id,
            $handover->outgoingShift?->client?->site_id,
            $handover->incomingShift?->site_id,
            $handover->incomingShift?->client?->site_id,
            $handover->client?->site_id,
        ])
            ->filter(fn ($siteId) => filled($siteId))
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyFleetHandoverScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('asset', fn (Builder $assetQuery) => $assetQuery->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn('site_id', $siteIds)
                ->orWhereIn('home_site_id', $siteIds);
        }));
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessFleetHandover(
        ?User $user,
        FleetShiftHandover $handover,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        if ($this->canBypass($user, $bypassPermissions)) {
            return;
        }

        $handover->loadMissing('asset:id,site_id,home_site_id');

        $siteIds = collect([
            $handover->asset?->site_id,
            $handover->asset?->home_site_id,
        ])
            ->filter(fn ($siteId) => filled($siteId))
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values()
            ->all();

        if ($siteIds === []) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        $allowedSiteIds = $this->accessibleSiteIds($user, $bypassPermissions);

        if (collect($siteIds)->intersect($allowedSiteIds)->isEmpty()) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyShiftScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn('site_id', $siteIds)
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
        });
    }
}
