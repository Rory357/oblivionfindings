<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetShiftHandover;
use App\Models\HsEvent;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserSiteAccessService
{
    public const DEFAULT_MESSAGE = 'You are not authorized to access records for this site.';

    /** @var array<string, bool> */
    private array $clientIncidentSiteColumnCache = [];

    /** @var array<string, int|null> */
    private array $alertSiteTenantCache = [];

    /** @var array<string, array<int, int>> */
    private array $accessibleSiteIdsCache = [];

    /**
     * @param  array<int, string>  $bypassPermissions
     * @return array<int, int>
     */
    public function accessibleSiteIds(?User $user, array $bypassPermissions = []): array
    {
        $cacheKey = implode('|', [
            $user ? (string) ($user->getKey() ?? spl_object_id($user)) : 'guest',
            (string) ($user?->organization_id ?? 'platform'),
            implode(',', $bypassPermissions),
        ]);
        if (array_key_exists($cacheKey, $this->accessibleSiteIdsCache)) {
            return $this->accessibleSiteIdsCache[$cacheKey];
        }

        if (! $user || $this->canSkipTenantScope($user, $bypassPermissions)) {
            return $this->accessibleSiteIdsCache[$cacheKey] = [];
        }

        $organizationId = $this->organizationId($user);
        if ($organizationId === null) {
            return $this->accessibleSiteIdsCache[$cacheKey] = [];
        }

        if ($this->canBypass($user, $bypassPermissions)) {
            return $this->accessibleSiteIdsCache[$cacheKey] = Site::query()
                ->where('tenant_id', $organizationId)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($siteId) => (int) $siteId)
                ->all();
        }

        $user->loadMissing('hrEmployeeProfile');

        $profile = $user->hrEmployeeProfile;
        $secondarySiteIds = is_array($profile?->secondary_site_ids)
            ? $profile->secondary_site_ids
            : [];

        $assignedSiteIds = collect([
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

        if ($assignedSiteIds === []) {
            return $this->accessibleSiteIdsCache[$cacheKey] = [];
        }

        $tenantSiteIds = Site::query()
            ->where('tenant_id', $organizationId)
            ->whereIn('id', $assignedSiteIds)
            ->pluck('id')
            ->map(fn ($siteId) => (int) $siteId)
            ->all();

        return $this->accessibleSiteIdsCache[$cacheKey] = array_values(array_filter(
            $assignedSiteIds,
            fn (int $siteId) => in_array($siteId, $tenantSiteIds, true),
        ));
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
     * The only installation-wide exception is an explicitly modelled platform
     * administrator: the admin RBAC role plus no tenant organization. Ordinary
     * tenant admins and users with report/H&S/fleet bypass permissions remain
     * bounded by users.organization_id.
     */
    public function isUnrestrictedPlatformUser(?User $user): bool
    {
        return $user !== null
            && $this->organizationId($user) === null
            && $user->hasRole('admin');
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
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
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

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            abort_unless(Client::query()->whereKey($clientId)->exists(), 403, $message ?? self::DEFAULT_MESSAGE);

            return;
        }

        $client = Client::query()->whereKey($clientId)->first(['id', 'organization_id', 'site_id']);
        if (! $client || ! $this->organizationsAgree($user, $client->organization_id)) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        $this->assertCanAccessSiteId(
            $user,
            $client->site_id ? (int) $client->site_id : null,
            $bypassPermissions,
            $message,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessClientIncident(
        ?User $user,
        ClientIncident $incident,
        array $bypassPermissions = [],
    ): void {
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $incident->loadMissing([
            'client:id,organization_id,site_id',
            'shift.client:id,organization_id,site_id',
        ]);

        if (! $incident->client
            || ! $this->organizationsAgree($user, $incident->client->organization_id)) {
            abort(403, self::DEFAULT_MESSAGE);
        }

        $siteId = $incident->getAttribute('site_id')
            ?: $incident->client?->site_id
            ?: $incident->shift?->site_id
            ?: $incident->shift?->client?->site_id;

        $this->assertCanAccessSiteId(
            $user,
            $siteId ? (int) $siteId : null,
            $bypassPermissions,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessHsEvent(
        ?User $user,
        HsEvent $event,
        array $bypassPermissions = [],
    ): void {
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $organizationId = $this->organizationId($user);
        $eventOrganizationId = $event->organization_id === null
            ? null
            : (int) $event->organization_id;
        $siteId = $event->site_id === null ? null : (int) $event->site_id;

        if ($organizationId === null
            || ($eventOrganizationId !== null && $eventOrganizationId !== $organizationId)
            || ($eventOrganizationId === null && $siteId === null)) {
            abort(403, self::DEFAULT_MESSAGE);
        }

        if ($siteId !== null) {
            $this->assertCanAccessSiteId($user, $siteId, $bypassPermissions);
        }
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
        // Callers frequently preload presentation-shaped relations that omit
        // tenant columns. Authorize against a separate canonical projection so
        // we neither trust nor overwrite the caller's richer relation data.
        $canonicalShift = Shift::query()->with([
            'site:id,tenant_id',
            'client:id,organization_id,site_id',
            'staff:id,organization_id',
        ])->find($shift->getKey());
        abort_unless($canonicalShift, 403, $message ?? self::DEFAULT_MESSAGE);
        $shift = $canonicalShift;
        $shiftOrganizationId = $this->nullablePositiveId($shift->organization_id);
        if ($shiftOrganizationId === null) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        if ($shift->site_id !== null
            && (! $shift->site
                || $this->nullablePositiveId($shift->site->tenant_id) !== $shiftOrganizationId)) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        if ($shift->client_id !== null) {
            if (! $shift->client
                || $this->nullablePositiveId($shift->client->organization_id) !== $shiftOrganizationId
                || $shift->client->site_id === null
                || ($shift->site_id !== null
                    && (int) $shift->site_id !== (int) $shift->client->site_id)) {
                abort(403, $message ?? self::DEFAULT_MESSAGE);
            }
        }

        if ($shift->user_id !== null
            && (! $shift->staff
                || $this->nullablePositiveId($shift->staff->organization_id) !== $shiftOrganizationId)) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $organizationId = $this->organizationId($user);
        if ($organizationId === null || $shiftOrganizationId !== $organizationId) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

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
        $siteId = $this->assertTimesheetIntrinsicIntegrity($timesheet, $message);

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $this->assertCanAccessSiteId(
            $user,
            $siteId,
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
        $siteId = $this->assertHandoverIntrinsicIntegrity($handover, $message);

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $this->assertCanAccessSiteId($user, $siteId, $bypassPermissions, $message);
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
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $alert->loadMissing('client:id,organization_id,site_id');
        if ($alert->client_id && ! $this->organizationsAgree($user, $alert->client?->organization_id)) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        $siteId = $alert->site_id
            ?: $alert->client?->site_id
            ?: data_get($alert->context, 'site_id')
            ?: data_get($alert->context, 'shift_context.site.id')
            ?: data_get($alert->context, 'shift.site_id')
            ?: data_get($alert->context, 'shift.site.id')
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
        $this->applyShiftIntrinsicIntegrity($query);

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $organizationId = $this->organizationId($user);
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($organizationId === null || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where($query->qualifyColumn('organization_id'), $organizationId);

        return $this->applyShiftScopeForSiteIds($query, $siteIds);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyTimesheetScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $this->applyTimesheetIntrinsicIntegrity($query);

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $shiftSiteColumn = $query->qualifyColumn('shift_site_id');
        $siteColumn = $query->qualifyColumn('site_id');

        return $query->where(function (Builder $nested) use ($siteIds, $shiftSiteColumn, $siteColumn) {
            $nested->whereIn($shiftSiteColumn, $siteIds)
                ->orWhere(function (Builder $legacyDirectSite) use ($siteIds, $shiftSiteColumn, $siteColumn) {
                    $legacyDirectSite->whereNull($shiftSiteColumn)
                        ->whereIn($siteColumn, $siteIds);
                })
                ->orWhere(function (Builder $shiftFallback) use ($siteIds, $shiftSiteColumn, $siteColumn) {
                    $shiftFallback->whereNull($shiftSiteColumn)
                        ->whereNull($siteColumn)
                        ->whereHas('shift', fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds));
                })
                ->orWhere(function (Builder $clientFallback) use ($siteIds, $shiftSiteColumn, $siteColumn) {
                    $clientFallback->whereNull($shiftSiteColumn)
                        ->whereNull($siteColumn)
                        ->whereNull('shift_id')
                        ->whereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
                });
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHandoverScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $this->applyHandoverIntrinsicIntegrity($query);

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
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
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('site_id', $siteIds);

        return $this->applyClientOrganizationScope($query, $user);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyClientIncidentScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $query->whereHas('client', fn (Builder $clientQuery) => $this->applyClientOrganizationScope($clientQuery, $user));

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        if (! $this->clientIncidentSiteColumnExists($query)) {
            return $this->applyClientIncidentRelationshipScopeForSiteIds($query, $siteIds);
        }

        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn($nested->qualifyColumn('site_id'), $siteIds)
                ->orWhere(function (Builder $legacy) use ($siteIds) {
                    $legacy->whereNull($legacy->qualifyColumn('site_id'));
                    $this->applyClientIncidentRelationshipScopeForSiteIds($legacy, $siteIds);
                });
        });
    }

    protected function clientIncidentSiteColumnExists(Builder $query): bool
    {
        $connection = $query->getConnection();
        $cacheKey = implode('|', [
            (string) spl_object_id($connection),
            (string) $connection->getName(),
            (string) $connection->getDatabaseName(),
            $query->getModel()->getTable(),
        ]);

        if (! array_key_exists($cacheKey, $this->clientIncidentSiteColumnCache)) {
            $this->clientIncidentSiteColumnCache[$cacheKey] = $this->schemaHasColumn($query, 'site_id');
        }

        return $this->clientIncidentSiteColumnCache[$cacheKey];
    }

    protected function schemaHasColumn(Builder $query, string $column): bool
    {
        return $query->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($query->getModel()->getTable(), $column);
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyClientIncidentRelationshipScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds))
                ->orWhereHas('shift', fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds));
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHsEventScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $organizationId = $this->organizationId($user);
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $tenantScope) use ($organizationId, $siteIds) {
            if ($siteIds !== []) {
                $tenantScope->where(function (Builder $siteScope) use ($organizationId, $siteIds) {
                    $siteScope
                        ->whereIn('site_id', $siteIds)
                        ->where(function (Builder $organizationScope) use ($organizationId) {
                            $organizationScope
                                ->whereNull('organization_id')
                                ->orWhere('organization_id', $organizationId);
                        });
                });
            }

            $organizationOnly = fn (Builder $organizationScope) => $organizationScope
                ->whereNull('site_id')
                ->where('organization_id', $organizationId);

            if ($siteIds === []) {
                $tenantScope->where($organizationOnly);
            } else {
                $tenantScope->orWhere($organizationOnly);
            }
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applySiteScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
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
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $organizationId = $this->organizationId($user);
        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('organization_id', $organizationId);

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
     * Scope approved staff to the same tenant and, when present, the specific
     * site carried by an H&S event. This is the canonical picker and mutation
     * boundary for H&S ownership, investigation and corrective-action work.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHsEventStaffScope(
        Builder $query,
        HsEvent $event,
        ?User $viewer,
        array $bypassPermissions = [],
    ): Builder {
        $query
            ->staff()
            ->whereNotNull($query->qualifyColumn('approved_at'));

        if ($event->site_id !== null) {
            $siteId = (int) $event->site_id;
            $query->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteId): void {
                $profileQuery->where(function (Builder $siteQuery) use ($siteId): void {
                    $siteQuery->where('primary_site_id', $siteId)
                        ->orWhereJsonContains('secondary_site_ids', $siteId);
                });
            });
        }

        return $this->applyStaffScope($query, $viewer, $bypassPermissions);
    }

    /**
     * Canonical eligibility for a Fleet handover recipient. Broad Fleet or
     * platform access never relaxes this record-level tenant/site invariant.
     */
    public function applyFleetRecipientEligibility(
        Builder $query,
        int $tenantId,
        int $siteId,
    ): Builder {
        return $query
            ->staff()
            ->where($query->qualifyColumn('organization_id'), $tenantId)
            ->whereNotNull($query->qualifyColumn('approved_at'))
            ->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($tenantId, $siteId) {
                $profileQuery
                    ->where($profileQuery->qualifyColumn('tenant_id'), $tenantId)
                    ->where($profileQuery->qualifyColumn('is_active'), true)
                    ->where(function (Builder $siteQuery) use ($siteId) {
                        $siteQuery->where($siteQuery->qualifyColumn('primary_site_id'), $siteId)
                            ->orWhereJsonContains($siteQuery->qualifyColumn('secondary_site_ids'), $siteId);
                    });
            });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyControlRoomAssigneeScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', [
            'admin',
            'provider_manager',
            'coordinator',
        ]));

        return $this->applyStaffScope($query, $user, $bypassPermissions);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAssignControlRoomAlertToUser(
        ?User $user,
        int $assigneeUserId,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        $query = User::query()
            ->staff()
            ->whereKey($assigneeUserId);

        $this->applyControlRoomAssigneeScope($query, $user, $bypassPermissions);

        abort_unless(
            $query->exists(),
            403,
            $message ?? 'You are not authorized to assign alerts to that staff member.',
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyAlertScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $organizationId = $this->organizationId($user);
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($organizationId === null || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        // A site match must never override a contradictory client tenant. This
        // mirrors assertCanAccessAlert() so list, aggregate, and bulk-action
        // queries cannot authorize a record that single-record actions reject.
        $this->applyAlertClientTenantIntegrity($query, $organizationId);

        return $this->applyAlertSitePrecedenceScope($query, $siteIds);
    }

    /**
     * Apply the canonical alert site precedence for a trusted explicit site
     * selection. All selected sites must exist and belong to one tenant.
     *
     * @param  array<int, mixed>  $siteIds
     */
    public function applyAlertSiteScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $siteIds = $this->normalizePositiveSiteIds($siteIds);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $organizationId = $this->tenantIdForAlertSiteIds($siteIds);
        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        $this->applyAlertClientTenantIntegrity($query, $organizationId);

        return $this->applyAlertSitePrecedenceScope($query, $siteIds);
    }

    /**
     * SQL expression for the same effective site precedence used by alert
     * authorization: direct alert site, direct client site, then context site.
     */
    public function alertEffectiveSiteExpression(Builder $query): string
    {
        $table = $query->getModel()->getTable();
        $alertSiteColumn = sprintf('`%s`.`site_id`', $table);
        $alertClientColumn = sprintf('`%s`.`client_id`', $table);
        $clientSiteExpression = sprintf(
            '(SELECT `cr_effective_client`.`site_id` FROM `clients` AS `cr_effective_client` WHERE `cr_effective_client`.`id` = %s AND `cr_effective_client`.`deleted_at` IS NULL LIMIT 1)',
            $alertClientColumn,
        );

        return sprintf(
            'COALESCE(%s, %s, %s)',
            $alertSiteColumn,
            $clientSiteExpression,
            $this->alertContextSiteExpression($query),
        );
    }

    private function applyAlertClientTenantIntegrity(Builder $query, int $organizationId): void
    {
        $alertClientColumn = $query->qualifyColumn('client_id');
        $query->where(function (Builder $clientIntegrity) use ($alertClientColumn, $organizationId) {
            $clientIntegrity->whereNull($alertClientColumn)
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                    ->where($clientQuery->qualifyColumn('organization_id'), $organizationId));
        });
    }

    /** @param array<int, int> $siteIds */
    private function applyAlertSitePrecedenceScope(Builder $query, array $siteIds): Builder
    {
        $alertSiteColumn = $query->qualifyColumn('site_id');
        $contextSiteExpression = $this->alertContextSiteExpression($query);
        $sitePlaceholders = implode(', ', array_fill(0, count($siteIds), '?'));

        return $query->where(function (Builder $nested) use (
            $alertSiteColumn,
            $contextSiteExpression,
            $siteIds,
            $sitePlaceholders,
        ) {
            $nested->whereIn($alertSiteColumn, $siteIds)
                ->orWhere(function (Builder $alertSiteFallback) use (
                    $alertSiteColumn,
                    $contextSiteExpression,
                    $siteIds,
                    $sitePlaceholders,
                ) {
                    $alertSiteFallback
                        ->whereNull($alertSiteColumn)
                        ->where(function (Builder $clientOrContext) use (
                            $contextSiteExpression,
                            $siteIds,
                            $sitePlaceholders,
                        ) {
                            $clientOrContext
                                ->whereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn(
                                    $clientQuery->qualifyColumn('site_id'),
                                    $siteIds,
                                ))
                                ->orWhere(function (Builder $contextFallback) use (
                                    $contextSiteExpression,
                                    $siteIds,
                                    $sitePlaceholders,
                                ) {
                                    $contextFallback
                                        ->whereDoesntHave('client', fn (Builder $clientQuery) => $clientQuery->whereNotNull(
                                            $clientQuery->qualifyColumn('site_id'),
                                        ))
                                        ->whereRaw(
                                            sprintf('%s IN (%s)', $contextSiteExpression, $sitePlaceholders),
                                            $siteIds,
                                        );
                                });
                        });
                });
        });
    }

    /** @param array<int, mixed> $siteIds
     * @return array<int, int>
     */
    private function normalizePositiveSiteIds(array $siteIds): array
    {
        $normalized = [];
        foreach ($siteIds as $siteId) {
            $validated = filter_var($siteId, FILTER_VALIDATE_INT);
            if ($validated === false || (int) $validated <= 0) {
                return [];
            }

            $normalized[] = (int) $validated;
        }

        return array_values(array_unique($normalized));
    }

    /** @param array<int, int> $siteIds */
    private function tenantIdForAlertSiteIds(array $siteIds): ?int
    {
        $cacheKey = implode(',', $siteIds);
        if (array_key_exists($cacheKey, $this->alertSiteTenantCache)) {
            return $this->alertSiteTenantCache[$cacheKey];
        }

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->get(['id', 'tenant_id']);
        $tenantIds = $sites
            ->pluck('tenant_id')
            ->filter(fn ($tenantId) => is_numeric($tenantId) && (int) $tenantId > 0)
            ->map(fn ($tenantId) => (int) $tenantId)
            ->unique()
            ->values();

        $tenantId = $sites->count() === count($siteIds) && $tenantIds->count() === 1
            ? (int) $tenantIds->first()
            : null;
        $this->alertSiteTenantCache[$cacheKey] = $tenantId;

        return $tenantId;
    }

    public function shiftSiteId(Shift $shift): ?int
    {
        $shift->loadMissing('client:id,site_id');

        $siteId = $shift->site_id ?: $shift->client?->site_id;

        return $siteId ? (int) $siteId : null;
    }

    public function timesheetSiteId(Timesheet $timesheet): ?int
    {
        return $this->assertTimesheetIntrinsicIntegrity($timesheet, null);
    }

    /**
     * @return array<int, int>
     */
    public function handoverSiteIds(ShiftHandover $handover): array
    {
        return [$this->assertHandoverIntrinsicIntegrity($handover, null)];
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyFleetHandoverScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $this->applyFleetHandoverIntrinsicIntegrity($query);

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('asset', function (Builder $assetQuery) use ($siteIds) {
            $siteColumn = $assetQuery->qualifyColumn('site_id');
            $homeSiteColumn = $assetQuery->qualifyColumn('home_site_id');

            $assetQuery->where(function (Builder $provenance) use (
                $siteIds,
                $siteColumn,
                $homeSiteColumn,
            ) {
                $provenance->whereIn($siteColumn, $siteIds)
                    ->orWhere(function (Builder $homeSiteFallback) use (
                        $siteIds,
                        $siteColumn,
                        $homeSiteColumn,
                    ) {
                        $homeSiteFallback
                            ->whereNull($siteColumn)
                            ->whereIn($homeSiteColumn, $siteIds);
                    });
            });
        });
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
        $handover->loadMissing([
            'asset:id,site_id,home_site_id',
            'outgoingUser:id,organization_id',
            'incomingUser:id,organization_id,approved_at,role',
        ]);

        $siteId = $handover->asset?->site_id
            ?: $handover->asset?->home_site_id;
        if (! $siteId) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        $siteId = (int) $siteId;
        $siteTenantId = Site::query()->whereKey($siteId)->value('tenant_id');
        $tenantId = $this->nullablePositiveId($handover->tenant_id);
        $incomingIsEligible = $handover->incoming_user_id !== null
            && $tenantId !== null
            && $siteTenantId !== null
            && User::query()
                ->whereKey($handover->incoming_user_id)
                ->tap(fn (Builder $incomingQuery) => $this->applyFleetRecipientEligibility(
                    $incomingQuery,
                    $tenantId,
                    $siteId,
                ))
                ->exists();
        abort_unless(
            $tenantId !== null
                && (int) $siteTenantId === $tenantId
                && $handover->outgoingUser
                && $this->nullablePositiveId($handover->outgoingUser->organization_id) === $tenantId
                && $incomingIsEligible,
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

        if ($this->canSkipTenantScope($user, $bypassPermissions)) {
            return;
        }

        $this->assertCanAccessSiteId(
            $user,
            $siteId,
            $bypassPermissions,
            $message,
        );
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyShiftScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $siteColumn = $query->qualifyColumn('site_id');

        return $query->where(function (Builder $nested) use ($siteIds, $siteColumn) {
            $nested->whereIn($siteColumn, $siteIds)
                ->orWhere(function (Builder $clientFallback) use ($siteIds, $siteColumn) {
                    $clientFallback
                        ->whereNull($siteColumn)
                        ->whereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn(
                            $clientQuery->qualifyColumn('site_id'),
                            $siteIds,
                        ));
                });
        });
    }

    private function applyShiftIntrinsicIntegrity(Builder $query): Builder
    {
        $organizationColumn = $query->qualifyColumn('organization_id');
        $siteColumn = $query->qualifyColumn('site_id');
        $clientColumn = $query->qualifyColumn('client_id');
        $userColumn = $query->qualifyColumn('user_id');

        return $query
            ->whereNotNull($organizationColumn)
            ->where(function (Builder $siteIntegrity) use ($siteColumn, $organizationColumn) {
                $siteIntegrity->whereNull($siteColumn)
                    ->orWhereHas('site', fn (Builder $siteQuery) => $siteQuery
                        ->whereColumn($siteQuery->qualifyColumn('tenant_id'), $organizationColumn));
            })
            ->where(function (Builder $clientIntegrity) use (
                $clientColumn,
                $organizationColumn,
                $siteColumn,
            ) {
                $clientIntegrity->whereNull($clientColumn)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereColumn($clientQuery->qualifyColumn('organization_id'), $organizationColumn)
                        ->whereNotNull($clientQuery->qualifyColumn('site_id'))
                        ->where(function (Builder $siteAgreement) use ($siteColumn) {
                            $siteAgreement->whereNull($siteColumn)
                                ->orWhereColumn('clients.site_id', $siteColumn);
                        }));
            })
            ->where(function (Builder $workerIntegrity) use ($userColumn, $organizationColumn) {
                $workerIntegrity->whereNull($userColumn)
                    ->orWhereHas('staff', fn (Builder $staffQuery) => $staffQuery
                        ->whereColumn($staffQuery->qualifyColumn('organization_id'), $organizationColumn));
            });
    }

    private function applyTimesheetIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $workerOrganization = "(SELECT `organization_id` FROM `users` WHERE `users`.`id` = {$row}.`user_id` LIMIT 1)";
        $directSite = "COALESCE({$row}.`shift_site_id`, {$row}.`site_id`)";
        $shiftSite = "(SELECT COALESCE(`ts_shift`.`site_id`, `ts_shift_client`.`site_id`) FROM `shifts` AS `ts_shift` LEFT JOIN `clients` AS `ts_shift_client` ON `ts_shift_client`.`id` = `ts_shift`.`client_id` WHERE `ts_shift`.`id` = {$row}.`shift_id` LIMIT 1)";
        $clientSite = "(SELECT `site_id` FROM `clients` WHERE `clients`.`id` = {$row}.`client_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$directSite}, {$shiftSite}, {$clientSite})";

        return $query
            ->whereRaw("{$workerOrganization} IS NOT NULL")
            ->whereRaw("({$row}.`shift_site_id` IS NULL OR {$row}.`site_id` IS NULL OR {$row}.`shift_site_id` = {$row}.`site_id`)")
            ->whereRaw("{$authoritativeSite} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `ts_site` WHERE `ts_site`.`id` = {$authoritativeSite} AND `ts_site`.`tenant_id` = {$workerOrganization})")
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `ts_client` WHERE `ts_client`.`id` = {$row}.`client_id` AND `ts_client`.`organization_id` = {$workerOrganization} AND `ts_client`.`site_id` = {$authoritativeSite}))")
            ->whereRaw("({$row}.`shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `ts_linked_shift` LEFT JOIN `clients` AS `ts_linked_client` ON `ts_linked_client`.`id` = `ts_linked_shift`.`client_id` WHERE `ts_linked_shift`.`id` = {$row}.`shift_id` AND `ts_linked_shift`.`organization_id` = {$workerOrganization} AND `ts_linked_shift`.`user_id` = {$row}.`user_id` AND (`ts_linked_shift`.`client_id` <=> {$row}.`client_id`) AND (`ts_linked_shift`.`client_id` IS NULL OR (`ts_linked_client`.`organization_id` = `ts_linked_shift`.`organization_id` AND `ts_linked_client`.`site_id` IS NOT NULL AND (`ts_linked_shift`.`site_id` IS NULL OR `ts_linked_shift`.`site_id` = `ts_linked_client`.`site_id`))) AND COALESCE(`ts_linked_shift`.`site_id`, `ts_linked_client`.`site_id`) = {$authoritativeSite}))");
    }

    private function applyHandoverIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $clientSite = "(SELECT `site_id` FROM `clients` WHERE `clients`.`id` = {$row}.`client_id` LIMIT 1)";
        $outgoingSite = "(SELECT COALESCE(`ho_out`.`site_id`, `ho_out_client`.`site_id`) FROM `shifts` AS `ho_out` LEFT JOIN `clients` AS `ho_out_client` ON `ho_out_client`.`id` = `ho_out`.`client_id` WHERE `ho_out`.`id` = {$row}.`outgoing_shift_id` LIMIT 1)";
        $incomingSite = "(SELECT COALESCE(`ho_in`.`site_id`, `ho_in_client`.`site_id`) FROM `shifts` AS `ho_in` LEFT JOIN `clients` AS `ho_in_client` ON `ho_in_client`.`id` = `ho_in`.`client_id` WHERE `ho_in`.`id` = {$row}.`incoming_shift_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$outgoingSite}, {$incomingSite}, {$clientSite})";

        return $query
            ->whereNotNull($query->qualifyColumn('organization_id'))
            ->whereRaw("{$authoritativeSite} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `ho_site` WHERE `ho_site`.`id` = {$authoritativeSite} AND `ho_site`.`tenant_id` = {$row}.`organization_id`)")
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `ho_client` WHERE `ho_client`.`id` = {$row}.`client_id` AND `ho_client`.`organization_id` = {$row}.`organization_id` AND `ho_client`.`site_id` = {$authoritativeSite}))")
            ->whereRaw("({$row}.`outgoing_staff_id` IS NULL OR EXISTS (SELECT 1 FROM `users` AS `ho_out_user` WHERE `ho_out_user`.`id` = {$row}.`outgoing_staff_id` AND `ho_out_user`.`organization_id` = {$row}.`organization_id`))")
            ->whereRaw("({$row}.`incoming_staff_id` IS NULL OR EXISTS (SELECT 1 FROM `users` AS `ho_in_user` WHERE `ho_in_user`.`id` = {$row}.`incoming_staff_id` AND `ho_in_user`.`organization_id` = {$row}.`organization_id`))")
            ->whereRaw("({$row}.`outgoing_shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `ho_out_shift` LEFT JOIN `clients` AS `ho_out_shift_client` ON `ho_out_shift_client`.`id` = `ho_out_shift`.`client_id` WHERE `ho_out_shift`.`id` = {$row}.`outgoing_shift_id` AND `ho_out_shift`.`organization_id` = {$row}.`organization_id` AND (`ho_out_shift`.`user_id` <=> {$row}.`outgoing_staff_id`) AND (`ho_out_shift`.`client_id` <=> {$row}.`client_id`) AND (`ho_out_shift`.`client_id` IS NULL OR (`ho_out_shift_client`.`organization_id` = `ho_out_shift`.`organization_id` AND `ho_out_shift_client`.`site_id` IS NOT NULL AND (`ho_out_shift`.`site_id` IS NULL OR `ho_out_shift`.`site_id` = `ho_out_shift_client`.`site_id`))) AND COALESCE(`ho_out_shift`.`site_id`, `ho_out_shift_client`.`site_id`) = {$authoritativeSite}))")
            ->whereRaw("({$row}.`incoming_shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `ho_in_shift` LEFT JOIN `clients` AS `ho_in_shift_client` ON `ho_in_shift_client`.`id` = `ho_in_shift`.`client_id` WHERE `ho_in_shift`.`id` = {$row}.`incoming_shift_id` AND `ho_in_shift`.`organization_id` = {$row}.`organization_id` AND (`ho_in_shift`.`user_id` <=> {$row}.`incoming_staff_id`) AND (`ho_in_shift`.`client_id` <=> {$row}.`client_id`) AND (`ho_in_shift`.`client_id` IS NULL OR (`ho_in_shift_client`.`organization_id` = `ho_in_shift`.`organization_id` AND `ho_in_shift_client`.`site_id` IS NOT NULL AND (`ho_in_shift`.`site_id` IS NULL OR `ho_in_shift`.`site_id` = `ho_in_shift_client`.`site_id`))) AND COALESCE(`ho_in_shift`.`site_id`, `ho_in_shift_client`.`site_id`) = {$authoritativeSite}))");
    }

    private function applyFleetHandoverIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $authoritativeSite = "(SELECT COALESCE(`fleet_asset`.`site_id`, `fleet_asset`.`home_site_id`) FROM `assets` AS `fleet_asset` WHERE `fleet_asset`.`id` = {$row}.`asset_id` LIMIT 1)";

        return $query
            ->whereNotNull($query->qualifyColumn('tenant_id'))
            ->whereNotNull($query->qualifyColumn('incoming_user_id'))
            ->whereRaw("EXISTS (SELECT 1 FROM `assets` AS `fleet_asset_row` JOIN `sites` AS `fleet_site` ON `fleet_site`.`id` = COALESCE(`fleet_asset_row`.`site_id`, `fleet_asset_row`.`home_site_id`) WHERE `fleet_asset_row`.`id` = {$row}.`asset_id` AND `fleet_site`.`tenant_id` = {$row}.`tenant_id`)")
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `fleet_outgoing` WHERE `fleet_outgoing`.`id` = {$row}.`outgoing_user_id` AND `fleet_outgoing`.`organization_id` = {$row}.`tenant_id`)")
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `fleet_incoming` JOIN `hr_employee_profiles` AS `fleet_profile` ON `fleet_profile`.`user_id` = `fleet_incoming`.`id` AND `fleet_profile`.`deleted_at` IS NULL WHERE `fleet_incoming`.`id` = {$row}.`incoming_user_id` AND `fleet_incoming`.`organization_id` = {$row}.`tenant_id` AND `fleet_incoming`.`approved_at` IS NOT NULL AND `fleet_incoming`.`role` NOT IN ('client', 'next_of_kin') AND NOT EXISTS (SELECT 1 FROM `role_user` JOIN `roles` ON `roles`.`id` = `role_user`.`role_id` WHERE `role_user`.`user_id` = `fleet_incoming`.`id` AND `roles`.`name` IN ('client', 'next_of_kin')) AND `fleet_profile`.`tenant_id` = {$row}.`tenant_id` AND `fleet_profile`.`is_active` = 1 AND (`fleet_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`fleet_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite}))))");
    }

    private function assertTimesheetIntrinsicIntegrity(Timesheet $timesheet, ?string $message): int
    {
        $canonicalTimesheet = Timesheet::query()->with([
            'staff:id,organization_id',
            'client:id,organization_id,site_id',
            'shift:id,organization_id,site_id,client_id,user_id',
            'shift.client:id,organization_id,site_id',
            'shift.staff:id,organization_id',
        ])->find($timesheet->getKey());
        abort_unless($canonicalTimesheet, 403, $message ?? self::DEFAULT_MESSAGE);
        $timesheet = $canonicalTimesheet;
        $organizationId = $this->nullablePositiveId($timesheet->staff?->organization_id);
        abort_if($organizationId === null, 403, $message ?? self::DEFAULT_MESSAGE);

        $siteIds = collect([$timesheet->shift_site_id, $timesheet->site_id]);
        if ($timesheet->client_id !== null) {
            abort_unless(
                $timesheet->client
                    && $this->nullablePositiveId($timesheet->client->organization_id) === $organizationId
                    && $this->nullablePositiveId($timesheet->client->site_id) !== null,
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
            $siteIds->push($timesheet->client->site_id);
        }

        if ($timesheet->shift_id !== null) {
            abort_unless(
                $timesheet->shift
                    && $this->nullablePositiveId($timesheet->shift->organization_id) === $organizationId
                    && $this->nullablePositiveId($timesheet->shift->user_id) === $this->nullablePositiveId($timesheet->user_id)
                    && $this->nullablePositiveId($timesheet->shift->client_id) === $this->nullablePositiveId($timesheet->client_id),
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
            $this->assertIntrinsicShiftRelations($timesheet->shift, $message);
            $siteIds->push($this->shiftSiteId($timesheet->shift));
        }

        $siteIds = $siteIds->filter(fn ($siteId) => $this->nullablePositiveId($siteId) !== null)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();
        abort_unless($siteIds->count() === 1, 403, $message ?? self::DEFAULT_MESSAGE);
        $siteId = (int) $siteIds->first();
        abort_unless(
            Site::query()->whereKey($siteId)->where('tenant_id', $organizationId)->exists(),
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

        return $siteId;
    }

    private function assertHandoverIntrinsicIntegrity(ShiftHandover $handover, ?string $message): int
    {
        $canonicalHandover = ShiftHandover::query()->with([
            'client:id,organization_id,site_id',
            'outgoingStaff:id,organization_id',
            'incomingStaff:id,organization_id',
            'outgoingShift:id,organization_id,site_id,client_id,user_id',
            'outgoingShift.client:id,organization_id,site_id',
            'outgoingShift.staff:id,organization_id',
            'incomingShift:id,organization_id,site_id,client_id,user_id',
            'incomingShift.client:id,organization_id,site_id',
            'incomingShift.staff:id,organization_id',
        ])->find($handover->getKey());
        abort_unless($canonicalHandover, 403, $message ?? self::DEFAULT_MESSAGE);
        $handover = $canonicalHandover;
        $organizationId = $this->nullablePositiveId($handover->organization_id);
        abort_if($organizationId === null, 403, $message ?? self::DEFAULT_MESSAGE);
        $siteIds = collect();

        if ($handover->client_id !== null) {
            abort_unless(
                $handover->client
                    && $this->nullablePositiveId($handover->client->organization_id) === $organizationId
                    && $this->nullablePositiveId($handover->client->site_id) !== null,
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
            $siteIds->push($handover->client->site_id);
        }

        foreach ([
            [$handover->outgoing_shift_id, $handover->outgoingShift, $handover->outgoing_staff_id, $handover->outgoingStaff],
            [$handover->incoming_shift_id, $handover->incomingShift, $handover->incoming_staff_id, $handover->incomingStaff],
        ] as [$shiftId, $shift, $staffId, $staff]) {
            if ($staffId !== null) {
                abort_unless(
                    $staff && $this->nullablePositiveId($staff->organization_id) === $organizationId,
                    403,
                    $message ?? self::DEFAULT_MESSAGE,
                );
            }
            if ($shiftId === null) {
                continue;
            }
            abort_unless(
                $shift
                    && $this->nullablePositiveId($shift->organization_id) === $organizationId
                    && $this->nullablePositiveId($shift->user_id) === $this->nullablePositiveId($staffId)
                    && $this->nullablePositiveId($shift->client_id) === $this->nullablePositiveId($handover->client_id),
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
            $this->assertIntrinsicShiftRelations($shift, $message);
            $siteIds->push($this->shiftSiteId($shift));
        }

        $siteIds = $siteIds->filter(fn ($siteId) => $this->nullablePositiveId($siteId) !== null)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();
        abort_unless($siteIds->count() === 1, 403, $message ?? self::DEFAULT_MESSAGE);
        $siteId = (int) $siteIds->first();
        abort_unless(
            Site::query()->whereKey($siteId)->where('tenant_id', $organizationId)->exists(),
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

        return $siteId;
    }

    private function assertIntrinsicShiftRelations(Shift $shift, ?string $message): void
    {
        $organizationId = $this->nullablePositiveId($shift->organization_id);
        abort_if($organizationId === null, 403, $message ?? self::DEFAULT_MESSAGE);
        if ($shift->user_id !== null) {
            abort_unless(
                $shift->staff
                    && $this->nullablePositiveId($shift->staff->organization_id) === $organizationId,
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
        }
        if ($shift->client_id !== null) {
            abort_unless(
                $shift->client
                    && $this->nullablePositiveId($shift->client->organization_id) === $organizationId
                    && $this->nullablePositiveId($shift->client->site_id) !== null
                    && ($shift->site_id === null || (int) $shift->site_id === (int) $shift->client->site_id),
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
        }
        abort_if($this->shiftSiteId($shift) === null, 403, $message ?? self::DEFAULT_MESSAGE);
    }

    /**
     * @return array<int, string>
     */
    protected function alertContextSitePaths(): array
    {
        return [
            '$.site_id',
            '$.shift_context.site.id',
            '$.shift.site_id',
            '$.shift.site.id',
            '$.site.id',
        ];
    }

    protected function alertContextSiteExpression(Builder $query): string
    {
        $contextColumn = sprintf('`%s`.`context`', $query->getModel()->getTable());
        $siteValues = collect($this->alertContextSitePaths())
            ->map(fn (string $jsonPath) => sprintf(
                "NULLIF(NULLIF(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(%s, '%s')), ''), '0'), 'null'), 'false')",
                $contextColumn,
                $jsonPath,
            ))
            ->implode(', ');

        return sprintf('CAST(COALESCE(%s) AS UNSIGNED)', $siteValues);
    }

    /** @param array<int, string> $bypassPermissions */
    private function canSkipTenantScope(?User $user, array $bypassPermissions): bool
    {
        return $this->canBypass($user, $bypassPermissions)
            && $this->isUnrestrictedPlatformUser($user);
    }

    private function organizationId(?User $user): ?int
    {
        $organizationId = $user?->organization_id;

        return $organizationId === null ? null : (int) $organizationId;
    }

    private function organizationsAgree(?User $user, mixed $recordOrganizationId): bool
    {
        $organizationId = $this->organizationId($user);

        return $organizationId !== null
            && $recordOrganizationId !== null
            && $organizationId === (int) $recordOrganizationId;
    }

    private function applyClientOrganizationScope(Builder $query, ?User $user): Builder
    {
        $organizationId = $this->organizationId($user);

        return $organizationId === null
            ? $query->whereRaw('1 = 0')
            : $query->where($query->qualifyColumn('organization_id'), $organizationId);
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
