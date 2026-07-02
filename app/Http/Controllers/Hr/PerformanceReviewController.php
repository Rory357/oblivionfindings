<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StorePerformanceReviewRequest;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PerformanceReviewController extends Controller
{
    use ResolvesHrTenant;
    use ServesPrivateAttachments;

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

        $review->load(['employee:id,name', 'reviewer:id,name', 'reviewGoals.goal:id,title']);

        $canManage = $user->canDo('hr.performance.manage');

        return Inertia::render('hr/performance/show-review', [
            'review' => $review,
            // Structured review goals (falls back to the legacy JSON blob for
            // reviews created before the child table existed).
            'reviewGoals' => $review->reviewGoals->isNotEmpty()
                ? $review->reviewGoals->map(fn ($g) => [
                    'id' => $g->id,
                    'description' => $g->description,
                    'status' => $g->status,
                    'rating' => $g->rating,
                    'goal' => $g->goal ? ['id' => $g->goal->id, 'title' => $g->goal->title] : null,
                ])->all()
                : collect($review->reviewGoalList())->map(fn ($d, $i) => [
                    'id' => -1 - $i,
                    'description' => $d,
                    'status' => 'open',
                    'rating' => null,
                    'goal' => null,
                ])->all(),
            'nextSteps' => $this->nextStepsFor($review, $canManage, $tenantId),
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * Deliberate, permission-gated "Next steps" affordance for signed-off
     * reviews. Never auto-creates anything — it only surfaces a prefillable
     * CTA into the existing PIP / succession flows when the review outcome
     * warrants it and no equivalent process is already underway.
     *
     * @return array{action: string, employee_profile_id?: int, staff: array<int, array{value: int, label: string}>, successionEmployees?: array<int, array{value: int, label: string}>}|null
     */
    private function nextStepsFor(HrPerformanceReview $review, bool $canManage, int $tenantId): ?array
    {
        if (! $canManage || $review->status !== 'signed_off' || $review->overall_rating === null) {
            return null;
        }

        $staffOptions = fn () => $this->reviewStaff($tenantId)
            ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])
            ->values()
            ->all();

        // Low outcome → structured improvement plan (unless one is already open).
        if ($review->overall_rating <= 2) {
            $hasActivePip = HrPerformanceImprovementPlan::where('employee_user_id', $review->employee_user_id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->exists();

            if ($hasActivePip) {
                return null;
            }

            return [
                'action' => 'pip',
                'staff' => $staffOptions(),
            ];
        }

        // Strong outcome → succession nomination (unless already a candidate).
        if ($review->overall_rating >= 4) {
            $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
                ->where('user_id', $review->employee_user_id)
                ->where('is_active', true)
                ->first(['id']);

            if (! $profile) {
                return null;
            }

            $isCandidate = HrSuccessionCandidate::where('employee_profile_id', $profile->id)
                ->whereHas('successionPlan', fn ($q) => $q->where('is_active', true))
                ->exists();

            if ($isCandidate) {
                return null;
            }

            return [
                'action' => 'succession',
                'employee_profile_id' => $profile->id,
                'staff' => $staffOptions(),
                'successionEmployees' => HrEmployeeProfile::where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->with('user:id,name')
                    ->orderBy('user_id')
                    ->limit(500)
                    ->get(['id', 'user_id'])
                    ->map(fn ($p) => ['value' => $p->id, 'label' => $p->user?->name ?? 'Unknown'])
                    ->all(),
            ];
        }

        return null;
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

        $review = HrPerformanceReview::create([
            'tenant_id' => $tenantId,
            'reviewer_user_id' => $user->id,
            'status' => 'draft',
            'created_by' => $user->id,
            ...$data,
        ]);

        if (array_key_exists('goals', $data)) {
            $review->syncReviewGoals($data['goals'] ?? []);
        }

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

        // A signed-off review is locked — no further edits via the generic update.
        abort_if($review->status === 'signed_off', 422, 'This review is signed off and locked.');

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

        if (array_key_exists('goals', $data)) {
            $review->syncReviewGoals($data['goals'] ?? []);
        }

        return redirect()->back()->with('success', 'Performance review updated.');
    }

    /**
     * Submit a draft review for sign-off (draft → in_progress).
     */
    public function submit(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        abort_unless(in_array($review->status, ['draft', 'in_progress'], true), 422, 'Only draft reviews can be submitted.');

        $review->update(['status' => 'in_progress', 'updated_by' => $user->id]);

        return redirect()->back()->with('success', 'Review submitted for sign-off.');
    }

    /**
     * Manager sign-off (Approve & lock) or return for edits.
     */
    public function signOff(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        abort_if($review->status === 'signed_off', 422, 'This review is already signed off.');

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approve,return'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($data['decision'] === 'return') {
            $review->update([
                'status' => 'in_progress',
                'updated_by' => $user->id,
            ]);

            return redirect()->back()->with('success', 'Review returned for edits.');
        }

        $review->update([
            'status' => 'signed_off',
            'manager_signed_off' => true,
            'manager_signed_off_at' => $review->manager_signed_off_at ?? now(),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Review signed off and locked.');
    }

    /**
     * Employee acknowledges their own completed review.
     */
    public function acknowledge(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user, 403);
        // Either the review subject, or a manager acting on their behalf.
        abort_unless(
            $review->employee_user_id === $user->id || $user->canDo('hr.performance.manage'),
            403,
        );

        if (! $review->employee_signed_off) {
            $review->update([
                'employee_signed_off' => true,
                'employee_signed_off_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Review acknowledged.');
    }

    /**
     * Upload evidence for a review (private disk).
     */
    public function uploadEvidence(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $review->tenant_id);
        abort_if($review->status === 'signed_off', 422, 'This review is signed off and locked.');

        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240']]);

        if ($review->evidence_path) {
            Storage::disk('private')->delete($review->evidence_path);
        }
        $path = $request->file('file')->store('hr/performance-reviews/'.$review->id, 'private');
        $review->update(['evidence_path' => $path]);

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    /**
     * Stream a review's evidence (private disk, hardened headers).
     */
    public function downloadEvidence(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $review->tenant_id);
        abort_unless($review->evidence_path, 404);

        return $this->streamPrivateAttachment(
            'private',
            $review->evidence_path,
            basename($review->evidence_path),
            Storage::disk('private')->mimeType($review->evidence_path) ?: null,
            'inline',
        );
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

        // Audit fix (round 2, item 1b): an "extend" recommendation actually
        // moves the employee's probation end date — previously the review was
        // recorded but hr_employee_profiles.probation_end_date never changed,
        // so the extension existed only on paper. Base: the current
        // probation_end_date (fallback: the review date when none was set).
        // Clearing the reminder stamp lets hr:probation-reminders fire again
        // for the new end date.
        if (($data['recommendation'] ?? null) === 'extend' && ! empty($data['extension_weeks'])) {
            $profile = HrEmployeeProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $data['employee_user_id'])
                ->first();

            if ($profile) {
                $base = $profile->probation_end_date ?? Carbon::parse($data['review_date']);
                $profile->forceFill([
                    'probation_end_date' => $base->copy()->addWeeks((int) $data['extension_weeks'])->toDateString(),
                    'probation_reminder_sent_at' => null,
                    'updated_by' => $user->id,
                ])->save();
            }
        }

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
