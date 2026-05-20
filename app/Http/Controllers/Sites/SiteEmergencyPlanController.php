<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteTypePlanPin;
use App\Services\Sites\SiteEmergencyPlanService;
use App\Services\Sites\SiteTypePlanPdfService;
use App\Services\Sites\SiteTypePlanPinPayloadValidator;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SiteEmergencyPlanController extends Controller
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
        private readonly SiteEmergencyPlanService $emergencyPlans,
        private readonly SiteTypePlanPdfService $pdfs,
        private readonly SiteTypePlanPinPayloadValidator $pinValidator,
    ) {}

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $plan = $this->plans->currentPublished($site);
        abort_unless($plan, 404);

        return Inertia::render('sites/emergency-plan/index', $this->emergencyPlans->viewModel($site, $plan) + [
            'typePlan' => $this->plans->summaryFor($site),
            'can' => [
                'update' => (bool) ($request->user()?->can('update', $site)),
            ],
        ]);
    }

    public function update(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $plan = $this->plans->currentPublished($site);
        abort_unless($plan, 404);

        $data = $this->pinValidator->validateBatch($request, $site, false);

        $draft = $this->plans->draftForEmergencyPins($site, $request->user()?->id);
        $pins = $this->plans->replaceEmergencyPins($draft, $data['pins']);

        return response()->json([
            'pins' => $pins->map(fn (SiteTypePlanPin $pin) => $this->plans->serializePin($pin))->values()->all(),
            'ready' => $this->emergencyPlans->readyToExport($draft->fresh(['pins'])),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function download(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $plan = $this->plans->currentPublished($site);
        abort_unless($plan, 404);

        return $this->pdfs->download($site, $plan, (string) $request->query('paper', data_get($plan->layout, 'export.paper', 'a4')));
    }
}
