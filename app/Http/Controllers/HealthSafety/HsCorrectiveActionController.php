<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\CreateHsCorrectiveActionFromRecommendationRequest;
use App\Http\Requests\HealthSafety\StoreHsCorrectiveActionRequest;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Exposes the (already gated) HsCorrectiveActionService over HTTP (E-Gap 3).
 * Each action maps to one service transition; the service enforces the gates —
 * notably separation of duties (a verifier must differ from the completer) and
 * the auto-advance of the event to `monitoring` once every action is resolved.
 * A forbidden transition surfaces as flash.error. Routes gated `hazards.manage`.
 */
class HsCorrectiveActionController extends Controller
{
    public function __construct(
        private readonly HsCorrectiveActionService $service,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** Add a standalone corrective action to the event. */
    public function store(StoreHsCorrectiveActionRequest $request, HsEvent $event)
    {
        $event = $this->resolveAccessibleEvent($request, $event);
        $data = $request->validated();
        $this->assertStaffIsAssignable(
            $request,
            $event,
            (int) $data['assigned_to_user_id'],
        );

        try {
            $this->service->createStandalone($event, $data, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action added.');
    }

    /** Seed a corrective action from an investigation recommendation (E-Gap 6). */
    public function seedFromRecommendation(
        CreateHsCorrectiveActionFromRecommendationRequest $request,
        HsEvent $event,
        HsInvestigation $investigation,
    ) {
        $event = $this->resolveAccessibleEvent($request, $event);
        abort_unless($investigation->hs_event_id === $event->id, 404);

        $data = $request->validated();
        $this->assertStaffIsAssignable(
            $request,
            $event,
            (int) $data['assigned_to_user_id'],
        );

        try {
            $this->service->createFromRecommendation(
                $investigation,
                $data['recommendation_index'],
                $data,
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action created from recommendation.');
    }

    /** Start (open → in_progress), optionally (re)assigning the owner. */
    public function start(Request $request, HsEvent $event, HsCorrectiveAction $action)
    {
        $event = $this->resolveAccessibleEvent($request, $event);
        $this->ensureBelongs($event, $action);

        $data = $request->validate(['assigned_to_user_id' => ['nullable', 'integer']]);
        if (isset($data['assigned_to_user_id'])) {
            $this->assertStaffIsAssignable(
                $request,
                $event,
                (int) $data['assigned_to_user_id'],
            );
        }

        try {
            $this->service->start($action, $data['assigned_to_user_id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action started.');
    }

    /** Complete (in_progress → completed) with evidence. */
    public function complete(Request $request, HsEvent $event, HsCorrectiveAction $action)
    {
        $event = $this->resolveAccessibleEvent($request, $event, []);
        $this->ensureBelongs($event, $action);

        $data = $request->validate([
            'completion_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->complete($action, [
                'completion_notes' => $data['completion_notes'] ?? null,
                'completed_by_user_id' => $request->user()->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action completed.');
    }

    /** Verify (completed → verified) — enforces strict-site evidence review and separation of duties. */
    public function verify(Request $request, HsEvent $event, HsCorrectiveAction $action)
    {
        $event = $this->resolveAccessibleEvent($request, $event, []);
        $this->ensureBelongs($event, $action);

        $data = $request->validate([
            'evidence_reviewed' => ['accepted'],
            'effective' => ['required', 'boolean'],
            'verification_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->verify($action, [
                'verified_by_user_id' => $request->user()->id,
                'evidence_reviewed' => true,
                'effectiveness_confirmed' => $request->boolean('effective'),
                'verification_notes' => $data['verification_notes'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action verified.');
    }

    /** Close (verified → closed); auto-advances the event to monitoring when all resolved. */
    public function close(Request $request, HsEvent $event, HsCorrectiveAction $action)
    {
        $event = $this->resolveAccessibleEvent($request, $event, []);
        $this->ensureBelongs($event, $action);

        try {
            $this->service->close($action);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action closed.');
    }

    /** Return for rework (completed → in_progress) with a reason. */
    public function returnForRework(Request $request, HsEvent $event, HsCorrectiveAction $action)
    {
        $event = $this->resolveAccessibleEvent($request, $event, []);
        $this->ensureBelongs($event, $action);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        try {
            $this->service->returnForRework($action, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Corrective action returned for rework.');
    }

    private function ensureBelongs(HsEvent $event, HsCorrectiveAction $action): void
    {
        abort_unless($action->hs_event_id === $event->id, 404);
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     */
    private function resolveAccessibleEvent(
        Request $request,
        HsEvent $event,
        array $bypassPermissions = ['healthSafety.viewAllSites'],
    ): HsEvent {
        $query = HsEvent::query();
        $this->siteAccess->applyHsEventScope(
            $query,
            $request->user(),
            $bypassPermissions,
        );

        return $query->findOrFail($event->id);
    }

    private function assertStaffIsAssignable(Request $request, HsEvent $event, int $staffId): void
    {
        $query = User::query()->whereKey($staffId);
        $this->siteAccess->applyHsEventStaffScope(
            $query,
            $event,
            $request->user(),
            ['healthSafety.viewAllSites'],
        );
        $staff = $query->first();

        if (! $staff || ! $staff->canDo('hazards.manage')) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'Choose approved H&S staff available for this event site.',
            ]);
        }
    }
}
