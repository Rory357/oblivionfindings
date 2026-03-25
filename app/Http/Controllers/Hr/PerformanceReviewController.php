<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
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

        $reviews = HrPerformanceReview::with(['employee:id,name', 'reviewer:id,name'])
            ->where('tenant_id', $tenantId)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('employee'), fn ($q, $empId) => $q->where('employee_user_id', $empId))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $probationReviews = HrProbationReview::with(['employee:id,name', 'reviewer:id,name'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('review_date')
            ->limit(20)
            ->get();

        return Inertia::render('hr/performance/reviews', [
            'reviews' => $reviews,
            'probationReviews' => $probationReviews,
            'filters' => [
                'status' => $request->query('status'),
                'employee' => $request->query('employee'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a new performance review.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);

        $staff = User::staff()
            ->whereIn('id', $staffIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/create-review', [
            'staff' => $staff,
            'reviewTypes' => [
                ['value' => 'annual', 'label' => 'Annual Review'],
                ['value' => 'mid_year', 'label' => 'Mid-Year Review'],
                ['value' => 'quarterly', 'label' => 'Quarterly Review'],
                ['value' => 'ad_hoc', 'label' => 'Ad Hoc Review'],
            ],
        ]);
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
     * Show form to edit a performance review.
     */
    public function edit(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        $review->load(['employee:id,name', 'reviewer:id,name']);

        return Inertia::render('hr/performance/edit-review', [
            'review' => $review,
            'reviewTypes' => [
                ['value' => 'annual', 'label' => 'Annual Review'],
                ['value' => 'mid_year', 'label' => 'Mid-Year Review'],
                ['value' => 'quarterly', 'label' => 'Quarterly Review'],
                ['value' => 'ad_hoc', 'label' => 'Ad Hoc Review'],
            ],
        ]);
    }

    /**
     * Store a new performance review.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'review_type' => ['required', 'string', 'in:annual,mid_year,quarterly,ad_hoc'],
            'review_period_start' => ['required', 'date'],
            'review_period_end' => ['required', 'date', 'after:review_period_start'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'development_areas' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'array'],
            'goals.*' => ['string', 'max:500'],
            'training_recommendations' => ['nullable', 'array'],
            'training_recommendations.*' => ['string', 'max:500'],
            'next_review_date' => ['nullable', 'date'],
        ]);

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
