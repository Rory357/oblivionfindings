<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCaseAccessService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreDisciplinaryActionRequest;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DisciplinaryController extends Controller
{
    public function __construct(
        private readonly HrCaseAccessService $caseAccess,
        private readonly UserSiteAccessService $siteAccess,
        private readonly PeopleMutationLockService $mutationLocks,
    ) {}

    /**
     * Disciplinary process stages in order. Public so the case show page
     * (which hosts the disciplinary wizards) can expose the same contract.
     */
    public const STAGES = [
        'allegation_raised',
        'investigation',
        'notice_issued',
        'meeting_scheduled',
        'meeting_held',
        'response_period',
        'outcome_decided',
        'outcome_communicated',
        'appeal',
        'closed',
    ];

    /**
     * Good faith checks that must be true before recording/communicating an outcome.
     */
    public const REQUIRED_GOOD_FAITH_CHECKS = [
        'allegation_communicated',
        'opportunity_to_respond',
        'response_genuinely_considered',
        'support_person_offered',
    ];

    /**
     * Disciplinary action type options shared with the case show page wizards.
     */
    public const ACTION_TYPE_OPTIONS = [
        ['value' => 'verbal_warning', 'label' => 'Verbal Warning'],
        ['value' => 'written_warning', 'label' => 'Written Warning'],
        ['value' => 'final_warning', 'label' => 'Final Warning'],
        ['value' => 'suspension', 'label' => 'Suspension'],
        ['value' => 'dismissal', 'label' => 'Dismissal'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    /**
     * Labels for the required good-faith checks, shared with the case show page.
     */
    public const GOOD_FAITH_CHECK_OPTIONS = [
        ['key' => 'allegation_communicated', 'label' => 'Allegation clearly communicated'],
        ['key' => 'opportunity_to_respond', 'label' => 'Genuine opportunity to respond provided'],
        ['key' => 'response_genuinely_considered', 'label' => 'Employee response genuinely considered'],
        ['key' => 'support_person_offered', 'label' => 'Support person was offered'],
    ];

    /**
     * The full-page create form was replaced by the Add-disciplinary wizard on
     * the case show page; keep the GET route working by deep-linking into it.
     */
    public function create(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);
        $this->assertCanAccessCase($user, $case);

        return redirect()->route('hr.cases.show', ['case' => $case->id, 'new' => 'disciplinary']);
    }

    /**
     * The full-page edit form was replaced by the Edit-disciplinary wizard on
     * the parent case show page; keep the GET route working by deep-linking.
     */
    public function edit(Request $request, HrDisciplinaryAction $action)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);
        $hrCase = $this->actionCaseForUser($user, $action);

        return redirect()->route('hr.cases.show', [
            'case' => $hrCase->id,
            'edit-disciplinary' => $action->id,
        ]);
    }

    /**
     * Store a new disciplinary action linked to an HR case.
     */
    public function store(StoreDisciplinaryActionRequest $request, HrCase $case)
    {
        $user = $request->user();
        $this->assertCanAccessCase($user, $case);

        $data = $request->validated();
        $action = DB::transaction(function () use ($user, $case, $data): HrDisciplinaryAction {
            $locks = $this->mutationLocks->lock([
                $user->id,
                ...$this->casePeopleIds($case),
                $data['employee_user_id'] ?? null,
                $data['investigator_user_id'] ?? null,
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.disciplinary.manage'), 403);

            $lockedCase = HrCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $freshSiteAccess = new UserSiteAccessService;
            $this->assertCanAccessCase(
                $actor,
                $lockedCase,
                new HrCaseAccessService($freshSiteAccess),
            );
            if ($lockedCase->status === 'closed') {
                throw ValidationException::withMessages([
                    'case' => 'A disciplinary action cannot be added to a closed HR case.',
                ]);
            }
            $this->assertAvailableActionParticipants($actor, $lockedCase, $data, $freshSiteAccess);
            $this->assertProspectiveDateTruth($data, null);

            $action = HrDisciplinaryAction::query()->create([
                'case_id' => $lockedCase->id,
                'stage' => 'allegation_raised',
                'created_by' => $actor->id,
                ...$data,
            ]);

            $this->logDisciplinaryCaseEvent(
                $action,
                $actor->id,
                'Disciplinary action created',
                'Action type: '.str_replace('_', ' ', (string) $action->action_type).'. Stage: allegation raised.'
            );

            return $action;
        });

        return redirect()->back()->with('success', 'Disciplinary action created.');
    }

    /**
     * Update an existing disciplinary action.
     */
    public function update(Request $request, HrDisciplinaryAction $action)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);
        $this->actionCaseForUser($user, $action);

        $data = $request->validate([
            'action_type' => ['sometimes', 'string', 'in:verbal_warning,written_warning,final_warning,suspension,dismissal,other'],
            'allegation_summary' => ['sometimes', 'string', 'max:10000'],
            'investigation_notes' => ['nullable', 'string', 'max:10000'],
            'investigator_user_id' => ['nullable', 'bail', 'integer', $this->visibleStaffRule($user)],
            'notice_issued_at' => ['nullable', 'date'],
            'notice_document_path' => ['nullable', 'string', 'max:500'],
            'meeting_scheduled_at' => ['nullable', 'date'],
            'meeting_location' => ['nullable', 'string', 'max:255'],
            'support_person_advised' => ['boolean'],
            'meeting_held_at' => ['nullable', 'date'],
            'meeting_notes' => ['nullable', 'string', 'max:10000'],
            'meeting_attendees' => ['nullable', 'array', 'max:100'],
            'meeting_attendees.*' => ['string', 'distinct', 'max:255'],
            'employee_response' => ['nullable', 'string', 'max:10000'],
            'response_deadline' => ['nullable', 'date'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'outcome_rationale' => ['nullable', 'string', 'max:5000'],
            'outcome_document_path' => ['nullable', 'string', 'max:500'],
            'good_faith_checklist' => ['nullable', 'array:allegation_communicated,opportunity_to_respond,response_genuinely_considered,support_person_offered'],
            'good_faith_checklist.*' => ['boolean'],
            'appeal_received' => ['sometimes', 'boolean'],
            'appeal_notes' => ['nullable', 'string', 'max:10000'],
            'appeal_outcome' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('good_faith_checklist', $data) && $data['good_faith_checklist'] !== null) {
            $data['good_faith_checklist'] = self::normalizeGoodFaithChecklist(
                (array) $data['good_faith_checklist'],
            );
        }

        [$action, $outcomeChanged] = DB::transaction(function () use ($user, $action, $data): array {
            $locks = $this->mutationLocks->lock([
                $user->id,
                $action->employee_user_id,
                $action->investigator_user_id,
                $data['investigator_user_id'] ?? null,
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.disciplinary.manage'), 403);

            $lockedCase = HrCase::query()->whereKey($action->case_id)->lockForUpdate()->firstOrFail();
            $lockedAction = HrDisciplinaryAction::query()
                ->whereKey($action->getKey())
                ->where('case_id', $lockedCase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $freshSiteAccess = new UserSiteAccessService;
            $this->assertCanAccessCase(
                $actor,
                $lockedCase,
                new HrCaseAccessService($freshSiteAccess),
            );
            abort_unless((int) $lockedAction->employee_user_id === (int) $lockedCase->user_id, 404);
            if ($lockedCase->status === 'closed' || $lockedAction->stage === 'closed') {
                throw ValidationException::withMessages([
                    'action' => 'A closed disciplinary record cannot be changed.',
                ]);
            }

            $this->assertAvailableActionParticipants($actor, $lockedCase, [
                'employee_user_id' => $lockedAction->employee_user_id,
                'investigator_user_id' => array_key_exists('investigator_user_id', $data)
                    ? $data['investigator_user_id']
                    : $lockedAction->investigator_user_id,
            ], $freshSiteAccess);

            $previousOutcome = trim((string) ($lockedAction->outcome ?? ''));
            $previousAppealReceived = (bool) $lockedAction->appeal_received;
            if ($previousOutcome !== ''
                && array_key_exists('outcome', $data)
                && trim((string) ($data['outcome'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'outcome' => 'A recorded disciplinary outcome cannot be cleared.',
                ]);
            }
            if ($previousAppealReceived
                && array_key_exists('appeal_received', $data)
                && ! (bool) $data['appeal_received']) {
                throw ValidationException::withMessages([
                    'appeal_received' => 'A recorded appeal cannot be withdrawn from the evidence history.',
                ]);
            }

            $prospectiveOutcome = array_key_exists('outcome', $data)
                ? trim((string) ($data['outcome'] ?? ''))
                : $previousOutcome;
            $goodFaithChecklist = array_key_exists('good_faith_checklist', $data)
                ? (array) ($data['good_faith_checklist'] ?? [])
                : (array) ($lockedAction->good_faith_checklist ?? []);
            $requiresGoodFaith = $prospectiveOutcome !== ''
                || $this->isOutcomePayload($data)
                || in_array($lockedAction->stage, ['outcome_decided', 'outcome_communicated', 'appeal', 'closed'], true);
            if ($requiresGoodFaith && ($missingChecks = $this->missingGoodFaithChecks($goodFaithChecklist)) !== []) {
                throw ValidationException::withMessages([
                    'good_faith' => 'The following good faith requirements must remain complete: '.implode(', ', $missingChecks),
                ]);
            }

            $this->assertProspectiveDateTruth($data, $lockedAction);

            $updateData = [
                ...$data,
                'updated_by' => $actor->id,
            ];
            if ($prospectiveOutcome !== '' && ! $lockedAction->outcome_decided_at) {
                $updateData['outcome_decided_at'] = now();
                $updateData['outcome_decided_by'] = $actor->id;
            }
            if (($data['appeal_received'] ?? false) && ! $lockedAction->appeal_received_at) {
                $updateData['appeal_received_at'] = now();
            }

            $lockedAction->update($updateData);
            $currentOutcome = trim((string) ($lockedAction->outcome ?? ''));
            $outcomeChanged = $currentOutcome !== '' && $currentOutcome !== $previousOutcome;
            if ($outcomeChanged) {
                $summary = mb_strlen($currentOutcome) > 240
                    ? mb_substr($currentOutcome, 0, 237).'...'
                    : $currentOutcome;
                $this->logDisciplinaryCaseEvent(
                    $lockedAction,
                    $actor->id,
                    'Disciplinary outcome recorded',
                    'Outcome summary: '.$summary,
                );
            }
            if (! $previousAppealReceived && (bool) $lockedAction->appeal_received) {
                $this->logDisciplinaryCaseEvent(
                    $lockedAction,
                    $actor->id,
                    'Disciplinary appeal received',
                    'An appeal was recorded for this disciplinary action.',
                );
            }

            return [$lockedAction->fresh(), $outcomeChanged];
        });

        // Dismissal outcome → surface an explicit "Start offboarding" next step
        // (never auto-create the checklist).
        if ($outcomeChanged) {
            if ($cta = $this->offboardingCtaFor($action, $user)) {
                return redirect()->back()
                    ->with('success', 'Disciplinary action updated. Outcome is dismissal — start offboarding when ready.')
                    ->with('offboarding_cta', $cta);
            }
        }

        return redirect()->back()->with('success', 'Disciplinary action updated.');
    }

    /**
     * Advance a disciplinary action to the next stage.
     *
     * Validates the good faith checklist before allowing outcome stages.
     */
    public function advanceStage(Request $request, HrDisciplinaryAction $action)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);
        $this->actionCaseForUser($user, $action);

        $outcomeStages = ['outcome_decided', 'outcome_communicated', 'closed'];
        [$action, $nextStage] = DB::transaction(function () use ($user, $action, $outcomeStages): array {
            $locks = $this->mutationLocks->lock([
                $user->id,
                $action->employee_user_id,
                $action->investigator_user_id,
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.disciplinary.manage'), 403);

            $lockedCase = HrCase::query()->whereKey($action->case_id)->lockForUpdate()->firstOrFail();
            $lockedAction = HrDisciplinaryAction::query()
                ->whereKey($action->getKey())
                ->where('case_id', $lockedCase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanAccessCase(
                $actor,
                $lockedCase,
                new HrCaseAccessService(new UserSiteAccessService),
            );
            abort_unless((int) $lockedAction->employee_user_id === (int) $lockedCase->user_id, 404);
            if ($lockedCase->status === 'closed') {
                throw ValidationException::withMessages([
                    'stage' => 'A disciplinary action cannot advance after its HR case is closed.',
                ]);
            }

            $currentIndex = array_search($lockedAction->stage, self::STAGES, true);
            if ($currentIndex === false || $currentIndex >= count(self::STAGES) - 1) {
                throw ValidationException::withMessages([
                    'stage' => 'Cannot advance beyond the final stage.',
                ]);
            }
            $nextStage = self::STAGES[$currentIndex + 1];

            if (in_array($nextStage, $outcomeStages, true)) {
                $missingChecks = $this->missingGoodFaithChecks(
                    (array) ($lockedAction->good_faith_checklist ?? []),
                );
                if ($missingChecks !== []) {
                    throw ValidationException::withMessages([
                        'good_faith' => 'The following good faith requirements must be completed before proceeding: '.implode(', ', $missingChecks),
                    ]);
                }
            }
            if ($nextStage === 'outcome_decided'
                && (trim((string) ($lockedAction->outcome ?? '')) === ''
                    || ! $lockedAction->outcome_decided_at)) {
                throw ValidationException::withMessages([
                    'outcome' => 'Record the disciplinary outcome and decision evidence before advancing.',
                ]);
            }

            $updateData = [
                'stage' => $nextStage,
                'updated_by' => $actor->id,
            ];
            if ($nextStage === 'outcome_communicated' && ! $lockedAction->outcome_communicated_at) {
                $updateData['outcome_communicated_at'] = now();
            }

            $fromStage = (string) $lockedAction->stage;
            $lockedAction->update($updateData);
            $this->logDisciplinaryCaseEvent(
                $lockedAction,
                $actor->id,
                'Disciplinary stage advanced',
                'Stage changed from '
                    .$this->humanizeStage($fromStage)
                    .' to '
                    .$this->humanizeStage($nextStage)
                    .'.',
            );

            return [$lockedAction->fresh(), $nextStage];
        });

        // Advancing a dismissal into an outcome stage → same explicit
        // "Start offboarding" next step as recording the outcome.
        if (in_array($nextStage, $outcomeStages, true) && ($cta = $this->offboardingCtaFor($action, $user))) {
            return redirect()->back()
                ->with('success', "Disciplinary action advanced to: {$nextStage}. Outcome is dismissal — start offboarding when ready.")
                ->with('offboarding_cta', $cta);
        }

        return redirect()->back()->with('success', "Disciplinary action advanced to: {$nextStage}.");
    }

    /**
     * Build the "Start offboarding" next-step payload when this action's
     * outcome indicates dismissal. Vocabulary: `action_type` carries the
     * canonical 'dismissal' value (ACTION_TYPE_OPTIONS); the free-text
     * `outcome` is checked for "dismiss" as a fallback for 'other' types.
     * Returns null when not a dismissal or the employee has no HR profile.
     *
     * @return array{label: string, url: string, employee_name: ?string}|null
     */
    protected function offboardingCtaFor(HrDisciplinaryAction $action, User $viewer): ?array
    {
        $isDismissal = $action->action_type === 'dismissal'
            || str_contains(mb_strtolower((string) $action->outcome), 'dismiss');

        if (! $isDismissal || ! $action->employee_user_id) {
            return null;
        }

        $employeeIsAvailable = $this->visibleStaffQuery($viewer)
            ->whereKey((int) $action->employee_user_id)
            ->exists();
        if (! $employeeIsAvailable) {
            return null;
        }

        $profileId = HrEmployeeProfile::query()
            ->where('user_id', $action->employee_user_id)
            ->value('id');

        if (! $profileId) {
            return null;
        }

        return [
            'label' => 'Start offboarding',
            'url' => "/hr/offboarding?new=1&employee={$profileId}",
            'employee_name' => $action->employee?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isOutcomePayload(array $payload): bool
    {
        foreach (['outcome', 'outcome_rationale', 'outcome_document_path', 'outcome_decided_at', 'outcome_communicated_at'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if (is_string($value)) {
                if (trim($value) !== '') {
                    return true;
                }

                continue;
            }

            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<int, string>
     */
    protected function missingGoodFaithChecks(array $checklist): array
    {
        $completed = collect($checklist)
            ->filter(fn ($value) => (bool) $value)
            ->keys()
            ->values()
            ->all();

        return array_values(array_diff(self::REQUIRED_GOOD_FAITH_CHECKS, $completed));
    }

    /**
     * Normalise a stored checklist to booleans keyed by the required checks.
     * Public static so the case show page (hosting the edit wizard) reuses it.
     *
     * @param  array<string, mixed>  $checklist
     * @return array<string, bool>
     */
    public static function normalizeGoodFaithChecklist(array $checklist): array
    {
        $normalized = [];
        foreach (self::REQUIRED_GOOD_FAITH_CHECKS as $key) {
            $normalized[$key] = (bool) ($checklist[$key] ?? false);
        }

        foreach ($checklist as $key => $value) {
            if (array_key_exists($key, $normalized)) {
                continue;
            }
            $normalized[$key] = (bool) $value;
        }

        return $normalized;
    }

    protected function humanizeStage(string $stage): string
    {
        return str_replace('_', ' ', $stage);
    }

    protected function logDisciplinaryCaseEvent(
        HrDisciplinaryAction $action,
        int $createdBy,
        string $title,
        ?string $description = null
    ): void {
        HrCaseEvent::query()->create([
            'case_id' => $action->case_id,
            'event_type' => 'investigation_update',
            'title' => $title,
            'description' => $description,
            'occurred_at' => now(),
            'visibility' => 'internal',
            'created_by' => $createdBy,
        ]);
    }

    private function actionCaseForUser(User $viewer, HrDisciplinaryAction $action): HrCase
    {
        $case = $action->hrCase()->firstOrFail();
        $this->assertCanAccessCase($viewer, $case);

        abort_unless(
            (int) $action->employee_user_id === (int) $case->user_id,
            404,
        );

        return $case;
    }

    private function assertCanAccessCase(
        User $viewer,
        HrCase $case,
        ?HrCaseAccessService $caseAccess = null,
    ): void {
        abort_unless(
            ($caseAccess ?? $this->caseAccess)
                ->applyVisibleCaseScope(HrCase::query(), $viewer)
                ->whereKey($case->getKey())
                ->exists(),
            404,
        );
    }

    /** @return Builder<User> */
    private function visibleStaffQuery(
        User $viewer,
        ?UserSiteAccessService $siteAccess = null,
    ): Builder {
        return ($siteAccess ?? $this->siteAccess)->applyStaffScope(User::query(), $viewer);
    }

    private function visibleStaffRule(User $viewer): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($viewer): void {
            if (! is_numeric($value)
                || ! $this->visibleStaffQuery($viewer)->whereKey((int) $value)->exists()) {
                $fail('The selected staff member is not available.');
            }
        };
    }

    /** @param array<string, mixed> $data */
    private function assertAvailableActionParticipants(
        User $viewer,
        HrCase $case,
        array $data,
        ?UserSiteAccessService $siteAccess = null,
    ): void {
        $errors = [];
        $employeeId = $data['employee_user_id'] ?? null;
        if (! is_numeric($employeeId)
            || (int) $employeeId !== (int) $case->user_id
            || ! $this->visibleStaffQuery($viewer, $siteAccess)->whereKey((int) $employeeId)->exists()) {
            $errors['employee_user_id'] = 'The selected staff member is not available.';
        }

        $investigatorId = $data['investigator_user_id'] ?? null;
        if ($investigatorId !== null
            && (! is_numeric($investigatorId)
                || ! $this->visibleStaffQuery($viewer, $siteAccess)->whereKey((int) $investigatorId)->exists())) {
            $errors['investigator_user_id'] = 'The selected staff member is not available.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertProspectiveDateTruth(
        array $data,
        ?HrDisciplinaryAction $action,
    ): void {
        $noticeIssuedAt = $this->prospectiveDate($data, $action, 'notice_issued_at');
        $meetingScheduledAt = $this->prospectiveDate($data, $action, 'meeting_scheduled_at');
        $meetingHeldAt = $this->prospectiveDate($data, $action, 'meeting_held_at');
        $responseDeadline = $this->prospectiveDate($data, $action, 'response_deadline');
        $errors = [];

        if ($noticeIssuedAt?->isFuture()) {
            $errors['notice_issued_at'] = 'Notice evidence cannot be dated in the future.';
        }
        if ($meetingHeldAt?->isFuture()) {
            $errors['meeting_held_at'] = 'Meeting evidence cannot be dated in the future.';
        }
        if ($meetingHeldAt && ! $meetingScheduledAt) {
            $errors['meeting_held_at'] = 'Record the scheduled meeting before recording that it was held.';
        } elseif ($meetingHeldAt && $meetingScheduledAt && $meetingHeldAt->lt($meetingScheduledAt)) {
            $errors['meeting_held_at'] = 'The meeting cannot be held before it was scheduled.';
        }
        if ($noticeIssuedAt && $meetingScheduledAt && $meetingScheduledAt->lt($noticeIssuedAt)) {
            $errors['meeting_scheduled_at'] = 'The meeting cannot be scheduled before notice was issued.';
        }
        if ($noticeIssuedAt && $responseDeadline && $responseDeadline->lt($noticeIssuedAt)) {
            $errors['response_deadline'] = 'The response deadline cannot be before notice was issued.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $data */
    private function prospectiveDate(
        array $data,
        ?HrDisciplinaryAction $action,
        string $field,
    ): ?Carbon {
        $value = array_key_exists($field, $data)
            ? $data[$field]
            : $action?->{$field};
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse($value);
    }

    /** @return array<int, int> */
    private function casePeopleIds(HrCase $case): array
    {
        return collect([
            $case->user_id,
            $case->reported_by,
            $case->assigned_to,
            $case->created_by,
            $case->updated_by,
            ...((array) ($case->access_list ?? [])),
        ])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
