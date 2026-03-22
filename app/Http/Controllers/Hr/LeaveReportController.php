<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Services\LeaveReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveReportController extends Controller
{
    public function __construct(
        private LeaveReportService $reportService,
    ) {}

    /**
     * Display leave reports: absenteeism, Bradford Factor, utilisation.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = null;
        $year = (int) $request->query('year', now()->year);

        $absenteeism = $this->reportService->getAbsenteeismReport($tenantId, $year);
        $bradfordFactor = $this->reportService->getBradfordFactor($tenantId, $year);
        $utilization = $this->reportService->getLeaveUtilizationReport($tenantId, $year);

        return Inertia::render('hr/leave/reports', [
            'absenteeism' => $absenteeism,
            'bradfordFactor' => $bradfordFactor,
            'utilization' => $utilization,
            'year' => $year,
            'can' => [
                'manage' => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }
}
