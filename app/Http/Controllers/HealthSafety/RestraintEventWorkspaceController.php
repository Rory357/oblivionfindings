<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\BehaviourSupportPlan;
use App\Models\RespiteStay;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Services\HealthSafety\BehaviourSupportPlanWorkspaceService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\RestraintEvidenceService;
use App\Services\HealthSafety\RestraintRegisterService;
use App\Services\HealthSafety\RestraintService;
use App\Services\HealthSafety\RestraintSourceProjectionService;
use App\Services\Respite\RespiteRestraintReadService;
use App\Support\HealthSafety\RestraintReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Permanent source workspaces; each command rechecks current source and actor authority. */
class RestraintEventWorkspaceController extends Controller
{
    public function __construct(
        private readonly RestraintAccessService $access,
        private readonly RestraintService $commands,
        private readonly RestraintSourceProjectionService $projection,
        private readonly RestraintEvidenceService $evidence,
        private readonly RestraintRegisterService $register,
    ) {}

    public function report(Request $request): Response|JsonResponse
    {
        abort_if($request->has('stay_id') || $request->has('respite_stay_id'), 422);
        // The generic entry retains restraint capture authority; Respite has a separate canonical-stay route.
        $this->access->withCurrentViewer($request->user(), fn ($current) => $this->access->assertOperation($current, 'capture'));

        return $this->reportPage($request);
    }

    public function reportStay(Request $request, RespiteStay $stay): Response|JsonResponse
    {
        $context = app(RespiteRestraintReadService::class)->reportContext($request->user(), $stay);
        foreach (['client_id', 'site_id', 'respite_stay_id'] as $key) {
            abort_if($request->filled($key) && $this->positive($request->input($key)) !== $context[$key], 404);
        }
        abort_if($request->has('stay_id'), 422);

        return $this->reportPage($request, $context);
    }

    private function reportPage(Request $request, ?array $context = null): Response|JsonResponse
    {
        $origin = RestraintReturn::sanitize($request->input('return_to'));
        $capture = $this->access->withCurrentViewer($request->user(), fn ($current) => $current->canDo('restraints.create') || $current->canDo('restraints.manage'));
        abort_if($request->input('kind') === 'plan' && ! ($context['can_create_plan'] ?? $capture), 403);
        $eventHint = $request->session()->get('restraint_confirmation');
        $planHint = $request->session()->get('behaviour_support_plan_confirmation');
        // First-create recovery is direct JSON only, never an Inertia URL or history entry.
        if ($request->has('receipt_key')) {
            abort_unless($this->json($request), 404);
            $key = $request->input('receipt_key');
            abort_unless(is_string($key) && Str::isUuid($key), 422);
            $saved = RestraintCommandReceipt::where('user_id', $request->user()->id)->where('command', 'create')->whereIn('source_type', ['event', 'plan'])->where('idempotency_key', strtolower($key))->first();
            $eventHint = $saved?->source_type === 'event' ? ['event_id' => (int) $saved->source_id, 'idempotency_key' => strtolower($key)] : null;
            $planHint = $saved?->source_type === 'plan' ? ['plan_id' => (int) $saved->source_id, 'idempotency_key' => strtolower($key)] : null;
        }
        $eventReceipt = $this->commands->confirmationForViewer($request->user(), $eventHint);
        $plans = app(BehaviourSupportPlanWorkspaceService::class);
        $planReceipt = $plans->confirmationForViewer($request->user(), $planHint);
        if ($context !== null) {
            $eventSource = $eventReceipt ? RestraintEvent::find($eventReceipt['event_id']) : null;
            if (! $eventSource || (int) $eventSource->client_id !== $context['client_id'] || (int) $eventSource->site_id !== $context['site_id'] || $eventSource->stay_id === null || (int) $eventSource->stay_id !== $context['respite_stay_id']) {
                $eventReceipt = null;
            }
            $planSource = $planReceipt ? BehaviourSupportPlan::find($planReceipt['plan_id']) : null;
            if (! $planSource || (int) $planSource->client_id !== $context['client_id'] || (int) $planSource->source_site_id !== $context['site_id'] || $planSource->source_stay_id === null || (int) $planSource->source_stay_id !== $context['respite_stay_id']) {
                $planReceipt = null;
            }
        }
        $eventHref = null;
        if ($eventReceipt && ($event = RestraintEvent::find($eventReceipt['event_id']))) {
            $can = $this->access->commandCan($event, $request->user());
            if ($can['view'] || $can['workspace']) {
                $eventHref = RestraintReturn::event($event->id, $origin);
                if (! $can['view']) {
                    $eventHref = str_replace('/events/'.$event->id, '/events/'.$event->id.'/review', $eventHref);
                }
            }
        }
        $plan = $planReceipt ? BehaviourSupportPlan::find($planReceipt['plan_id']) : null;
        $props = [
            'pickers' => $context['pickers'] ?? $this->register->pickers($request->user(), $request->only(['client_id', 'site_id'])),
            'capture_context' => $context ? array_intersect_key($context, array_flip(['client_id', 'site_id', 'respite_stay_id', 'store_href'])) : null,
            'can' => ['capture' => $context !== null || $capture, 'create_plan' => $context['can_create_plan'] ?? $capture],
            'return_to' => $origin,
            'restraint_confirmation' => $eventReceipt,
            'behaviour_support_plan_confirmation' => $planReceipt,
            'restraint_href' => $eventHref,
            'plan_href' => $plan ? $plans->readableHref($plan, $request->user(), $origin) : null,
        ];

        return $this->json($request) ? response()->json($props) : Inertia::render('health-safety/restraints/events/report', $props);
    }

