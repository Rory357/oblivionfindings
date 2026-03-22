<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobPosting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CareerPortalController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — public careers page                                        */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $department = $request->query('department');
        $location = $request->query('location');

        $postings = HrJobPosting::open()
            ->when($department, fn ($q) => $q->where('department', $department))
            ->when($location, fn ($q) => $q->where('location', $location))
            ->orderByDesc('published_at')
            ->get()
            ->map(fn ($posting) => [
                'id' => $posting->id,
                'title' => $posting->title,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'salary_range' => $posting->salary_range,
                'published_at' => $posting->published_at?->toDateString(),
                'closes_at' => $posting->closes_at?->toDateString(),
            ]);

        $departments = HrJobPosting::open()
            ->whereNotNull('department')
            ->distinct()
            ->pluck('department')
            ->sort()
            ->values();

        $locations = HrJobPosting::open()
            ->whereNotNull('location')
            ->distinct()
            ->pluck('location')
            ->sort()
            ->values();

        return Inertia::render('careers/index', [
            'postings' => $postings,
            'departments' => $departments,
            'locations' => $locations,
            'filters' => [
                'department' => $department,
                'location' => $location,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — public job detail                                           */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrJobPosting $posting)
    {
        abort_unless($posting->status === 'published', 404);

        return Inertia::render('careers/show', [
            'posting' => [
                'id' => $posting->id,
                'title' => $posting->title,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
                'description' => $posting->description,
                'requirements' => $posting->requirements,
                'salary_range' => $posting->salary_range,
                'published_at' => $posting->published_at?->toDateString(),
                'closes_at' => $posting->closes_at?->toDateString(),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Apply — application form                                           */
    /* ------------------------------------------------------------------ */

    public function apply(Request $request, HrJobPosting $posting)
    {
        abort_unless($posting->status === 'published', 404);

        return Inertia::render('careers/apply', [
            'posting' => [
                'id' => $posting->id,
                'title' => $posting->title,
                'department' => $posting->department,
                'location' => $posting->location,
                'employment_type' => $posting->employment_type,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Application                                                  */
    /* ------------------------------------------------------------------ */

    public function storeApplication(Request $request, HrJobPosting $posting)
    {
        abort_unless($posting->status === 'published', 404);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        // Create or find candidate
        $candidate = HrCandidate::firstOrCreate(
            [
                'tenant_id' => $posting->tenant_id,
                'personal_email' => $validated['email'],
            ],
            [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'personal_phone' => $validated['phone'] ?? null,
                'source' => 'website',
                'status' => 'website_submission',
                'privacy_consent_given_at' => now(),
                'privacy_consent_ip' => $request->ip(),
            ]
        );

        // Handle CV upload
        $cvPath = null;
        $cvName = null;
        if ($request->hasFile('cv')) {
            $cvPath = $request->file('cv')->store("candidates/{$candidate->id}/cv", 'private');
            $cvName = $request->file('cv')->getClientOriginalName();
        }

        // Create application
        HrApplication::create([
            'tenant_id' => $posting->tenant_id,
            'candidate_id' => $candidate->id,
            'position_title' => $posting->title,
            'position_role' => $posting->department ?? '',
            'cover_letter' => $validated['cover_letter'] ?? null,
            'cv_storage_path' => $cvPath,
            'cv_original_name' => $cvName,
            'status' => 'active',
        ]);

        // Increment applications count
        $posting->increment('applications_count');

        return redirect("/careers/{$posting->id}")->with('success', 'Your application has been submitted. Thank you!');
    }
}
