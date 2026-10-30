<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HsWorkspaceController extends Controller
{
    public function registers(Request $request, BehaviourSupportApprovalPolicySettings $settings): Response
    {
        return Inertia::render('health-safety/registers/index', [
            'can_manage_settings' => $settings->canManageSettings($request->user()),
        ]);
    }

    public function reports(Request $request, HsGovernanceReportController $reports): Response
    {
        $validated = $request->validate([
            'view' => ['nullable', 'in:board,notifications,investigation-outcomes,action-traceability,risk-register'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);
        $view = $validated['view'] ?? null;
        $report = null;
        if ($view !== null) {
            // A permitted directory link never substitutes for report authority.
            abort_unless($request->user()->canDo('governance.view'), 403);
            $method = match ($view) {
                'board' => 'boardSummary',
                'notifications' => 'worksafeRegister',
                'investigation-outcomes' => 'investigationOutcomes',
                'action-traceability' => 'correctiveActionTraceability',
                'risk-register' => 'riskAssessmentRegister',
            };
            $report = $reports->{$method}($request)->getData(true);
        }

        return Inertia::render('health-safety/reports/index', [
            'view' => $view,
            'report' => $report,
            'filters' => [
                'from' => $validated['from'] ?? '',
                'to' => $validated['to'] ?? '',
                'site_id' => $validated['site_id'] ?? null,
            ],
        ]);
    }
}
