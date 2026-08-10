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

        $currentHeadcount = $this->forecastService->getCurrentHeadcount();
        $forecast = $this->forecastService->getForecast(12);
        $budgetVsActual = $this->forecastService->getBudgetVsActual();
        $attritionRisk = $this->forecastService->getAttritionRisk();

        return Inertia::render('hr/headcount/index', [
            // The page reads `current` — keep this key aligned (mismatch crashed
            // the page: Object.entries(undefined.by_department)).
            'current' => $currentHeadcount,
            'forecast' => $forecast,
            'budgetVsActual' => $budgetVsActual,
            'attritionRisk' => $attritionRisk,
            'can' => [
                // Gates the "Create requisition" seam into the Recruitment hub
                // (the /hr/recruitment routes sit behind hr.recruitment.view).
                'view_recruitment' => $user->canDo('hr.recruitment.view'),
            ],
        ]);
    }
}
