<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\CertificateService;
use App\Domain\Hr\Services\ExpenseService;
use App\Domain\Hr\Services\TrainingService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreTrainingCourseRequest;
use App\Http\Requests\Hr\UpdateTrainingCourseRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        protected TrainingService $trainingService,
        protected CertificateService $certificateService,
        protected ExpenseService $expenseService,
    ) {}

    /* ================================================================== */
    /*  Hub (canonical page — Dashboard / Catalog / Assignments tabs)     */
    /* ================================================================== */

    public function catalog(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $staffUserIds = $this->hrStaffUserIdsForTenant($tenantId);

        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'title');

        $metrics = $this->trainingService->courseMetrics($tenantId);

        $courses = HrCourse::query()
            ->forTenant($tenantId)
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
            ->forTenant($tenantId)
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
            'summary' => $this->trainingService->getTrainingSummary($tenantId),
            'dashboard' => $this->trainingService->getDashboardData($tenantId, $staffUserIds),
            'courses' => $courses->values(),
            'assignments' => $assignments->values(),
            'categories' => HrCourse::forTenant($tenantId)->whereNotNull('category')->distinct()->pluck('category')->values(),
            'deliveryMethods' => [
                ['value' => 'online', 'label' => 'Online'],
                ['value' => 'in_person', 'label' => 'In Person'],
                ['value' => 'blended', 'label' => 'Blended'],
                ['value' => 'self_paced', 'label' => 'Self-Paced'],
            ],
            'lookups' => $this->wizardLookups($tenantId, $staffUserIds),
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'category' => $request->query('category'),
                'delivery_method' => $request->query('delivery_method'),
                'mandatory_only' => $request->boolean('mandatory_only'),
            ],
            'can' => [
                'manage' => $this->canManage($user),
                'enroll' => $this->canEnroll($user),
                'record' => $this->canRecord($user),
                'claim' => $user->canDo('hr.expenses.create') || $user->canDo('hr.expenses.manage') || $this->canManage($user),
            ],
        ]);
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $course->tenant_id);

        $course->load([
            'sessions' => fn ($q) => $q->orderBy('session_date')->withCount('enrollments'),
            'sessions.trainer:id,name',
            'enrollments' => fn ($q) => $q->with('user:id,name')->orderByDesc('enrolled_at')->limit(8),
        ]);

        $metrics = ($this->trainingService->courseMetrics($tenantId))[$course->id]
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
    /*  Courses                                                            */
    /* ================================================================== */

    public function storeCourse(StoreTrainingCourseRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $this->trainingService->createCourse([
            'tenant_id' => $tenantId,
            ...$request->validated(),
        ]);

        return redirect()->back()->with('success', 'Course created.');
    }

    public function updateCourse(UpdateTrainingCourseRequest $request, HrCourse $course)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $course->tenant_id);

        $this->trainingService->updateCourse($course, $request->validated());

        return redirect()->back()->with('success', 'Course saved.');
    }

    public function toggleCourse(Request $request, HrCourse $course)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $course->tenant_id);

        $this->trainingService->setCourseActive($course, ! $course->is_active);

        return redirect()->back()->with('success', $course->is_active ? 'Course archived.' : 'Course activated.');
    }

    public function bulkArchiveCourses(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'course_ids' => ['required', 'array'],
            'course_ids.*' => ['integer'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $active = $data['active'] ?? false;
        HrCourse::forTenant($tenantId)->whereIn('id', $data['course_ids'])->update(['is_active' => $active]);

        return redirect()->back()->with('success', $active ? 'Courses activated.' : 'Courses archived.');
    }

    /* ================================================================== */
    /*  Sessions                                                           */
    /* ================================================================== */

    public function storeSession(Request $request, HrCourse $course)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $course->tenant_id);

        $data = $this->validateSession($request);
        $this->trainingService->createSession($course, $data);

        return redirect()->back()->with('success', 'Session scheduled.');
    }

    public function updateSession(Request $request, HrCourseSession $session)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $session->tenant_id);

        $this->trainingService->updateSession($session, $this->validateSession($request));

        return redirect()->back()->with('success', 'Session updated.');
    }

    public function cancelSession(Request $request, HrCourseSession $session)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $session->tenant_id);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->trainingService->cancelSession($session, $reason);

        return redirect()->back()->with('success', 'Session cancelled — enrolled staff notified.');
    }

    private function validateSession(Request $request): array
    {
        return $request->validate([
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
    }

    /* ================================================================== */
    /*  Enrolments & completion                                           */
    /* ================================================================== */

    public function enroll(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'user_id' => ['required_without:user_ids', 'integer', 'exists:users,id'],
            'user_ids' => ['required_without:user_id', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'course_id' => ['required', 'integer', 'exists:hr_courses,id'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $userIds = $data['user_ids'] ?? [$data['user_id']];
        $this->trainingService->enrollMany($tenantId, $userIds, $data['course_id'], $data['session_id'] ?? null, $data['notes'] ?? null);

        return redirect()->back()->with('success', count($userIds) > 1 ? 'Employees enrolled.' : 'Employee enrolled in course.');
    }

    public function completeEnrollment(Request $request, HrCourseEnrollment $enrollment)
    {
        $user = $request->user();
        abort_unless($this->canRecord($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $enrollment->tenant_id);

        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'completed_at' => ['nullable', 'date'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->hasFile('certificate')) {
            $data['certificate_path'] = $request->file('certificate')->store('hr/training/certificates', 'private');
        }

        $this->trainingService->completeEnrollment($enrollment, $data);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:hr_courses,id'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'completed_at' => ['required', 'date'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $certPath = $request->hasFile('certificate')
            ? $request->file('certificate')->store('hr/training/certificates', 'private')
            : null;

        foreach ($data['user_ids'] as $userId) {
            $enrollment = HrCourseEnrollment::firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => (int) $userId, 'course_id' => $data['course_id']],
                ['status' => 'enrolled', 'enrolled_at' => now(), 'session_id' => $data['session_id'] ?? null]
            );

            $this->trainingService->completeEnrollment($enrollment, [
                'score' => $data['score'] ?? null,
                'completed_at' => $data['completed_at'],
                'certificate_path' => $certPath,
                'notes' => $data['notes'] ?? null,
            ]);
        }

        return redirect()->back()->with('success', 'Completion recorded.');
    }

    /* ================================================================== */
    /*  Assignments                                                        */
    /* ================================================================== */

    public function storeAssignments(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer', 'exists:hr_courses,id'],
            'audience_type' => ['required', 'in:individuals,role,site,cohort'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:120'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'due_at' => ['nullable', 'date'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'source' => ['nullable', 'in:manual,role_rule,hs_requirement'],
        ]);

        $count = $this->trainingService->createAssignments($tenantId, $data, $user->id);

        return redirect()->back()->with('success', $count > 0 ? "Training assigned to {$count} record(s)." : 'No matching people for that audience.');
    }

    public function previewAssignments(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer'],
            'audience_type' => ['required', 'in:individuals,role,site,cohort'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'role' => ['nullable', 'string', 'max:120'],
            'site_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->trainingService->previewAudience($tenantId, $data));
    }

    public function remindAssignment(Request $request, HrCourseAssignment $assignment)
    {
        $user = $request->user();
        abort_unless($this->canEnroll($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $assignment->tenant_id);

        $this->trainingService->remindAssignment($assignment);

        return redirect()->back()->with('success', 'Reminder sent.');
    }

    public function waiveAssignment(Request $request, HrCourseAssignment $assignment)
    {
        $user = $request->user();
        abort_unless($this->canManage($user) || $this->canRecord($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $assignment->tenant_id);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->trainingService->waiveAssignment($assignment, $reason);

        return redirect()->back()->with('success', 'Assignment waived.');
    }

    /* ================================================================== */
    /*  Fees (expense claim)                                               */
    /* ================================================================== */

    public function claimFee(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

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
    /*  Export                                                             */
    /* ================================================================== */

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $this->authorizeView($user);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $type = $request->query('type', 'catalog');

        [$filename, $headers, $rows] = match ($type) {
            'assignments' => $this->assignmentsExport($tenantId),
            'enrolments' => $this->enrolmentsExport($tenantId),
            default => $this->catalogExport($tenantId),
        };

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function catalogExport(int $tenantId): array
    {
        $metrics = $this->trainingService->courseMetrics($tenantId);
        $rows = HrCourse::forTenant($tenantId)->orderBy('title')->get()->map(fn (HrCourse $c) => [
            $c->title, $c->code, $c->category, $c->delivery_method, (float) $c->duration_hours,
            $c->provider, $c->cost, $c->is_mandatory ? 'Yes' : 'No',
            $metrics[$c->id]['enrol'] ?? 0, ($metrics[$c->id]['completion'] ?? 0).'%',
            $c->is_active ? 'Active' : 'Inactive',
        ])->all();

        return ['training-catalog.csv', ['Title', 'Code', 'Category', 'Delivery', 'Hours', 'Provider', 'Fee', 'Mandatory', 'Enrolled', 'Completion', 'Status'], $rows];
    }

    private function assignmentsExport(int $tenantId): array
    {
        $rows = HrCourseAssignment::forTenant($tenantId)
            ->with(['user:id,name', 'course:id,title'])
            ->orderByDesc('assigned_at')->get()
            ->map(fn (HrCourseAssignment $a) => [
                $a->user?->name, $a->course?->title, $a->source,
                $a->due_at?->toDateString(), $a->effectiveStatus(), $a->score,
            ])->all();

        return ['training-assignments.csv', ['Person', 'Course', 'Source', 'Due', 'Status', 'Score'], $rows];
    }

    private function enrolmentsExport(int $tenantId): array
    {
        $rows = HrCourseEnrollment::forTenant($tenantId)
            ->with(['user:id,name', 'course:id,title'])
            ->orderByDesc('enrolled_at')->get()
            ->map(fn (HrCourseEnrollment $e) => [
                $e->user?->name, $e->course?->title, $e->status,
                $e->enrolled_at?->toDateString(), $e->completed_at?->toDateString(), $e->score,
            ])->all();

        return ['training-enrolments.csv', ['Person', 'Course', 'Status', 'Enrolled', 'Completed', 'Score'], $rows];
    }

    /* ================================================================== */
    /*  Certificate (existing)                                             */
    /* ================================================================== */

    public function downloadCertificate(Request $request, HrCourseEnrollment $enrollment)
    {
        $user = $request->user();
        $this->authorizeView($user);
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
    /*  Helpers                                                            */
    /* ================================================================== */

    private function wizardLookups(int $tenantId, array $staffUserIds): array
    {
        $staff = User::whereIn('id', $staffUserIds ?: [0])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        if ($staff->isEmpty()) {
            // Users are tenanted by organization_id (no users.tenant_id column).
            $staff = User::where('organization_id', $tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values();
        }

        $roles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->whereNotNull('position_role')
            ->distinct()
            ->orderBy('position_role')
            ->pluck('position_role')
            ->filter()
            ->map(fn ($r) => ['value' => $r, 'label' => Str::headline($r)])
            ->values();

        $requirements = HrComplianceRequirement::query()
            ->where('tenant_id', $tenantId)
            ->where('check_type', 'training_course')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])
            ->values();

        return [
            'staff' => $staff,
            'sites' => Site::orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])->values(),
            'roles' => $roles,
            'requirements' => $requirements,
            'categories' => HrCourse::forTenant($tenantId)->whereNotNull('category')->distinct()->pluck('category')->filter()->values(),
        ];
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
}
