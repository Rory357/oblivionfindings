<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\BehaviourSupportPlan;
use App\Services\HealthSafety\BehaviourSupportPlanApprovalService;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use App\Services\HealthSafety\BehaviourSupportPlanWorkspaceService;
use App\Support\HealthSafety\RestraintReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Permanent plan reads and typed commands; navigation never widens source authority. */
final class BehaviourSupportPlanWorkspaceController extends Controller
{
    private const ACTIONS = ['edit', 'review', 'approve', 'decision', 'activate', 'submit-review', 'archive'];

    private const FRAMING = ['_token', '_method', 'return_to', 'section', 'action', 'include_history', 'version', 'approval', 'receipt_key'];

    public function __construct(private readonly BehaviourSupportPlanService $plans, private readonly BehaviourSupportPlanApprovalService $approvals, private readonly BehaviourSupportPlanWorkspaceService $workspace) {}

    public function show(Request $request, BehaviourSupportPlan $plan): Response|JsonResponse
    {
        return $this->page($request, $plan, 'view');
    }

    public function reviewWorkspace(Request $request, BehaviourSupportPlan $plan): Response|JsonResponse
    {
        return $this->page($request, $plan, 'review');
    }

    public function approvalWorkspace(Request $request, BehaviourSupportPlan $plan): Response|JsonResponse
    {
        return $this->page($request, $plan, 'approval');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->plans->create($request->user(), $this->payload($request)));
    }

    public function update(Request $request, BehaviourSupportPlan $plan): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->plans->update($plan, $request->user(), $this->payload($request)));
    }

    public function submitReview(Request $request, BehaviourSupportPlan $plan): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->plans->submitReview($plan, $request->user(), $this->payload($request)));
    }

    public function activate(Request $request, BehaviourSupportPlan $plan): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->plans->activate($plan, $request->user(), $this->payload($request)));
    }

    public function archive(Request $request, BehaviourSupportPlan $plan): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->plans->archive($plan, $request->user(), $this->payload($request)));
    }

    public function review(Request $request, BehaviourSupportPlan $plan): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->plans->review($plan, $request->user(), $this->payload($request)));
    }

    public function decision(Request $request, BehaviourSupportPlan $plan): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->approvals->decide($plan, $request->user(), $this->payload($request)));
    }

    private function page(Request $request, BehaviourSupportPlan $plan, string $mode): Response|JsonResponse
    {
        $query = $request->validate(['include_history' => ['sometimes', 'boolean'], 'receipt_key' => ['sometimes', 'string', 'uuid']]);
        $version = $request->has('version') ? $this->positive($request->input('version')) : null;
        $approval = $request->has('approval') ? $this->positive($request->input('approval')) : null;
        $origin = RestraintReturn::sanitize($request->input('return_to'));
        $detail = $this->workspace->detail($plan, $request->user(), $mode, $origin, ['include_history' => (bool) ($query['include_history'] ?? true), 'version' => $version, 'approval' => $approval]);
        $hint = isset($query['receipt_key']) ? ['plan_id' => (int) $plan->id, 'idempotency_key' => strtolower($query['receipt_key'])] : $request->session()->get('behaviour_support_plan_confirmation');
        $confirmation = $this->workspace->confirmationForViewer($request->user(), $hint);
        if ($confirmation && $confirmation['plan_id'] !== (int) $plan->id) {
            $confirmation = null;
        }
        $props = ['detail' => $detail, 'pickers' => $detail['pickers'], 'workspace' => $mode, 'section' => $this->section($request->input('section')) ?? ($mode === 'view' ? 'overview' : $mode),
            'action' => $this->action($request->input('action')), 'selected_version_id' => $version, 'selected_approval_id' => $approval,
            'return_to' => $origin, 'behaviour_support_plan_confirmation' => $confirmation];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render('health-safety/restraints/plans/show', $props);
    }

    private function payload(Request $request): array
    {
        return $request->except(self::FRAMING);
    }

    private function confirmed(Request $request, array $result): RedirectResponse|JsonResponse
    {
        $proof = $this->workspace->confirmationForViewer($request->user(), $result);
        abort_unless($proof, 403);
        $proof['replayed'] = (bool) ($result['replayed'] ?? false);
        $plan = BehaviourSupportPlan::withTrashed()->findOrFail($proof['plan_id']);
        $origin = RestraintReturn::sanitize($request->input('return_to'));
        $section = $this->section($request->input('section')) ?? match ($proof['command']) {
            'update' => 'content', 'review' => 'review', 'approval' => 'approval', default => 'overview'
        };
        $action = $this->action($request->input('action'));
        $preferred = in_array($action, ['approve', 'decision'], true) || $section === 'approval' ? 'approval' : ($action === 'review' || $section === 'review' ? 'review' : null);
        $href = $this->workspace->readableHref($plan, $request->user(), $origin, $section, $preferred);
        if ($href && $action) {
            $href .= (str_contains($href, '?') ? '&' : '?').'action='.rawurlencode($action);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'accepted', 'receipt' => $proof, 'behaviour_support_plan_confirmation' => $proof, 'plan_href' => $href]);
        }
        $fallback = RestraintReturn::REGISTER.'/report?kind=plan'.($origin !== null ? '&return_to='.rawurlencode($origin) : '');
        if ($href === null) {
            $fallback .= '&section='.rawurlencode($section);
            if ($action) {
                $fallback .= '&action='.rawurlencode($action);
            }
        }

        return redirect($href ?? $fallback)->with('behaviour_support_plan_confirmation', $proof);
    }

    private function action(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::ACTIONS, true) ? $value : null;
    }

    private function section(mixed $value): ?string
    {
        return is_string($value) && in_array($value, RestraintReturn::PLAN_SECTIONS, true) ? $value : null;
    }

    private function positive(mixed $value): int
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^[1-9]\\d*$/D', (string) $value) && strlen((string) $value) <= 16 && (float) $value <= 9007199254740991, 404);

        return (int) $value;
    }
}
