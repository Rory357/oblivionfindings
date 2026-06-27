<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrJobPosting;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Legacy public careers surface for the older HrJobPosting system. Only the
 * job-detail page (`/careers/{slug}`) and the candidate application-status
 * tracker (`/careers/application/{token}`) still route here — the listing and
 * apply/submit flows have moved to the requisition-backed
 * {@see \App\Http\Controllers\Careers\CareerPortalController}. This whole stack
 * is slated for retirement once HrJobPosting is consolidated onto requisitions.
 */
class CareerPortalController extends Controller
{
    /** Slugs reserved for specific career portal routes. */
    private const RESERVED_SLUGS = ['application', 'offers', 'jobs'];

    /* ------------------------------------------------------------------ */
    /*  Show — public job detail                                           */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, string $slug)
    {
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
}
