<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Services\CompensationService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompensationController extends Controller
{
    public function __construct(
        protected CompensationService $compensationService,
    ) {}

    /**
     * List salary bands with optional filtering.
     */
    public function bands(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        $bands = HrSalaryBand::query()
            ->when($request->query('role'), fn ($q, $role) => $q->where('position_role', $role))
            ->when($request->query('active_only'), fn ($q) => $q->active())
            ->orderBy('position_role')
            ->orderBy('band_name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/compensation/bands', [
            'bands' => $bands,
            'filters' => [
                'role' => $request->query('role'),
                'active_only' => $request->boolean('active_only'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Create a new salary band.
     */
    public function storeBand(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $data = $request->validate([
            'position_role' => ['required', 'string', 'max:255'],
            'band_name' => ['required', 'string', 'max:255'],
            'min_salary' => ['required', 'numeric', 'min:0'],
            'mid_salary' => ['required', 'numeric', 'min:0'],
            'max_salary' => ['required', 'numeric', 'min:0'],
            'min_hourly' => ['required', 'numeric', 'min:0'],
            'max_hourly' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);

        HrSalaryBand::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Salary band created.');
    }

    /**
     * Update an existing salary band.
     */
    public function updateBand(Request $request, HrSalaryBand $band)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $data = $request->validate([
            'position_role' => ['sometimes', 'string', 'max:255'],
            'band_name' => ['sometimes', 'string', 'max:255'],
            'min_salary' => ['sometimes', 'numeric', 'min:0'],
            'mid_salary' => ['sometimes', 'numeric', 'min:0'],
            'max_salary' => ['sometimes', 'numeric', 'min:0'],
            'min_hourly' => ['sometimes', 'numeric', 'min:0'],
            'max_hourly' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        $band->update($data);

        return redirect()->back()->with('success', 'Salary band updated.');
    }

    /**
     * Compensation history for a specific employee.
     */
    public function history(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        $profile->load('user:id,name');

        $history = HrCompensationHistory::where('employee_profile_id', $profile->id)
            ->with(['approver:id,name', 'creator:id,name'])
            ->orderByDesc('effective_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/compensation/history', [
            'profile' => $profile,
            'history' => $history,
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * List compensation review cycles.
     */
    public function reviews(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        $reviews = HrCompensationReview::query()
            ->withCount('items')
            ->with('creator:id,name')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/compensation/reviews', [
            'reviews' => $reviews,
            'filters' => [
                'status' => $request->query('status'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a compensation review.
     */
    public function createReview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $employees = HrEmployeeProfile::with('user:id,name')
            ->active()
            ->get(['id', 'user_id', 'position_title', 'annual_salary', 'hourly_rate']);

        return Inertia::render('hr/compensation/review-detail', [
            'review' => null,
            'employees' => $employees,
            'reviewCycles' => [
                ['value' => 'annual', 'label' => 'Annual'],
                ['value' => 'mid_year', 'label' => 'Mid-Year'],
                ['value' => 'ad_hoc', 'label' => 'Ad Hoc'],
            ],
            'can' => [
                'manage' => true,
            ],
        ]);
    }

    /**
     * Store a new compensation review.
     */
    public function storeReview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'review_cycle' => ['required', 'string', 'in:annual,mid_year,ad_hoc'],
            'effective_date' => ['required', 'date'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array'],
            'items.*.employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'items.*.current_salary' => ['required', 'numeric', 'min:0'],
            'items.*.proposed_salary' => ['required', 'numeric', 'min:0'],
            'items.*.change_percentage' => ['required', 'numeric'],
            'items.*.justification' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->compensationService->createCompensationReview([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->route('hr.compensation.reviews.index')->with('success', 'Compensation review created.');
    }

    /**
     * Show a single compensation review with its items.
     */
    public function showReview(Request $request, HrCompensationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        $review->load([
            'items.employeeProfile.user:id,name',
            'items.approver:id,name',
            'creator:id,name',
        ]);

        $employees = HrEmployeeProfile::with('user:id,name')
            ->active()
            ->get(['id', 'user_id', 'position_title', 'annual_salary', 'hourly_rate']);

        return Inertia::render('hr/compensation/review-detail', [
            'review' => $review,
            'employees' => $employees,
            'reviewCycles' => [
                ['value' => 'annual', 'label' => 'Annual'],
                ['value' => 'mid_year', 'label' => 'Mid-Year'],
                ['value' => 'ad_hoc', 'label' => 'Ad Hoc'],
            ],
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Apply an approved compensation review (bulk update).
     */
    public function applyReview(Request $request, HrCompensationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $this->compensationService->applyCompensationReview($review);

        return redirect()->back()->with('success', 'Compensation review applied successfully. Employee profiles have been updated.');
    }
}
