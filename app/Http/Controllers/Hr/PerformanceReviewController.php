<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StorePerformanceReviewRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrProbationReview;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PerformanceReviewController extends Controller
{
    use ResolvesHrTenant;

    /**
     * List all performance reviews.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $search = trim((string) $request->query('q', ''));

        $reviews = HrPerformanceReview::with(['employee:id,name', 'reviewer:id,name'])
            ->where('tenant_id', $tenantId)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('employee'), fn ($q, $empId) => $q->where('employee_user_id', $empId))
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->whereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%"))
                   ->orWhereHas('reviewer', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $probationReviews = HrProbationReview::with(['employee:id,name', 'reviewer:id,name'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('review_date')
            ->limit(20)
            ->get();

        // Summary stats
        $allReviews = HrPerformanceReview::where('tenant_id', $tenantId);
        $totalCount = (clone $allReviews)->count();
        $completedCount = (clone $allReviews)->whereIn('status', ['completed', 'signed_off'])->count();
        $overdueCount = (clone $allReviews)->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->whereNotNull('next_review_date')
            ->where('next_review_date', '<', now())
            ->count();
        $draftCount = (clone $allReviews)->where('status', 'draft')->count();

        // Rating distribution
        $ratingDistribution = HrPerformanceReview::where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'signed_off'])
            ->whereNotNull('overall_rating')
            ->selectRaw('overall_rating as rating, COUNT(*) as count')
            ->groupBy('overall_rating')
            ->orderBy('overall_rating')
            ->pluck('count', 'rating');

        $ratingData = collect(range(1, 5))->map(fn ($r) => [
            'rating' => $r,
            'count' => (int) ($ratingDistribution[$r] ?? 0),
        ]);

        // Status distribution
        $statusDistribution = HrPerformanceReview::where('tenant_id', $tenantId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return Inertia::render('hr/performance/reviews', [
            'reviews' => $reviews,
            'probationReviews' => $probationReviews,
            'stats' => [
                'total' => $totalCount,
                'completed' => $completedCount,
                'overdue' => $overdueCount,
                'draft' => $draftCount,
            ],
            'ratingDistribution' => $ratingData,
            'statusDistribution' => collect($statusDistribution)->map(fn ($count, $status) => [
                'status' => $status,
                'count' => (int) $count,
            ])->values(),
            'filters' => [
                'status' => $request->query('status'),
                'q' => $search,
            ],
            'staff' => $this->reviewStaff($tenantId),
            'reviewTypes' => $this->reviewTypeOptions(),
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /** Staff selectable in the review wizard (tenant-scoped). */
    private function reviewStaff(int $tenantId)
    {
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);

        return User::staff()
            ->when($staffIds !== [], fn ($query) => $query->whereIn('id', $staffIds))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /** Review-type options for the wizard. */
    private function reviewTypeOptions(): array
    {
        return [
            ['value' => 'annual', 'label' => 'Annual Review'],
            ['value' => 'mid_year', 'label' => 'Mid-Year Review'],
            ['value' => 'quarterly', 'label' => 'Quarterly Review'],
            ['value' => 'ad_hoc', 'label' => 'Ad Hoc Review'],
        ];
    }

    /**
     * The page-based create form was replaced by the ReviewWizardDialog on the
     * reviews hub. Preserve the route with a redirect.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.performance.reviews.index');
    }

    /**
     * Show a single performance review.
     */
    public function show(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        $review->load(['employee:id,name', 'reviewer:id,name']);

        return Inertia::render('hr/performance/show-review', [
            'review' => $review,
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * The page-based edit form was replaced by the ReviewWizardDialog (edit mode)
     * on the reviews hub. Preserve the route with a redirect.
     */
    public function edit(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.performance.reviews.index');
    }

    /**
     * Store a new performance review.
     */
    public function store(StorePerformanceReviewRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validated();

        HrPerformanceReview::create([
            'tenant_id' => $tenantId,
            'reviewer_user_id' => $user->id,
            'status' => 'draft',
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Performance review created.');
    }

    /**
     * Update an existing performance review.
     */
    public function update(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        $data = $request->validate([
            'review_type' => ['sometimes', 'string', 'in:annual,mid_year,quarterly,ad_hoc'],
            'review_period_start' => ['sometimes', 'date'],
            'review_period_end' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:draft,in_progress,completed,signed_off'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'development_areas' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'array'],
            'goals.*' => ['string', 'max:500'],
            'training_recommendations' => ['nullable', 'array'],
            'training_recommendations.*' => ['string', 'max:500'],
            'employee_comments' => ['nullable', 'string', 'max:5000'],
            'employee_signed_off' => ['nullable', 'boolean'],
            'manager_signed_off' => ['nullable', 'boolean'],
            'next_review_date' => ['nullable', 'date'],
        ]);

        if (isset($data['employee_signed_off']) && $data['employee_signed_off'] && ! $review->employee_signed_off) {
            $data['employee_signed_off_at'] = now();
        }

        if (isset($data['manager_signed_off']) && $data['manager_signed_off'] && ! $review->manager_signed_off) {
            $data['manager_signed_off_at'] = now();
        }

        $data['updated_by'] = $user->id;

        $review->update($data);

        return redirect()->back()->with('success', 'Performance review updated.');
    }

    /**
     * Store a new probation review.
     */
    public function storeProbation(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'review_number' => ['required', 'integer', 'min:1'],
            'review_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:scheduled,completed,extended,passed,failed'],
            'areas_assessed' => ['nullable', 'array'],
            'areas_assessed.*' => ['string', 'max:500'],
            'concerns' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'in:pass,extend,fail'],
            'extension_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        HrProbationReview::create([
            'tenant_id' => $tenantId,
            'reviewer_user_id' => $user->id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Probation review recorded.');
    }

    /**
     * Update an existing probation review.
     */
    public function updateProbation(Request $request, HrProbationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        $data = $request->validate([
            'review_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:scheduled,completed,extended,passed,failed'],
            'areas_assessed' => ['nullable', 'array'],
            'areas_assessed.*' => ['string', 'max:500'],
            'concerns' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'in:pass,extend,fail'],
            'extension_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'employee_acknowledged' => ['nullable', 'boolean'],
        ]);

        if (isset($data['employee_acknowledged']) && $data['employee_acknowledged'] && ! $review->employee_acknowledged) {
            $data['employee_acknowledged_at'] = now();
        }

        $review->update($data);

        return redirect()->back()->with('success', 'Probation review updated.');
    }
}
