<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Notifications\ApplicationConfirmationNotification;
use App\Domain\Hr\Notifications\JobApplicationReceivedNotification;
use App\Domain\Hr\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CareerPortalController extends Controller
{
    /** Slugs reserved for specific career portal routes */
    private const RESERVED_SLUGS = ['application', 'offers', 'jobs'];

    public function __construct(
        private readonly RecruitmentService $recruitmentService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — public careers page                                        */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $department = $request->query('department');
        $location = $request->query('location');
        $search = trim((string) $request->query('search', ''));
        $employmentType = $request->query('employment_type');

        $query = HrJobPosting::open()
            ->where('is_internal', false)
            ->when($department, fn ($q) => $q->where('department', $department))
            ->when($location, fn ($q) => $q->where('location', $location))
            ->when($employmentType, fn ($q) => $q->where('employment_type', $employmentType))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('title', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            }));

        $postings = $query
            ->orderByDesc('published_at')
            ->get()
            ->map(fn ($posting) => [
                'id' => $posting->id,
                'slug' => $posting->slug,
                'title' => $posting->title,
                'summary' => $posting->summary,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'is_remote' => $posting->is_remote,
                'salary_range' => $posting->salary_range,
                'published_at' => $posting->published_at?->toDateString(),
                'closes_at' => $posting->closes_at?->toDateString(),
            ]);

        $departments = HrJobPosting::open()->where('is_internal', false)
            ->whereNotNull('department')->distinct()->pluck('department')->sort()->values();

        $locations = HrJobPosting::open()->where('is_internal', false)
            ->whereNotNull('location')->distinct()->pluck('location')->sort()->values();

        return Inertia::render('careers/index', [
            'postings' => $postings,
            'departments' => $departments,
            'locations' => $locations,
            'filters' => [
                'department' => $department,
                'location' => $location,
                'search' => $search,
                'employment_type' => $employmentType,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — public job detail                                           */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, string $slug)
    {
        // Guard against reserved slugs
        abort_if(in_array($slug, self::RESERVED_SLUGS, true), 404);

        $posting = HrJobPosting::publishedBySlug($slug)->firstOrFail();

        // Increment views (atomic at DB level)
        $posting->increment('views_count');

        return Inertia::render('careers/show', [
            'posting' => [
                'id' => $posting->id,
                'slug' => $posting->slug,
                'title' => $posting->title,
                'summary' => $posting->summary,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'is_remote' => $posting->is_remote,
                'description' => $posting->description,
                'requirements' => $posting->requirements,
                'responsibilities' => $posting->responsibilities,
                'salary_range' => $posting->salary_range,
                'published_at' => $posting->published_at?->toDateString(),
                'closes_at' => $posting->closes_at?->toDateString(),
                'screening_questions' => $posting->screening_questions ?? [],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Apply — application form                                           */
    /* ------------------------------------------------------------------ */

    public function apply(Request $request, string $slug)
    {
        abort_if(in_array($slug, self::RESERVED_SLUGS, true), 404);

        $posting = HrJobPosting::publishedBySlug($slug)->firstOrFail();

        return Inertia::render('careers/apply', [
            'posting' => [
                'id' => $posting->id,
                'slug' => $posting->slug,
                'title' => $posting->title,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'is_remote' => $posting->is_remote,
                'screening_questions' => $posting->screening_questions ?? [],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Application — via RecruitmentService                         */
    /* ------------------------------------------------------------------ */

    public function storeApplication(Request $request, string $slug)
    {
        abort_if(in_array($slug, self::RESERVED_SLUGS, true), 404);

        $posting = HrJobPosting::publishedBySlug($slug)->firstOrFail();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'screening_answers' => ['nullable', 'array'],
            'privacy_consent' => ['accepted'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);

        // Handle CV upload
        $cvPath = null;
        $cvName = null;
        if ($request->hasFile('cv')) {
            $cvPath = $request->file('cv')->store('candidates/cv', 'private');
            $cvName = $request->file('cv')->getClientOriginalName();
        }

        $trackingToken = Str::random(48);

        // Create candidate + application in a single transaction
        try {
            $result = DB::transaction(function () use ($posting, $validated, $cvPath, $cvName, $trackingToken, $request) {
                // Create or find candidate
                try {
                    $candidate = $this->recruitmentService->createCandidate([
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'personal_email' => $validated['email'],
                        'personal_phone' => $validated['phone'] ?? null,
                        'source' => $validated['source'] ?? 'website',
                        'privacy_consent_given_at' => now(),
                        'privacy_consent_ip' => $request->ip(),
                    ], $posting->tenant_id);
                } catch (\InvalidArgumentException $e) {
                    // Candidate already exists — find them
                    $candidate = HrCandidate::query()
                        ->where('tenant_id', $posting->tenant_id)
                        ->where('personal_email', strtolower(trim($validated['email'])))
                        ->first();

                    if (! $candidate) {
                        throw new \RuntimeException('Unable to process application.');
                    }
                }

                // Create application
                $application = $this->recruitmentService->createApplication($candidate, [
                    'position_title' => $posting->title,
                    'position_role' => $posting->department ?? 'general',
                    'cover_letter' => $validated['cover_letter'] ?? null,
                    'cv_storage_path' => $cvPath,
                    'cv_original_name' => $cvName,
                ]);

                // Set posting-specific fields that RecruitmentService doesn't handle
                $application->update([
                    'job_posting_id' => $posting->id,
                    'screening_answers' => $validated['screening_answers'] ?? null,
                    'candidate_tracking_token' => $trackingToken,
                ]);

                // Increment applications count (inside transaction for consistency)
                $posting->increment('applications_count');

                return compact('candidate', 'application');
            });
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->back()->withErrors(['email' => 'You have already applied for this position.']);
        } catch (\RuntimeException $e) {
            return redirect()->back()->withErrors(['email' => 'Unable to process your application. Please try again.']);
        }

        // Send notifications outside transaction (non-critical, should not block submission)
        try {
            $this->dispatchNotifications($posting, $result['candidate'], $result['application']);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch job application notifications', [
                'posting_id' => $posting->id,
                'application_id' => $result['application']->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect("/careers/{$posting->slug}")->with('success', 'Your application has been submitted successfully. Check your email for confirmation.');
    }

    /* ------------------------------------------------------------------ */
    /*  Application Status — candidate tracking                            */
    /* ------------------------------------------------------------------ */

    public function applicationStatus(string $token)
    {
        $application = HrApplication::where('candidate_tracking_token', $token)
            ->with(['candidate:id,first_name,last_name,status', 'jobPosting:id,title,slug,department,location'])
            ->firstOrFail();

        $stageLabels = [
            'new' => 'Application Received',
            'screening' => 'Under Review',
            'interview_scheduled' => 'Interview Scheduled',
            'interview_completed' => 'Interview Completed',
            'reference_check' => 'Reference Check in Progress',
            'offer_pending' => 'Offer Being Prepared',
            'offer_sent' => 'Offer Sent',
            'offer_accepted' => 'Offer Accepted',
            'onboarding' => 'Onboarding',
            'hired' => 'Hired',
            'active' => 'Application Received',
            'offered' => 'Offer Extended',
            'rejected' => 'Application Unsuccessful',
            'withdrawn' => 'Application Withdrawn',
        ];

        $candidateStage = $application->candidate?->status ?? $application->status ?? 'active';

        return Inertia::render('careers/application-status', [
            'application' => [
                'position_title' => $application->position_title,
                'applied_at' => $application->created_at?->toDateString(),
                'status' => $candidateStage,
                'status_label' => $stageLabels[$candidateStage] ?? 'Processing',
                'posting' => $application->jobPosting ? [
                    'title' => $application->jobPosting->title,
                    'department' => $application->jobPosting->department,
                    'location' => $application->jobPosting->location,
                ] : null,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function dispatchNotifications(HrJobPosting $posting, HrCandidate $candidate, HrApplication $application): void
    {
        $notification = new JobApplicationReceivedNotification($posting, $candidate, $application);

        // Notify hiring manager
        if ($posting->hiring_manager_id && $posting->hiringManager) {
            $posting->hiringManager->notify($notification);
        }

        // Notify custom email addresses
        if (! empty($posting->notification_emails)) {
            foreach ($posting->notification_emails as $email) {
                Notification::route('mail', $email)->notify($notification);
            }
        }

        // Send confirmation to candidate
        Notification::route('mail', $candidate->personal_email)
            ->notify(new ApplicationConfirmationNotification($posting, $candidate, $application));
    }
}
