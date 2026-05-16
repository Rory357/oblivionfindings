<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteTypePlanPin;
use App\Services\Sites\SiteEmergencyPlanService;
use App\Services\Sites\SiteTypePlanPdfService;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SiteEmergencyPlanController extends Controller
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
        private readonly SiteEmergencyPlanService $emergencyPlans,
        private readonly SiteTypePlanPdfService $pdfs,
    ) {}

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $plan = $this->plans->currentPublished($site);
        abort_unless($plan, 404);

        return Inertia::render('sites/emergency-plan/index', $this->emergencyPlans->viewModel($site, $plan) + [
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

        $data = $request->validate([
            'pins' => ['required', 'array'],
            'pins.*.kind' => ['required', 'string', Rule::in(SiteTypePlanPin::EMERGENCY_KINDS)],
            'pins.*.label' => ['nullable', 'string', 'max:120'],
            'pins.*.notes' => ['nullable', 'string', 'max:5000'],
            'pins.*.meta' => ['nullable', 'array'],
            'pins.*.x' => ['required', 'numeric', 'between:0,1'],
            'pins.*.y' => ['required', 'numeric', 'between:0,1'],
            'pins.*.rotation_deg' => ['nullable', 'integer', 'between:-360,360'],
            'pins.*.width' => ['nullable', 'numeric', 'between:0,1'],
            'pins.*.height' => ['nullable', 'numeric', 'between:0,1'],
            'pins.*.path_points' => ['nullable', 'array'],
            'pins.*.sort_order' => ['nullable', 'integer'],
        ]);

        $plan->pins()->whereIn('kind', SiteTypePlanPin::EMERGENCY_KINDS)->delete();
        $pins = $this->plans->replacePins($plan, $data['pins'], false);

        return response()->json([
            'pins' => $pins->map(fn (SiteTypePlanPin $pin) => $this->plans->serializePin($pin))->values()->all(),
            'ready' => $this->emergencyPlans->readyToExport($plan->fresh(['pins'])),
        ]);
    }

    public function download(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $plan = $this->plans->currentPublished($site);
        abort_unless($plan, 404);

        return $this->pdfs->download($site, $plan, (string) $request->query('paper', 'a4'));
    }
}

