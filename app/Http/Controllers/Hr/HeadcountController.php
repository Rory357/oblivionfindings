<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Services\HeadcountForecastService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HeadcountController extends Controller
{
    public function __construct(
        private readonly HeadcountForecastService $forecastService,
    ) {}

    /**
     * Headcount planning dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.analytics.view'), 403);

        $tenantId = null;

        $currentHeadcount = $this->forecastService->getCurrentHeadcount($tenantId);
        $forecast = $this->forecastService->getForecast($tenantId, 12);
        $budgetVsActual = $this->forecastService->getBudgetVsActual($tenantId);
        $attritionRisk = $this->forecastService->getAttritionRisk($tenantId);

        return Inertia::render('hr/headcount/index', [
            'currentHeadcount' => $currentHeadcount,
            'forecast' => $forecast,
            'budgetVsActual' => $budgetVsActual,
            'attritionRisk' => $attritionRisk,
        ]);
    }
}
