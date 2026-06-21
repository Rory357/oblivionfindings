<?php

namespace App\Services\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\SubstanceStorageLocation;

/**
 * Provides H&S summary data for module context pages
 * (site detail, client profile, fleet vehicle, asset detail).
 *
 * All methods are read-only lookups designed for embedding in
 * existing module pages without modifying those pages' core data flow.
 */
class HsModuleSummaryService
{
    /** Maximum recent events returned in each summary. */
    private const RECENT_EVENTS_LIMIT = 5;

    /** Columns selected for recent event lists (keep payload small). */
    private const RECENT_EVENT_COLUMNS = ['id', 'reference_number', 'event_category', 'severity', 'status', 'reported_at'];

    /* ------------------------------------------------------------------ */
    /*  Site context                                                        */
    /* ------------------------------------------------------------------ */

    public function forSite(int $siteId): array
    {
        return [
            'open_events' => HsEvent::forSite($siteId)->open()->count(),
            'open_events_high_critical' => HsEvent::forSite($siteId)->open()->highOrCritical()->count(),
            'open_corrective_actions' => HsCorrectiveAction::open()
                ->whereHas('hsEvent', fn ($q) => $q->where('site_id', $siteId))
                ->count(),
            'overdue_corrective_actions' => HsCorrectiveAction::overdue()
                ->whereHas('hsEvent', fn ($q) => $q->where('site_id', $siteId))
                ->count(),
            'active_risk_assessments' => HsRiskAssessment::active()
                ->forAssessable('App\\Models\\Site', $siteId)
                ->count(),
            'high_extreme_risks' => HsRiskAssessment::active()
                ->forAssessable('App\\Models\\Site', $siteId)
                ->highOrExtreme()
                ->count(),
            'risk_assessments_due_review' => HsRiskAssessment::dueForReview()
                ->forAssessable('App\\Models\\Site', $siteId)
                ->count(),
            'recent_events' => HsEvent::forSite($siteId)
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
    /*  Client context                                                     */
    /* ------------------------------------------------------------------ */

    public function forClient(int $clientId): array
    {
        return [
            'open_events' => HsEvent::forClient($clientId)->open()->count(),
            'open_events_high_critical' => HsEvent::forClient($clientId)->open()->highOrCritical()->count(),
            'total_events' => HsEvent::forClient($clientId)->count(),
            'open_corrective_actions' => HsCorrectiveAction::open()
                ->whereHas('hsEvent', fn ($q) => $q->where('client_id', $clientId))
                ->count(),
            'active_risk_assessments' => HsRiskAssessment::active()
                ->forAssessable('App\\Models\\Client', $clientId)
                ->count(),
            'recent_events' => HsEvent::forClient($clientId)
                ->orderByDesc('reported_at')
                ->limit(self::RECENT_EVENTS_LIMIT)
                ->get(self::RECENT_EVENT_COLUMNS)
                ->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Fleet / Asset context                                              */
    /* ------------------------------------------------------------------ */

    public function forAsset(int $assetId): array
    {
        return [
            'open_events' => HsEvent::where('asset_id', $assetId)->open()->count(),
            'total_events' => HsEvent::where('asset_id', $assetId)->count(),
            'open_corrective_actions' => HsCorrectiveAction::open()
                ->whereHas('hsEvent', fn ($q) => $q->where('asset_id', $assetId))
                ->count(),
            'active_risk_assessments' => HsRiskAssessment::active()
                ->forAssessable('App\\Models\\Asset', $assetId)
                ->count(),
            'recent_events' => HsEvent::where('asset_id', $assetId)
                ->orderByDesc('reported_at')
                ->limit(self::RECENT_EVENTS_LIMIT)
                ->get(self::RECENT_EVENT_COLUMNS)
                ->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Staff context                                                       */
    /* ------------------------------------------------------------------ */

    public function forStaff(int $userId): array
    {
        return [
            'involved_events' => HsEvent::where('staff_id', $userId)->count(),
            'open_events' => HsEvent::where('staff_id', $userId)->open()->count(),
            'assigned_corrective_actions' => HsCorrectiveAction::forAssignee($userId)->open()->count(),
            'overdue_corrective_actions' => HsCorrectiveAction::forAssignee($userId)->overdue()->count(),
            'led_investigations' => \App\Models\HsInvestigation::forInvestigator($userId)->count(),
            'active_investigations' => \App\Models\HsInvestigation::forInvestigator($userId)->active()->count(),
        ];
    }
}
