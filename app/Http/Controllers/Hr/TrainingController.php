<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreTrainingCourseRequest;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Domain\Hr\Services\CertificateService;
use App\Domain\Hr\Services\TrainingService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TrainingController extends Controller
{
    public function __construct(
        protected TrainingService $trainingService,
        protected CertificateService $certificateService,
    ) {}

    /**
     * Course catalog listing.
     */
    public function catalog(Request $request)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.training.view') || $user->canDo('training.viewAny'));
        abort_unless($canView, 403);

        $courses = HrCourse::query()
            ->forTenant($user->tenant_id)
            ->withCount(['enrollments', 'sessions' => fn ($q) => $q->upcoming()])
            ->when($request->query('category'), fn ($q, $cat) => $q->where('category', $cat))
            ->when($request->query('delivery_method'), fn ($q, $dm) => $q->where('delivery_method', $dm))
            ->when($request->boolean('mandatory_only'), fn ($q) => $q->mandatory())
            ->when($request->query('search'), fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString();

        $categories = HrCourse::forTenant($user->tenant_id)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        $summary = $this->trainingService->getTrainingSummary($user->tenant_id);

        return Inertia::render('hr/training/catalog', [
            'courses' => $courses,
            'categories' => $categories,
            'summary' => $summary,
            'filters' => [
                'category' => $request->query('category'),
                'delivery_method' => $request->query('delivery_method'),
                'mandatory_only' => $request->boolean('mandatory_only'),
                'search' => $request->query('search'),
            ],
            'deliveryMethods' => [
                ['value' => 'online', 'label' => 'Online'],
                ['value' => 'in_person', 'label' => 'In Person'],
                ['value' => 'blended', 'label' => 'Blended'],
                ['value' => 'self_paced', 'label' => 'Self-Paced'],
            ],
            'can' => [
                'manage' => $user->canDo('hr.training.manage') || $user->canDo('training.manageCourses'),
            ],
        ]);
    }

    /**
     * Show a single course with sessions and enrollments.
     */
    public function showCourse(Request $request, HrCourse $course)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.training.view') || $user->canDo('training.viewAny'));
        abort_unless($canView, 403);

        $course->load([
            'sessions' => fn ($q) => $q->orderBy('session_date'),
            'enrollments' => fn ($q) => $q->with('user:id,name')->orderByDesc('enrolled_at'),
        ]);

        $users = User::where('tenant_id', $user->tenant_id)->get(['id', 'name']);

        return Inertia::render('hr/training/course', [
            'course' => $course,
            'users' => $users,
            'can' => [
                'manage' => $user->canDo('hr.training.manage') || $user->canDo('training.manageCourses'),
                'enroll' => $user->canDo('hr.training.manage') || $user->canDo('training.enrol'),
            ],
        ]);
    }

    /**
     * Store a new course.
     */
    public function storeCourse(StoreTrainingCourseRequest $request)
    {
        $user = $request->user();

        $data = $request->validated();

        $this->trainingService->createCourse([
            'tenant_id' => $user->tenant_id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Course created.');
    }

    /**
     * Enroll a user in a course.
     */
    public function enroll(Request $request)
    {
        $user = $request->user();
        $canEnroll = $user && (
            $user->canDo('hr.training.manage')
            || $user->canDo('training.manageCourses')
            || $user->canDo('training.enrol')
        );
        abort_unless($canEnroll, 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'course_id' => ['required', 'integer', 'exists:hr_courses,id'],
            'session_id' => ['nullable', 'integer', 'exists:hr_course_sessions,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->trainingService->enroll(
            $user->tenant_id,
            $data['user_id'],
            $data['course_id'],
            $data['session_id'] ?? null,
            $data['notes'] ?? null,
        );

        return redirect()->back()->with('success', 'Employee enrolled in course.');
    }

    /**
     * Mark an enrollment as completed.
     */
    public function completeEnrollment(Request $request, HrCourseEnrollment $enrollment)
    {
        $user = $request->user();
        $canComplete = $user && (
            $user->canDo('hr.training.manage')
            || $user->canDo('training.manageCourses')
            || $user->canDo('training.record')
        );
        abort_unless($canComplete, 403);

        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'certificate_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->trainingService->completeEnrollment($enrollment, $data);

        return redirect()->back()->with('success', 'Enrollment marked as completed.');
    }

    /**
     * Generate and download a training certificate for a completed enrollment.
     */
    public function downloadCertificate(Request $request, HrCourseEnrollment $enrollment)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.training.view') || $user->canDo('training.viewAny'));
        abort_unless($canView, 403);
        abort_unless($enrollment->status === 'completed', 404, 'Certificate is only available for completed enrollments.');

        // Generate if not already generated
        $path = $enrollment->certificate_path;
        if (! $path || ! Storage::disk('private')->exists($path)) {
            $path = $this->certificateService->generateCertificate($enrollment);
        }

        $enrollment->loadMissing('course');
        $filename = 'certificate_' . \Illuminate\Support\Str::slug($enrollment->course?->title ?? 'course') . '.html';

        return Storage::disk('private')->download($path, $filename, [
            'Content-Type' => 'text/html',
        ]);
    }
}
