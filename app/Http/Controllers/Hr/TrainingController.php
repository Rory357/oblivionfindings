<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\CertificateService;
use App\Domain\Hr\Services\ExpenseService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Domain\Hr\Services\TrainingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreTrainingCourseRequest;
use App\Http\Requests\Hr\UpdateTrainingCourseRequest;
use App\Models\Site;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingController extends Controller
{
    public function __construct(
        protected TrainingService $trainingService,
        protected CertificateService $certificateService,
        protected ExpenseService $expenseService,
        protected HrCurrentStaffService $currentStaff,
        protected UserSiteAccessService $siteAccess,
        protected PeopleMutationLockService $mutationLocks,
    ) {}

    /* ================================================================== */
    /*  Hub (canonical page — Dashboard / Catalog / Assignments tabs) */
    /* ================================================================== */

    public function catalog(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $staffUserIds = $this->visibleStaffUserIds($user);

        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'title');

        $metrics = $this->trainingService->courseMetrics($staffUserIds);

        $courses = HrCourse::query()
            ->when($request->query('category'), fn ($q, $cat) => $q->where('category', $cat))
            ->when($request->query('delivery_method'), fn ($q, $dm) => $q->where('delivery_method', $dm))
            ->when($request->boolean('mandatory_only'), fn ($q) => $q->mandatory())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('provider', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->withCount(['sessions' => fn ($q) => $q->upcoming()])
            ->get()
            ->map(function (HrCourse $c) use ($metrics) {
                $m = $metrics[$c->id] ?? ['enrol' => 0, 'completion' => 0, 'expiring' => 0];

                return [
                    'id' => $c->id,
                    'title' => $c->title,
                    'code' => $c->code,
                    'category' => $c->category,
                    'delivery_method' => $c->delivery_method,
                    'duration_hours' => (float) $c->duration_hours,
                    'provider' => $c->provider,
                    'cost' => $c->cost !== null ? (float) $c->cost : null,
                    'is_mandatory' => (bool) $c->is_mandatory,
                    'is_active' => (bool) $c->is_active,
                    'requires_renewal' => (bool) $c->requires_renewal,
                    'validity_period_months' => $c->validity_period_months,
                    'cpd_points' => $c->cpd_points,
                    'sessions_count' => $c->sessions_count,
                    'enrol' => $m['enrol'],
                    'completion' => $m['completion'],
                    'expiring' => $m['expiring'],
                ];
            });

        $courses = $this->sortCourses($courses, $sort);

        $assignments = HrCourseAssignment::query()
            ->whereIn('user_id', $staffUserIds ?: [0])
            ->with(['user:id,name', 'course:id,title'])
            ->orderByDesc('assigned_at')
            ->limit(300)
            ->get()
            ->map(fn (HrCourseAssignment $a) => [
                'id' => $a->id,
                'person' => $a->user?->name ?? 'Unknown',
                'course' => $a->course?->title ?? 'Unknown',
                'source' => $a->source,
                'due' => $a->due_at?->toDateString(),
                'status' => $a->effectiveStatus(),
                'score' => $a->score !== null ? (float) $a->score : null,
            ]);

        return Inertia::render('hr/training/catalog', [
            'summary' => $this->trainingService->getTrainingSummary($staffUserIds),
            'dashboard' => $this->trainingService->getDashboardData($staffUserIds),
            'courses' => $courses->values(),
            'assignments' => $assignments->values(),
            'categories' => HrCourse::query()->whereNotNull('category')->distinct()->pluck('category')->values(),
            'deliveryMethods' => [
                ['value' => 'online', 'label' => 'Online'],
                ['value' => 'in_person', 'label' => 'In Person'],
                ['value' => 'blended', 'label' => 'Blended'],
                ['value' => 'self_paced', 'label' => 'Self-Paced'],
            ],
            'lookups' => $this->wizardLookups($user, $staffUserIds),
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'category' => $request->query('category'),
                'delivery_method' => $request->query('delivery_method'),
                'mandatory_only' => $request->boolean('mandatory_only'),
            ],
            'competency' => $user->canDo('hr.performance.view') ? $this->competencySummary($staffUserIds) : null,
            'induction' => $user->canDo('hr.onboarding.view') ? $this->inductionSummary($staffUserIds) : null,
            'can' => [
                'manage' => $this->canManage($user),
                'enroll' => $this->canEnroll($user),
                'record' => $this->canRecord($user),
                'claim' => $this->canClaim($user),
                'competency' => (bool) $user->canDo('hr.performance.view'),
                'induction' => (bool) $user->canDo('hr.onboarding.view'),
            ],
        ]);
    }

    /**
     * Read-only competency-framework summary for the hub's Competency tab.
     * Deep-links to the canonical /hr/performance/competencies surface.
     */
    private function competencySummary(array $staffUserIds): array
    {
        $visibleProfile = fn ($query) => $query
            ->whereIn('user_id', $staffUserIds ?: [0]);
        $frameworks = HrCompetency::query()->active()
            ->withCount([
                'assessments' => fn ($query) => $query
                    ->whereHas('employeeProfile', $visibleProfile),
            ])
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'category']);

        $assessments = HrCompetencyAssessment::query()
            ->whereHas('employeeProfile', $visibleProfile);

        return [
            'total_frameworks' => $frameworks->count(),
            'total_assessments' => (clone $assessments)->count(),
            'assessments_this_month' => (clone $assessments)->whereDate('assessment_date', '>=', now()->startOfMonth()->toDateString())->count(),
            'frameworks' => $frameworks->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'category' => $f->category,
                'assessment_count' => $f->assessments_count,
            ])->values(),
            'manage_url' => '/hr/performance/competencies',
        ];
    }

    /**
     * Read-only staff-induction summary for the hub's Induction tab.
     * Deep-links to the canonical /hr/onboarding surface.
     */
    private function inductionSummary(array $staffUserIds): array
    {
        $templates = HrOnboardingTemplate::query()->where('is_active', true)
            ->orderBy('role')->get(['id', 'role', 'site_type', 'tasks']);

        $base = HrOnboardingChecklist::query()
            ->whereHas('employeeProfile', fn ($query) => $query
                ->whereIn('user_id', $staffUserIds ?: [0]));

        return [
            'total_templates' => $templates->count(),
            'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'not_started' => (clone $base)->where('status', 'not_started')->count(),
            'templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'role' => $t->role,
                'site_type' => $t->site_type,
                'task_count' => is_array($t->tasks) ? count($t->tasks) : 0,
            ])->values(),
            'manage_url' => '/hr/onboarding',
        ];
    }

    /**
     * Legacy deep-link to a single course → open the hub with its sheet.
     */
    public function showCourse(Request $request, HrCourse $course)
    {
        $this->authorizeView($request->user());

        return redirect()->route('hr.training.catalog', ['course' => $course->id]);
    }

    /**
     * JSON detail for the slide-over sheet (sessions + recent enrolments).
     */
    public function courseDetail(Request $request, HrCourse $course)
    {
        $user = $request->user();
        $this->authorizeView($user);
        $staffUserIds = $this->visibleStaffUserIds($user);

        $course->load([
            // Capacity is a property of the shared session. Count every occupied
            // seat while keeping the identifiable enrollment list Site-scoped.
            'sessions' => fn ($q) => $q->orderBy('session_date')->withCount('enrollments'),
            'sessions.trainer:id,name',
            'enrollments' => fn ($q) => $q
                ->whereIn('user_id', $staffUserIds ?: [0])
                ->with('user:id,name')
                ->orderByDesc('enrolled_at')
                ->limit(8),
        ]);

        $metrics = ($this->trainingService->courseMetrics($staffUserIds))[$course->id]
            ?? ['enrol' => 0, 'completion' => 0, 'expiring' => 0];

        return response()->json([
            'id' => $course->id,
            'title' => $course->title,
            'code' => $course->code,
            'provider' => $course->provider,
            'category' => $course->category,
            'delivery_method' => $course->delivery_method,
            'duration_hours' => (float) $course->duration_hours,
            'cost' => $course->cost !== null ? (float) $course->cost : null,
            'is_mandatory' => (bool) $course->is_mandatory,
            'is_active' => (bool) $course->is_active,
            'requires_renewal' => (bool) $course->requires_renewal,
            'validity_period_months' => $course->validity_period_months,
            'metrics' => $metrics,
            'sessions' => $course->sessions->map(fn (HrCourseSession $s) => [
                'id' => $s->id,
                'session_date' => $s->session_date?->toDateString(),
                'start_time' => $s->start_time ? substr((string) $s->start_time, 0, 5) : null,
                'end_time' => $s->end_time ? substr((string) $s->end_time, 0, 5) : null,
                'location' => $s->location,
                'trainer' => $s->trainer?->name ?? $s->facilitator,
                'status' => $s->status,
                'seats' => $s->max_participants ? max(0, $s->max_participants - $s->enrollments_count) : null,
            ])->values(),
            'enrollments' => $course->enrollments->map(fn (HrCourseEnrollment $e) => [
                'id' => $e->id,
                'name' => $e->user?->name ?? 'Unknown',
                'status' => $e->status,
                'score' => $e->score !== null ? (float) $e->score : null,
            ])->values(),
        ]);
    }

    /* ================================================================== */
    /*  Courses */
    /* ================================================================== */

    public function storeCourse(StoreTrainingCourseRequest $request)
    {
        $this->trainingService->createCourse($request->validated());

        return redirect()->back()->with('success', 'Course created.');
    }

    public function updateCourse(UpdateTrainingCourseRequest $request, HrCourse $course)
    {
        $this->trainingService->updateCourse($course, $request->validated());

        return redirect()->back()->with('success', 'Course saved.');
    }

    public function toggleCourse(Request $request, HrCourse $course)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $this->trainingService->setCourseActive($course, ! $course->is_active);

        return redirect()->back()->with('success', $course->is_active ? 'Course archived.' : 'Course activated.');
    }

    public function bulkArchiveCourses(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $data = $request->validate([
            'course_ids' => ['required', 'array', 'max:100'],
            'course_ids.*' => ['integer'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $active = $data['active'] ?? false;
        HrCourse::query()->whereIn('id', $data['course_ids'])->update(['is_active' => $active]);

        return redirect()->back()->with('success', $active ? 'Courses activated.' : 'Courses archived.');
    }

    /* ================================================================== */
    /*  Sessions */
    /* ================================================================== */

    public function storeSession(Request $request, HrCourse $course)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $data = $this->validateSession($request, $user);
        $this->trainingService->createSession($course, $data);

        return redirect()->back()->with('success', 'Session scheduled.');
    }

    public function updateSession(Request $request, HrCourseSession $session)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $this->trainingService->updateSession($session, $this->validateSession($request, $user));

        return redirect()->back()->with('success', 'Session updated.');
    }

    public function cancelSession(Request $request, HrCourseSession $session)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->trainingService->cancelSession($session, $reason);

        return redirect()->back()->with('success', 'Session cancelled — enrolled staff notified.');
    }

    private function validateSession(Request $request, User $viewer): array
    {
        $data = $request->validate([
            'session_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'online_link' => ['nullable', 'string', 'max:500'],
            'trainer_id' => ['nullable', 'integer', 'exists:users,id'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($data['trainer_id'])) {
            $this->assertVisibleUserIds($viewer, [(int) $data['trainer_id']], 'trainer_id');
        }

        return $data;
    }

    /* ================================================================== */
    /*  Enrolments & completion */
    /* ================================================================== */

    public function enroll(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $data = $request->validate([
            'user_id' => ['required_without:user_ids', 'integer', 'exists:users,id', $this->currentStaff->recipientRule()],
            'user_ids' => ['required_without:user_id', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id', $this->currentStaff->recipientRule()],
            'course_id' => ['required', 'integer', 'exists:hr_courses,id'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->trainingService->assertSessionMatchesCourses(
            $data['session_id'] ?? null,
            [(int) $data['course_id']],
        );

        $userIds = $data['user_ids'] ?? [$data['user_id']];
        $this->assertVisibleUserIds(
            $user,
            $userIds,
            array_key_exists('user_ids', $data) ? 'user_ids' : 'user_id',
        );
        DB::transaction(function () use ($user, $userIds, $data): void {
            $locked = $this->mutationLocks->lock([$user->id, ...$userIds]);
            $lockedActor = $locked['users']->get($user->id);
            abort_unless($lockedActor instanceof User && $this->canEnroll($lockedActor), 403);
            $freshSiteAccess = new UserSiteAccessService;
            $this->assertVisibleUserIds(
                $lockedActor,
                $userIds,
                array_key_exists('user_ids', $data) ? 'user_ids' : 'user_id',
                $freshSiteAccess,
            );

            $this->trainingService->enrollMany(
                $userIds,
                $data['course_id'],
                $data['session_id'] ?? null,
                $data['notes'] ?? null,
            );
        });

        return redirect()->back()->with('success', count($userIds) > 1 ? 'Employees enrolled.' : 'Employee enrolled in course.');
    }

    public function completeEnrollment(Request $request, HrCourseEnrollment $enrollment)
    {
        $user = $request->user();
        abort_unless($this->canRecord($user), 403);
        $enrollment = $this->visibleEnrollment($user, (int) $enrollment->getKey());

        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'completed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $newCertificatePath = null;
        $committed = false;
        $enrollmentId = (int) $enrollment->getKey();
        if ($request->hasFile('certificate') && DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use (&$newCertificatePath, $enrollmentId): void {
                $this->deleteTrainingCertificate($newCertificatePath, $enrollmentId);
            });
        }

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $data,
                $enrollmentId,
                &$newCertificatePath,
                &$committed,
            ): void {
                [, $lockedEnrollment] = $this->lockVisibleEnrollment(
                    $user,
                    $enrollmentId,
                    fn (User $actor) => $this->canRecord($actor),
                );
                $oldCertificatePath = $lockedEnrollment->certificate_path;
                $completion = $data;
                if ($request->hasFile('certificate')) {
                    $storedPath = $request->file('certificate')->store(
                        "hr/training/certificates/{$enrollmentId}",
                        'private',
                    );
                    if (! is_string($storedPath)) {
                        throw ValidationException::withMessages([
                            'certificate' => 'The certificate could not be stored. Please try again.',
                        ]);
                    }
                    $newCertificatePath = $storedPath;
                    $completion['certificate_path'] = $newCertificatePath;
                }

                $this->trainingService->completeEnrollment($lockedEnrollment, $completion);
                DB::afterCommit(function () use (
                    $enrollmentId,
                    $oldCertificatePath,
                    &$newCertificatePath,
                    &$committed,
                ): void {
                    $committed = true;
                    try {
                        $persistedPath = HrCourseEnrollment::query()
                            ->whereKey($enrollmentId)
                            ->value('certificate_path');
                        if ($newCertificatePath
                            && $oldCertificatePath !== $newCertificatePath
                            && $persistedPath !== $oldCertificatePath) {
                            $this->deleteTrainingCertificate($oldCertificatePath, $enrollmentId);
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }, 1);
            $committed = true;
        } catch (\Throwable $exception) {
            if (! $committed) {
                $this->deleteTrainingCertificate($newCertificatePath, $enrollmentId);
            }

            throw $exception;
        }

        return redirect()->back()->with('success', 'Completion recorded.');
    }

    /**
     * Record completion for one or more people (Record-completion wizard).
     * Finds-or-creates an enrollment per person, then completes it.
     */
    public function recordCompletion(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canRecord($user), 403);
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:hr_courses,id'],
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id', $this->currentStaff->recipientRule()],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'completed_at' => ['required', 'date', 'before_or_equal:today'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $userIds = collect($data['user_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->trainingService->assertSessionMatchesCourses(
            $data['session_id'] ?? null,
            [(int) $data['course_id']],
        );
        if (count($userIds) > 1 && $request->hasFile('certificate')) {
            throw ValidationException::withMessages([
                'certificate' => 'Upload a certificate for one employee at a time.',
            ]);
        }
        if (count($userIds) > 1 && filled($data['certificate_number'] ?? null)) {
            throw ValidationException::withMessages([
                'certificate_number' => 'Enter a certificate number for one employee at a time.',
            ]);
        }
        $this->assertVisibleUserIds($user, $userIds);

        $newCertificatePath = null;
        $certificateEnrollmentId = null;
        $committed = false;
        if ($request->hasFile('certificate') && DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use (&$newCertificatePath, &$certificateEnrollmentId): void {
                $this->deleteTrainingCertificate($newCertificatePath, $certificateEnrollmentId);
            });
        }

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $userIds,
                $data,
                &$newCertificatePath,
                &$certificateEnrollmentId,
                &$committed,
            ): void {
                $locked = $this->mutationLocks->lock([$user->id, ...$userIds]);
                $lockedActor = $locked['users']->get($user->id);
                abort_unless($lockedActor instanceof User && $this->canRecord($lockedActor), 403);
                $this->assertVisibleUserIds(
                    $lockedActor,
                    $userIds,
                    'user_ids',
                    new UserSiteAccessService,
                );

                foreach ($userIds as $userId) {
                    $enrollment = HrCourseEnrollment::firstOrCreate(
                        ['user_id' => $userId, 'course_id' => $data['course_id']],
                        [
                            'status' => 'enrolled',
                            'enrolled_at' => now(),
                            'session_id' => $data['session_id'] ?? null,
                        ]
                    );
                    if (! empty($data['session_id']) && (int) $enrollment->session_id !== (int) $data['session_id']) {
                        $enrollment->update(['session_id' => (int) $data['session_id']]);
                    }

                    $oldCertificatePath = $enrollment->certificate_path;
                    if ($request->hasFile('certificate')) {
                        $certificateEnrollmentId = (int) $enrollment->getKey();
                        $storedPath = $request->file('certificate')->store(
                            "hr/training/certificates/{$certificateEnrollmentId}",
                            'private',
                        );
                        if (! is_string($storedPath)) {
                            throw ValidationException::withMessages([
                                'certificate' => 'The certificate could not be stored. Please try again.',
                            ]);
                        }
                        $newCertificatePath = $storedPath;
                    }

                    $this->trainingService->completeEnrollment($enrollment, [
                        'score' => $data['score'] ?? null,
                        'completed_at' => $data['completed_at'],
                        'certificate_path' => $newCertificatePath,
                        'certificate_number' => $data['certificate_number'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]);

                    if ($newCertificatePath) {
                        DB::afterCommit(function () use (
                            $oldCertificatePath,
                            &$newCertificatePath,
                            &$certificateEnrollmentId,
                            &$committed,
                        ): void {
                            $committed = true;
                            try {
                                $persistedPath = HrCourseEnrollment::query()
                                    ->whereKey($certificateEnrollmentId)
                                    ->value('certificate_path');
                                if ($oldCertificatePath !== $newCertificatePath
                                    && $persistedPath !== $oldCertificatePath) {
                                    $this->deleteTrainingCertificate(
                                        $oldCertificatePath,
                                        $certificateEnrollmentId,
                                    );
                                }
                            } catch (\Throwable $exception) {
                                report($exception);
                            }
                        });
                    }
                }
            }, 1);
            $committed = true;
        } catch (\Throwable $exception) {
            if (! $committed) {
                $this->deleteTrainingCertificate($newCertificatePath, $certificateEnrollmentId);
            }

            throw $exception;
        }

        return redirect()->back()->with('success', 'Completion recorded.');
    }

    /* ================================================================== */
    /*  Assignments */
    /* ================================================================== */

    public function storeAssignments(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $data = $request->validate([
            'course_ids' => ['required', 'array', 'min:1', 'max:100'],
            'course_ids.*' => ['integer', 'exists:hr_courses,id'],
            'audience_type' => ['required', 'in:individuals,role,site,cohort'],
            'user_ids' => ['nullable', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id', $this->currentStaff->recipientRule()],
            'role' => ['required_if:audience_type,role', 'nullable', 'string', 'max:120'],
            'site_id' => ['required_if:audience_type,site', 'nullable', 'integer', 'exists:sites,id'],
            'due_at' => ['nullable', 'date'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'source' => ['nullable', 'in:manual,role_rule,hs_requirement'],
        ]);
        $this->trainingService->assertSessionMatchesCourses(
            $data['session_id'] ?? null,
            $data['course_ids'],
        );

        $allowedUserIds = $this->visibleStaffUserIds($user);
        if (($data['audience_type'] ?? null) === 'individuals') {
            $this->assertVisibleUserIds($user, $data['user_ids'] ?? []);
        }
        $this->assertAccessibleAudienceSite($user, $data);

        $initialTargets = $this->trainingService->resolveAudience(
            $data,
            $allowedUserIds,
        );
        $count = 0;
        if ($initialTargets !== []) {
            $count = DB::transaction(function () use ($user, $initialTargets, $data): int {
                $locked = $this->mutationLocks->lock([$user->id, ...$initialTargets]);
                $lockedActor = $locked['users']->get($user->id);
                abort_unless($lockedActor instanceof User && $this->canEnroll($lockedActor), 403);

                $freshSiteAccess = new UserSiteAccessService;
                $this->assertAccessibleAudienceSite($lockedActor, $data, $freshSiteAccess);
                $freshAllowed = $this->visibleStaffUserIds($lockedActor, $freshSiteAccess);
                $lockedAllowed = collect($initialTargets)
                    ->intersect($freshAllowed)
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
                if (count($lockedAllowed) !== count($initialTargets)) {
                    throw ValidationException::withMessages([
                        'user_ids' => 'The selected training audience changed. Review it and try again.',
                    ]);
                }

                return $this->trainingService->createAssignments(
                    $data,
                    $lockedActor->id,
                    $lockedAllowed,
                );
            });
        }

        return redirect()->back()->with('success', $count > 0 ? "Training assigned to {$count} record(s)." : 'No matching people for that audience.');
    }

    public function previewAssignments(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $data = $request->validate([
            'course_ids' => ['nullable', 'array', 'max:100'],
            'course_ids.*' => ['integer', 'exists:hr_courses,id'],
            'audience_type' => ['required', 'in:individuals,role,site,cohort'],
            'user_ids' => ['nullable', 'array', 'max:500'],
            'user_ids.*' => ['integer'],
            'role' => ['required_if:audience_type,role', 'nullable', 'string', 'max:120'],
            'site_id' => ['required_if:audience_type,site', 'nullable', 'integer'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
        ]);
        $this->trainingService->assertSessionMatchesCourses(
            $data['session_id'] ?? null,
            $data['course_ids'] ?? [],
        );

        if (($data['audience_type'] ?? null) === 'individuals') {
            $this->assertVisibleUserIds($user, $data['user_ids'] ?? []);
        }
        $this->assertAccessibleAudienceSite($user, $data);

        return response()->json($this->trainingService->previewAudience(
            $data,
            $this->visibleStaffUserIds($user),
        ));
    }

    public function remindAssignment(Request $request, HrCourseAssignment $assignment)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $assignment = $this->visibleAssignment($user, (int) $assignment->getKey());

        DB::transaction(function () use ($user, $assignment): void {
            [, $lockedAssignment] = $this->lockVisibleAssignment(
                $user,
                (int) $assignment->getKey(),
                fn (User $actor) => $this->canEnroll($actor),
            );
            $this->trainingService->remindAssignment($lockedAssignment);
        });

        return redirect()->back()->with('success', 'Reminder sent.');
    }

    public function waiveAssignment(Request $request, HrCourseAssignment $assignment)
    {
        $user = $request->user();
        abort_unless($this->canManage($user) || $this->canRecord($user), 403);
        $assignment = $this->visibleAssignment($user, (int) $assignment->getKey());

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        DB::transaction(function () use ($user, $assignment, $reason): void {
            [, $lockedAssignment] = $this->lockVisibleAssignment(
                $user,
                (int) $assignment->getKey(),
                fn (User $actor) => $this->canManage($actor) || $this->canRecord($actor),
            );
            $this->trainingService->waiveAssignment($lockedAssignment, $reason);
        });

        return redirect()->back()->with('success', 'Assignment waived.');
    }

    /* ================================================================== */
    /*  Fees (expense claim) */
    /* ================================================================== */

    public function claimFee(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canClaim($user), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'course_id' => ['nullable', 'integer', 'exists:hr_courses,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.category' => ['required', 'string', 'max:50'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.expense_date' => ['required', 'date'],
        ]);

        // The course (when given) is the expense source. The catalogue is
        // application-global, while the claim remains owned by the actor.
        if (! empty($data['course_id'])) {
            HrCourse::query()->findOrFail($data['course_id']);
        }

        $items = collect($data['items'])->map(fn ($it) => [
            'description' => $it['description'],
            'category' => $it['category'],
            'amount' => $it['amount'],
            'expense_date' => $it['expense_date'],
            'source_type' => $data['course_id'] ? HrCourse::class : null,
            'source_id' => $data['course_id'] ?? null,
        ])->all();

        $claim = $this->expenseService->createClaim($user, [
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'currency' => 'NZD',
            'items' => $items,
        ]);
        $this->expenseService->submitClaim($claim);

        return redirect()->back()->with('success', 'Expense claim submitted.');
    }

    /* ================================================================== */
    /*  Export */
    /* ================================================================== */

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $this->authorizeView($user);
        $staffUserIds = $this->visibleStaffUserIds($user);
        $type = $request->query('type', 'catalog');

        [$filename, $headers, $rows] = match ($type) {
            'assignments' => $this->assignmentsExport($staffUserIds),
            'enrolments' => $this->enrolmentsExport($staffUserIds),
            default => $this->catalogExport($staffUserIds),
        };

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, $headers);
            foreach ($rows as $row) {
                $this->putCsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function catalogExport(array $staffUserIds): array
    {
        $metrics = $this->trainingService->courseMetrics($staffUserIds);
        $rows = HrCourse::query()->orderBy('title')->get()->map(fn (HrCourse $c) => [
            $c->title, $c->code, $c->category, $c->delivery_method, (float) $c->duration_hours,
            $c->provider, $c->cost, $c->is_mandatory ? 'Yes' : 'No',
            $metrics[$c->id]['enrol'] ?? 0, ($metrics[$c->id]['completion'] ?? 0).'%',
            $c->is_active ? 'Active' : 'Inactive',
        ])->all();

        return ['training-catalog.csv', ['Title', 'Code', 'Category', 'Delivery', 'Hours', 'Provider', 'Fee', 'Mandatory', 'Enrolled', 'Completion', 'Status'], $rows];
    }

    private function assignmentsExport(array $staffUserIds): array
    {
        $rows = HrCourseAssignment::query()
            ->whereIn('user_id', $staffUserIds ?: [0])
            ->with(['user:id,name', 'course:id,title'])
            ->orderByDesc('assigned_at')->get()
            ->map(fn (HrCourseAssignment $a) => [
                $a->user?->name, $a->course?->title, $a->source,
                $a->due_at?->toDateString(), $a->effectiveStatus(), $a->score,
            ])->all();

        return ['training-assignments.csv', ['Person', 'Course', 'Source', 'Due', 'Status', 'Score'], $rows];
    }

    private function enrolmentsExport(array $staffUserIds): array
    {
        $rows = HrCourseEnrollment::query()
            ->whereIn('user_id', $staffUserIds ?: [0])
            ->with(['user:id,name', 'course:id,title'])
            ->orderByDesc('enrolled_at')->get()
            ->map(fn (HrCourseEnrollment $e) => [
                $e->user?->name, $e->course?->title, $e->status,
                $e->enrolled_at?->toDateString(), $e->completed_at?->toDateString(), $e->score,
            ])->all();

        return ['training-enrolments.csv', ['Person', 'Course', 'Status', 'Enrolled', 'Completed', 'Score'], $rows];
    }

    /* ================================================================== */
    /*  Certificate (existing) */
    /* ================================================================== */

    public function downloadCertificate(Request $request, HrCourseEnrollment $enrollment)
    {
        $user = $request->user();
        $this->authorizeView($user);
        $enrollment = $this->visibleEnrollment($user, (int) $enrollment->getKey());
        abort_unless($enrollment->status === 'completed', 404, 'Certificate is only available for completed enrollments.');

        $path = $enrollment->certificate_path;
        if (! $path || ! Storage::disk('private')->exists($path)) {
            $path = $this->certificateService->generateCertificate($enrollment);
        }

        $enrollment->loadMissing('course');
        $extension = pathinfo((string) $path, PATHINFO_EXTENSION) ?: 'html';
        $filename = 'certificate_'.Str::slug($enrollment->course?->title ?? 'course').'.'.$extension;

        return Storage::disk('private')->download($path, $filename);
    }

    /* ================================================================== */
    /*  Helpers */
    /* ================================================================== */

    private function wizardLookups(User $viewer, array $staffUserIds): array
    {
        $staff = User::whereIn('id', $staffUserIds ?: [0])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        $roles = HrEmployeeProfile::query()
            ->whereIn('user_id', $staffUserIds ?: [0])
            ->whereNotNull('position_role')
            ->distinct()
            ->orderBy('position_role')
            ->pluck('position_role')
            ->filter()
            ->map(fn ($r) => ['value' => $r, 'label' => Str::headline($r)])
            ->values();

        $requirements = HrComplianceRequirement::query()
            ->where('check_type', 'training_course')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])
            ->values();

        $sites = Site::query();
        $this->siteAccess->applySiteScope($sites, $viewer);

        return [
            'staff' => $staff,
            'sites' => $sites->orderBy('name')->get(['id', 'name'])
                ->map(fn ($site) => ['value' => (string) $site->id, 'label' => $site->name])
                ->values(),
            'roles' => $roles,
            'requirements' => $requirements,
            'categories' => HrCourse::query()->whereNotNull('category')->distinct()->pluck('category')->filter()->values(),
        ];
    }

    /** @return array<int, int> */
    private function visibleStaffUserIds(
        User $viewer,
        ?UserSiteAccessService $siteAccess = null,
    ): array {
        $query = User::query();
        ($siteAccess ?? $this->siteAccess)->applyStaffScope($query, $viewer);

        return $query->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $requestedUserIds */
    private function assertVisibleUserIds(
        User $viewer,
        array $requestedUserIds,
        string $field = 'user_ids',
        ?UserSiteAccessService $siteAccess = null,
    ): void {
        $requested = collect($requestedUserIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $visible = collect($this->visibleStaffUserIds($viewer, $siteAccess))
            ->intersect($requested)
            ->sort()
            ->values();

        if ($requested->isEmpty() || $visible->all() !== $requested->all()) {
            throw ValidationException::withMessages([
                $field => 'Every selected person must be current staff at an accessible Site.',
            ]);
        }
    }

    private function assertAccessibleAudienceSite(
        User $viewer,
        array $data,
        ?UserSiteAccessService $siteAccess = null,
    ): void {
        if (($data['audience_type'] ?? null) !== 'site') {
            return;
        }

        $siteId = (int) ($data['site_id'] ?? 0);
        if ($siteId < 1 || ! in_array(
            $siteId,
            ($siteAccess ?? $this->siteAccess)->accessibleSiteIds($viewer),
            true,
        )) {
            throw ValidationException::withMessages([
                'site_id' => 'The selected Site is not available.',
            ]);
        }
    }

    private function deleteTrainingCertificate(?string $path, ?int $enrollmentId): void
    {
        if (! $path || ! $enrollmentId) {
            return;
        }

        $normalised = str_replace('\\', '/', $path);
        $segments = explode('/', $normalised);
        $ownedPrefix = "hr/training/certificates/{$enrollmentId}/";
        $legacyUploadRoot = 'hr/training/certificates/';
        $legacyUploadRelative = Str::after($normalised, $legacyUploadRoot);
        $enrollment = HrCourseEnrollment::query()->whereKey($enrollmentId)->first();
        $legacyGeneratedPrefix = $enrollment
            ? "hr-certificates/{$enrollment->user_id}/"
            : null;
        $isOwnedPath = Str::startsWith($normalised, $ownedPrefix);
        $isLegacyDirectUpload = Str::startsWith($normalised, $legacyUploadRoot)
            && ! Str::contains($legacyUploadRelative, '/');
        $isLegacyGenerated = $legacyGeneratedPrefix
            && Str::startsWith($normalised, $legacyGeneratedPrefix);
        if (in_array('..', $segments, true)
            || (! $isOwnedPath && ! $isLegacyDirectUpload && ! $isLegacyGenerated)) {
            return;
        }

        try {
            $referencedByAnotherEnrollment = HrCourseEnrollment::query()
                ->where('certificate_path', $normalised)
                ->whereKeyNot($enrollmentId)
                ->exists();
            $referencedByTrainingRecord = StaffTrainingRecord::withTrashed()
                ->where('certificate_path', $normalised)
                ->exists();
            if ($referencedByAnotherEnrollment || $referencedByTrainingRecord) {
                return;
            }

            Storage::disk('private')->delete($normalised);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function visibleEnrollment(User $viewer, int $enrollmentId): HrCourseEnrollment
    {
        $enrollment = HrCourseEnrollment::query()
            ->whereKey($enrollmentId)
            ->whereIn('user_id', $this->visibleStaffUserIds($viewer) ?: [0])
            ->first();
        abort_unless($enrollment, 404);

        return $enrollment;
    }

    private function visibleAssignment(User $viewer, int $assignmentId): HrCourseAssignment
    {
        $assignment = HrCourseAssignment::query()
            ->whereKey($assignmentId)
            ->whereIn('user_id', $this->visibleStaffUserIds($viewer) ?: [0])
            ->first();
        abort_unless($assignment, 404);

        return $assignment;
    }

    /**
     * @param  callable(User): bool  $authorised
     * @return array{0: User, 1: HrCourseEnrollment}
     */
    private function lockVisibleEnrollment(
        User $actor,
        int $enrollmentId,
        callable $authorised,
    ): array {
        $candidate = HrCourseEnrollment::query()->whereKey($enrollmentId)->first();
        abort_unless($candidate, 404);

        $locked = $this->mutationLocks->lock([$actor->id, $candidate->user_id]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless($lockedActor instanceof User && $authorised($lockedActor), 403);

        $freshSiteAccess = new UserSiteAccessService;
        $lockedEnrollment = HrCourseEnrollment::query()
            ->whereKey($enrollmentId)
            ->whereIn('user_id', $this->visibleStaffUserIds($lockedActor, $freshSiteAccess) ?: [0])
            ->lockForUpdate()
            ->first();
        abort_unless($lockedEnrollment, 404);

        return [$lockedActor, $lockedEnrollment];
    }

    /**
     * @param  callable(User): bool  $authorised
     * @return array{0: User, 1: HrCourseAssignment}
     */
    private function lockVisibleAssignment(
        User $actor,
        int $assignmentId,
        callable $authorised,
    ): array {
        $candidate = HrCourseAssignment::query()->whereKey($assignmentId)->first();
        abort_unless($candidate, 404);

        $locked = $this->mutationLocks->lock([$actor->id, $candidate->user_id]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless($lockedActor instanceof User && $authorised($lockedActor), 403);

        $freshSiteAccess = new UserSiteAccessService;
        $lockedAssignment = HrCourseAssignment::query()
            ->whereKey($assignmentId)
            ->whereIn('user_id', $this->visibleStaffUserIds($lockedActor, $freshSiteAccess) ?: [0])
            ->lockForUpdate()
            ->first();
        abort_unless($lockedAssignment, 404);

        return [$lockedActor, $lockedAssignment];
    }

    private function sortCourses($courses, string $sort)
    {
        return match ($sort) {
            'completion' => $courses->sortByDesc('completion'),
            'enrol' => $courses->sortByDesc('enrol'),
            'cost' => $courses->sortByDesc(fn ($c) => $c['cost'] ?? 0),
            'expiring' => $courses->sortByDesc('expiring'),
            default => $courses->sortBy('title'),
        };
    }

    private function authorizeView(?User $user): void
    {
        abort_unless($user && ($user->canDo('hr.training.view') || $user->canDo('training.viewAny')), 403);
    }

    private function canManage(?User $user): bool
    {
        return (bool) $user && ($user->canDo('hr.training.manage') || $user->canDo('training.manageCourses'));
    }

    private function canEnroll(?User $user): bool
    {
        return $this->canManage($user) || (bool) ($user && $user->canDo('training.enrol'));
    }

    private function canRecord(?User $user): bool
    {
        return $this->canManage($user) || (bool) ($user && $user->canDo('training.record'));
    }

    private function canClaim(?User $user): bool
    {
        return (bool) $user && ($user->canDo('hr.expenses.create') || $user->canDo('hr.expenses.manage') || $this->canManage($user));
    }
}
