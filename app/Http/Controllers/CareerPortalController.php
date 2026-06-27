<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrApplication;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Legacy public careers surface. The posting-backed job-detail page has been
 * retired with the HrJobPosting subsystem; only the candidate application-status
 * tracker (`/careers/application/{token}`) still routes here, now sourced from
 * requisitions. The listing and apply/submit flows live on the requisition-backed
 * {@see \App\Http\Controllers\Careers\CareerPortalController}.
 */
class CareerPortalController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Application Status — candidate tracking                            */
    /* ------------------------------------------------------------------ */

    public function applicationStatus(string $token)
    {
        $application = HrApplication::where('candidate_tracking_token', $token)
            ->with([
                'candidate:id,first_name,last_name,status',
                'requisition:id,title',
                'targetSite:id,name',
            ])
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
                // Sourced from the requisition (the live recruitment flow).
                'posting' => $application->requisition ? [
                    'title' => $application->requisition->title,
                    'department' => null,
                    'location' => $application->targetSite?->name,
                ] : null,
            ],
        ]);
    }
}
