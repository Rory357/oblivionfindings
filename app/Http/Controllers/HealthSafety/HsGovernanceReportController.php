<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\HealthSafety\HsComplianceExportService;
use App\Services\HealthSafety\HsGovernanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Governance-facing H&S report endpoints.
 *
 * These produce structured JSON outputs suitable for:
 *  - Board pack generation
 *  - Audit evidence
 *  - WorkSafe compliance documentation
 *  - Committee meeting pre-reads
 *
 * All endpoints are read-only. No mutations.
 */
class HsGovernanceReportController extends Controller
{
    public function __construct(
        private readonly HsGovernanceService $governanceService,
        private readonly HsComplianceExportService $complianceService,
    ) {}

    /**
     * Board-level H&S summary — for board packs and CEO reports.
     */
    public function boardSummary(Request $request): JsonResponse
    {
        $siteId = $this->requestedSiteId($request);
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        return response()->json(
            $this->governanceService->getBoardSummary($from, $to, $request->user(), $siteId)
        );
    }

    /**
     * WorkSafe notifiable events register.
     */
    public function worksafeRegister(Request $request): JsonResponse
    {
        $siteId = $this->requestedSiteId($request);
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        return response()->json(
            $this->complianceService->worksafeRegister($from, $to, $request->user(), $siteId)
        );
    }

    /**
     * Investigation outcomes summary.
     */
    public function investigationOutcomes(Request $request): JsonResponse
    {
        $siteId = $this->requestedSiteId($request);
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        return response()->json(
            $this->complianceService->investigationOutcomes($from, $to, $request->user(), $siteId)
        );
    }

    /**
     * Corrective action traceability report.
     */
    public function correctiveActionTraceability(Request $request): JsonResponse
    {
        $siteId = $this->requestedSiteId($request);
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        return response()->json(
            $this->complianceService->correctiveActionTraceability(
                $request->input('status'),
                $from,
                $to,
                $request->user(),
                $siteId,
            )
        );
    }

    /**
     * Risk assessment register.
     */
    public function riskAssessmentRegister(Request $request): JsonResponse
    {
        $siteId = $this->requestedSiteId($request);

        $filters = $request->validate([
            'tab' => ['sometimes', 'in:all,active,drafts,due,high,closed'],
            'status' => ['nullable', 'in:draft,active,under_review,superseded,archived'],
            'risk_level' => ['nullable', 'in:low,medium,high,extreme'],
            'due_for_review' => ['nullable', 'in:true,false'],
            'risk_acceptable' => ['nullable', 'in:0,1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'hs_event_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(
            $this->complianceService->riskAssessmentRegister($request->user(), $siteId, $filters)
        );
    }

    private function requestedSiteId(Request $request): ?int
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        return isset($validated['site_id']) ? (int) $validated['site_id'] : null;
    }
}
