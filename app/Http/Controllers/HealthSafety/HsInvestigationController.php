<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\User;
use App\Services\HealthSafety\HsInvestigationService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Exposes the (already gated) HsInvestigationService over HTTP (E-Gap 3). Each
 * action maps to one service transition; the service enforces every guard
 * (open event + no active investigation; ≥1 cause; recommendations on complete;
 * no transition skips) and a forbidden transition surfaces as flash.error rather
 * than a raw write. All routes are gated `hazards.manage`.
 */
class HsInvestigationController extends Controller
{
    public function __construct(
        private readonly HsInvestigationService $service,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** Start an investigation: create (methodology, lead, team) then move to in_progress. */
    public function store(Request $request, HsEvent $event)
    {
        $event = $this->resolveAccessibleEvent($request, $event);

        $data = $request->validate([
            'methodology' => ['required', Rule::in(HsInvestigation::VALID_METHODOLOGIES)],
            'lead_investigator_id' => ['required', 'integer'],
            'team_member_ids' => ['nullable', 'array'],
            'team_member_ids.*' => ['integer'],
            'target_completion_date' => ['nullable', 'date'],
        ]);
        $this->assertStaffAreAssignable(
            $request,
            $event,
            [(int) $data['lead_investigator_id']],
            'lead_investigator_id',
        );
        $this->assertStaffAreAssignable(
            $request,
            $event,
            array_map('intval', $data['team_member_ids'] ?? []),
            'team_member_ids',
        );

        try {
            $investigation = $this->service->create($event, [
                'methodology' => $data['methodology'],
                'lead_investigator_id' => $data['lead_investigator_id'],
                'team_member_ids' => $data['team_member_ids'] ?? [],
                'target_completion_date' => $data['target_completion_date'] ?? null,
            ]);
            $this->service->start($investigation, $data['lead_investigator_id']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation started.');
    }

    /** Record findings: causes, contributing factors, recommendations (→ findings_recorded). */
    public function recordFindings(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $event = $this->resolveAccessibleEvent($request, $event);
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate([
            'immediate_causes' => ['nullable', 'array'],
            'root_causes' => ['nullable', 'array'],
            'contributing_factors' => ['nullable', 'array'],
            'findings_summary' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'array'],
            'lessons_learned' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->recordFindings($investigation, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Findings recorded.');
    }

    /** Submit for review (findings_recorded → under_review). */
    public function submit(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $event = $this->resolveAccessibleEvent($request, $event);
        $this->ensureBelongs($event, $investigation);

        try {
            $this->service->submitForReview($investigation);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation submitted for review.');
    }

    /** Return for rework (under_review → in_progress) with reviewer notes. */
    public function returnForRework(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $event = $this->resolveAccessibleEvent($request, $event);
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate(['review_notes' => ['required', 'string', 'max:2000']]);

        try {
            $this->service->returnForRework($investigation, $data['review_notes']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation returned for rework.');
    }

    /** Complete (under_review → completed); auto-advances the event to corrective_action. */
    public function complete(Request $request, HsEvent $event, HsInvestigation $investigation)
    {
        $event = $this->resolveAccessibleEvent($request, $event);
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate(['approved_by_id' => ['nullable', 'integer']]);
        if (isset($data['approved_by_id'])) {
            $this->assertStaffAreAssignable(
                $request,
                $event,
                [(int) $data['approved_by_id']],
                'approved_by_id',
            );
        }

        try {
            $this->service->complete($investigation, [
                'reviewed_by_id' => $request->user()->id,
                'reviewed_at' => now(),
                'approved_by_id' => $data['approved_by_id'] ?? $request->user()->id,
                'approved_at' => now(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Investigation completed.');
    }

    /** Record or revise the explicit outcome of one completed recommendation. */
    public function disposition(
        Request $request,
        HsEvent $event,
        HsInvestigation $investigation,
        int $recommendationIndex,
    ) {
        $event = $this->resolveAccessibleEvent($request, $event);
        $this->ensureBelongs($event, $investigation);

        $data = $request->validate([
            'disposition' => ['required', Rule::in(HsRecommendationDisposition::VALID_DISPOSITIONS)],
            'reason' => [
                'nullable',
                'required_unless:disposition,'.HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                'string',
                'max:2000',
            ],
            'assigned_to_user_id' => [
                'nullable',
                'required_if:disposition,'.HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                'integer',
            ],
            'due_date' => [
                'nullable',
                'required_if:disposition,'.HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                'date_format:Y-m-d',
            ],
            'priority' => [
                'nullable',
                'required_if:disposition,'.HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                Rule::in([
                    HsCorrectiveAction::PRIORITY_LOW,
                    HsCorrectiveAction::PRIORITY_MEDIUM,
                    HsCorrectiveAction::PRIORITY_HIGH,
                    HsCorrectiveAction::PRIORITY_CRITICAL,
                ]),
            ],
            'responsibility_choice' => [
                'nullable',
                'required_if:disposition,'.HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                Rule::in(['transfer_task', 'new_responsibility']),
            ],
            'source_control_room_task_id' => [
                'nullable',
                'required_if:responsibility_choice,transfer_task',
                'integer',
            ],
            'new_responsibility_reason' => [
                'nullable',
                'required_if:responsibility_choice,new_responsibility',
                'string',
                'min:10',
                'max:1000',
            ],
        ]);
        if ($data['disposition'] === HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION) {
            $this->assertStaffAreAssignable(
                $request,
                $event,
                [(int) $data['assigned_to_user_id']],
                'assigned_to_user_id',
            );
        }

        try {
            $this->service->dispositionRecommendation(
                $investigation,
                $recommendationIndex,
                $data['disposition'],
                $request->user(),
                $data['reason'] ?? null,
                $data['disposition'] === HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION
                    ? $data
                    : null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recommendation outcome recorded.');
    }

    private function ensureBelongs(HsEvent $event, HsInvestigation $investigation): void
    {
        abort_unless($investigation->hs_event_id === $event->id, 404);
    }

    private function resolveAccessibleEvent(Request $request, HsEvent $event): HsEvent
    {
        $query = HsEvent::query();
        $this->siteAccess->applyHsEventScope(
            $query,
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );

        return $query->findOrFail($event->id);
    }

    /** @param list<int> $staffIds */
    private function assertStaffAreAssignable(
        Request $request,
        HsEvent $event,
        array $staffIds,
        string $field,
    ): void {
        $staffIds = array_values(array_unique(array_filter($staffIds, fn (int $id): bool => $id > 0)));
        if ($staffIds === []) {
            return;
        }

        $query = User::query()->whereIn('id', $staffIds);
        $this->siteAccess->applyHsEventStaffScope(
            $query,
            $event,
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
        $eligibleIds = $query->get()
            ->filter(fn (User $staff): bool => $staff->canDo('hazards.manage'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (array_diff($staffIds, $eligibleIds) !== []) {
            throw ValidationException::withMessages([
                $field => 'Choose approved H&S staff available for this event site.',
            ]);
        }
    }
}
