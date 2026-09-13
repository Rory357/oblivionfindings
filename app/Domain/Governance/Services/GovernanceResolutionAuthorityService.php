<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\StrategicPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Explicit decision authority for carried resolutions (GOV-R03 / GOV-R08).
 *
 * Authority is never inferred from motion wording, titles, keywords or
 * JSON hints. A draft decision paper is bound to one exact record; the
 * binding stores that record's revision and a fingerprint of its canonical
 * terms. Applying the decision re-derives the fingerprint from the locked
 * record and consumes the binding once.
 */
class GovernanceResolutionAuthorityService
{
    private const LABELS = [
        GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE => 'voting rules profile',
        GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN => 'strategic plan',
        GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT => 'budget adjustment',
        GovernanceResolutionBinding::SUBJECT_BUDGET => 'budget',
        GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW => 'performance review',
    ];

    /**
     * Bind a draft decision paper to the exact record it asks the board to
     * approve. Replaces any earlier binding on the same draft paper.
     */
    public function bind(Resolution $resolution, string $subjectType, int $subjectId, User $actor): GovernanceResolutionBinding
    {
        if (! in_array($subjectType, GovernanceResolutionBinding::SUBJECT_TYPES, true)) {
            throw new \DomainException('Unsupported decision authority subject.');
        }

        return DB::transaction(function () use ($resolution, $subjectType, $subjectId, $actor): GovernanceResolutionBinding {
            $lockedResolution = Resolution::query()->whereKey($resolution->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedResolution->isDraft()) {
                throw new \DomainException('Decision authority can only be bound while the paper is a draft.');
            }

            $attributes = match ($subjectType) {
                GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE => $this->votingProfileBindingAttributes($lockedResolution, $subjectId, $actor),
                GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN => $this->strategicPlanBindingAttributes($subjectId, $actor),
                GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT => $this->budgetAdjustmentBindingAttributes($subjectId, $actor),
                GovernanceResolutionBinding::SUBJECT_BUDGET => $this->budgetBindingAttributes($subjectId, $actor),
                GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW => $this->performanceReviewBindingAttributes($subjectId, $actor),
            };

            GovernanceResolutionBinding::query()
                ->where('resolution_id', $lockedResolution->getKey())
                ->lockForUpdate()
                ->get()
                ->each(fn (GovernanceResolutionBinding $existing) => $existing->delete());

            $binding = GovernanceResolutionBinding::create([
                ...$attributes,
                'resolution_id' => $lockedResolution->getKey(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_fingerprint' => self::fingerprint($attributes['bound_terms']),
                'bound_by' => $actor->getKey(),
                'bound_at' => now(),
            ]);

            GovernanceAuditService::log('resolution.authority_bound', 'Resolution', (int) $lockedResolution->getKey(), [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_revision' => $binding->subject_revision,
                'subject_fingerprint' => $binding->subject_fingerprint,
            ]);

            return $binding;
        }, 3);
    }

    /**
     * Apply the `authority_binding` input of the decision-paper authoring
     * form: `{subject_type, subject_id}` binds; an empty value unbinds; an
     * absent key leaves the paper's authority untouched. The fingerprint is
     * always derived server-side from the locked subject record.
     *
     * @param  array<string, mixed>  $validated
     */
    public function applyAuthoringInput(Resolution $resolution, array $validated, User $actor): void
    {
        if (! array_key_exists('authority_binding', $validated)) {
            return;
        }

        $input = $validated['authority_binding'];

        try {
            if (! is_array($input) || empty($input['subject_type']) || empty($input['subject_id'])) {
                $this->unbind($resolution, $actor);

                return;
            }

            $this->bind($resolution, (string) $input['subject_type'], (int) $input['subject_id'], $actor);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'authority_binding' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The decision-paper wizard's approval picker: the bindable records plus
     * one group per supported subject list (derived, never hardcoded).
     *
     * @return array{authoritySubjects: array<string, mixed>, authoritySubjectGroups: list<array{key: string, subject_type: string, label: string}>}
     */
    public function authoringChoices(User $actor): array
    {
        $options = $this->authoringOptions($actor);

        $groups = [];
        foreach (array_keys($options) as $key) {
            $subjectType = \Illuminate\Support\Str::singular((string) $key);
            if (! in_array($subjectType, GovernanceResolutionBinding::SUBJECT_TYPES, true)) {
                continue;
            }
            $groups[] = [
                'key' => (string) $key,
                'subject_type' => $subjectType,
                'label' => \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', (string) $key)),
            ];
        }

        return [
            'authoritySubjects' => $options,
            'authoritySubjectGroups' => $groups,
        ];
    }

    /**
     * Records a decision paper author may bind, limited to what they can view.
     *
     * Performance reviews are privacy-restricted: only reviews the actor may
     * both view (record audience) and decide on are listed, labelled with
     * safe identity only — never the rating, assessment or board decision.
     *
     * @return array{budget_adjustments: array<int, array<string, mixed>>, budgets: array<int, array<string, mixed>>, strategic_plans: array<int, array<string, mixed>>, performance_reviews: array<int, array<string, mixed>>, voting_profiles: array<int, array<string, mixed>>}
     */
    public function authoringOptions(User $actor): array
    {
        $gate = Gate::forUser($actor);

        $adjustments = BudgetAdjustment::query()
            ->where('status', 'submitted')
            ->with(['budget', 'lineItem:id,description,category'])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(fn (BudgetAdjustment $adjustment) => $adjustment->budget && $gate->allows('view', $adjustment->budget))
            ->map(fn (BudgetAdjustment $adjustment) => [
                'id' => (int) $adjustment->id,
                'label' => sprintf(
                    '%s — %s: %s $%s',
                    $adjustment->budget->title ?: 'Budget '.$adjustment->budget->fiscal_year,
                    $adjustment->lineItem?->description ?? 'Line #'.$adjustment->budget_line_item_id,
                    ucfirst((string) $adjustment->adjustment_type),
                    number_format((float) $adjustment->amount, 2),
                ),
                'budget_id' => (int) $adjustment->budget_id,
                'budget_line_item_id' => (int) $adjustment->budget_line_item_id,
                'direction' => $adjustment->adjustment_type,
                'amount' => self::money($adjustment->amount),
                'reason' => $adjustment->reason,
            ])
            ->values()
            ->all();

        $plans = StrategicPlan::query()
            ->whereIn('status', ['draft', 'review'])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(fn (StrategicPlan $plan) => $gate->allows('view', $plan))
            ->map(fn (StrategicPlan $plan) => [
                'id' => (int) $plan->id,
                'label' => sprintf('%s (version %d)', $plan->title, (int) ($plan->version_number ?? 1)),
                'version_number' => (int) ($plan->version_number ?? 1),
            ])
            ->values()
            ->all();

        $profiles = ! $actor->canDo('governance.settings.view') ? [] : GovernanceVotingProfile::query()
            ->where('is_active', false)
            ->with('committee:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (GovernanceVotingProfile $profile): bool {
                $reference = strtolower(trim((string) $profile->governing_document_reference));

                return $reference !== '' && ! str_contains($reference, 'candidate') && ! str_contains($reference, 'pending');
            })
            ->map(fn (GovernanceVotingProfile $profile) => [
                'id' => (int) $profile->id,
                'label' => sprintf(
                    '%s voting rules — %s%s',
                    $profile->committee?->name ?? 'Board',
                    $profile->governing_document_reference,
                    $profile->governing_document_version ? ' ('.$profile->governing_document_version.')' : '',
                ),
                'governing_body' => $profile->governing_body,
                'board_committee_id' => $profile->board_committee_id ? (int) $profile->board_committee_id : null,
            ])
            ->values()
            ->all();

        $budgets = ! $gate->allows('viewAny', Budget::class) ? [] : Budget::query()
            ->whereIn('status', ['drafting', 'proposed'])
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'fiscal_year', 'title', 'total_budget', 'currency', 'status', 'version_number'])
            ->filter(fn (Budget $budget) => $gate->allows('view', $budget))
            ->map(fn (Budget $budget) => [
                'id' => (int) $budget->id,
                'label' => sprintf(
                    '%s — FY %s (version %d): $%s',
                    $budget->title ?: 'Budget '.$budget->fiscal_year,
                    $budget->fiscal_year,
                    (int) ($budget->version_number ?? 1),
                    number_format((float) $budget->total_budget, 2),
                ),
                'fiscal_year' => (string) $budget->fiscal_year,
                'version_number' => (int) ($budget->version_number ?? 1),
                'total_budget' => self::money($budget->total_budget),
            ])
            ->values()
            ->all();

        // Privacy-restricted: the same record audience as the register, and
        // only reviews this actor may decide on. Identity-only columns are
        // selected so no restricted content is ever loaded for the list.
        $reviews = [];
        if ($actor->canDo('governance.performance.view') && $actor->canDo('governance.performance.manage')) {
            $reviewQuery = PerformanceReview::query()
                ->select(['id', 'reviewee_id', 'review_cycle', 'review_type', 'status'])
                ->where('status', '!=', 'completed')
                ->with('reviewee:id,name')
                ->orderByDesc('id')
                ->limit(100);
            app(GovernanceRecordAccessService::class)->scopePerformanceReviews($reviewQuery, $actor);

            $reviews = $reviewQuery->get()
                ->filter(fn (PerformanceReview $review) => $gate->allows('view', $review) && $gate->allows('update', $review))
                ->map(fn (PerformanceReview $review) => [
                    'id' => (int) $review->id,
                    'label' => sprintf(
                        'Performance review — %s · %s',
                        $review->reviewee?->name ?? 'Reviewee #'.$review->reviewee_id,
                        $review->review_cycle,
                    ),
                    'review_cycle' => (string) $review->review_cycle,
                    'review_type' => (string) $review->review_type,
                ])
                ->values()
                ->all();
        }

        return [
            'budget_adjustments' => $adjustments,
            'budgets' => $budgets,
            'strategic_plans' => $plans,
            'performance_reviews' => $reviews,
            'voting_profiles' => $profiles,
        ];
    }

