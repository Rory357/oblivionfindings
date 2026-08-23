<?php

namespace App\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetShiftHandover;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Illuminate\Database\Eloquent\Builder;

class UserSiteAccessService
{
    public const DEFAULT_MESSAGE = 'You are not authorized to access records for this site.';

    /**
     * The one explicit application-wide H&S Site bypass. H&S capability
     * permissions (hazards.*, restraints.*, hr.wellbeing.*) never imply it.
     *
     * @var array<int, string>
     */
    public const HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

    /**
     * Explicit application-wide People/profile read boundary for central HR,
     * administrators, and auditors. Broad employee-view permissions alone do
     * not imply access beyond a viewer's approved Sites.
     *
     * @var array<int, string>
     */
    public const HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS = ['hr.employees.viewAllSites'];

    /**
     * Explicit governance spend Site-scope bypass. The request, manageAny,
     * and approve permissions remain separate action/ownership authority.
     *
     * @var array<int, string>
     */
    public const GOVERNANCE_SPEND_SITE_BYPASS_PERMISSIONS = ['governance.spend.viewAllSites'];

    /** @var array<string, bool> */
    private array $clientIncidentSiteColumnCache = [];

    /** @var array<string, array<int, int>> */
    private array $accessibleSiteIdsCache = [];

    /**
     * @param  array<int, string>  $bypassPermissions
     * @return array<int, int>
     */
    public function accessibleSiteIds(?User $user, array $bypassPermissions = []): array
    {
        $cacheKey = implode('|', [
            $user ? (string) ($user->getKey() ?? 'unsaved') : 'guest',
            $user ? (string) spl_object_id($user) : 'guest',
            implode(',', $bypassPermissions),
        ]);
        if (array_key_exists($cacheKey, $this->accessibleSiteIdsCache)) {
            return $this->accessibleSiteIdsCache[$cacheKey];
        }

        if (! $user) {
            return $this->accessibleSiteIdsCache[$cacheKey] = [];
        }

        if ($this->canBypass($user, $bypassPermissions)) {
            return $this->accessibleSiteIdsCache[$cacheKey] = Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($siteId) => (int) $siteId)
                ->all();
        }

        $user->loadMissing('hrEmployeeProfile');

        $profile = $user->hrEmployeeProfile;
        if (! $profile || ! $this->isCurrentEmployeeProfile($profile)) {
            return $this->accessibleSiteIdsCache[$cacheKey] = [];
        }

        $secondarySiteIds = is_array($profile?->secondary_site_ids)
            ? $profile->secondary_site_ids
            : [];

