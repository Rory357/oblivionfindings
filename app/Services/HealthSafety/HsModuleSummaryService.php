<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\HazardousSubstance;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Provides H&S summary data for module context pages
 * (site detail, client profile, fleet vehicle, asset detail).
 *
 * All methods are read-only lookups designed for embedding in
 * existing module pages without modifying those pages' core data flow.
 */
class HsModuleSummaryService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** Maximum recent events returned in each summary. */
    private const RECENT_EVENTS_LIMIT = 5;

    /** Columns selected for recent event lists (keep payload small). */
    private const RECENT_EVENT_COLUMNS = ['id', 'reference_number', 'event_category', 'severity', 'status', 'reported_at'];

    /* ------------------------------------------------------------------ */
    /*  Site context */
    /* ------------------------------------------------------------------ */

    public function forSite(int $siteId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->forSiteSnapshot($siteId, $viewer, $context));
    }

    private function forSiteSnapshot(int $siteId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return [
            'open_events' => $this->eventQuery($viewer, HsEvent::forSite($siteId), $context)->open()->count(),
            'open_events_high_critical' => $this->eventQuery($viewer, HsEvent::forSite($siteId), $context)->open()->highOrCritical()->count(),
            'open_corrective_actions' => $this->scopedCorrectiveActions($viewer, 'site_id', $siteId, false, $context)
                ->count(),
            'overdue_corrective_actions' => $this->scopedCorrectiveActions($viewer, 'site_id', $siteId, true, $context)
                ->count(),
            'active_risk_assessments' => $this->riskAssessmentsForSite($siteId, $viewer, $context)
                ->active()
                ->count(),
            'high_extreme_risks' => $this->riskAssessmentsForSite($siteId, $viewer, $context)
                ->active()
                ->highOrExtreme()
                ->count(),
            'risk_assessments_due_review' => $this->riskAssessmentsForSite($siteId, $viewer, $context)
                ->dueForReview()
                ->count(),
            'recent_events' => $this->eventQuery($viewer, HsEvent::forSite($siteId), $context)
                ->orderByDesc('reported_at')
                ->limit(self::RECENT_EVENTS_LIMIT)
                ->get(self::RECENT_EVENT_COLUMNS)
                ->toArray(),
        ];
    }

    /**
     * Hazardous substances stored at a site — the read-mostly "Chemicals stored
     * here" panel. The master record lives in the Chemical register; each row
     * deep-links back to it. Reuses the substance SDS-state signal.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function chemicalsStoredForSite(int $siteId): array
    {
        $locations = SubstanceStorageLocation::query()
            ->where('site_id', $siteId)
            ->with(['hazardousSubstance' => fn ($q) => $q->with('currentSds')])
            ->orderBy('location_description')
            ->get();

        $rows = $locations->map(function (SubstanceStorageLocation $loc) {
            $substance = $loc->hazardousSubstance;

            return [
                'id' => $loc->id,
                'substance' => $substance ? [
                    'id' => $substance->id,
                    'name' => $substance->name,
                    'common_name' => $substance->common_name,
                    'is_controlled_substance' => (bool) $substance->is_controlled_substance,
                ] : null,
                'location_description' => $loc->location_description,
                'current_quantity' => $loc->current_quantity !== null ? (float) $loc->current_quantity : null,
                'quantity_unit' => $loc->quantity_unit,
                'maximum_quantity' => $loc->maximum_quantity !== null ? (float) $loc->maximum_quantity : null,
                'container_type' => $loc->container_type,
                'properly_labelled' => (bool) $loc->properly_labelled,
                'segregation_compliant' => (bool) $loc->segregation_compliant,
                'last_audit_date' => optional($loc->last_audit_date)->toDateString(),
                'sds_state' => $substance?->sds_state ?? 'missing',
            ];
        })->values();

        return [
            'rows' => $rows->all(),
            'summary' => [
                'count' => $rows->count(),
                'controlled' => $rows->where('substance.is_controlled_substance', true)->count(),
                'sds_to_action' => $rows->whereIn('sds_state', ['expiring', 'expired', 'missing'])->count(),
                'segregation_gaps' => $rows->where('segregation_compliant', false)->count(),
            ],
            // Active substances for the "Add storage here" picker (master record in the register).
            'substances' => HazardousSubstance::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Client context */
    /* ------------------------------------------------------------------ */

    public function forClient(int $clientId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->forClientSnapshot($clientId, $viewer, $context));
    }

    private function forClientSnapshot(int $clientId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return [
            'open_events' => $this->eventQuery($viewer, HsEvent::forClient($clientId), $context)->open()->count(),
            'open_events_high_critical' => $this->eventQuery($viewer, HsEvent::forClient($clientId), $context)->open()->highOrCritical()->count(),
            'total_events' => $this->eventQuery($viewer, HsEvent::forClient($clientId), $context)->count(),
            'open_corrective_actions' => $this->scopedCorrectiveActions($viewer, 'client_id', $clientId, false, $context)
                ->count(),
            'active_risk_assessments' => $this->scopedRiskAssessments(HsRiskAssessment::active()->forAssessable(Client::class, $clientId), $viewer, $context)
                ->count(),
            'recent_events' => $this->eventQuery($viewer, HsEvent::forClient($clientId), $context)
                ->orderByDesc('reported_at')
                ->limit(self::RECENT_EVENTS_LIMIT)
                ->get(self::RECENT_EVENT_COLUMNS)
                ->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Fleet / Asset context */
    /* ------------------------------------------------------------------ */

    public function forAsset(int $assetId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->forAssetSnapshot($assetId, $viewer, $context));
    }

    private function forAssetSnapshot(int $assetId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return [
            'open_events' => $this->eventQuery($viewer, HsEvent::where('asset_id', $assetId), $context)->open()->count(),
            'total_events' => $this->eventQuery($viewer, HsEvent::where('asset_id', $assetId), $context)->count(),
            'open_corrective_actions' => $this->scopedCorrectiveActions($viewer, 'asset_id', $assetId, false, $context)
                ->count(),
            'active_risk_assessments' => $this->scopedRiskAssessments(HsRiskAssessment::active()->forAssessable('App\\Models\\Asset', $assetId), $viewer, $context)
                ->count(),
            'recent_events' => $this->eventQuery($viewer, HsEvent::where('asset_id', $assetId), $context)
                ->orderByDesc('reported_at')
                ->limit(self::RECENT_EVENTS_LIMIT)
                ->get(self::RECENT_EVENT_COLUMNS)
                ->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Staff context */
    /* ------------------------------------------------------------------ */

    public function forStaff(int $userId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->forStaffSnapshot($userId, $viewer, $context));
    }

    private function forStaffSnapshot(int $userId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return [
            'involved_events' => $this->eventQuery($viewer, HsEvent::where('staff_id', $userId), $context)->count(),
            'open_events' => $this->eventQuery($viewer, HsEvent::where('staff_id', $userId), $context)->open()->count(),
            'assigned_corrective_actions' => $this->scopedChildren(HsCorrectiveAction::forAssignee($userId)->open(), $viewer, $context)->count(),
            'overdue_corrective_actions' => $this->scopedChildren(HsCorrectiveAction::forAssignee($userId)->overdue(), $viewer, $context)->count(),
            'led_investigations' => $this->scopedChildren(HsInvestigation::forInvestigator($userId), $viewer, $context)->count(),
            'active_investigations' => $this->scopedChildren(HsInvestigation::forInvestigator($userId)->active(), $viewer, $context)->count(),
        ];
    }

    private function eventQuery(?User $viewer, Builder $query, ?HealthSafetyReadContext $context = null): Builder
    {
        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $viewer, $context));
    }

    private function riskAssessmentsForSite(int $siteId, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds(HsRiskAssessment::query(), [$siteId]);

        return $this->scopedRiskAssessments($query, $viewer, $context);
    }

    private function scopedChildren(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $column = $query->getModel()->qualifyColumn('hs_event_id');
        $children = $this->sourceProofQuery($query);
        $candidates = HsEvent::withTrashed()->whereIn('hs_events.id', $children->select($column));

        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidChildSourceScope($query, $column, $viewer, $context, $candidates));
    }

    private function scopedRiskAssessments(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $modern = $this->sourceProofQuery($query);
        $legacy = $this->sourceProofQuery($query)->whereIn('assessable_type', [HsEvent::class, (new HsEvent)->getMorphClass()]);
        $candidates = HsEvent::withTrashed()->where(fn (Builder $events) => $events
            ->whereIn('hs_events.id', $modern->select('hs_risk_assessments.hs_event_id'))
            ->orWhereIn('hs_events.id', $legacy->select('hs_risk_assessments.assessable_id')));

        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $viewer, $context, $candidates));
    }

    private function sourceProofQuery(Builder $query): Builder
    {
        $proof = (clone $query)->withoutEagerLoads()->reorder();
        $proof->getQuery()->limit = null;
        $proof->getQuery()->offset = null;

        return $proof;
    }

    private function scopedCorrectiveActions(?User $viewer, string $field, int $id, bool $overdue, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = $overdue ? HsCorrectiveAction::overdue() : HsCorrectiveAction::open();
        $query->whereHas('hsEvent', fn (Builder $event) => $event->where($field, $id));

        return $this->applyRelatedSourceScope($query, $viewer, $context);
    }

    /** Source proof uses an executable filtered child subquery, never the correlated relation builder. */
    private function applyRelatedSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $children = (clone $query)->withoutEagerLoads()->reorder();
        $children->getQuery()->limit = null;
        $children->getQuery()->offset = null;
        $candidates = HsEvent::query()->whereIn('hs_events.id', $children->select($query->getModel()->qualifyColumn('hs_event_id')));

        return $this->protectMaterialisation($query->whereHas('hsEvent', fn (Builder $event) => app(HsEventAccessService::class)->applyFirstAidSourceScope($event, $viewer, $context, $candidates)));
    }

    /** All source decisions and their final SQL share the caller's original prepared evidence. */
    private ?HealthSafetyReadMaterialisationProof $materialisationProof = null;

    private function protectMaterialisation(Builder $query): Builder
    {
        return $this->materialisationProof?->protect($query) ?? $query;
    }
    private function withSourceRead(?User $viewer, ?HealthSafetyReadContext $context, callable $consumer): mixed
    {
        $project = static fn (mixed $result): mixed => is_array($result) && ! array_is_list($result)
            ? [...$result, 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown'] : $result;
        if ($viewer === null) {
            abort_unless($context === null, 403);

            return DB::transaction(fn () => $project($consumer(null, null)));
        }
        $permissions = array_values(array_unique([...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS]));
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $permissions, $consumer, $project): mixed {
            $active->assertViewer($viewer);

            return $active->consumeSourceViewer($permissions,
                function (User $current) use ($active, $project, $consumer): mixed {
                    return HealthSafetyReadMaterialisationProof::within($active, function (HealthSafetyReadMaterialisationProof $proof) use ($current, $active, $project, $consumer): mixed {
                        $previous = $this->materialisationProof;
                        $this->materialisationProof = $proof;
                        try { return $project($consumer($current, $active)); }
                        finally { $this->materialisationProof = $previous; }
                    });
                });
        };

        return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, $permissions, $consume);
    }
}
