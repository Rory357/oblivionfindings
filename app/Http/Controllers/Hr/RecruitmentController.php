<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Services\RecruitmentAnalyticsService;
use App\Domain\Hr\Services\RecruitmentService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecruitmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = null;

        $candidates = HrCandidate::with('applications')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Group by status for pipeline view
        $pipeline = HrCandidate::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return Inertia::render('hr/recruitment/index', [
            'candidates' => $candidates,
            'pipeline' => $pipeline,
            'stages' => RecruitmentService::STAGES,
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    /**
     * Kanban board view of recruitment pipeline.
     */
    public function kanban(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = null;

        $kanbanStages = ['new', 'screening', 'interview_scheduled', 'interview_completed', 'reference_check', 'offer_pending', 'offer_sent', 'offer_accepted', 'hired', 'withdrawn', 'rejected'];

        $candidates = HrCandidate::with(['applications' => function ($q) {
            $q->select('id', 'candidate_id', 'position_title', 'status');
        }])
            ->whereIn('status', $kanbanStages)
            ->orderByDesc('current_stage_entered_at')
            ->get();

        $columns = [];
        foreach ($kanbanStages as $stage) {
            $columns[$stage] = $candidates
                ->where('status', $stage)
                ->map(function ($candidate) {
                    $daysInStage = $candidate->current_stage_entered_at
                        ? (int) $candidate->current_stage_entered_at->diffInDays(now())
                        : 0;

                    return [
                        'id' => $candidate->id,
                        'name' => $candidate->full_name,
                        'position' => $candidate->applications->first()?->position_title ?? 'No position',
                        'days_in_stage' => $daysInStage,
                        'source' => $candidate->source,
                        'created_at' => $candidate->created_at->toISOString(),
                    ];
                })
                ->values()
                ->toArray();
        }

        return Inertia::render('hr/recruitment/kanban', [
            'columns' => $columns,
            'stages' => $kanbanStages,
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    /**
     * Recruitment analytics dashboard.
     */
    public function analytics(Request $request, RecruitmentAnalyticsService $analyticsService)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = null;

        return Inertia::render('hr/recruitment/analytics', [
            'timeToHire' => $analyticsService->getTimeToHire($tenantId),
            'sourceEffectiveness' => $analyticsService->getSourceEffectiveness($tenantId),
            'pipelineConversion' => $analyticsService->getPipelineConversion($tenantId),
            'openPositions' => $analyticsService->getOpenPositionsSummary($tenantId),
        ]);
    }
}