        $assignedSiteIds = collect([
            $profile?->primary_site_id,
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

        $currentSiteIds = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $assignedSiteIds)
            ->pluck('id')
            ->map(fn ($siteId) => (int) $siteId)
            ->all();

        return $this->accessibleSiteIdsCache[$cacheKey] = array_values(array_filter(
            $assignedSiteIds,
            fn (int $siteId) => in_array($siteId, $currentSiteIds, true),
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

    /** @return array<int, int> */
    public function accessibleHealthSafetySiteIds(?User $user): array
    {
        return $this->accessibleSiteIds($user, self::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
    }

    public function canAccessAllHealthSafetySites(?User $user): bool
    {
        return $this->canBypass($user, self::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
    }

    public function assertCanAccessHealthSafetySiteId(?User $user, ?int $siteId): void
    {
        $this->assertCanAccessSiteId(
            $user,
            $siteId,
            self::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
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

        $client = Client::query()->whereKey($clientId)->first(['id', 'site_id']);
        if (! $client) {
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
        $incident->loadMissing([
            'client:id,site_id',
            'shift.client:id,site_id',
        ]);

        if (! $incident->client) {
            abort(403, self::DEFAULT_MESSAGE);
        }

        $siteId = $incident->getAttribute('site_id')
            ?: $incident->shift?->site_id
            ?: $incident->shift?->client?->site_id
            ?: $incident->client?->site_id;

        $this->assertCanAccessSiteId(
            $user,
            $siteId ? (int) $siteId : null,
            $bypassPermissions,
        );
    }

    /**
     * Resolve the one canonical Site represented by a client incident.
     * Direct, Shift, Client and Shift-Client provenance must converge.
     *
     * @throws \LogicException when provenance is missing or conflicting
     */
    public function effectiveClientIncidentSiteId(ClientIncident $incident): int
    {
        $canonical = ClientIncident::query()->with([
            'client:id,site_id',
            'shift:id,site_id,client_id',
            'shift.client:id,site_id',
        ])->find($incident->getKey());

        if (! $canonical || ! $canonical->client) {
            throw new \LogicException('Client incident provenance could not be resolved.');
        }

        if ($canonical->shift && (int) $canonical->shift->client_id !== (int) $canonical->client_id) {
            throw new \LogicException('Client incident Shift and Client provenance conflicts.');
        }

        $siteIds = collect([
            $this->nullablePositiveId($canonical->getAttribute('site_id')),
            $this->nullablePositiveId($canonical->client->site_id),
            $this->nullablePositiveId($canonical->shift?->site_id),
            $this->nullablePositiveId($canonical->shift?->client?->site_id),
        ])->filter()->unique()->values();

        if ($siteIds->count() !== 1) {
            throw new \LogicException('Client incident provenance does not converge on one Site.');
        }

        return (int) $siteIds->first();
    }

    /**
     * Validate a linked incident against both viewer access and the selected Site.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanUseClientIncidentAtSite(
        ?User $user,
        ClientIncident $incident,
        int $siteId,
        array $bypassPermissions = [],
    ): void {
        $this->assertCanAccessSiteId($user, $siteId, $bypassPermissions);

        try {
            $incidentSiteId = $this->effectiveClientIncidentSiteId($incident);
        } catch (\LogicException) {
            abort(403, self::DEFAULT_MESSAGE);
        }

        abort_unless($incidentSiteId === $siteId, 403, self::DEFAULT_MESSAGE);
    }

    /**
     * Canonical Site scope for the workplace-injury register.
     * Missing-Site rows are deliberately excluded from interactive access.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyWorkplaceInjuryScope(
        Builder $query,
        ?User $user,
        array $bypassPermissions = [],
    ): Builder {
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);

        return $siteIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn('site_id'), $siteIds);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessWorkplaceInjury(
        ?User $user,
        WorkplaceInjury $injury,
        array $bypassPermissions = [],
    ): void {
        $this->assertCanAccessSiteId(
            $user,
            $this->nullablePositiveId($injury->site_id),
            $bypassPermissions,
        );
    }

    /**
     * Canonical restraint-event Site scope. A restricted viewer must be
     * allowed both the recorded Site and the linked Client's current Site so
     * stale or conflicting provenance cannot disclose another Site's PHI.
     * The named application-wide H&S permission deliberately bypasses this
     * restriction so authorised governance roles can inspect legacy records.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyRestraintEventScope(
        Builder $query,
        ?User $user,
        array $bypassPermissions = self::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
    ): Builder {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        return $this->applyRestraintEventSiteScopeForSiteIds(
            $query,
            $this->accessibleSiteIds($user, $bypassPermissions),
        );
    }

    /**
     * @param  array<int, int|string>  $siteIds
     */
    public function applyRestraintEventSiteScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $siteIds = $this->normalizePositiveSiteIds($siteIds);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn($query->qualifyColumn('site_id'), $siteIds)
            ->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds));
    }

    /**
     * Behaviour support plans inherit their Site boundary from their Client.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyBehaviourSupportPlanScope(
        Builder $query,
        ?User $user,
        array $bypassPermissions = self::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
    ): Builder {
        if ($this->canBypass($user, $bypassPermissions)) {
            return $query;
        }

        return $this->applyBehaviourSupportPlanSiteScopeForSiteIds(
            $query,
            $this->accessibleSiteIds($user, $bypassPermissions),
        );
    }

    /**
     * @param  array<int, int|string>  $siteIds
     */
    public function applyBehaviourSupportPlanSiteScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $siteIds = $this->normalizePositiveSiteIds($siteIds);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'client',
            fn (Builder $client) => $client->whereIn('site_id', $siteIds),
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
        $siteId = $this->nullablePositiveId($event->site_id);
        if ($siteId === null) {
            abort_unless($this->canBypass($user, $bypassPermissions), 403, self::DEFAULT_MESSAGE);

            return;
        }

        $this->assertCanAccessSiteId($user, $siteId, $bypassPermissions);
    }

    /**
     * Resolve the one canonical Site represented by a risk assessment.
     *
     * Assessments may be attached to an H&S event, a Site, or a Client. When
     * more than one provenance path is present they must converge on the same
     * Site. A null result is reserved for a genuinely standalone assessment;
     * broken, unsupported, or conflicting provenance is rejected.
     */
    public function effectiveHsRiskAssessmentSiteId(HsRiskAssessment $assessment): ?int
    {
        $canonical = HsRiskAssessment::query()
            ->with(['hsEvent:id,site_id', 'assessable'])
            ->find($assessment->getKey());

        if (! $canonical) {
            throw new \LogicException('Risk assessment provenance could not be resolved.');
        }

        $eventId = $this->nullablePositiveId($canonical->hs_event_id);
        $assessableId = $this->nullablePositiveId($canonical->assessable_id);
        $assessableType = $canonical->assessable_type ?: null;

        if (($assessableType === null) !== ($assessableId === null)) {
            throw new \LogicException('Risk assessment provenance is incomplete.');
        }

        $eventSiteId = null;
        if ($eventId !== null) {
            $eventSiteId = $this->nullablePositiveId($canonical->hsEvent?->site_id);
            if ($eventSiteId === null) {
                throw new \LogicException('Risk assessment event provenance is invalid.');
            }
        }

        $assessableSiteId = null;
        if ($assessableType !== null) {
            $assessableSiteId = match ($assessableType) {
                Site::class => $canonical->assessable instanceof Site
                    ? $this->nullablePositiveId($canonical->assessable->getKey())
                    : null,
                Client::class => $canonical->assessable instanceof Client
                    ? $this->nullablePositiveId($canonical->assessable->site_id)
                    : null,
                default => throw new \LogicException('Risk assessment provenance type is unsupported.'),
            };

            if ($assessableSiteId === null) {
                throw new \LogicException('Risk assessment assessable provenance is invalid.');
            }
        }

        if ($eventSiteId !== null && $assessableSiteId !== null && $eventSiteId !== $assessableSiteId) {
            throw new \LogicException('Risk assessment provenance does not converge on one Site.');
        }

        return $eventSiteId ?? $assessableSiteId;
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessHsRiskAssessment(
        ?User $user,
        HsRiskAssessment $assessment,
        array $bypassPermissions = [],
    ): void {
        try {
            $siteId = $this->effectiveHsRiskAssessmentSiteId($assessment);
        } catch (\LogicException) {
            abort(403, self::DEFAULT_MESSAGE);
        }

        if ($siteId === null) {
            abort_unless($this->canBypass($user, $bypassPermissions), 403, self::DEFAULT_MESSAGE);

            return;
        }

        $this->assertCanAccessSiteId($user, $siteId, $bypassPermissions);
    }

    /**
     * Validate a create/update attachment before it becomes persisted state.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanUseHsRiskAssessmentContext(
        ?User $user,
        string $type,
        ?int $id,
        array $bypassPermissions = [],
    ): void {
        if ($type === 'standalone') {
            abort_unless($id === null && $this->canBypass($user, $bypassPermissions), 403, self::DEFAULT_MESSAGE);

            return;
        }

        abort_unless($id !== null && $id > 0, 403, self::DEFAULT_MESSAGE);

        match ($type) {
            'site' => $this->assertCanAccessSiteId($user, $id, $bypassPermissions),
            'client' => $this->assertCanAccessClientId($user, $id, $bypassPermissions),
            'event' => $this->assertCanAccessHsEvent(
                $user,
                HsEvent::query()->findOrFail($id),
                $bypassPermissions,
            ),
            default => abort(403, self::DEFAULT_MESSAGE),
        };
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
        $canonicalShift = Shift::query()->with([
            'site:id',
            'client:id,site_id',
            'staff:id',
        ])->find($shift->getKey());
        abort_unless($canonicalShift, 403, $message ?? self::DEFAULT_MESSAGE);
        $shift = $canonicalShift;
        $this->assertIntrinsicShiftRelations($shift, $message);

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
    public function assertCanAccessShiftOpenPosition(
        ?User $user,
        ShiftOpenPosition $position,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        $query = ShiftOpenPosition::query()->whereKey($position->getKey());

        abort_unless(
            $this->applyShiftOpenPositionScope($query, $user, $bypassPermissions)->exists(),
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanAccessShiftReplacementRequest(
        ?User $user,
        ShiftReplacementRequest $replacement,
        array $bypassPermissions = [],
        ?string $message = null,
    ): void {
        $query = ShiftReplacementRequest::query()->whereKey($replacement->getKey());

        abort_unless(
            $this->applyShiftReplacementScope($query, $user, $bypassPermissions)->exists(),
            403,
            $message ?? self::DEFAULT_MESSAGE,
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
        $alert->loadMissing('client:id,site_id');
        if ($alert->client_id && ! $alert->client) {
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
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyShiftScopeForSiteIds($query, $siteIds);
    }

    public function applyShiftIntegrityScope(Builder $query): Builder
    {
        return $this->applyShiftIntrinsicIntegrity($query);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyShiftOpenPositionScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $this->applyShiftOpenPositionIntegrityScope($query);
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'shift',
            fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds),
        );
    }

    public function applyShiftOpenPositionIntegrityScope(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->whereHas('shift', fn (Builder $shiftQuery) => $this->applyShiftIntrinsicIntegrity($shiftQuery))
            ->where(function (Builder $replacementLink) use ($table): void {
                $replacementLink->whereNull("{$table}.replacement_request_id")
                    ->orWhereHas('replacementRequest', fn (Builder $replacementQuery) => $this
                        ->applyShiftReplacementIntegrityScope($replacementQuery)
                        ->whereColumn('shift_replacement_requests.shift_id', "{$table}.shift_id"));
            })
            ->where(function (Builder $claimer): void {
                $claimer->whereNull('claimed_by')->orWhereHas('claimer');
            })
            ->where(function (Builder $approver): void {
                $approver->whereNull('approved_by')->orWhereHas('approver');
            });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyShiftReplacementScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $this->applyShiftReplacementIntegrityScope($query);
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'shift',
            fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds),
        );
    }

    public function applyShiftReplacementIntegrityScope(Builder $query): Builder
    {
        return $query
            ->whereNotNull($query->qualifyColumn('shift_id'))
            ->whereNotNull($query->qualifyColumn('current_staff_id'))
            ->whereHas('shift', fn (Builder $shiftQuery) => $this->applyShiftIntrinsicIntegrity($shiftQuery))
            ->whereHas('currentStaff')
            ->where(function (Builder $requester): void {
                $requester->whereNull('requested_by')->orWhereHas('requester');
            })
            ->where(function (Builder $replacement): void {
                $replacement->whereNull('replacement_user_id')->orWhereHas('replacementStaff');
            })
            ->where(function (Builder $approver): void {
                $approver->whereNull('approved_by')->orWhereHas('approver');
            })
            ->where(function (Builder $canceller): void {
                $canceller->whereNull('cancelled_by')->orWhereHas('canceller');
            });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyTimesheetScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $this->applyTimesheetIntrinsicIntegrity($query);

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
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);

        return $this->applyHandoverSiteScopeForSiteIds($query, $siteIds);
    }

    /**
     * @param  array<int, int|string>  $siteIds
     */
    public function applyHandoverSiteScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $this->applyHandoverIntegrityScope($query);

        $siteIds = $this->normalizePositiveSiteIds($siteIds);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        // Intrinsic integrity requires the outgoing Shift, incoming Shift when
        // present, and Client to converge. The outgoing Shift is therefore the
        // authoritative and sufficient Site path for the access predicate.
        return $query->whereHas(
            'outgoingShift',
            fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds),
        );
    }

    public function applyHandoverIntegrityScope(Builder $query): Builder
    {
        return $this->applyHandoverIntrinsicIntegrity($query);
    }

    public function handoverHasIntrinsicIntegrity(ShiftHandover $handover): bool
    {
        $query = ShiftHandover::query()->whereKey($handover->getKey());

        return $this->applyHandoverIntegrityScope($query)->exists();
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyClientScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('site_id', $siteIds);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyClientIncidentScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
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
            $nested->where(function (Builder $shiftSnapshot) use ($siteIds): void {
                $shiftSnapshot
                    ->whereNotNull('shift_id')
                    ->whereHas('shift', fn (Builder $shiftQuery) => $this->applyShiftScopeForSiteIds($shiftQuery, $siteIds));
            })->orWhere(function (Builder $clientFallback) use ($siteIds): void {
                $clientFallback
                    ->whereNull('shift_id')
                    ->whereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
            });
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHsEventScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        $canViewApplicationWide = $this->canBypass($user, $bypassPermissions);
        if ($siteIds === [] && ! $canViewApplicationWide) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($canViewApplicationWide, $siteIds): void {
            if ($siteIds !== []) {
                $scope->whereIn('site_id', $siteIds);
            }

            if ($canViewApplicationWide) {
                $siteIds === []
                    ? $scope->whereNull('site_id')
                    : $scope->orWhereNull('site_id');
            }
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHsRiskAssessmentScope(
        Builder $query,
        ?User $user,
        array $bypassPermissions = [],
    ): Builder {
        $canViewApplicationWide = $this->canBypass($user, $bypassPermissions);
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);

        return $query->where(function (Builder $access) use ($canViewApplicationWide, $siteIds): void {
            $this->applyHsRiskAssessmentSiteScopeForSiteIds($access, $siteIds);

            if ($canViewApplicationWide) {
                $access->orWhere(function (Builder $standalone): void {
                    $standalone->whereNull('hs_event_id')
                        ->whereNull('assessable_type')
                        ->whereNull('assessable_id');
                });
            }
        });
    }

    /** Canonical application-wide scope for trusted background/reporting callers. */
    public function applyHsRiskAssessmentApplicationScope(Builder $query, bool $includeStandalone = true): Builder
    {
        $siteIds = Site::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $query->where(function (Builder $scope) use ($includeStandalone, $siteIds): void {
            $this->applyHsRiskAssessmentSiteScopeForSiteIds($scope, $siteIds);

            if ($includeStandalone) {
                $scope->orWhere(function (Builder $standalone): void {
                    $standalone->whereNull('hs_event_id')
                        ->whereNull('assessable_type')
                        ->whereNull('assessable_id');
                });
            }
        });
    }

    /**
     * Apply the canonical assessment provenance contract for an explicit Site set.
     * Invalid references, unsupported polymorphs, and conflicting dual provenance
     * never enter lists, counts, dashboards, summaries, or exports.
     *
     * @param  array<int, int|string>  $siteIds
     */
    public function applyHsRiskAssessmentSiteScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $siteIds = $this->normalizePositiveSiteIds($siteIds);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $assessmentTable = $query->getModel()->getTable();
        $eventClientAgreement = <<<'SQL'
exists (
    select 1
    from `clients`
    inner join `hs_events`
        on `hs_events`.`id` = `hs_risk_assessments`.`hs_event_id`
        and `hs_events`.`deleted_at` is null
    where `clients`.`id` = `hs_risk_assessments`.`assessable_id`
      and `clients`.`deleted_at` is null
      and `clients`.`site_id` = `hs_events`.`site_id`
)
SQL;
        $eventClientAgreement = str_replace('`hs_risk_assessments`', '`'.$assessmentTable.'`', $eventClientAgreement);

        return $query->where(function (Builder $scope) use ($siteIds, $eventClientAgreement): void {
            $scope->where(function (Builder $siteOnly) use ($siteIds): void {
                $siteOnly->whereNull('hs_event_id')
                    ->where('assessable_type', Site::class)
                    ->whereIn('assessable_id', $siteIds);
            })->orWhere(function (Builder $clientOnly) use ($siteIds): void {
                $clientOnly->whereNull('hs_event_id')
                    ->where('assessable_type', Client::class)
                    ->whereIn('assessable_id', Client::query()
                        ->whereIn('site_id', $siteIds)
                        ->select('id'));
            })->orWhere(function (Builder $eventOnly) use ($siteIds): void {
                $eventOnly->whereNotNull('hs_event_id')
                    ->whereNull('assessable_type')
                    ->whereNull('assessable_id')
                    ->whereHas('hsEvent', fn (Builder $event) => $event->whereIn('site_id', $siteIds));
            })->orWhere(function (Builder $eventAndSite) use ($siteIds): void {
                $eventAndSite->whereNotNull('hs_event_id')
                    ->where('assessable_type', Site::class)
                    ->whereIn('assessable_id', $siteIds)
                    ->whereHas('hsEvent', fn (Builder $event) => $event
                        ->whereIn('site_id', $siteIds)
                        ->whereColumn('hs_events.site_id', 'hs_risk_assessments.assessable_id'));
            })->orWhere(function (Builder $eventAndClient) use ($siteIds, $eventClientAgreement): void {
                $eventAndClient->whereNotNull('hs_event_id')
                    ->where('assessable_type', Client::class)
                    ->whereIn('assessable_id', Client::query()
                        ->whereIn('site_id', $siteIds)
                        ->select('id'))
                    ->whereHas('hsEvent', fn (Builder $event) => $event->whereIn('site_id', $siteIds))
                    ->whereRaw($eventClientAgreement);
            });
        });
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    public function applySiteScope(Builder $query, ?User $user, array $bypassPermissions = []): Builder
    {
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
        $query->staff()
            ->whereNotNull($query->qualifyColumn('approved_at'))
            ->whereHas('hrEmployeeProfile', fn (Builder $profileQuery) => $this->applyCurrentEmployeeProfileScope($profileQuery));

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
     * Scope current approved staff through the explicit HR all-Sites
     * permission without ever relaxing canonical Site provenance.
     */
    public function applyHrEmployeeStaffScope(Builder $query, ?User $user): Builder
    {
        $query->staff()
            ->whereNotNull($query->qualifyColumn('approved_at'))
            ->whereHas('hrEmployeeProfile', fn (Builder $profileQuery) => $this->applyCurrentEmployeeProfileScope($profileQuery));

        $siteIds = $this->accessibleSiteIds($user, self::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteIds): void {
            $profileQuery->where(function (Builder $siteQuery) use ($siteIds): void {
                $siteQuery->whereIn('primary_site_id', $siteIds);

                foreach ($siteIds as $siteId) {
                    $siteQuery->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });
        });
    }

    /**
     * Validate that a selected person is current approved staff assigned to the
     * exact injury Site. Application-wide visibility never relaxes that
     * staff-to-Site provenance invariant.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function assertCanUseCurrentStaffAtSite(
        ?User $viewer,
        int $staffId,
        int $siteId,
        array $bypassPermissions = [],
    ): void {
        $this->assertCanAccessSiteId($viewer, $siteId, $bypassPermissions);

        $query = $this->applyStaffScope(
            User::query()->whereKey($staffId),
            $viewer,
            $bypassPermissions,
        );

        $query->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteId): void {
            $profileQuery->where(function (Builder $siteQuery) use ($siteId): void {
                $siteQuery->where('primary_site_id', $siteId)
                    ->orWhereJsonContains('secondary_site_ids', $siteId);
            });
        });

        abort_unless($query->exists(), 403, self::DEFAULT_MESSAGE);
    }

    /**
     * Retain Site provenance for historical HR records without making former
     * staff current recipients, assignees, or picker options. Unlike
     * applyStaffScope(), this deliberately accepts ended, inactive, unapproved,
     * and soft-deleted employee profiles, but still requires their recorded Site
     * to be visible to the viewer.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHistoricalStaffSiteScope(
        Builder $query,
        ?User $user,
        array $bypassPermissions = [],
    ): Builder {
        $profiles = HrEmployeeProfile::withTrashed()->select('user_id');

        if (! $this->canBypass($user, $bypassPermissions)) {
            $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
            if ($siteIds === []) {
                return $query->whereRaw('1 = 0');
            }

            $profiles->where(function (Builder $siteQuery) use ($siteIds): void {
                $siteQuery->whereIn('primary_site_id', $siteIds);

                foreach ($siteIds as $siteId) {
                    $siteQuery->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });
        }

        return $query->whereIn($query->qualifyColumn('id'), $profiles);
    }

    /**
     * Scope retained employee provenance through the explicit HR all-Sites
     * permission while continuing to reject missing or invalid Site identity.
     */
    public function applyHistoricalHrEmployeeStaffScope(Builder $query, ?User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user, self::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $profiles = HrEmployeeProfile::withTrashed()
            ->select('user_id')
            ->where(function (Builder $siteQuery) use ($siteIds): void {
                $siteQuery->whereIn('primary_site_id', $siteIds);

                foreach ($siteIds as $siteId) {
                    $siteQuery->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });

        return $query->whereIn($query->qualifyColumn('id'), $profiles);
    }

    /**
     * Scope employee profiles to current approved staff at a Site visible to
     * the viewer. This is the canonical picker and profile-mutation boundary.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyCurrentStaffProfileScope(
        Builder $query,
        ?User $viewer,
        array $bypassPermissions = [],
    ): Builder {
        $currentStaff = $this->applyStaffScope(
            User::query()->select('users.id'),
            $viewer,
            $bypassPermissions,
        );

        return $query->whereIn($query->qualifyColumn('user_id'), $currentStaff);
    }

    /** Scope current employee profiles through the canonical HR Site boundary. */
    public function applyCurrentHrEmployeeProfileScope(Builder $query, ?User $viewer): Builder
    {
        $currentStaff = $this->applyHrEmployeeStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );

        return $query->whereIn($query->qualifyColumn('user_id'), $currentStaff);
    }

    /**
     * Scope employee profiles to retained staff provenance at a Site visible
     * to the viewer. Former and archived profiles remain readable, but this is
     * deliberately not an assignment or recipient boundary.
     *
     * @param  array<int, string>  $bypassPermissions
     */
    public function applyHistoricalStaffProfileScope(
        Builder $query,
        ?User $viewer,
        array $bypassPermissions = [],
    ): Builder {
        $historicalStaff = $this->applyHistoricalStaffSiteScope(
            User::query()->select('users.id'),
            $viewer,
            $bypassPermissions,
        );

        return $query->whereIn($query->qualifyColumn('user_id'), $historicalStaff);
    }

    /** Scope retained employee profiles through the canonical HR Site boundary. */
    public function applyHistoricalHrEmployeeProfileScope(Builder $query, ?User $viewer): Builder
    {
        $historicalStaff = $this->applyHistoricalHrEmployeeStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );

        return $query->whereIn($query->qualifyColumn('user_id'), $historicalStaff);
    }

    /**
     * Scope approved staff to the current application and, when present, the
     * specific Site carried by an H&S event. This is the canonical picker and mutation
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
            ->whereNotNull($query->qualifyColumn('approved_at'))
            ->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($event): void {
                $this->applyCurrentEmployeeProfileScope($profileQuery);

                if ($event->site_id === null) {
                    return;
                }

                $siteId = (int) $event->site_id;
                $profileQuery->where(function (Builder $siteQuery) use ($siteId): void {
                    $siteQuery->where('primary_site_id', $siteId)
                        ->orWhereJsonContains('secondary_site_ids', $siteId);
                });
            });

        return $this->applyStaffScope($query, $viewer, $bypassPermissions);
    }

    /**
     * Canonical eligibility for a Fleet handover recipient. Broad Fleet access
     * never relaxes this record-level Site and current-employment invariant.
     */
    public function applyFleetRecipientEligibility(
        Builder $query,
        int $siteId,
    ): Builder {
        $query
            ->staff()
            ->whereNotNull($query->qualifyColumn('approved_at'))
            ->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteId): void {
                $this->applyCurrentEmployeeProfileScope($profileQuery)
                    ->where(function (Builder $siteQuery) use ($siteId): void {
                        $siteQuery->where($siteQuery->qualifyColumn('primary_site_id'), $siteId)
                            ->orWhereJsonContains($siteQuery->qualifyColumn('secondary_site_ids'), $siteId);
                    });
            });

        return $query;
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
        $siteIds = $this->accessibleSiteIds($user, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyAlertSitePrecedenceScope($query, $siteIds);
    }

    /**
     * Apply the canonical alert site precedence for a trusted explicit site
     * selection. All selected Sites must be current application Sites.
     *
     * @param  array<int, mixed>  $siteIds
     */
    public function applyAlertSiteScopeForSiteIds(Builder $query, array $siteIds): Builder
    {
        $siteIds = $this->normalizePositiveSiteIds($siteIds);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        if (! $this->allSiteIdsExist($siteIds)) {
            return $query->whereRaw('1 = 0');
        }

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
    private function allSiteIdsExist(array $siteIds): bool
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereIn('id', $siteIds)
            ->count() === count($siteIds);
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
        ]);

        $siteId = $handover->asset?->site_id
            ?: $handover->asset?->home_site_id;
        if (! $siteId) {
            abort(403, $message ?? self::DEFAULT_MESSAGE);
        }

        $siteId = (int) $siteId;
        $outgoingIsEligible = $handover->outgoing_user_id !== null
            && User::query()
                ->whereKey($handover->outgoing_user_id)
                ->tap(fn (Builder $outgoingQuery) => $this->applyFleetRecipientEligibility(
                    $outgoingQuery,
                    $siteId,
                ))
                ->exists();
        $incomingIsEligible = $handover->incoming_user_id !== null
            && User::query()
                ->whereKey($handover->incoming_user_id)
                ->tap(fn (Builder $incomingQuery) => $this->applyFleetRecipientEligibility(
                    $incomingQuery,
                    $siteId,
                ))
                ->exists();
        abort_unless(
            Site::query()->active()->notArchived()->whereKey($siteId)->exists()
                && $outgoingIsEligible
                && $incomingIsEligible,
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

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
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $siteColumn = $query->qualifyColumn('site_id');
        $clientColumn = $query->qualifyColumn('client_id');
        $userColumn = $query->qualifyColumn('user_id');
        $clientSite = "(SELECT `site_id` FROM `clients` AS `shift_client_site` WHERE `shift_client_site`.`id` = {$row}.`client_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$row}.`site_id`, {$clientSite})";
        $today = now()->toDateString();

        return $query
            ->where(function (Builder $siteIntegrity) use ($siteColumn) {
                $siteIntegrity->whereNull($siteColumn)
                    ->orWhereHas('site');
            })
            ->where(function (Builder $clientIntegrity) use (
                $clientColumn,
                $siteColumn,
            ) {
                $clientIntegrity->whereNull($clientColumn)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereNotNull($clientQuery->qualifyColumn('site_id'))
                        ->where(function (Builder $siteAgreement) use ($siteColumn) {
                            $siteAgreement->whereNull($siteColumn)
                                ->orWhereColumn('clients.site_id', $siteColumn);
                        }));
            })
            ->where(function (Builder $workerIntegrity) use ($userColumn) {
                $workerIntegrity->whereNull($userColumn)
                    ->orWhereHas('staff');
            })
            ->where(function (Builder $siteProvenance) use ($siteColumn): void {
                $siteProvenance->whereNotNull($siteColumn)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereNotNull($clientQuery->qualifyColumn('site_id')));
            })
            ->whereRaw("({$row}.`user_id` IS NULL OR EXISTS (SELECT 1 FROM `users` AS `shift_worker` JOIN `hr_employee_profiles` AS `shift_profile` ON `shift_profile`.`user_id` = `shift_worker`.`id` AND `shift_profile`.`deleted_at` IS NULL WHERE `shift_worker`.`id` = {$row}.`user_id` AND `shift_worker`.`approved_at` IS NOT NULL AND `shift_worker`.`role` NOT IN ('client', 'next_of_kin') AND NOT EXISTS (SELECT 1 FROM `role_user` JOIN `roles` ON `roles`.`id` = `role_user`.`role_id` WHERE `role_user`.`user_id` = `shift_worker`.`id` AND `roles`.`name` IN ('client', 'next_of_kin')) AND `shift_profile`.`is_active` = 1 AND (`shift_profile`.`start_date` IS NULL OR DATE(`shift_profile`.`start_date`) <= ?) AND (`shift_profile`.`end_date` IS NULL OR DATE(`shift_profile`.`end_date`) >= ?) AND (`shift_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`shift_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite})))))", [$today, $today]);
    }

    private function applyTimesheetIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $directSite = "COALESCE({$row}.`shift_site_id`, {$row}.`site_id`)";
        $shiftSite = "(SELECT COALESCE(`ts_shift`.`site_id`, `ts_shift_client`.`site_id`) FROM `shifts` AS `ts_shift` LEFT JOIN `clients` AS `ts_shift_client` ON `ts_shift_client`.`id` = `ts_shift`.`client_id` WHERE `ts_shift`.`id` = {$row}.`shift_id` LIMIT 1)";
        $clientSite = "(SELECT `site_id` FROM `clients` WHERE `clients`.`id` = {$row}.`client_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$directSite}, {$shiftSite}, {$clientSite})";

        return $query
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `ts_user` WHERE `ts_user`.`id` = {$row}.`user_id`)")
            ->whereRaw("({$row}.`shift_site_id` IS NULL OR {$row}.`site_id` IS NULL OR {$row}.`shift_site_id` = {$row}.`site_id`)")
            ->whereRaw("{$authoritativeSite} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `ts_site` WHERE `ts_site`.`id` = {$authoritativeSite})")
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `ts_client` WHERE `ts_client`.`id` = {$row}.`client_id` AND `ts_client`.`site_id` = {$authoritativeSite}))")
            ->whereRaw("({$row}.`shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `ts_linked_shift` LEFT JOIN `clients` AS `ts_linked_client` ON `ts_linked_client`.`id` = `ts_linked_shift`.`client_id` WHERE `ts_linked_shift`.`id` = {$row}.`shift_id` AND `ts_linked_shift`.`user_id` = {$row}.`user_id` AND (`ts_linked_shift`.`client_id` <=> {$row}.`client_id`) AND (`ts_linked_shift`.`client_id` IS NULL OR (`ts_linked_client`.`site_id` IS NOT NULL AND (`ts_linked_shift`.`site_id` IS NULL OR `ts_linked_shift`.`site_id` = `ts_linked_client`.`site_id`))) AND COALESCE(`ts_linked_shift`.`site_id`, `ts_linked_client`.`site_id`) = {$authoritativeSite}))");
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
            ->whereNotNull($query->qualifyColumn('outgoing_shift_id'))
            ->whereNotNull($query->qualifyColumn('client_id'))
            ->whereNotNull($query->qualifyColumn('outgoing_staff_id'))
            ->whereRaw("{$authoritativeSite} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `ho_site` WHERE `ho_site`.`id` = {$authoritativeSite})")
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `ho_client` WHERE `ho_client`.`id` = {$row}.`client_id` AND `ho_client`.`site_id` = {$authoritativeSite}))")
            ->whereRaw("({$row}.`outgoing_staff_id` IS NULL OR EXISTS (SELECT 1 FROM `users` AS `ho_out_user` WHERE `ho_out_user`.`id` = {$row}.`outgoing_staff_id`))")
            ->whereRaw("({$row}.`incoming_staff_id` IS NULL OR EXISTS (SELECT 1 FROM `users` AS `ho_in_user` WHERE `ho_in_user`.`id` = {$row}.`incoming_staff_id`))")
            ->whereRaw("({$row}.`outgoing_shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `ho_out_shift` LEFT JOIN `clients` AS `ho_out_shift_client` ON `ho_out_shift_client`.`id` = `ho_out_shift`.`client_id` WHERE `ho_out_shift`.`id` = {$row}.`outgoing_shift_id` AND (`ho_out_shift`.`user_id` <=> {$row}.`outgoing_staff_id`) AND (`ho_out_shift`.`client_id` <=> {$row}.`client_id`) AND (`ho_out_shift`.`client_id` IS NULL OR (`ho_out_shift_client`.`site_id` IS NOT NULL AND (`ho_out_shift`.`site_id` IS NULL OR `ho_out_shift`.`site_id` = `ho_out_shift_client`.`site_id`))) AND COALESCE(`ho_out_shift`.`site_id`, `ho_out_shift_client`.`site_id`) = {$authoritativeSite}))")
            ->whereRaw("({$row}.`incoming_shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `ho_in_shift` LEFT JOIN `clients` AS `ho_in_shift_client` ON `ho_in_shift_client`.`id` = `ho_in_shift`.`client_id` WHERE `ho_in_shift`.`id` = {$row}.`incoming_shift_id` AND (`ho_in_shift`.`user_id` <=> {$row}.`incoming_staff_id`) AND (`ho_in_shift`.`client_id` <=> {$row}.`client_id`) AND (`ho_in_shift`.`client_id` IS NULL OR (`ho_in_shift_client`.`site_id` IS NOT NULL AND (`ho_in_shift`.`site_id` IS NULL OR `ho_in_shift`.`site_id` = `ho_in_shift_client`.`site_id`))) AND COALESCE(`ho_in_shift`.`site_id`, `ho_in_shift_client`.`site_id`) = {$authoritativeSite}))");
    }

    private function applyFleetHandoverIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $authoritativeSite = "(SELECT COALESCE(`fleet_asset`.`site_id`, `fleet_asset`.`home_site_id`) FROM `assets` AS `fleet_asset` WHERE `fleet_asset`.`id` = {$row}.`asset_id` LIMIT 1)";
        $today = now()->toDateString();

        return $query
            ->whereNotNull($query->qualifyColumn('outgoing_user_id'))
            ->whereNotNull($query->qualifyColumn('incoming_user_id'))
            ->whereRaw("EXISTS (SELECT 1 FROM `assets` AS `fleet_asset_row` JOIN `sites` AS `fleet_site` ON `fleet_site`.`id` = COALESCE(`fleet_asset_row`.`site_id`, `fleet_asset_row`.`home_site_id`) WHERE `fleet_asset_row`.`id` = {$row}.`asset_id`)")
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `fleet_outgoing` JOIN `hr_employee_profiles` AS `fleet_outgoing_profile` ON `fleet_outgoing_profile`.`user_id` = `fleet_outgoing`.`id` AND `fleet_outgoing_profile`.`deleted_at` IS NULL WHERE `fleet_outgoing`.`id` = {$row}.`outgoing_user_id` AND `fleet_outgoing`.`approved_at` IS NOT NULL AND `fleet_outgoing`.`role` NOT IN ('client', 'next_of_kin') AND NOT EXISTS (SELECT 1 FROM `role_user` JOIN `roles` ON `roles`.`id` = `role_user`.`role_id` WHERE `role_user`.`user_id` = `fleet_outgoing`.`id` AND `roles`.`name` IN ('client', 'next_of_kin')) AND `fleet_outgoing_profile`.`is_active` = 1 AND (`fleet_outgoing_profile`.`start_date` IS NULL OR DATE(`fleet_outgoing_profile`.`start_date`) <= ?) AND (`fleet_outgoing_profile`.`end_date` IS NULL OR DATE(`fleet_outgoing_profile`.`end_date`) >= ?) AND (`fleet_outgoing_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`fleet_outgoing_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite}))))", [$today, $today])
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `fleet_incoming` JOIN `hr_employee_profiles` AS `fleet_incoming_profile` ON `fleet_incoming_profile`.`user_id` = `fleet_incoming`.`id` AND `fleet_incoming_profile`.`deleted_at` IS NULL WHERE `fleet_incoming`.`id` = {$row}.`incoming_user_id` AND `fleet_incoming`.`approved_at` IS NOT NULL AND `fleet_incoming`.`role` NOT IN ('client', 'next_of_kin') AND NOT EXISTS (SELECT 1 FROM `role_user` JOIN `roles` ON `roles`.`id` = `role_user`.`role_id` WHERE `role_user`.`user_id` = `fleet_incoming`.`id` AND `roles`.`name` IN ('client', 'next_of_kin')) AND `fleet_incoming_profile`.`is_active` = 1 AND (`fleet_incoming_profile`.`start_date` IS NULL OR DATE(`fleet_incoming_profile`.`start_date`) <= ?) AND (`fleet_incoming_profile`.`end_date` IS NULL OR DATE(`fleet_incoming_profile`.`end_date`) >= ?) AND (`fleet_incoming_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`fleet_incoming_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite}))))", [$today, $today]);
    }

    private function assertTimesheetIntrinsicIntegrity(Timesheet $timesheet, ?string $message): int
    {
        $canonicalTimesheet = Timesheet::query()->with([
            'staff:id',
            'client:id,site_id',
            'shift:id,site_id,client_id,user_id',
            'shift.client:id,site_id',
            'shift.staff:id',
        ])->find($timesheet->getKey());
        abort_unless($canonicalTimesheet, 403, $message ?? self::DEFAULT_MESSAGE);
        $timesheet = $canonicalTimesheet;
        abort_unless($timesheet->staff, 403, $message ?? self::DEFAULT_MESSAGE);

        $siteIds = collect([$timesheet->shift_site_id, $timesheet->site_id]);
        if ($timesheet->client_id !== null) {
            abort_unless(
                $timesheet->client
                    && $this->nullablePositiveId($timesheet->client->site_id) !== null,
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
            $siteIds->push($timesheet->client->site_id);
        }

        if ($timesheet->shift_id !== null) {
            abort_unless(
                $timesheet->shift
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
            Site::query()->whereKey($siteId)->exists(),
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

        return $siteId;
    }

    private function assertHandoverIntrinsicIntegrity(ShiftHandover $handover, ?string $message): int
    {
        $canonicalHandover = ShiftHandover::query()->with([
            'client:id,site_id',
            'outgoingStaff:id',
            'incomingStaff:id',
            'outgoingShift:id,site_id,client_id,user_id',
            'outgoingShift.client:id,site_id',
            'outgoingShift.staff:id',
            'incomingShift:id,site_id,client_id,user_id',
            'incomingShift.client:id,site_id',
            'incomingShift.staff:id',
        ])->find($handover->getKey());
        abort_unless($canonicalHandover, 403, $message ?? self::DEFAULT_MESSAGE);
        $handover = $canonicalHandover;
        $siteIds = collect();

        abort_unless(
            $handover->client_id !== null
                && $handover->client
                && $this->nullablePositiveId($handover->client->site_id) !== null,
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );
        $siteIds->push($handover->client->site_id);

        abort_unless(
            $handover->outgoing_shift_id !== null
                && $handover->outgoingShift
                && $handover->outgoing_staff_id !== null
                && $handover->outgoingStaff,
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

        foreach ([
            [$handover->outgoing_shift_id, $handover->outgoingShift, $handover->outgoing_staff_id, $handover->outgoingStaff],
            [$handover->incoming_shift_id, $handover->incomingShift, $handover->incoming_staff_id, $handover->incomingStaff],
        ] as [$shiftId, $shift, $staffId, $staff]) {
            if ($staffId !== null) {
                abort_unless($staff, 403, $message ?? self::DEFAULT_MESSAGE);
            }
            if ($shiftId === null) {
                continue;
            }
            abort_unless(
                $shift
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
            Site::query()->whereKey($siteId)->exists(),
            403,
            $message ?? self::DEFAULT_MESSAGE,
        );

        return $siteId;
    }

    private function assertIntrinsicShiftRelations(Shift $shift, ?string $message): void
    {
        abort_if($shift->user_id !== null && ! $shift->staff, 403, $message ?? self::DEFAULT_MESSAGE);
        if ($shift->client_id !== null) {
            abort_unless(
                $shift->client
                    && $this->nullablePositiveId($shift->client->site_id) !== null
                    && ($shift->site_id === null || (int) $shift->site_id === (int) $shift->client->site_id),
                403,
                $message ?? self::DEFAULT_MESSAGE,
            );
        }
        $siteId = $this->shiftSiteId($shift);
        abort_if($siteId === null, 403, $message ?? self::DEFAULT_MESSAGE);
        abort_unless(Site::query()->whereKey($siteId)->exists(), 403, $message ?? self::DEFAULT_MESSAGE);

        if ($shift->user_id !== null) {
            $workerQuery = User::query()->whereKey($shift->user_id);
            $this->applyFleetRecipientEligibility($workerQuery, $siteId);
            abort_unless($workerQuery->exists(), 403, $message ?? self::DEFAULT_MESSAGE);
        }
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

        if ($query->getConnection()->getDriverName() === 'sqlite') {
            $siteValues = collect($this->alertContextSitePaths())
                ->map(fn (string $jsonPath) => sprintf(
                    "NULLIF(NULLIF(NULLIF(NULLIF(CAST(json_extract(%s, '%s') AS TEXT), ''), '0'), 'null'), 'false')",
                    $contextColumn,
                    $jsonPath,
                ))
                ->implode(', ');

            return sprintf('CAST(COALESCE(%s) AS INTEGER)', $siteValues);
        }

        $siteValues = collect($this->alertContextSitePaths())
            ->map(fn (string $jsonPath) => sprintf(
                "NULLIF(NULLIF(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(%s, '%s')), ''), '0'), 'null'), 'false')",
                $contextColumn,
                $jsonPath,
            ))
            ->implode(', ');

        return sprintf('CAST(COALESCE(%s) AS UNSIGNED)', $siteValues);
    }

    private function applyCurrentEmployeeProfileScope(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where(function (Builder $dates) use ($today): void {
                $dates->whereNull($dates->qualifyColumn('start_date'))
                    ->orWhereDate($dates->qualifyColumn('start_date'), '<=', $today);
            })
            ->where(function (Builder $dates) use ($today): void {
                $dates->whereNull($dates->qualifyColumn('end_date'))
                    ->orWhereDate($dates->qualifyColumn('end_date'), '>=', $today);
            });
    }

    private function isCurrentEmployeeProfile(HrEmployeeProfile $profile): bool
    {
        $today = now()->startOfDay();

        return ! $profile->trashed()
            && (bool) $profile->is_active
            && ($profile->start_date === null || $profile->start_date->copy()->startOfDay()->lessThanOrEqualTo($today))
            && ($profile->end_date === null || $profile->end_date->copy()->startOfDay()->greaterThanOrEqualTo($today));
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
