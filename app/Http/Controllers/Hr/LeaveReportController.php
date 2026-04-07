<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Services\LeaveReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveReportController extends Controller
{
    use Concerns\ResolvesHrTenant;

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

        $tenantId = $this->resolveHrTenantIdForUser($user);
        abort_unless($tenantId, 403, 'Unable to determine tenant context for leave reports.');
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
