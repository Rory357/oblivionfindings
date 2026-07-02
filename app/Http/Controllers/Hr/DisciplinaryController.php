<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreDisciplinaryActionRequest;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DisciplinaryController extends Controller
{
    use ResolvesHrTenant;

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $action->tenant_id);

        $hrCase = $action->hrCase()->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $hrCase->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);

        $data = $request->validated();

        $action = HrDisciplinaryAction::create([
            'tenant_id' => $tenantId,
            'case_id' => $case->id,
            'stage' => 'allegation_raised',
            'created_by' => $user->id,
            ...$data,
        ]);

        $this->logDisciplinaryCaseEvent(
            $action,
            $user->id,
            'Disciplinary action created',
            'Action type: ' . str_replace('_', ' ', (string) $action->action_type) . '. Stage: allegation raised.'
        );

        return redirect()->back()->with('success', 'Disciplinary action created.');
    }

    /**
     * Update an existing disciplinary action.
     */
    public function update(Request $request, HrDisciplinaryAction $action)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $action->tenant_id);
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $investigatorRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');

        $data = $request->validate([
            'action_type' => ['sometimes', 'string', 'in:verbal_warning,written_warning,final_warning,suspension,dismissal,other'],
            'allegation_summary' => ['sometimes', 'string', 'max:10000'],
            'investigation_notes' => ['nullable', 'string', 'max:10000'],
            'investigator_user_id' => ['nullable', 'integer', $investigatorRule],
            'notice_issued_at' => ['nullable', 'date'],
            'notice_document_path' => ['nullable', 'string', 'max:500'],
            'meeting_scheduled_at' => ['nullable', 'date'],
            'meeting_location' => ['nullable', 'string', 'max:255'],
            'support_person_advised' => ['boolean'],
            'meeting_held_at' => ['nullable', 'date'],
            'meeting_notes' => ['nullable', 'string', 'max:10000'],
            'meeting_attendees' => ['nullable', 'array'],
            'meeting_attendees.*' => ['string', 'max:255'],
            'employee_response' => ['nullable', 'string', 'max:10000'],
            'response_deadline' => ['nullable', 'date'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'outcome_rationale' => ['nullable', 'string', 'max:5000'],
            'outcome_document_path' => ['nullable', 'string', 'max:500'],
            'good_faith_checklist' => ['nullable', 'array'],
            'appeal_received' => ['nullable', 'boolean'],
            'appeal_notes' => ['nullable', 'string', 'max:10000'],
            'appeal_outcome' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['updated_by'] = $user->id;

        if ($this->isOutcomePayload($data)) {
            $goodFaithChecklist = array_key_exists('good_faith_checklist', $data)
                ? (array) ($data['good_faith_checklist'] ?? [])
                : (array) ($action->good_faith_checklist ?? []);

            $missingChecks = $this->missingGoodFaithChecks($goodFaithChecklist);
            if ($missingChecks !== []) {
                return redirect()->back()->withErrors([
                    'good_faith' => 'The following good faith requirements must be completed before recording an outcome: '
                        . implode(', ', $missingChecks),
                ]);
            }
        }

        // Auto-set timestamps for outcome fields
        if (isset($data['outcome']) && $data['outcome'] && ! $action->outcome_decided_at) {
            $data['outcome_decided_at'] = now();
            $data['outcome_decided_by'] = $user->id;
        }

        if (isset($data['appeal_received']) && $data['appeal_received'] && ! $action->appeal_received_at) {
            $data['appeal_received_at'] = now();
        }

        $previousOutcome = trim((string) ($action->outcome ?? ''));
        $previousAppealReceived = (bool) $action->appeal_received;
        $action->update($data);

        $currentOutcome = trim((string) ($action->outcome ?? ''));
        if ($currentOutcome !== '' && $currentOutcome !== $previousOutcome) {
            $summary = $currentOutcome;
            if (mb_strlen($summary) > 240) {
                $summary = mb_substr($summary, 0, 237) . '...';
            }

            $this->logDisciplinaryCaseEvent(
                $action,
                $user->id,
                'Disciplinary outcome recorded',
                'Outcome summary: ' . $summary
            );
        }

        if (! $previousAppealReceived && (bool) $action->appeal_received) {
            $this->logDisciplinaryCaseEvent(
                $action,
                $user->id,
                'Disciplinary appeal received',
                'An appeal was recorded for this disciplinary action.'
            );
        }

        // Dismissal outcome → surface an explicit "Start offboarding" next step
        // (never auto-create the checklist).
        if ($currentOutcome !== '' && $currentOutcome !== $previousOutcome) {
            if ($cta = $this->offboardingCtaFor($action)) {
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $action->tenant_id);

        $currentIndex = array_search($action->stage, self::STAGES, true);

        if ($currentIndex === false || $currentIndex >= count(self::STAGES) - 1) {
            return redirect()->back()->withErrors(['stage' => 'Cannot advance beyond the final stage.']);
        }

        $nextStage = self::STAGES[$currentIndex + 1];

        // Validate good faith checklist before outcome-related stages
        $outcomeStages = ['outcome_decided', 'outcome_communicated', 'closed'];
        if (in_array($nextStage, $outcomeStages, true)) {
            $missingChecks = $this->missingGoodFaithChecks((array) ($action->good_faith_checklist ?? []));

            if ($missingChecks !== []) {
                return redirect()->back()->withErrors([
                    'good_faith' => 'The following good faith requirements must be completed before proceeding: ' . implode(', ', $missingChecks),
                ]);
            }
        }

        $updateData = [
            'stage' => $nextStage,
            'updated_by' => $user->id,
        ];

        // Auto-set milestone timestamps when advancing
        if ($nextStage === 'outcome_communicated' && ! $action->outcome_communicated_at) {
            $updateData['outcome_communicated_at'] = now();
        }

        $fromStage = (string) $action->stage;
        $action->update($updateData);
        $this->logDisciplinaryCaseEvent(
            $action,
            $user->id,
            'Disciplinary stage advanced',
            'Stage changed from '
                . $this->humanizeStage($fromStage)
                . ' to '
                . $this->humanizeStage((string) $nextStage)
                . '.'
        );

        // Advancing a dismissal into an outcome stage → same explicit
        // "Start offboarding" next step as recording the outcome.
        if (in_array($nextStage, $outcomeStages, true) && ($cta = $this->offboardingCtaFor($action))) {
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
    protected function offboardingCtaFor(HrDisciplinaryAction $action): ?array
    {
        $isDismissal = $action->action_type === 'dismissal'
            || str_contains(mb_strtolower((string) $action->outcome), 'dismiss');

        if (! $isDismissal || ! $action->employee_user_id) {
            return null;
        }

        $profileId = HrEmployeeProfile::query()
            ->where('tenant_id', $action->tenant_id)
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
     * @param array<string, mixed> $payload
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
     * @param array<string, mixed> $checklist
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
     * @param array<string, mixed> $checklist
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
}
