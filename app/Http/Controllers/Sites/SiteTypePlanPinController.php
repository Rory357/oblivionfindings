<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteTypePlanPin;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteTypePlanPinController extends Controller
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
    ) {}

    public function storeBatch(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $this->validatePins($request);
        $plan = $this->plans->currentDraft($site)
            ?? $this->plans->storeDraft($site, $this->plans->seedDefaultLayout($site->type), null, $request->user()?->id);

        $pins = $this->plans->replacePins($plan, $data['pins'], (bool) ($data['replace'] ?? false));

        return response()->json([
            'pins' => $pins->map(fn (SiteTypePlanPin $pin) => $this->plans->serializePin($pin))->values()->all(),
            'typePlan' => $this->plans->summaryFor($site->fresh()),
        ]);
    }

    public function update(Request $request, Site $site, SiteTypePlanPin $pin)
    {
        $this->authorize('update', $site);
        $this->guardPin($site, $pin);

        $data = $this->validateSinglePin($request);
        $pin->update($data);

        return response()->json([
            'pin' => $this->plans->serializePin($pin->fresh()),
        ]);
    }

    public function destroy(Request $request, Site $site, SiteTypePlanPin $pin)
    {
        $this->authorize('update', $site);
        $this->guardPin($site, $pin);

        $pin->delete();

        return response()->json(['deleted' => true]);
    }

    private function validatePins(Request $request): array
    {
        return $request->validate([
            'replace' => ['nullable', 'boolean'],
            'pins' => ['required', 'array'],
            'pins.*.id' => ['nullable', 'integer'],
            'pins.*.kind' => ['required', 'string', Rule::in(SiteTypePlanPin::KINDS)],
            'pins.*.subkind' => ['nullable', 'string', 'max:64'],
            'pins.*.device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'pins.*.room_ref_type' => ['nullable', 'string', 'max:64'],
            'pins.*.room_ref_id' => ['nullable', 'integer'],
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
    }

    private function validateSinglePin(Request $request): array
    {
        return $request->validate([
            'kind' => ['sometimes', 'required', 'string', Rule::in(SiteTypePlanPin::KINDS)],
            'subkind' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'room_ref_type' => ['nullable', 'string', 'max:64'],
            'room_ref_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'meta' => ['nullable', 'array'],
            'x' => ['sometimes', 'required', 'numeric', 'between:0,1'],
            'y' => ['sometimes', 'required', 'numeric', 'between:0,1'],
            'rotation_deg' => ['nullable', 'integer', 'between:-360,360'],
            'width' => ['nullable', 'numeric', 'between:0,1'],
            'height' => ['nullable', 'numeric', 'between:0,1'],
            'path_points' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    private function guardPin(Site $site, SiteTypePlanPin $pin): void
    {
        $pin->loadMissing('plan');
        abort_unless((int) $pin->plan->site_id === (int) $site->id, 404);
    }
}

