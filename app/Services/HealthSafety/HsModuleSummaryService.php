<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;

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
