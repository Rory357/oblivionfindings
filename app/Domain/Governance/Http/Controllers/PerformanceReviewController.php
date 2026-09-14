<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Services\PerformanceReviewService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PerformanceReviewController extends Controller
{
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

        $query = PerformanceReview::with(['reviewee', 'goals', 'kpis']);

        $access = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $access->scopePerformanceReviews($query, $request->user());

        // Header counts come from the same audience scope as the list, before
        // the header filters narrow it, so totals never equal "rows shown".
        $summaryBase = $access->scopePerformanceReviews(PerformanceReview::query(), $request->user());
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'active' => (clone $summaryBase)->where('status', '!=', 'completed')->count(),
            'completed' => (clone $summaryBase)->where('status', 'completed')->count(),
            'board_review' => (clone $summaryBase)->where('status', 'board_review')->count(),
        ];

        if ($request->has('reviewee_id')) {
            $query->byReviewee($request->reviewee_id);
        }

        $status = $request->string('status')->toString();
        if ($status === 'active') {
            $query->where('status', '!=', 'completed');
        } elseif (in_array($status, ['draft', 'self_review', 'peer_review', 'board_review', 'completed'], true)) {
            $query->where('status', $status);
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

        $user = $request->user() ?? auth()->user();
        $canCreate = $this->canWriteReviews($user, 'create');

        $reviews = $query->orderByDesc('created_at')
            ->paginate(15)
            ->through(function (PerformanceReview $review) use ($user) {
                $isReviewee = (int) $review->reviewee_id === (int) $user?->id;
                $canAssess = $user ? $user->can('assess', $review) : false;

                if ($isReviewee && ! $canAssess && $review->status !== 'completed') {
                    $review->overall_rating = null;
                    $review->overall_assessment = null;
                    $review->board_decision = null;
                    $review->decision_notes = null;
                }

                return $review;
            });

        return Inertia::render('Governance/Performance/Index', [
            'reviews' => $reviews,
            'review_cycles' => $this->getReviewCycles(),
            'summary' => $summary,
            'filters' => [
                'status' => $status ?: null,
                'review_type' => $reviewType ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ],
            'can_create' => $canCreate,
            // New-review wizard options, only for viewers who may create.
            'board_members' => $canCreate ? $this->boardMemberOptions() : [],
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

    /** @return array<int, array{id:int,user_id:int,name:string,board_role:string|null}> */
    protected function boardMemberOptions(): array
    {
        return \App\Domain\Governance\Models\BoardMember::with('user:id,name')
            ->get()
            ->filter(fn ($member) => $member->user !== null)
            ->map(fn ($member) => [
                'id' => (int) $member->id,
                'user_id' => (int) $member->user->id,
                'name' => (string) $member->user->name,
                'board_role' => $member->board_role,
            ])
            ->values()
            ->all();
    }

    public function show(Request $request, PerformanceReview $review)
    {
        $this->authorize('view', $review);

        $user = $request->user() ?? auth()->user();
        $isReviewee = (int) $review->reviewee_id === (int) $user?->id;
        $canAssess = $user ? $user->can('assess', $review) : false;

        $review->load(['reviewee', 'goals', 'kpis', 'creator']);

        $scorecard = $this->performanceService->generateScorecard($review);

        if ($isReviewee && ! $canAssess && $review->status !== 'completed') {
            $review->overall_rating = null;
            $review->overall_assessment = null;
            $review->board_decision = null;
            $review->decision_notes = null;

            if (is_array($scorecard)) {
                $scorecard['overall_rating'] = null;
                $scorecard['board_decision'] = null;
                $scorecard['overall_score'] = null;
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'review' => $review,
                'scorecard' => $scorecard,
                'can_assess' => $canAssess,
            ]);
        }

        return Inertia::render('Governance/Performance/Show', [
            'review' => $review,
            'scorecard' => $scorecard,
            'can_assess' => $canAssess,
            // Edit wizard: same audience as the retired edit page + update route.
            'can_update' => $this->canWriteReviews($user, 'update', $review),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', PerformanceReview::class);

        $validated = $request->validate([
            'reviewee_id' => 'required|exists:users,id',
            'review_cycle' => 'required|string',
            'review_type' => 'required|in:quarterly,annual,ad_hoc',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
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
            ->with('success', 'Performance review created.');
    }

    public function update(Request $request, PerformanceReview $review)
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'overall_rating' => 'sometimes|in:exceeds,meets,needs_improvement,unsatisfactory',
            'overall_assessment' => 'sometimes|string',
            'board_decision' => 'sometimes|in:remuneration_increase,maintain,development_plan,performance_improvement',
            'decision_notes' => 'nullable|string',
        ]);

        $review->update($validated);

        return redirect()->back()->with('success', 'Review updated.');
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

    public function submitAssessment(Request $request, PerformanceReview $review)
    {
        $this->authorize('assess', $review);

        $validated = $request->validate([
            'goal_assessments' => 'required|array',
            'goal_assessments.*.score' => 'required|numeric|min:1|max:5',
            'goal_assessments.*.comments' => 'nullable|string',
            'overall_rating' => 'required|in:exceeds,meets,needs_improvement,unsatisfactory',
            'board_decision' => 'required|in:remuneration_increase,maintain,development_plan,performance_improvement',
            'decision_notes' => 'nullable|string',
        ]);

        $this->performanceService->submitBoardAssessment(
            $review,
            $validated['goal_assessments'],
            $validated['overall_rating'],
            $validated['board_decision'],
            $validated['decision_notes'] ?? null
        );

        return redirect()->back()->with('success', 'Assessment submitted.');
    }

    /** Legacy deep link: the edit wizard is a dialog on the show page. */
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
     * Submit the self-assessment, advancing the review to board review.
     */
    public function submitSelfAssessment(Request $request, PerformanceReview $review)
    {
        $this->authorize('submitSelfAssessment', $review);

        $validated = $request->validate([
            'self_assessment' => 'required|string|max:10000',
        ]);

        $review->submitSelfAssessment($validated['self_assessment']);

        return redirect()->back()->with('success', 'Self-assessment submitted for board review.');
    }

    /**
     * Board approval — finalises the review to completed, optionally linking the
     * approving resolution. A cited resolution must be explicitly bound to this
     * exact review decision (verified and consumed by the model).
     */
    public function approve(Request $request, PerformanceReview $review)
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'resolution_id' => 'nullable|integer',
        ]);

        $resolutionId = isset($validated['resolution_id']) ? (int) $validated['resolution_id'] : null;

        $review->approve($resolutionId, $request->user()->id);

        return redirect()->back()->with('success', 'Performance review approved and completed.');
    }

    protected function getReviewCycles(): array
    {
        $year = now()->year;
        return [
            ['value' => "{$year}-Q1", 'label' => "Q1 {$year}"],
            ['value' => "{$year}-Q2", 'label' => "Q2 {$year}"],
            ['value' => "{$year}-Q3", 'label' => "Q3 {$year}"],
            ['value' => "{$year}-Q4", 'label' => "Q4 {$year}"],
            ['value' => "{$year}-Annual", 'label' => "{$year} Annual Review"],
        ];
    }
}
