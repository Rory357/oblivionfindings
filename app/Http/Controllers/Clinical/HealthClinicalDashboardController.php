<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HealthClinicalDashboardController extends Controller
{
    public function __construct(
        private readonly ClinicalDashboardService $dashboardService,
    ) {}

    public function index(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.dashboard'), 403);

        return inertia('health-clinical/index', [
            'kpis' => $this->dashboardService->getKpis(),
            'overdue_items' => $this->dashboardService->getOverdueItems(),
            'recent_events' => $this->dashboardService->getRecentEvents(),
            'recent_observations' => $this->dashboardService->getRecentObservations(),
        ]);
    }
}
