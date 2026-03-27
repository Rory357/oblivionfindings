<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\DashboardAggregatorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FinanceDashboardController extends Controller
{
    public function __construct(
        private DashboardAggregatorService $dashboardService,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $data = $this->dashboardService->getDashboardData($orgId);

        return Inertia::render('finance/Dashboard', $data);
    }
}
