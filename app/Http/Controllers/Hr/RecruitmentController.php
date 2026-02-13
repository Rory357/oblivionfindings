<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCandidate;
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
}
