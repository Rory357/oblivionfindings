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

    public function create()
    {
        $boardMembers = \App\Domain\Governance\Models\BoardMember::with('user')->get();
        
        return Inertia::render('Governance/Performance/Create', [
            'boardMembers' => $boardMembers,
        ]);
    }

    public function index(Request $request)
    {
        $query = PerformanceReview::with(['reviewee', 'goals', 'kpis']);

        if ($request->has('reviewee_id')) {
            $query->byReviewee($request->reviewee_id);
        }

        $reviews = $query->orderByDesc('created_at')->paginate(15);

        return Inertia::render('Governance/Performance/Index', [
            'reviews' => $reviews,
            'review_cycles' => $this->getReviewCycles(),
        ]);
    }

    public function show(PerformanceReview $review)
    {
        $review->load(['reviewee', 'goals', 'kpis', 'creator']);

        $scorecard = $this->performanceService->generateScorecard($review);

        return Inertia::render('Governance/Performance/Show', [
            'review' => $review,
            'scorecard' => $scorecard,
            'can_assess' => auth()->user()->canDo('governance.performance.manage'),
        ]);
    }

    public function store(Request $request)
    {
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

    public function edit(PerformanceReview $review)
    {
        $review->load(['reviewee', 'goals', 'kpis']);

        return Inertia::render('Governance/Performance/Edit', [
            'review' => $review,
        ]);
    }

    public function submitFeedback(Request $request, PerformanceReview $review)
    {
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
        abort_unless(auth()->user()?->canDo('governance.performance.manage'), 403);

        $validated = $request->validate([
            'self_assessment' => 'required|string|max:10000',
        ]);

        $review->submitSelfAssessment($validated['self_assessment']);

        return redirect()->back()->with('success', 'Self-assessment submitted for board review.');
    }

    /**
     * Board approval — finalises the review to completed, optionally linking the
     * approving resolution.
     */
    public function approve(Request $request, PerformanceReview $review)
    {
        abort_unless(auth()->user()?->canDo('governance.performance.manage'), 403);

        $validated = $request->validate([
            'resolution_id' => 'nullable|integer',
        ]);

        $review->approve($validated['resolution_id'] ?? null);

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
