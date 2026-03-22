<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Services\WorkforceAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnalyticsDashboardController extends Controller
{
    public function __construct(
        private readonly WorkforceAnalyticsService $analyticsService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — workforce analytics dashboard                              */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.analytics.view'), 403);

        $tenantId = null;

        $headcountTrend = $this->analyticsService->getHeadcountTrend($tenantId, 12);
        $turnoverRate = $this->analyticsService->getTurnoverRate($tenantId, 'year');
        $tenureBrackets = $this->analyticsService->getTenureBrackets($tenantId);
        $complianceScore = $this->analyticsService->getComplianceScore($tenantId);
        $leaveUtilization = $this->analyticsService->getLeaveUtilization($tenantId);
        $departmentBreakdown = $this->analyticsService->getDepartmentBreakdown($tenantId);

        $currentHeadcount = ! empty($headcountTrend)
            ? $headcountTrend[count($headcountTrend) - 1]['count']
            : 0;

        $avgTenure = $this->calculateAverageTenure($tenureBrackets);

        return Inertia::render('hr/analytics/index', [
            'headcountTrend' => $headcountTrend,
            'currentHeadcount' => $currentHeadcount,
            'turnoverRate' => $turnoverRate,
            'tenureBrackets' => $tenureBrackets,
            'avgTenure' => $avgTenure,
            'complianceScore' => $complianceScore,
            'leaveUtilization' => $leaveUtilization,
            'departmentBreakdown' => $departmentBreakdown,
        ]);
    }

    /**
     * Estimate average tenure from bracket data.
     */
    private function calculateAverageTenure(array $brackets): string
    {
        $midpoints = [
            '< 1 year' => 0.5,
            '1-2 years' => 1.5,
            '2-5 years' => 3.5,
            '5-10 years' => 7.5,
            '10+ years' => 12,
        ];

        $total = 0;
        $count = 0;

        foreach ($brackets as $bracket) {
            $mid = $midpoints[$bracket['bracket']] ?? 3;
            $total += $mid * $bracket['count'];
            $count += $bracket['count'];
        }

        if ($count === 0) {
            return '0 years';
        }

        $avg = round($total / $count, 1);

        return $avg . ' years';
    }
}