    public function show(Request $request, RestraintEvent $event): Response|JsonResponse
    {
        return $this->workspace($request, $event, false);
    }

    public function reviewWorkspace(Request $request, RestraintEvent $event): Response|JsonResponse
    {
        return $this->workspace($request, $event, true);
    }

    private function workspace(Request $request, RestraintEvent $event, bool $review): Response|JsonResponse
    {
        $detail = $this->projection->event($event, $request->user(), $review);
        // The source is authorised before child existence is considered.
        foreach (['followup' => 'followups', 'observation' => 'observations', 'debrief' => 'debriefs', 'attachment' => 'attachments'] as $key => $relation) {
            if ($request->has($key)) {
                $id = $this->positive($request->input($key));
                abort_unless(collect($detail['event'][$relation])->contains(fn ($child) => (int) $child['id'] === $id), 404);
            }
        }
        $props = [
            'detail' => $detail,
            'workspace' => $review ? 'review' : 'view',
            'pickers' => ($detail['can']['review'] || $detail['can']['capture']) ? $this->register->pickers($request->user(), [], $event) : [],
            'return_to' => RestraintReturn::sanitize($request->input('return_to')),
            'restraint_confirmation' => $this->confirmation($request, (int) $event->id),
        ];

        return $this->json($request) ? response()->json($props) : Inertia::render('health-safety/restraints/events/show', $props);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->create($request->user(), $this->commandInput($request)));
    }

    public function correct(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->correct($event, $request->user(), $this->commandInput($request)));
    }

    public function review(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->review($event, $request->user(), $this->commandInput($request)));
    }

    public function assignReview(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->assignReview($event, $request->user(), $this->commandInput($request)));
    }

    public function linkIncident(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->linkIncident($event, $request->user(), $this->commandInput($request)));
    }

    public function unlinkIncident(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->unlinkIncident($event, $request->user(), $this->commandInput($request)));
    }

    public function addObservation(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->addObservation($event, $request->user(), $this->commandInput($request)));
    }

    public function correctObservation(Request $request, RestraintEvent $event, int $observation): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->correctObservation($event, $request->user(), [...$this->commandInput($request), 'observation_id' => $observation]));
    }

    public function recordDebrief(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->recordDebrief($event, $request->user(), $this->commandInput($request)));
    }

    public function correctDebrief(Request $request, RestraintEvent $event, int $debrief): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->correctDebrief($event, $request->user(), [...$this->commandInput($request), 'debrief_id' => $debrief]));
    }

    public function addFollowup(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->addFollowup($event, $request->user(), $this->commandInput($request)));
    }

    public function reassignFollowup(Request $request, RestraintEvent $event, int $followup): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->reassignFollowup($event, $request->user(), [...$this->commandInput($request), 'followup_id' => $followup]));
    }

    public function completeFollowup(Request $request, RestraintEvent $event, int $followup): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->completeFollowup($event, $request->user(), [...$this->commandInput($request), 'followup_id' => $followup]));
    }

    public function correctFollowup(Request $request, RestraintEvent $event, int $followup): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->correctFollowup($event, $request->user(), [...$this->commandInput($request), 'followup_id' => $followup]));
    }

    public function cancelFollowup(Request $request, RestraintEvent $event, int $followup): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->commands->cancelFollowup($event, $request->user(), [...$this->commandInput($request), 'followup_id' => $followup]));
    }

    public function addEvidence(Request $request, RestraintEvent $event): RedirectResponse|JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        return $this->confirmed($request, $this->evidence->add($event, $request->user(), $this->commandInput($request, ['file']), $request->file('file')));
    }

    public function removeEvidence(Request $request, RestraintEvent $event, RestraintEventAttachment $attachment): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->evidence->remove($event, $attachment, $request->user(), $this->commandInput($request)));
    }

    public function downloadEvidence(Request $request, RestraintEvent $event, RestraintEventAttachment $attachment): StreamedResponse
    {
        return $this->evidence->download($event, $attachment, $request->user());
    }

    private function confirmed(Request $request, array $result): RedirectResponse|JsonResponse
    {
        $proof = $this->commands->confirmationForViewer($request->user(), $result);
        abort_unless($proof && $proof['idempotency_key'] === strtolower((string) $request->input('idempotency_key')) && $proof['version'] === $result['version'] && $proof['command'] === $result['command'], 403);
        $proof['replayed'] = $result['replayed'];
        $origin = RestraintReturn::sanitize($request->input('return_to'));
        $event = RestraintEvent::findOrFail($result['event_id']);
        $can = $this->access->commandCan($event, $request->user());
        $section = in_array($request->input('section'), RestraintReturn::EVENT_SECTIONS, true) ? $request->input('section') : null;
        $href = ($can['view'] || $can['workspace']) ? RestraintReturn::event($event->id, $origin, $section, $proof['followup_id'] ?? null) : null;
        if ($href !== null && ! $can['view']) {
            $href = str_replace('/events/'.$event->id, '/events/'.$event->id.'/review', $href);
        }
        if ($this->json($request)) {
            return response()->json(['status' => 'accepted', 'restraint_confirmation' => $proof, 'event_href' => $href]);
        }
        // Capture does not grant read access. Its own report page carries only the accepted receipt.
        $target = $href ?? RestraintReturn::REGISTER.'/report'.($origin ? '?return_to='.rawurlencode($origin) : '');
        if ($href && is_string($request->input('action')) && in_array($request->input('action'), self::ACTIONS, true)) {
            $target .= (str_contains($target, '?') ? '&' : '?').'action='.rawurlencode($request->input('action'));
        }
        foreach (['observation', 'debrief', 'attachment'] as $child) {
            if ($href && isset($proof[$child.'_id'])) {
                $target .= (str_contains($target, '?') ? '&' : '?').$child.'='.(int) $proof[$child.'_id'];
            }
        }

        return redirect($target)->with('success', 'Restrictive intervention work recorded.')->with('restraint_confirmation', $proof);
    }

    private const ACTIONS = ['correct', 'review', 'assign-review', 'link-incident', 'unlink-incident', 'add-observation', 'correct-observation', 'record-debrief', 'correct-debrief', 'add-followup', 'reassign-followup', 'complete-followup', 'correct-followup', 'cancel-followup', 'add-evidence', 'remove-evidence'];

    private function confirmation(Request $request, ?int $eventId = null): ?array
    {
        $hint = $eventId !== null && $request->has('receipt_key') ? ['event_id' => $eventId, 'idempotency_key' => $request->input('receipt_key')] : $request->session()->get('restraint_confirmation');

        return $this->commands->confirmationForViewer($request->user(), $hint);
    }

    private function commandInput(Request $request, array $extra = []): array
    {
        return $request->except(['_token', '_method', 'return_to', 'section', 'action', ...$extra]);
    }

    private function positive(mixed $value): int
    {
        abort_unless(is_scalar($value) && preg_match('/^[1-9]\d*$/D', (string) $value) === 1 && strlen((string) $value) <= 16 && (float) $value <= 9007199254740991, 404);

        return (int) $value;
    }

    private function json(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }
}
