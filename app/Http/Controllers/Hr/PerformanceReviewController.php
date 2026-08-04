<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\Hr\Services\HrSuccessionAccessService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StorePerformanceReviewRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Throwable;

class PerformanceReviewController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly HrPerformanceAccessService $access,
        private readonly HrSuccessionAccessService $successionAccess,
    ) {}

    /**
     * List all performance reviews.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $search = trim((string) $request->query('q', ''));

        $allReviews = $this->access->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user);
        $reviews = (clone $allReviews)
            ->with(['employee:id,name', 'reviewer:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('employee'), fn ($q, $empId) => $q->where('employee_user_id', $empId))
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->whereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('reviewer', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $probationReviews = $this->access
            ->applyHistoricalSubjectScope(HrProbationReview::query(), $user)
            ->with(['employee:id,name', 'reviewer:id,name'])
            ->orderByDesc('review_date')
            ->limit(20)
            ->get();

        // Summary stats
        $totalCount = (clone $allReviews)->count();
        $completedCount = (clone $allReviews)->whereIn('status', ['completed', 'signed_off'])->count();
        $overdueCount = (clone $allReviews)->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->whereNotNull('next_review_date')
            ->where('next_review_date', '<', now())
            ->count();
        $draftCount = (clone $allReviews)->where('status', 'draft')->count();

        // Rating distribution
        $ratingDistribution = (clone $allReviews)
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
        $statusDistribution = (clone $allReviews)
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
            'staff' => $this->reviewStaff($user),
            'reviewTypes' => $this->reviewTypeOptions(),
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /** Current staff selectable in the review wizard. */
    private function reviewStaff(User $viewer)
    {
        return $this->access
            ->currentUserIds($viewer)
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);
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
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $review = $this->access->performanceReview($user, $review)
            ->load(['employee:id,name', 'reviewer:id,name', 'reviewGoals.goal:id,title']);

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
            'nextSteps' => $this->nextStepsFor($review, $canManage, $user),
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
     * @return array{action: string, staff: array<int, array{value: int, label: string}>}|null
     */
    private function nextStepsFor(HrPerformanceReview $review, bool $canManage, User $viewer): ?array
    {
        if (! $canManage || $review->status !== 'signed_off' || $review->overall_rating === null) {
            return null;
        }

        $staffOptions = fn () => $this->reviewStaff($viewer)
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
            $profile = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $viewer)
                ->where('user_id', $review->employee_user_id)
                ->first(['id']);

            if (! $profile) {
                return null;
            }

            $isCandidate = HrSuccessionCandidate::query()
                ->where('employee_profile_id', $profile->id)
                ->whereIn(
                    'succession_plan_id',
                    $this->successionAccess->visiblePlans($viewer)->active()->select('id'),
                )
                ->exists();

            if ($isCandidate) {
                return null;
            }

            return [
                'action' => 'succession',
                'staff' => $staffOptions(),
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
        $this->access->performanceReview($user, $review);

        return redirect()->route('hr.performance.reviews.index');
    }

    /**
     * Store a new performance review.
     */
    public function store(StorePerformanceReviewRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $this->access->currentStaff($user, $user);
        $employee = $this->access->currentStaff($user, (int) $data['employee_user_id']);

        DB::transaction(function () use ($data, $employee, $user): void {
            $review = HrPerformanceReview::query()->create([
                ...$data,
                'employee_user_id' => $employee->id,
                'reviewer_user_id' => $user->id,
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            if (array_key_exists('goals', $data)) {
                $review->syncReviewGoals($data['goals'] ?? []);
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Performance review created.');
    }

    /**
     * Update an existing performance review.
     */
    public function update(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);
        $this->access->performanceReview($user, $review);

        // A signed-off review is locked — no further edits via the generic update.
        abort_if($review->status === 'signed_off', 422, 'This review is signed off and locked.');

        $data = $request->validate([
            'review_type' => ['sometimes', 'string', 'in:annual,mid_year,quarterly,ad_hoc'],
            'review_period_start' => ['sometimes', 'date'],
            'review_period_end' => ['sometimes', 'date'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'development_areas' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'array'],
            'goals.*' => ['string', 'max:500'],
            'training_recommendations' => ['nullable', 'array'],
            'training_recommendations.*' => ['string', 'max:500'],
            'next_review_date' => ['nullable', 'date'],
        ]);

        $data['updated_by'] = $user->id;

        DB::transaction(function () use ($data, $review, $user): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user)
                ->lockForUpdate()
                ->findOrFail($review->getKey());
            abort_if($locked->status === 'signed_off', 422, 'This review is signed off and locked.');
            $locked->update($data);

            if (array_key_exists('goals', $data)) {
                $locked->syncReviewGoals($data['goals'] ?? []);
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Performance review updated.');
    }

    /**
     * Submit a draft review for sign-off (draft → in_progress).
     */
    public function submit(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);
        $this->access->performanceReview($user, $review);

        DB::transaction(function () use ($review, $user): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user)
                ->lockForUpdate()
                ->findOrFail($review->getKey());
            abort_unless($locked->status === 'draft', 422, 'Only draft reviews can be submitted.');
            $locked->update(['status' => 'in_progress', 'updated_by' => $user->id]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Review submitted for sign-off.');
    }

    /**
     * Manager sign-off (Approve & lock) or return for edits.
     */
    public function signOff(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);
        $this->access->performanceReview($user, $review);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approve,return'],
        ]);

        $approved = DB::transaction(function () use ($data, $review, $user): bool {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user)
                ->lockForUpdate()
                ->findOrFail($review->getKey());
            abort_if($locked->status === 'signed_off', 422, 'This review is already signed off.');
            abort_unless(in_array($locked->status, ['in_progress', 'completed'], true), 422, 'Only submitted reviews can be signed off.');

            if ($data['decision'] === 'return') {
                $locked->update([
                    'status' => 'in_progress',
                    'updated_by' => $user->id,
                ]);

                return false;
            }

            $locked->update([
                'status' => 'signed_off',
                'manager_signed_off' => true,
                'manager_signed_off_at' => $locked->manager_signed_off_at ?? now(),
                'updated_by' => $user->id,
            ]);

            return true;
        }, attempts: 1);

        if (! $approved) {
            return redirect()->back()->with('success', 'Review returned for edits.');
        }

        // The employee is now the waiting party — tell them to acknowledge.
        app(HrNotificationService::class)->notifyReviewReadyForAcknowledgement($review->fresh());

        return redirect()->back()->with('success', 'Review signed off and locked.');
    }

    /**
     * Employee acknowledges their own completed review.
     */
    public function acknowledge(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->access->currentStaff($user, $user);
        $data = $request->validate([
            'employee_comments' => ['nullable', 'string', 'max:5000'],
        ]);

        $changed = DB::transaction(function () use ($data, $review, $user): bool {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user)
                ->whereKey($review->getKey())
                ->where('employee_user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($locked->status === 'signed_off' && $locked->manager_signed_off, 422, 'Only a manager-signed review can be acknowledged.');

            if ($locked->employee_signed_off) {
                return false;
            }

            $locked->update([
                'employee_comments' => $data['employee_comments'] ?? $locked->employee_comments,
                'employee_signed_off' => true,
                'employee_signed_off_at' => now(),
            ]);

            return true;
        }, attempts: 1);

        if ($changed) {
            app(HrNotificationService::class)->notifyReviewSignedOff($review->fresh());
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
        $this->access->currentStaff($user, $user);
        $review = $this->access->performanceReview($user, $review);
        abort_if($review->status === 'signed_off', 422, 'This review is signed off and locked.');

        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240']]);

        $path = $request->file('file')->store('hr/performance-reviews/'.$review->id, 'private');
        try {
            DB::transaction(function () use ($path, $review, $user): void {
                $locked = $this->access
                    ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user)
                    ->lockForUpdate()
                    ->findOrFail($review->getKey());
                abort_if($locked->status === 'signed_off', 422, 'This review is signed off and locked.');
                $oldPath = $locked->evidence_path;
                $locked->update(['evidence_path' => $path, 'updated_by' => $user->id]);

                DB::afterCommit(function () use ($oldPath, $path): void {
                    if ($oldPath && $oldPath !== $path) {
                        Storage::disk('private')->delete($oldPath);
                    }
                });
            }, attempts: 1);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    /**
     * Stream a review's evidence (private disk, hardened headers).
     */
    public function downloadEvidence(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ((int) $review->employee_user_id === (int) $user->id) {
            $this->access->currentStaff($user, $user);
            $review = HrPerformanceReview::query()
                ->where('employee_user_id', $user->id)
                ->findOrFail($review->getKey());
        } else {
            abort_unless($user->canDo('hr.performance.view'), 404);
            $this->access->currentStaff($user, $user);
            $review = $this->access->performanceReview($user, $review);
            abort_unless(
                $user->canDo('hr.performance.manage') || $review->reviewer_user_id === $user->id,
                404,
            );
        }
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
        $this->access->currentStaff($user, $user);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer'],
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
        $employee = $this->access->currentStaff($user, (int) $data['employee_user_id']);

        DB::transaction(function () use ($data, $employee, $user): void {
            HrProbationReview::query()->create([
                ...$data,
                'employee_user_id' => $employee->id,
                'reviewer_user_id' => $user->id,
                'created_by' => $user->id,
            ]);

            // The profile extension and review are one atomic employment fact.
            if (($data['recommendation'] ?? null) === 'extend' && ! empty($data['extension_weeks'])) {
                $profile = $this->access
                    ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                    ->where('user_id', $employee->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $base = $profile->probation_end_date ?? Carbon::parse($data['review_date']);
                $profile->forceFill([
                    'probation_end_date' => $base->copy()->addWeeks((int) $data['extension_weeks'])->toDateString(),
                    'probation_reminder_sent_at' => null,
                    'updated_by' => $user->id,
                ])->save();
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Probation review recorded.');
    }

    /**
     * Update an existing probation review.
     */
    public function updateProbation(Request $request, HrProbationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);
        $this->access->probationReview($user, $review);

        $data = $request->validate([
            'review_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:scheduled,completed,extended,passed,failed'],
            'areas_assessed' => ['nullable', 'array'],
            'areas_assessed.*' => ['string', 'max:500'],
            'concerns' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'in:pass,extend,fail'],
            'extension_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($data, $review, $user): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrProbationReview::query(), $user)
                ->lockForUpdate()
                ->findOrFail($review->getKey());
            $locked->update($data);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Probation review updated.');
    }
}
