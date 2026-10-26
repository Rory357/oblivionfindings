<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\UpdateBehaviourSupportApprovalPolicyRequest;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use App\Services\Incidents\IncidentRoutingSettings;
use App\Services\Incidents\IncidentIndependencePolicySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class HealthSafetySettingsController extends Controller
{
    public function __construct(
        private readonly BehaviourSupportApprovalPolicySettings $settings,
        private readonly IncidentRoutingSettings $routing,
        private readonly IncidentIndependencePolicySettings $independence,
        private readonly \App\Services\Incidents\IncidentEvidenceWindowPolicySettings $evidenceWindows,
    ) {}

    public function index(Request $request): Response
    {
        $this->settings->assertManager($request->user());
        $query = $request->validate([
            'window_policy_page' => ['sometimes', 'string', 'regex:/\A[1-9][0-9]{0,6}\z/', 'integer', 'max:1000000'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'routing_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'independence_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $props = $this->settings->editor($request->user(), (int) ($query['page'] ?? 1));
        $props['health_safety_policy_receipt'] = $this->settings->receiptForManager($request->user(), $request->session()->get('health_safety_policy_receipt'));
        $routing = $this->routing->editor($request->user(), (int) ($query['routing_page'] ?? 1));
        $props['routing_configuration'] = $routing['routing_configuration'];
        $props['routing_history'] = $routing['routing_history'];
        $props['routing_sites'] = $routing['sites'];
        $props['can_manage_routing'] = $routing['can_manage_routing'];
        $props['routing_viewer_id'] = (int) $request->user()->id;
        $uuid = $request->session()->get('health_safety_routing_request_uuid');
        $props['health_safety_routing_receipt'] = is_string($uuid)
            ? $this->routing->receiptForManager($request->user(), $uuid) : null;
        $independence = $this->independence->editor($request->user(), (int) ($query['independence_page'] ?? 1));
        $props['independence_policy'] = $independence['independence_policy'];
        $props['independence_history'] = $independence['independence_history'];
        $props['can_manage_independence'] = $independence['can_manage_independence'];
        $props['independence_viewer_id'] = (int) $request->user()->id;
        $independenceUuid = $request->session()->get('health_safety_independence_request_uuid');
        $props['health_safety_independence_receipt'] = is_string($independenceUuid)
            ? $this->independence->receiptForManager($request->user(), $independenceUuid) : null;

        $evidence = $this->evidenceWindows->editor($request->user(), (int) ($query['window_policy_page'] ?? 1));
        $props['evidence_window_policy'] = $evidence['policy'];
        $props['evidence_window_history'] = $evidence['history'];
        $props['evidence_window_options'] = $evidence['options'];
        $props['can_manage_evidence_windows'] = $evidence['can_manage'];
        $props['evidence_window_viewer_id'] = (int) $request->user()->id;
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($props)->header('Cache-Control', 'private, no-store');
        }
        // Every partial visit replaces scoped references/history from current approved-Site evidence.
        foreach (['evidence_window_policy', 'evidence_window_history', 'evidence_window_options', 'can_manage_evidence_windows', 'evidence_window_viewer_id', 'routing_configuration', 'routing_history', 'routing_sites', 'can_manage_routing', 'routing_viewer_id', 'health_safety_routing_receipt', 'independence_policy', 'independence_history', 'can_manage_independence', 'independence_viewer_id', 'health_safety_independence_receipt'] as $key) {
            $props[$key] = Inertia::always($props[$key]);
        }
        return Inertia::render('health-safety/settings', $props)->toResponse($request)
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateBehaviourSupportApprovalPolicyRequest $request): RedirectResponse|JsonResponse
    {
        $result = $this->settings->save($request->user(), $request->validated());

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['receipt' => $result])
            : redirect()->route('health-safety.settings.index')->with('health_safety_policy_receipt', $result);
    }
}
