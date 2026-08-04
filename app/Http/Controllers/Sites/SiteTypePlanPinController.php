<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteTypePlanPin;
use App\Services\Sites\SiteTypePlanPinPayloadValidator;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;

class SiteTypePlanPinController extends Controller
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
        private readonly SiteTypePlanPinPayloadValidator $pinValidator,
    ) {}

    public function storeBatch(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $this->pinValidator->validateBatch($request, $site);

        if (($data['mode'] ?? 'full') === 'emergency') {
            $plan = $this->plans->draftForEmergencyPins($site, $request->user()?->id);
            $pins = $this->plans->replaceEmergencyPins($plan, $data['pins']);
        } else {
            $plan = $this->plans->currentDraft($site)
                ?? $this->plans->storeDraft($site, $this->plans->seedDefaultLayout($site->type), null, $request->user()?->id);

            $pins = $this->plans->replacePins($plan, $data['pins'], (bool) ($data['replace'] ?? false));
        }

        return response()->json([
            'pins' => $pins->map(fn (SiteTypePlanPin $pin) => $this->plans->serializePin($pin))->values()->all(),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function update(Request $request, Site $site, SiteTypePlanPin $pin)
    {
        $this->authorize('update', $site);
        $this->guardPin($site, $pin);

        $data = $this->pinValidator->validateSingle($request, $site, $pin);
        $pin = $this->plans->updatePin($site, $pin, $data);

        return response()->json([
            'pin' => $this->plans->serializePin($pin),
        ]);
    }

    public function destroy(Request $request, Site $site, SiteTypePlanPin $pin)
    {
        $this->authorize('update', $site);
        $this->guardPin($site, $pin);

        $this->plans->deletePin($site, $pin);

        return response()->json(['deleted' => true]);
    }

    private function guardPin(Site $site, SiteTypePlanPin $pin): void
    {
        $pin->loadMissing('plan');
        abort_unless((int) $pin->plan->site_id === (int) $site->id, 404);
    }
}
