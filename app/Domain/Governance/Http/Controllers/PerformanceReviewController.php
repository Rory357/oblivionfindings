<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Governance\Services\PerformanceReviewService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PerformanceReviewController extends Controller
{
    /**
     * Roles whose holders are reviewed by the board, in list order.
     *
     * @var array<string, string>
     */
    private const REVIEWEE_ROLES = [
        'ceo' => 'Chief executive',
        'coo' => 'Chief operating officer',
        'cfo' => 'Chief financial officer',
    ];

    /** Status filter values → stored statuses ("drafting" is the stored draft). */
    private const STATUS_FILTERS = [
        'draft' => ['drafting', 'draft'],
        'self_review' => ['self_review'],
        'peer_review' => ['peer_review'],
        'board_review' => ['board_review'],
        'completed' => ['completed'],
    ];

    public function __construct(
        protected PerformanceReviewService $performanceService
    ) {}

    /**
     * Legacy deep link: the new-review wizard is a dialog on the index.
     * Authorise exactly as the retired page did, then open it there.
     */
    public function create()
    {
        $this->authorize('create', PerformanceReview::class);

        return redirect()->route('governance.performance.index', ['create' => 1]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', PerformanceReview::class);

        $user = $request->user();
        $access = app(GovernanceRecordAccessService::class);

        // Rows only carry what the list shows: counts, never the goals, their
        // board scores or comments (P0-11: nothing confidential in the payload).
        $query = PerformanceReview::query()
            ->with('reviewee:id,name')
            ->withCount(['goals', 'kpis']);
        $access->scopePerformanceReviews($query, $user);

        // Header counts come from the same audience scope as the list, before
        // the header filters narrow it, so totals never equal "rows shown".
        $summaryBase = $access->scopePerformanceReviews(PerformanceReview::query(), $user);
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'active' => (clone $summaryBase)->where('status', '!=', 'completed')->count(),
            'completed' => (clone $summaryBase)->where('status', 'completed')->count(),
            'board_review' => (clone $summaryBase)->where('status', 'board_review')->count(),
        ];

        if ($request->has('reviewee_id')) {
            $query->byReviewee((int) $request->reviewee_id);
        }

        $status = $request->string('status')->toString();
        if ($status === 'active') {
            $query->where('status', '!=', 'completed');
        } elseif (isset(self::STATUS_FILTERS[$status])) {
            $query->whereIn('status', self::STATUS_FILTERS[$status]);
        }

        $reviewType = $request->string('review_type')->toString();
        if (in_array($reviewType, ['quarterly', 'annual', 'ad_hoc'], true)) {
            $query->where('review_type', $reviewType);
        }

        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($inner) use ($search) {
                $inner->where('review_cycle', 'like', "%{$search}%")
                    ->orWhereHas('reviewee', fn ($reviewee) => $reviewee->where('name', 'like', "%{$search}%"));
            });
        }

        $canCreate = $this->canWriteReviews($user, 'create');

        $reviews = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function (PerformanceReview $review) use ($user) {
                $hidden = $this->assessmentHiddenFrom($user, $review);
                if ($hidden) {
                    $this->maskBoardAssessment($review);
                }

                $review->setAttribute('assessment_hidden', $hidden);
                $review->setAttribute('cycle_label', PerformanceReview::cycleLabel($review->review_cycle));

                return $review;
            });

        $cycles = $this->getReviewCycles();

        return Inertia::render('Governance/Performance/Index', [
            'reviews' => $reviews,
            'review_cycles' => $cycles['options'],
            'current_cycle' => $cycles['current_cycle'],
            'current_financial_year' => $cycles['current_financial_year'],
            'summary' => $summary,
            'filters' => [
                'status' => $status ?: null,
                'review_type' => $reviewType ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ],
            'can_create' => $canCreate,
            // New-review wizard options, only for viewers who may create.
            'reviewees' => $canCreate ? $this->revieweeOptions() : [],
        ]);
    }

    /**
     * Mirrors the write routes: the policy AND the route's manage permission,
     * so a button never renders for a request the route would refuse.
     */
    protected function canWriteReviews(?User $user, string $ability, ?PerformanceReview $review = null): bool
    {
        if (! $user || ! $user->canDo('governance.performance.manage')) {
            return false;
        }

        return $review
            ? $user->can($ability, $review)
            : $user->can($ability, PerformanceReview::class);
    }

    /**
     * The people the board reviews: the CEO, then executives, plus anyone who
     * already has a review (so an existing reviewee is never unselectable).
     *
     * @return array<int, array{user_id:int,name:string,role_label:string|null}>
     */
    protected function revieweeOptions(): array
    {
        $roleNames = array_keys(self::REVIEWEE_ROLES);

        return User::query()
            ->select(['id', 'name'])
            ->where(function ($query) use ($roleNames) {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', $roleNames))
                    ->orWhereIn('id', PerformanceReview::query()->select('reviewee_id'));
            })
            ->with('roles:id,name')
            ->get()
            ->map(function (User $candidate) use ($roleNames) {
                $roleName = collect($roleNames)
                    ->first(fn (string $name) => $candidate->roles->contains('name', $name));

                return [
                    'user_id' => (int) $candidate->id,
                    'name' => (string) $candidate->name,
                    'role_label' => $roleName ? self::REVIEWEE_ROLES[$roleName] : null,
                    'order' => $roleName ? array_search($roleName, $roleNames, true) : count($roleNames),
                ];
            })
            ->sortBy([['order', 'asc'], ['name', 'asc']])
            ->map(fn (array $option) => [
                'user_id' => $option['user_id'],
                'name' => $option['name'],
                'role_label' => $option['role_label'],
            ])
            ->values()
            ->all();
    }

    public function show(Request $request, PerformanceReview $review)
    {
        $this->authorize('view', $review);

        $user = $request->user();
        $isReviewee = (int) $review->reviewee_id === (int) $user?->id;
        $canAssess = $user ? $user->can('assess', $review) : false;
        $canUpdate = $this->canWriteReviews($user, 'update', $review);
        $assessmentHidden = $this->assessmentHiddenFrom($user, $review);

        $review->load(['reviewee:id,name', 'goals', 'kpis', 'creator:id,name']);

        $scorecard = $this->performanceService->generateScorecard($review);
        $boardAssessmentRecorded = $review->hasBoardAssessment();

        if ($assessmentHidden) {
            $this->maskBoardAssessment($review, $scorecard);
        }

        $review->setAttribute('cycle_label', PerformanceReview::cycleLabel($review->review_cycle));

        $payload = [
            'review' => $review,
            'scorecard' => $scorecard,
            'can_assess' => $canAssess && ! $review->isCompleted(),
            // Kept for the "Complete review" and legacy edit-link gates.
            'can_update' => $canUpdate,
            'is_reviewee' => $isReviewee,
            'assessment_hidden' => $assessmentHidden,
            // Not even whether the board has scored yet is shared early.
            'board_assessment_recorded' => $assessmentHidden ? null : $boardAssessmentRecorded,
            'can_submit_self_assessment' => $isReviewee
                && $user->can('submitSelfAssessment', $review)
                && ! $review->isCompleted()
                && $review->self_assessment_submitted_at === null,
            'can_complete' => $canUpdate
                && ! $review->isCompleted()
                && $boardAssessmentRecorded
                && filled($review->board_decision),
            'completion_resolutions' => $canUpdate && ! $review->isCompleted()
                ? $this->completionResolutions($review, $user)
                : [],
            'approval_resolution' => $this->visibleApprovalResolution($review, $user),
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Governance/Performance/Show', $payload);
    }

    public function store(Request $request)
    {
        $this->authorize('create', PerformanceReview::class);

        $validated = $request->validate([
            'reviewee_id' => 'required|exists:users,id',
            'review_cycle' => 'required|string|max:50',
            'review_type' => 'required|in:quarterly,annual,ad_hoc',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
        ], [
            'reviewee_id.required' => 'Choose who is being reviewed.',
            'reviewee_id.exists' => 'That person could not be found. Choose the CEO or an executive from the list.',
            'review_cycle.required' => 'Choose the review cycle.',
            'review_type.required' => 'Choose the kind of review.',
            'review_type.in' => 'Choose an annual, quarterly or one-off review.',
            'period_start.required' => 'Set the date the review period starts.',
            'period_end.required' => 'Set the date the review period ends.',
            'period_end.after' => 'The review period must end after it starts.',
        ]);

        $review = $this->performanceService->createReview(
            User::find($validated['reviewee_id']),
            $validated['review_cycle'],
            $validated['review_type'],
            \Carbon\Carbon::parse($validated['period_start']),
            \Carbon\Carbon::parse($validated['period_end']),
            auth()->user()
        );

        // Generate default goals and KPIs for CEO
        $this->performanceService->generateDefaultGoals($review);
        $this->performanceService->generateDefaultKpis($review);

        return redirect()->route('governance.performance.show', $review)
            ->with('success', 'Performance review created with the standard goals and key performance measures.');
    }

    public function update(Request $request, PerformanceReview $review)
    {
        $this->authorize('update', $review);

        if ($review->isCompleted()) {
            return redirect()->back()->with('error', "This review is complete, so the board's assessment can no longer change.");
        }

        $validated = $request->validate([
            'overall_rating' => 'sometimes|in:exceeds,meets,needs_improvement,unsatisfactory',
            'overall_assessment' => 'sometimes|string',
            'board_decision' => 'sometimes|in:remuneration_increase,maintain,development_plan,performance_improvement',
            'decision_notes' => 'nullable|string',
        ], [
            'overall_rating.in' => 'Choose one of the listed ratings.',
            'board_decision.in' => 'Choose one of the listed decisions.',
        ]);

        $review->update($validated);

        return redirect()->back()->with('success', 'Performance review updated.');
    }

    public function addGoal(Request $request, PerformanceReview $review)
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'pillar' => 'required|in:safety,quality,people,finance,compliance,it_resilience',
            'goal_description' => 'required|string',
            'success_criteria' => 'required|string',
            'weight' => 'required|numeric|min:0|max:100',
            'target_score' => 'required|numeric|min:1|max:5',
        ]);

        $this->performanceService->addGoal(
            $review,
            $validated['pillar'],
            $validated['goal_description'],
            $validated['success_criteria'],
            $validated['weight'],
            $validated['target_score']
        );

        return redirect()->back()->with('success', 'Goal added.');
    }

    /**
     * The board's assessment — the one place goals are scored and the overall
     * rating, decision and notes are recorded. Hidden from the reviewee until
     * the review is completed.
     */
    public function submitAssessment(Request $request, PerformanceReview $review)
    {
        $this->authorize('assess', $review);

        $validated = $request->validate([
            'goal_assessments' => 'sometimes|array',
            'goal_assessments.*.score' => 'required|numeric|min:1|max:5',
            'goal_assessments.*.comments' => 'nullable|string',
            'overall_rating' => 'required|in:exceeds,meets,needs_improvement,unsatisfactory',
            'overall_assessment' => 'nullable|string|max:10000',
            'board_decision' => 'required|in:remuneration_increase,maintain,development_plan,performance_improvement',
            'decision_notes' => 'nullable|string',
        ], [
            'goal_assessments.*.score.required' => 'Give this goal a score from 1 to 5.',
            'goal_assessments.*.score.min' => 'Scores go from 1 (not met) to 5 (well exceeded).',
            'goal_assessments.*.score.max' => 'Scores go from 1 (not met) to 5 (well exceeded).',
            'goal_assessments.*.score.numeric' => 'Give this goal a score from 1 to 5.',
            'overall_rating.required' => "Choose the board's overall rating.",
            'overall_rating.in' => 'Choose one of the listed ratings.',
            'board_decision.required' => "Choose the board's decision.",
            'board_decision.in' => 'Choose one of the listed decisions.',
        ]);

        if ($review->isCompleted()) {
            throw ValidationException::withMessages([
                'overall_rating' => "This review is complete, so the board's assessment can no longer change.",
            ]);
        }

        DB::transaction(function () use ($review, $validated): void {
            $this->performanceService->submitBoardAssessment(
                $review,
                $validated['goal_assessments'] ?? [],
                $validated['overall_rating'],
                $validated['board_decision'],
                $validated['decision_notes'] ?? null
            );

            if (array_key_exists('overall_assessment', $validated)) {
                $review->update(['overall_assessment' => $validated['overall_assessment']]);
            }
        });

        return redirect()->back()->with('success', "The board's assessment is saved. The CEO won't see it until the review is completed.");
    }

    /** Legacy deep link: the board's assessment is a dialog on the show page. */
    public function edit(PerformanceReview $review)
    {
        $this->authorize('update', $review);

        return redirect()->route('governance.performance.show', ['review' => $review->id, 'edit' => 1]);
    }

    public function submitFeedback(Request $request, PerformanceReview $review)
    {
        $this->authorize('view', $review);

        $validated = $request->validate([
            'reviewer_role' => 'required|in:board_member,peer,direct_report,self',
            'ratings' => 'nullable|array',
            'strengths' => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'comments' => 'nullable|string',
            'is_anonymous' => 'boolean',
        ]);

        $review->feedback()->create([
            ...$validated,
            'reviewer_id' => auth()->id(),
            'submitted_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Feedback submitted.');
    }

    /**
     * The reviewee sends their self-assessment to the board (once).
     */
    public function submitSelfAssessment(Request $request, PerformanceReview $review)
    {
        $this->authorize('submitSelfAssessment', $review);

        $validated = $request->validate([
            'self_assessment' => 'required|string|max:10000',
        ], [
            'self_assessment.required' => 'Write your self-assessment before sending it to the board.',
            'self_assessment.max' => 'Keep your self-assessment to 10,000 characters.',
        ]);

        $review->submitSelfAssessment($validated['self_assessment']);

        return redirect()->back()->with('success', 'Your self-assessment has been sent to the board.');
    }

    /**
     * Complete the review — optionally citing the resolution that approved
     * the board's decision. A cited resolution must have been linked to this
     * exact review before the vote (verified and used once by the model).
     */
    public function approve(Request $request, PerformanceReview $review)
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'resolution_id' => 'nullable|integer',
        ]);

        $resolutionId = isset($validated['resolution_id']) ? (int) $validated['resolution_id'] : null;

        $review->approve($resolutionId, $request->user()->id);

        return redirect()->back()->with('success', "Review completed. The CEO can now see the board's rating, decision and notes.");
    }

    /**
     * A reviewee who cannot assess never receives the board's scoring until
     * the review is completed.
     */
    private function assessmentHiddenFrom(?User $user, PerformanceReview $review): bool
    {
        if (! $user || (int) $review->reviewee_id !== (int) $user->id) {
            return false;
        }

        return ! $user->can('assess', $review) && ! $review->isCompleted();
    }

    /**
     * Clear every board-assessment field from the payload: the overall rating,
     * narrative, decision and notes; each goal's board score, comments and the
     * status derived from that score; and the scorecard's scores and actuals.
     *
     * @param  array<string, mixed>|null  $scorecard
     */
    private function maskBoardAssessment(PerformanceReview $review, ?array &$scorecard = null): void
    {
        $review->setAttribute('overall_rating', null);
        $review->setAttribute('overall_assessment', null);
        $review->setAttribute('board_decision', null);
        $review->setAttribute('decision_notes', null);

        if ($review->relationLoaded('goals')) {
            $review->goals->each(function ($goal): void {
                $goal->setAttribute('actual_score', null);
                $goal->setAttribute('board_assessment', null);
                $goal->setAttribute('status', null);
            });
        }

        if (is_array($scorecard)) {
            $scorecard['overall_rating'] = null;
            $scorecard['board_decision'] = null;
            $scorecard['overall_score'] = null;

            foreach ($scorecard['pillars'] ?? [] as $pillar => $data) {
                $scorecard['pillars'][$pillar]['score'] = null;
                $scorecard['pillars'][$pillar]['goals'] = collect($data['goals'] ?? [])
                    ->map(fn ($goal) => [...$goal, 'actual' => null, 'status' => null])
                    ->values()
                    ->all();
            }
        }
    }

    /**
     * Passed resolutions linked (and not yet used) to this exact review that
     * the viewer may see — the only resolutions that can complete it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function completionResolutions(PerformanceReview $review, User $user): array
    {
        return Resolution::query()
            ->where('outcome', 'carried')
            ->whereIn('status', PerformanceReview::PASSED_RESOLUTION_STATUSES)
            ->whereHas('authorityBindings', fn ($query) => $query
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW)
                ->where('subject_id', $review->id)
                ->whereNull('consumed_at'))
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->get(['id', 'resolution_reference', 'title', 'closed_at', 'governance_meeting_id'])
            ->filter(fn (Resolution $resolution) => $user->can('view', $resolution))
            ->map(fn (Resolution $resolution) => [
                'id' => (int) $resolution->id,
                'title' => $resolution->title,
                'reference' => $resolution->resolution_reference,
                'closed_at' => $resolution->closed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function visibleApprovalResolution(PerformanceReview $review, User $user): ?array
    {
        if (! $review->approval_resolution_id || ! $review->isCompleted()) {
            return null;
        }

        $resolution = Resolution::query()->find($review->approval_resolution_id);
        if (! $resolution || ! $user->can('view', $resolution)) {
            return null;
        }

        return [
            'id' => (int) $resolution->id,
            'title' => $resolution->title,
            'reference' => $resolution->resolution_reference,
        ];
    }

    /**
     * Review cycles for the previous, current and next NZ financial year
     * (1 July – 30 June). Annual cycles are named for the year the financial
     * year ends ("Annual review 2026" = 2025/26); quarters are calendar
     * quarters. Each option carries its review window so the wizard can fill
     * the dates in.
     *
     * @return array{options: array<int, array<string, mixed>>, current_cycle: string, current_financial_year: string}
     */
    protected function getReviewCycles(): array
    {
        $timezone = (string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE);
        $today = CarbonImmutable::now($timezone);
        $currentYearEnd = $today->month >= 7 ? $today->year + 1 : $today->year;

        $options = [];
        foreach ([$currentYearEnd - 1, $currentYearEnd, $currentYearEnd + 1] as $yearEnd) {
            $yearStart = CarbonImmutable::create($yearEnd - 1, 7, 1, 0, 0, 0, $timezone);
            $financialYear = GovernanceLabels::financialYear($yearEnd);

            $options[] = [
                'value' => "{$yearEnd}-Annual",
                'label' => "Annual review {$yearEnd} (financial year {$financialYear})",
                'review_type' => 'annual',
                'period_start' => $yearStart->toDateString(),
                'period_end' => $yearStart->addYear()->subDay()->toDateString(),
                'financial_year' => $financialYear,
                'is_current' => $yearEnd === $currentYearEnd,
            ];

            foreach ([[$yearEnd - 1, 3], [$yearEnd - 1, 4], [$yearEnd, 1], [$yearEnd, 2]] as [$year, $quarter]) {
                $start = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1, 0, 0, 0, $timezone);
                $end = $start->addMonths(3)->subDay();

                $options[] = [
                    'value' => "{$year}-Q{$quarter}",
                    'label' => sprintf('Quarter %d, %d (%s–%s)', $quarter, $year, $start->format('F'), $end->format('F Y')),
                    'review_type' => 'quarterly',
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'financial_year' => $financialYear,
                    'is_current' => $today->between($start, $end->endOfDay()),
                ];
            }
        }

        $currentQuarter = intdiv($today->month - 1, 3) + 1;

        return [
            'options' => $options,
            'current_cycle' => "{$today->year}-Q{$currentQuarter}",
            'current_financial_year' => GovernanceLabels::financialYear($currentYearEnd),
        ];
    }
}
