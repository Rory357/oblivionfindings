<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class IncidentRoutingSettingsController extends Controller
{
    public function __construct(private readonly IncidentRoutingSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['routing_page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        return response()->json($this->settings->editor($request->user(), (int) ($input['routing_page'] ?? 1))
            + ['routing_viewer_id' => (int) $request->user()->id])->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $request): JsonResponse
    {
        $input = $request->validate([
            'site_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'scope' => ['sometimes', 'in:ordinary,restricted,triage'],
            'search' => ['nullable', 'string', 'max:100'],
            'selected_id' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ]);
        return response()->json([
            'candidates' => $this->settings->candidates($request->user(), (int) $input['site_id'],
                $input['scope'] ?? 'ordinary', $input['search'] ?? '', isset($input['selected_id']) ? (int) $input['selected_id'] : null),
            'viewer_id' => (int) $request->user()->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        // Preserve the service's closed, actor-bound command contract and atomic audit.
        $receipt = $this->settings->save($request->user(), $request->all());
        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['receipt' => $receipt])->header('Cache-Control', 'private, no-store')
            : redirect()->route('health-safety.settings.index')
                ->with('health_safety_routing_request_uuid', $receipt['request_uuid']);
    }
}
