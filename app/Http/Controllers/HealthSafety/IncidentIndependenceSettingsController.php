<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\Incidents\IncidentIndependencePolicySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class IncidentIndependenceSettingsController extends Controller
{
    public function __construct(private readonly IncidentIndependencePolicySettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['independence_page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        return response()->json($this->settings->editor($request->user(), (int) ($input['independence_page'] ?? 1))
            + ['independence_viewer_id' => (int) $request->user()->id])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $receipt = $this->settings->save($request->user(), $request->all());
        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['receipt' => $receipt])->header('Cache-Control', 'private, no-store')
            : redirect()->route('health-safety.settings.index')
                ->with('health_safety_independence_request_uuid', $receipt['request_uuid']);
    }
}
