<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreJobPostingRequest;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Notifications\JobPostingApprovalRequestNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JobPostingController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — list all job postings                                      */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = $user->tenant_id ?? 1;
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $query = HrJobPosting::forTenant($tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('title', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            }))
            ->with('position:id,title,code')
            ->with('creator:id,name')
            ->with('hiringManager:id,name')
            ->orderByDesc('created_at');

        $postings = $query->paginate(20)->withQueryString();

        $postings->through(fn ($posting) => [
            'id' => $posting->id,
            'title' => $posting->title,
            'slug' => $posting->slug,
            'department' => $posting->department,
            'location' => $posting->location,
            'employment_type' => $posting->employment_type,
            'is_remote' => $posting->is_remote,
            'is_internal' => $posting->is_internal,
            'status' => $posting->status,
            'published_at' => $posting->published_at?->toDateString(),
            'closes_at' => $posting->closes_at?->toDateString(),
            'applications_count' => $posting->applications_count,
            'views_count' => $posting->views_count,
            'position' => $posting->position ? ['id' => $posting->position->id, 'title' => $posting->position->title] : null,
            'hiring_manager' => $posting->hiringManager?->name,
            'created_by' => $posting->creator?->name,
            'created_at' => $posting->created_at?->toDateString(),
        ]);

        // Summary stats
        $statsQuery = HrJobPosting::forTenant($tenantId);
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'published' => (clone $statsQuery)->where('status', 'published')->count(),
            'draft' => (clone $statsQuery)->where('status', 'draft')->count(),
            'pending_approval' => (clone $statsQuery)->where('status', 'pending_approval')->count(),
            'closed' => (clone $statsQuery)->where('status', 'closed')->count(),
        ];

        return Inertia::render('hr/job-postings/index', [
            'postings' => $postings,
            'stats' => $stats,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — form                                                      */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $positions = HrPosition::forTenant($user->tenant_id)
            ->active()
            ->orderBy('title')
            ->get(['id', 'title', 'department']);

        $users = User::orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/job-postings/create', [
            'positions' => $positions,
            'users' => $users,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — save posting                                               */
    /* ------------------------------------------------------------------ */

    public function store(StoreJobPostingRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $validated['tenant_id'] = $user->tenant_id ?? 1;
        $validated['created_by'] = $user->id;
        $validated['status'] = 'draft';

        HrJobPosting::create($validated);

        return redirect('/hr/job-postings')->with('success', 'Job posting created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — detail view                                                 */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $posting->load('position:id,title,code', 'creator:id,name', 'hiringManager:id,name', 'approver:id,name');

        // Recent applications
        $recentApplications = HrApplication::where('job_posting_id', $posting->id)
            ->with('candidate:id,first_name,last_name,personal_email,status')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($app) => [
                'id' => $app->id,
                'candidate_name' => trim(($app->candidate?->first_name ?? '') . ' ' . ($app->candidate?->last_name ?? '')),
                'candidate_email' => $app->candidate?->personal_email,
                'candidate_stage' => $app->candidate?->status,
                'applied_at' => $app->created_at?->toDateString(),
                'status' => $app->status,
            ]);

        // Analytics
        $totalApplications = HrApplication::where('job_posting_id', $posting->id)->count();
        $conversionRate = $posting->views_count > 0
            ? round(($totalApplications / $posting->views_count) * 100, 1)
            : 0;

        $daysPublished = $posting->published_at
            ? (int) $posting->published_at->diffInDays(now())
            : 0;

        return Inertia::render('hr/job-postings/show', [
            'posting' => [
                'id' => $posting->id,
                'title' => $posting->title,
                'slug' => $posting->slug,
                'summary' => $posting->summary,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'is_remote' => $posting->is_remote,
                'is_internal' => $posting->is_internal,
                'description' => $posting->description,
                'requirements' => $posting->requirements,
                'responsibilities' => $posting->responsibilities,
                'salary_range_min' => $posting->salary_range_min,
                'salary_range_max' => $posting->salary_range_max,
                'show_salary' => $posting->show_salary,
                'salary_range' => $posting->salary_range,
                'status' => $posting->status,
                'requires_approval' => $posting->requires_approval,
                'published_at' => $posting->published_at?->toDateString(),
                'closes_at' => $posting->closes_at?->toDateString(),
                'approved_at' => $posting->approved_at?->toDateString(),
                'applications_count' => $posting->applications_count,
                'views_count' => $posting->views_count,
                'screening_questions' => $posting->screening_questions ?? [],
                'notification_emails' => $posting->notification_emails ?? [],
                'position' => $posting->position ? ['id' => $posting->position->id, 'title' => $posting->position->title] : null,
                'hiring_manager' => $posting->hiringManager ? ['id' => $posting->hiringManager->id, 'name' => $posting->hiringManager->name] : null,
                'approved_by' => $posting->approver?->name,
                'created_by' => $posting->creator?->name,
                'created_at' => $posting->created_at?->toDateString(),
            ],
            'recentApplications' => $recentApplications,
            'analytics' => [
                'views' => $posting->views_count,
                'applications' => $totalApplications,
                'conversion_rate' => $conversionRate,
                'days_published' => $daysPublished,
            ],
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Edit — edit form                                                   */
    /* ------------------------------------------------------------------ */

    public function edit(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $positions = HrPosition::forTenant($user->tenant_id)
            ->active()
            ->orderBy('title')
            ->get(['id', 'title', 'department']);

        $users = User::orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/job-postings/create', [
            'posting' => $posting,
            'positions' => $positions,
            'users' => $users,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update                                                             */
    /* ------------------------------------------------------------------ */

    public function update(StoreJobPostingRequest $request, HrJobPosting $posting)
    {
        $posting->update($request->validated());

        return redirect()->back()->with('success', 'Job posting updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Publish                                                            */
    /* ------------------------------------------------------------------ */

    public function publish(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        if ($posting->status === 'published') {
            return redirect()->back()->with('error', 'Posting is already published.');
        }

        // If requires approval and not yet approved
        if ($posting->requires_approval && ! $posting->approved_at) {
            $posting->update(['status' => 'pending_approval']);

            // Notify managers for approval
            $managers = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->get();

            foreach ($managers as $manager) {
                $manager->notify(new JobPostingApprovalRequestNotification($posting, $user));
            }

            return redirect()->back()->with('success', 'Posting submitted for approval.');
        }

        $posting->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Job posting published.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve                                                            */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        if ($posting->status !== 'pending_approval') {
            return redirect()->back()->with('error', 'This posting is not pending approval.');
        }

        $posting->update([
            'status' => 'published',
            'published_at' => now(),
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Job posting approved and published.');
    }

    /* ------------------------------------------------------------------ */
    /*  Reject Approval                                                    */
    /* ------------------------------------------------------------------ */

    public function rejectApproval(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        if ($posting->status !== 'pending_approval') {
            return redirect()->back()->with('error', 'This posting is not pending approval.');
        }

        $posting->update(['status' => 'draft']);

        return redirect()->back()->with('success', 'Posting returned to draft.');
    }

    /* ------------------------------------------------------------------ */
    /*  Close                                                              */
    /* ------------------------------------------------------------------ */

    public function close(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        if ($posting->status === 'closed') {
            return redirect()->back()->with('error', 'Posting is already closed.');
        }

        $posting->update(['status' => 'closed']);

        return redirect()->back()->with('success', 'Job posting closed.');
    }

    /* ------------------------------------------------------------------ */
    /*  Duplicate                                                          */
    /* ------------------------------------------------------------------ */

    public function duplicate(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $newPosting = $posting->replicate([
            'status', 'published_at', 'approved_by', 'approved_at',
            'applications_count', 'views_count', 'closing_soon_notified_at', 'slug',
            'closes_at', // Reset closing date for duplicate
        ]);

        $newPosting->title = $posting->title . ' (Copy)';
        $newPosting->slug = null; // Auto-generate new slug on save
        $newPosting->status = 'draft';
        $newPosting->applications_count = 0;
        $newPosting->views_count = 0;
        $newPosting->published_at = null;
        $newPosting->approved_by = null;
        $newPosting->approved_at = null;
        $newPosting->closing_soon_notified_at = null;
        $newPosting->closes_at = null;
        $newPosting->created_by = $user->id;
        $newPosting->save();

        return redirect("/hr/job-postings/{$newPosting->id}/edit")->with('success', 'Posting duplicated as draft.');
    }

    /* ------------------------------------------------------------------ */
    /*  Preview — admin preview of public page                             */
    /* ------------------------------------------------------------------ */

    public function preview(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        return Inertia::render('hr/job-postings/preview', [
            'posting' => [
                'id' => $posting->id,
                'title' => $posting->title,
                'slug' => $posting->slug,
                'summary' => $posting->summary,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'is_remote' => $posting->is_remote,
                'is_internal' => $posting->is_internal,
                'description' => $posting->description,
                'requirements' => $posting->requirements,
                'responsibilities' => $posting->responsibilities,
                'salary_range' => $posting->salary_range,
                'show_salary' => $posting->show_salary,
                'salary_range_min' => $posting->salary_range_min,
                'salary_range_max' => $posting->salary_range_max,
                'status' => $posting->status,
                'closes_at' => $posting->closes_at?->toDateString(),
                'screening_questions' => $posting->screening_questions ?? [],
            ],
        ]);
    }
}
