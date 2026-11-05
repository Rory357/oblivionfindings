<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\ItOnCall;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\OnCallHandover;
use App\Domain\SecurityDevices\Screens\Alerts\AlertRulesPresenter;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Domain\SecurityDevices\Screens\Alerts\AutomationsPresenter;
use App\Domain\SecurityDevices\Screens\Alerts\OnCallPresenter;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Alerts (S&D rebuild drawing pg-sdr-13) and what people do on an alert (pop-ups 20, 45). */
final class AlertsController extends Controller
{
    public function __construct(
        private readonly AlertsPresenter $alerts,
        private readonly DeviceAlertEngine $engine,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request, DeviceFormOptions $formOptions, AlertRulesPresenter $rules): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && AlertsPresenter::canOpen($user), 403);
        $manages = $user->canDo('securityDevices.monitoring.manage');

        return Inertia::render('security-devices/alerts/index', [
            ...$this->alerts->present($user, $request->only(['tab', 'site', 'alert', 'page', 'q'])),
            // Quiet time picks devices from the same choices as Add a device, loaded when it opens.
            'deviceForm' => Inertia::optional(fn (): array => $formOptions->present($user)),
            // Quiet time (pop-up 44) and Add alert rule (21) pick devices from this list, loaded when one opens.
            'alertDevices' => Inertia::optional(fn (): array => $manages ? $this->alerts->pickableDevices($user) : []),
            // Add alert rule (21): who can be told, and whether a rule can cover every Site.
            'ruleForm' => Inertia::optional(fn (): ?array => $manages ? $rules->formOptions($user) : null),
            // S&D Phase F: Add automation (40): Sites, their devices, roles and the safe actions, loaded when it opens.
            'automationForm' => Inertia::optional(fn (): ?array => AutomationsPresenter::canManage($user) ? app(AutomationsPresenter::class)->formOptions($user) : null),
        ]);
    }

    public function acknowledge(Request $request, int $alert): JsonResponse
    {
        [$user, $record] = $this->actable($request, $alert);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'remind' => ['required', 'boolean'],
        ]);
        $this->engine->acknowledge($record, $user, $validated['note'] ?? null, (bool) $validated['remind']);

        return response()->json(['message' => 'Everyone can see you have it.']);
    }

    public function assign(Request $request, int $alert): JsonResponse
    {
        [$user, $record] = $this->actable($request, $alert);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $assignee = collect($this->candidates($record))->firstWhere('id', (int) $validated['user_id']);
        if ($assignee === null) {
            throw ValidationException::withMessages(['user_id' => 'Choose someone who can look after alerts at this Site.']);
        }
        $this->engine->assign($record, $user, User::query()->findOrFail((int) $validated['user_id']), $validated['note'] ?? null);

        return response()->json(['message' => $assignee['name'].' has been told.']);
    }

    /** People who could look after this alert: allowed to acknowledge alerts and see its Site. */
    public function assignees(Request $request, int $alert): JsonResponse
    {
        [, $record] = $this->actable($request, $alert);

        return response()->json(['people' => $this->candidates($record)]);
    }

    public function snooze(Request $request, int $alert): JsonResponse
    {
        [$user, $record] = $this->actable($request, $alert);
        $validated = $request->validate([
            'until' => ['required', 'date'],
            'why' => ['nullable', 'string', 'max:500'],
        ]);
        $until = CarbonImmutable::parse($validated['until'])->setTimezone('UTC');
        if ($until->lte(CarbonImmutable::now('UTC')->addMinutes(4))) {
            throw ValidationException::withMessages(['until' => 'Choose a time at least 5 minutes from now.']);
        }
        if ($until->gt(CarbonImmutable::now('UTC')->addDays(7))) {
            throw ValidationException::withMessages(['until' => 'Snooze for a week at most. For planned work, use Quiet time.']);
        }
        $this->engine->snooze($record, $user, $until, $validated['why'] ?? null);

        return response()->json(['message' => 'Snoozed. It comes back early if it gets worse.']);
    }

    /** A safety alert (or wrong sensor logins) is dealt with: a person marks it done. */
    public function done(Request $request, int $alert): JsonResponse
    {
        [$user, $record] = $this->actable($request, $alert);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->engine->done($record, $user, $validated['note'] ?? null);

        return response()->json(['message' => 'Marked done.']);
    }

    /** Pop-up 45: note on the timeline that the incident form was opened from this alert. */
    public function reportIncident(Request $request, int $alert): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('incidents.create'), 403);
        $record = $this->alerts->scoped($user)->findOrFail($alert);
        $this->engine->incidentReported($record, $user);

        return response()->json(['url' => '/incidents/create?type=incident']);
    }

    /**
     * S&D Phase F (pg-sdr-7c): raise the H&S hazard from a scald-risk alert. The person who clicks
     * is its reporter; the hazard is linked to the alert so the tap's "fixed" notice reaches them.
     */
    public function raiseHazard(Request $request, int $alert): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('hazards.create'), 403);
        $record = $this->alerts->scoped($user)->findOrFail($alert);
        $hazard = app(\App\Domain\SecurityDevices\Care\ScaldWatch::class)->raiseHazard($user, $record);

        return response()->json(['hazard_id' => (int) $hazard->id, 'url' => '/hazards/'.$hazard->id], 201);
    }

    /**
     * S&D round 3 (pg-sdr-13d): read-only "All handovers" page. Same permission as reading handover
     * notes on the On call tab (canOpen + alerts.acknowledge), scoped to the viewer's Sites.
     */
    public function handovers(Request $request, OnCallPresenter $onCall): Response
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && AlertsPresenter::canOpen($user)
                && $user->canDo('securityDevices.alerts.acknowledge'),
            403,
        );

        return Inertia::render('security-devices/alerts/handovers', $onCall->presentHandovers($user, $request->only(['q', 'page'])));
    }

    /**
     * S&D round 3 (pg-sdr-13d): a handover note for whoever is on IT on call next. It goes to the next
     * person on the on-call roster (or nobody yet, when the roster is empty), from the person writing it.
     */
    public function handover(Request $request, ItOnCall $onCall): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.alerts.acknowledge'), 403);
        $validated = $request->validate([
            'still_open' => ['nullable', 'string', 'max:2000'],
            'coming_up' => ['nullable', 'string', 'max:2000'],
            'good_to_know' => ['nullable', 'string', 'max:2000'],
        ]);
        $words = array_map(fn (mixed $text): ?string => is_string($text) && trim($text) !== '' ? trim($text) : null, $validated);
        if (array_filter($words) === []) {
            throw ValidationException::withMessages(['still_open' => 'Write at least one part of the handover.']);
        }
        $now = CarbonImmutable::now('UTC');
        $next = $onCall->shifts($now, $now->addDays(14))
            ->first(fn ($shift): bool => (int) $shift->user_id !== (int) $user->id);
        $note = OnCallHandover::query()->create([
            'from_user_id' => (int) $user->id,
            'to_user_id' => $next?->user_id === null ? null : (int) $next->user_id,
            'still_open' => $words['still_open'] ?? null,
            'coming_up' => $words['coming_up'] ?? null,
            'good_to_know' => $words['good_to_know'] ?? null,
        ]);
        AuditLogger::logOrFail('security_devices.on_call.handover_written', $note, ['actor_id' => (int) $user->id, 'to_user_id' => $note->to_user_id]);

        return response()->json([
            'message' => $next?->staff?->name ? 'Handover saved for '.$next->staff->name.'.' : 'Handover saved.',
        ], 201);
    }

    /** @return array{0: User, 1: DeviceAlert} */
    private function actable(Request $request, int $alert): array
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.alerts.acknowledge'), 403);
        $record = $this->alerts->scoped($user)->findOrFail($alert);

        return [$user, $record];
    }

    /** @return list<array{id: int, name: string, hint: string|null}> */
    private function candidates(DeviceAlert $alert): array
    {
        $siteId = (int) ($alert->site_id ?? 0);

        return User::query()
            ->whereNotNull('approved_at')
            ->with(['roles.permissions', 'permissionOverrides', 'roles'])
            ->orderBy('name')
            ->limit(300)
            ->get()
            ->filter(fn (User $person): bool => $person->canDo('securityDevices.alerts.acknowledge')
                && ($siteId === 0 || in_array($siteId, $this->access->accessibleSiteIds($person), true)))
            ->map(fn (User $person): array => [
                'id' => (int) $person->id,
                'name' => (string) $person->name,
                'hint' => $person->roles->first()?->label ?: null,
            ])
            ->values()
            ->all();
    }
}