    /**
     * Remove decision authority from a draft paper.
     */
    public function unbind(Resolution $resolution, User $actor): void
    {
        DB::transaction(function () use ($resolution, $actor): void {
            $lockedResolution = Resolution::query()->whereKey($resolution->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedResolution->isDraft()) {
                throw new \DomainException('Decision authority can only be changed while the paper is a draft.');
            }

            $bindings = GovernanceResolutionBinding::query()
                ->where('resolution_id', $lockedResolution->getKey())
                ->lockForUpdate()
                ->get();

            if ($bindings->isEmpty()) {
                return;
            }

            $bindings->each(fn (GovernanceResolutionBinding $binding) => $binding->delete());

            GovernanceAuditService::log('resolution.authority_unbound', 'Resolution', (int) $lockedResolution->getKey(), [
                'removed_by' => $actor->getKey(),
                'subjects' => $bindings->map(fn ($b) => ['type' => $b->subject_type, 'id' => $b->subject_id])->all(),
            ]);
        }, 3);
    }

    /**
     * Verify the carried resolution was explicitly bound to this exact
     * subject revision and consume that authority once. The caller must
     * already hold row locks on the resolution and the subject inside the
     * current transaction.
     *
     * @param  array<string, mixed>  $currentTerms  canonical terms derived from the locked subject
     *
     * @throws \DomainException when no usable binding matches
     */
    public function verifyAndConsume(
        Resolution $lockedResolution,
        string $subjectType,
        int $subjectId,
        array $currentTerms,
        ?int $actorId,
    ): GovernanceResolutionBinding {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Resolution authority must be verified inside the applying transaction.');
        }

