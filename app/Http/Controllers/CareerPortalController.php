<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Referrals\Services\PublicProviderWebsite;
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

        // Public copy: sentence case, NZ English.
        $stageLabels = [
            'new' => 'Application received',
            'screening' => 'Under review',
            'interview_scheduled' => 'Interview arranged',
            'interview_completed' => 'Interview done',
            'reference_check' => 'Checking references',
            'offer_pending' => 'Preparing an offer',
            'offer_sent' => 'Offer sent',
            'offer_accepted' => 'Offer accepted',
            'onboarding' => 'Getting ready to start',
            'hired' => 'Welcome aboard',
            'active' => 'Application received',
            'offered' => 'Offer sent',
            'rejected' => 'Not successful this time',
            'withdrawn' => 'Application withdrawn',
        ];

        $candidateStage = $application->candidate?->status ?? $application->status ?? 'active';

        return Inertia::render('careers/application-status', [
            // The site header and footer (the public layout).
            'provider' => app(PublicProviderWebsite::class)->content(),
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
