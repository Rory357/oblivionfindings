<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Models\HrPosition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $tenantId = $user->tenant_id;
        $status = $request->query('status');

        $postings = HrJobPosting::forTenant($tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('position:id,title,code')
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $postings->through(fn ($posting) => [
            'id' => $posting->id,
            'title' => $posting->title,
            'department' => $posting->department,
            'location' => $posting->location,
            'employment_type' => $posting->employment_type,
            'status' => $posting->status,
            'published_at' => $posting->published_at?->toDateString(),
            'closes_at' => $posting->closes_at?->toDateString(),
            'applications_count' => $posting->applications_count,
            'position' => $posting->position ? ['id' => $posting->position->id, 'title' => $posting->position->title] : null,
            'created_by' => $posting->creator?->name,
            'created_at' => $posting->created_at?->toDateString(),
        ]);

        return Inertia::render('hr/job-postings/index', [
            'postings' => $postings,
            'filters' => [
                'status' => $status,
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

        return Inertia::render('hr/job-postings/create', [
            'positions' => $positions,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — save posting                                               */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'position_id' => ['nullable', 'integer', 'exists:hr_positions,id'],
            'department' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'description' => ['required', 'string', 'max:50000'],
            'requirements' => ['nullable', 'string', 'max:50000'],
            'salary_range_min' => ['nullable', 'numeric', 'min:0'],
            'salary_range_max' => ['nullable', 'numeric', 'min:0'],
            'show_salary' => ['boolean'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $validated['tenant_id'] = $user->tenant_id;
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

        $posting->load('position:id,title,code', 'creator:id,name');

        return Inertia::render('hr/job-postings/show', [
            'posting' => [
                'id' => $posting->id,
                'title' => $posting->title,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'description' => $posting->description,
                'requirements' => $posting->requirements,
                'salary_range_min' => $posting->salary_range_min,
                'salary_range_max' => $posting->salary_range_max,
                'show_salary' => $posting->show_salary,
                'salary_range' => $posting->salary_range,
                'status' => $posting->status,
                'published_at' => $posting->published_at?->toDateString(),
                'closes_at' => $posting->closes_at?->toDateString(),
                'applications_count' => $posting->applications_count,
                'position' => $posting->position ? ['id' => $posting->position->id, 'title' => $posting->position->title] : null,
                'created_by' => $posting->creator?->name,
                'created_at' => $posting->created_at?->toDateString(),
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

        return Inertia::render('hr/job-postings/create', [
            'posting' => $posting,
            'positions' => $positions,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update                                                             */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrJobPosting $posting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'position_id' => ['nullable', 'integer', 'exists:hr_positions,id'],
            'department' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'description' => ['required', 'string', 'max:50000'],
            'requirements' => ['nullable', 'string', 'max:50000'],
            'salary_range_min' => ['nullable', 'numeric', 'min:0'],
            'salary_range_max' => ['nullable', 'numeric', 'min:0'],
            'show_salary' => ['boolean'],
            'closes_at' => ['nullable', 'date'],
        ]);

        $posting->update($validated);

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

        $posting->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Job posting published.');
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

        $posting->update([
            'status' => 'closed',
        ]);

        return redirect()->back()->with('success', 'Job posting closed.');
    }
}