        $label = self::LABELS[$subjectType] ?? 'record';

        $binding = GovernanceResolutionBinding::query()
            ->where('resolution_id', $lockedResolution->getKey())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->lockForUpdate()
            ->first();

        if (! $binding) {
            throw new \DomainException("The resolution was not explicitly bound to this {$label} before it was published for voting.");
        }

        if ($binding->isConsumed()) {
            throw new \DomainException("This resolution's authority over the {$label} has already been used.");
        }

        if (! hash_equals((string) $binding->subject_fingerprint, self::fingerprint($currentTerms))) {
            throw new \DomainException("The {$label} has changed since the resolution was bound to it; the board has not approved the current terms.");
        }

        $binding->forceFill([
            'consumed_at' => now(),
            'consumed_by' => $actorId,
        ])->save();

        GovernanceAuditService::log('resolution.authority_consumed', 'Resolution', (int) $lockedResolution->getKey(), [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_fingerprint' => $binding->subject_fingerprint,
        ]);

        return $binding;
    }

    /**
     * Effective committee of a resolution (its own or its meeting's).
     */
    public function resolutionCommitteeId(Resolution $resolution): ?int
    {
        $committeeId = $resolution->board_committee_id ?? $resolution->meeting?->board_committee_id;

        return $committeeId ? (int) $committeeId : null;
    }

    /**
     * A board profile can only be approved by a board resolution; a committee
     * profile by the board or by that same committee.
     */
    public function assertBodyMayApproveProfile(Resolution $resolution, GovernanceVotingProfile $profile): void
    {
        $resolutionCommitteeId = $this->resolutionCommitteeId($resolution);
        $profileCommitteeId = $profile->board_committee_id ? (int) $profile->board_committee_id : null;

        if ($resolutionCommitteeId === null) {
            return;
        }

        if ($profileCommitteeId === null || $resolutionCommitteeId !== $profileCommitteeId) {
            throw new \DomainException('A committee resolution cannot approve voting rules for a different governing body.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function votingProfileTerms(
        GovernanceVotingProfile $profile,
        ?string $documentReference = null,
        ?string $documentVersion = null,
    ): array {
        return [
            'subject_type' => GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
            'subject_id' => (int) $profile->getKey(),
            'governing_body' => (string) $profile->governing_body,
            'board_committee_id' => $profile->board_committee_id ? (int) $profile->board_committee_id : null,
            'legal_form' => $this->text($profile->legal_form),
            'governing_document_reference' => $this->text($documentReference ?? $profile->governing_document_reference),
            'governing_document_version' => $this->text($documentVersion ?? $profile->governing_document_version),
            'quorum_mode' => $this->text($profile->quorum_mode),
            'quorum_formula' => $this->text($profile->quorum_formula),
            'ordinary_threshold_formula' => $this->text($profile->ordinary_threshold_formula),
            'unanimous_denominator_formula' => $this->text($profile->unanimous_denominator_formula),
            'written_voting_permitted' => (bool) $profile->written_voting_permitted,
            'written_unanimity_required' => (bool) $profile->written_unanimity_required,
            'recusal_policy' => $this->text($profile->recusal_policy),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function strategicPlanTerms(StrategicPlan $plan): array
    {
        return [
            'subject_type' => GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN,
            'subject_id' => (int) $plan->getKey(),
            'version_number' => (int) ($plan->version_number ?? 1),
            'title' => $this->text($plan->title),
            'planning_horizon' => $this->text($plan->planning_horizon),
            'period_start' => $plan->period_start?->toDateString(),
            'period_end' => $plan->period_end?->toDateString(),
            'vision_statement' => $this->text($plan->vision_statement),
            'mission_statement' => $this->text($plan->mission_statement),
            'values' => is_array($plan->values) ? $plan->values : [],
            'supersedes_plan_id' => $plan->supersedes_plan_id ? (int) $plan->supersedes_plan_id : null,
            'goals' => $plan->goals()
                ->orderBy('id')
                ->get()
                ->map(fn ($goal) => [
                    'id' => (int) $goal->id,
                    'title' => $this->text($goal->title),
                    'description' => $this->text($goal->description),
                    'pillar' => $this->text($goal->pillar),
                    'timeframe' => $this->text($goal->timeframe),
                    'key_results' => is_array($goal->key_results) ? $goal->key_results : [],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function budgetAdjustmentTerms(BudgetAdjustment $adjustment): array
    {
        return [
            'subject_type' => GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT,
            'subject_id' => (int) $adjustment->getKey(),
            'budget_id' => (int) $adjustment->budget_id,
            'budget_line_item_id' => (int) $adjustment->budget_line_item_id,
            'direction' => (string) $adjustment->adjustment_type,
            'amount' => self::money($adjustment->amount),
            'reason' => $this->text($adjustment->reason),
        ];
    }

    /**
     * The whole budget the board is asked to approve: identity and version,
     * envelope and every budgeted line. Operational figures that move while
     * the budget runs (forecasts, actuals, variance notes) are not terms.
     *
     * @return array<string, mixed>
     */
    public function budgetTerms(Budget $budget): array
    {
        return [
            'subject_type' => GovernanceResolutionBinding::SUBJECT_BUDGET,
            'subject_id' => (int) $budget->getKey(),
            'version_number' => (int) ($budget->version_number ?? 1),
            'fiscal_year' => $this->text($budget->fiscal_year),
            'title' => $this->text($budget->title),
            'description' => $this->text($budget->description),
            'currency' => $this->text($budget->currency ?: 'NZD'),
            'total_budget' => self::money($budget->total_budget),
            'supersedes_budget_id' => $budget->supersedes_budget_id ? (int) $budget->supersedes_budget_id : null,
            'line_items' => $budget->lineItems()
                ->orderBy('id')
                ->get(['id', 'category', 'subcategory', 'description', 'account_code', 'budget_amount'])
                ->map(fn ($line) => [
                    'id' => (int) $line->id,
                    'category' => $this->text($line->category),
                    'subcategory' => $this->text($line->subcategory),
                    'description' => $this->text($line->description),
                    'account_code' => $this->text($line->account_code),
                    'budget_amount' => self::money($line->budget_amount),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The board's decision on a performance review. The review's identity
     * is recorded in the clear; the privacy-restricted content the board
     * decided (reviewee, rating, assessment, decision, notes, goal scores) is
     * covered by a keyed digest. The immutable binding is frozen into the
     * published decision paper, so it must never disclose that content —
     * yet any change to it after binding still changes the fingerprint.
     *
     * @return array<string, mixed>
     */
    public function performanceReviewTerms(PerformanceReview $review): array
    {
        $restricted = [
            'reviewee_id' => (int) $review->reviewee_id,
            'overall_rating' => $this->text($review->overall_rating),
            'overall_assessment' => $this->text($review->overall_assessment),
            'board_decision' => $this->text($review->board_decision),
            'decision_notes' => $this->text($review->decision_notes),
            'goals' => $review->goals()
                ->orderBy('id')
                ->get(['id', 'pillar', 'goal_description', 'success_criteria', 'weight', 'target_score', 'actual_score', 'board_assessment'])
                ->map(fn ($goal) => [
                    'id' => (int) $goal->id,
                    'pillar' => $this->text($goal->pillar),
                    'goal_description' => $this->text($goal->goal_description),
                    'success_criteria' => $this->text($goal->success_criteria),
                    'weight' => self::money($goal->weight),
                    'target_score' => self::money($goal->target_score),
                    'actual_score' => $goal->actual_score === null ? null : self::money($goal->actual_score),
                    'board_assessment' => $this->text($goal->board_assessment),
                ])
                ->values()
                ->all(),
        ];

        return [
            'subject_type' => GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW,
            'subject_id' => (int) $review->getKey(),
            'review_cycle' => $this->text($review->review_cycle),
            'review_type' => $this->text($review->review_type),
            'period_start' => $review->period_start?->toDateString(),
            'period_end' => $review->period_end?->toDateString(),
            'decision_digest' => hash_hmac(
                'sha256',
                json_encode(
                    self::canonical($restricted),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ),
                'governance.resolution-authority.performance-review|'.config('app.key'),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    public static function fingerprint(array $terms): string
    {
        return hash('sha256', json_encode(
            self::canonical($terms),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    public static function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function votingProfileBindingAttributes(Resolution $resolution, int $profileId, User $actor): array
    {
        abort_unless($actor->canDo('governance.settings.view'), 403);

        $profile = GovernanceVotingProfile::query()->whereKey($profileId)->lockForUpdate()->first();
        if (! $profile) {
            throw new \DomainException('The selected voting rules profile does not exist.');
        }

        if ($profile->is_active) {
            throw new \DomainException('The selected voting rules profile is already active; record proposed rule changes as a new profile first.');
        }

        $reference = strtolower(trim((string) $profile->governing_document_reference));
        if ($reference === '' || str_contains($reference, 'candidate') || str_contains($reference, 'pending')) {
            throw new \DomainException('Record the actual governing document reference on the voting rules profile before binding a decision paper to it.');
        }

        $this->assertBodyMayApproveProfile($resolution, $profile);

        $terms = $this->votingProfileTerms($profile);

        return [
            'subject_revision' => $terms['governing_document_version'],
            'governing_body' => $terms['governing_body'],
            'board_committee_id' => $terms['board_committee_id'],
            'document_reference' => $terms['governing_document_reference'],
            'document_version' => $terms['governing_document_version'],
            'bound_terms' => $terms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function strategicPlanBindingAttributes(int $planId, User $actor): array
    {
        $plan = StrategicPlan::query()->whereKey($planId)->lockForUpdate()->first();
        if (! $plan) {
            throw new \DomainException('The selected strategic plan does not exist.');
        }

        abort_unless(Gate::forUser($actor)->allows('view', $plan), 403);

        if (! $plan->isDraft()) {
            throw new \DomainException('Only a draft or in-review strategic plan can be bound to a decision paper.');
        }

        $terms = $this->strategicPlanTerms($plan);

        return [
            'subject_revision' => 'v'.$terms['version_number'],
            'bound_terms' => $terms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetAdjustmentBindingAttributes(int $adjustmentId, User $actor): array
    {
        $adjustment = BudgetAdjustment::query()->whereKey($adjustmentId)->lockForUpdate()->first();
        if (! $adjustment || ! $adjustment->budget) {
            throw new \DomainException('The selected budget adjustment does not exist.');
        }

        abort_unless(Gate::forUser($actor)->allows('view', $adjustment->budget), 403);

        if ($adjustment->status !== 'submitted') {
            throw new \DomainException('Only a submitted budget adjustment awaiting decision can be bound to a decision paper.');
        }

        $terms = $this->budgetAdjustmentTerms($adjustment);

        return [
            'subject_revision' => null,
            'budget_id' => $terms['budget_id'],
            'budget_line_item_id' => $terms['budget_line_item_id'],
            'amount' => $terms['amount'],
            'direction' => $terms['direction'],
            'bound_terms' => $terms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetBindingAttributes(int $budgetId, User $actor): array
    {
        $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->first();
        if (! $budget) {
            throw new \DomainException('The selected budget does not exist.');
        }

        abort_unless(Gate::forUser($actor)->allows('view', $budget), 403);

        if (! $budget->isDrafting() && ! $budget->isProposed()) {
            throw new \DomainException('Only a drafting or proposed budget awaiting board approval can be bound to a decision paper.');
        }

        $terms = $this->budgetTerms($budget);

        return [
            'subject_revision' => 'v'.$terms['version_number'],
            'budget_id' => $terms['subject_id'],
            'amount' => $terms['total_budget'],
            'bound_terms' => $terms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function performanceReviewBindingAttributes(int $reviewId, User $actor): array
    {
        $review = PerformanceReview::query()->whereKey($reviewId)->lockForUpdate()->first();
        if (! $review) {
            throw new \DomainException('The selected performance review does not exist.');
        }

        $gate = Gate::forUser($actor);
        abort_unless(
            $actor->canDo('governance.performance.manage')
                && $gate->allows('view', $review)
                && $gate->allows('update', $review),
            403,
        );

        if ($review->isCompleted()) {
            throw new \DomainException('Only a performance review still awaiting the board decision can be bound to a decision paper.');
        }

        return [
            'subject_revision' => null,
            'bound_terms' => $this->performanceReviewTerms($review),
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn ($item) => self::canonical($item), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
