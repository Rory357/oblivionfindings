<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CandidateController extends Controller
{
    public function __construct(
        private readonly RecruitmentService $recruitmentService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Create / Store                                                     */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $sites = Site::orderBy('name')->get(['id', 'name']);

        $roles = collect(['support_worker', 'team_lead', 'coordinator', 'provider_manager', 'admin'])
            ->map(fn ($r) => ['value' => $r, 'label' => str($r)->replace('_', ' ')->title()->toString()])
            ->values()
            ->toArray();

        return Inertia::render('hr/candidates/create', [
            'sites' => $sites,
            'roles' => $roles,
            'sources' => ['direct', 'referral', 'job_board', 'agency', 'website', 'other'],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $validated = $request->validate([
            'first_name'     => ['required', 'string', 'max:255'],
            'last_name'      => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'personal_email' => ['required', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:50'],
            'source'         => ['nullable', 'string', 'max:100'],
            'source_detail'  => ['nullable', 'string', 'max:255'],
            'notes'          => ['nullable', 'string', 'max:5000'],
            'tags'           => ['nullable', 'array'],
            'tags.*'         => ['string', 'max:100'],

            // Optional initial application fields
            'position_title'  => ['nullable', 'string', 'max:255'],
            'position_role'   => ['nullable', 'string', 'max:100'],
            'target_site_id'  => ['nullable', 'integer', 'exists:sites,id'],
            'cover_letter'    => ['nullable', 'string', 'max:10000'],
            'cv'              => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        $candidate = $this->recruitmentService->createCandidate(
            $validated,
            $user->tenant_id,
            $user->id,
        );

        // Create initial application if position details provided
        if (! empty($validated['position_title'])) {
            $applicationData = [
                'position_title' => $validated['position_title'],
                'position_role'  => $validated['position_role'] ?? null,
                'target_site_id' => $validated['target_site_id'] ?? null,
                'cover_letter'   => $validated['cover_letter'] ?? null,
            ];

            if ($request->hasFile('cv')) {
                $cvPath = $request->file('cv')->store("candidates/{$candidate->id}/cv", 'private');
                $applicationData['cv_storage_path'] = $cvPath;
                $applicationData['cv_original_name'] = $request->file('cv')->getClientOriginalName();
            }

            $this->recruitmentService->createApplication($candidate, $applicationData);
        }

        return redirect()->back()->with('success', 'Candidate created successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show                                                               */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);


        $candidate->load([
            'applications.targetSite:id,name',
            'applications.interviews',
            'applications.referenceChecks',
            'applications.offer',
            'creator:id,name',
        ]);

        return Inertia::render('hr/candidates/show', [
            'candidate' => $candidate,
            'stages' => RecruitmentService::STAGES,
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update                                                             */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $validated = $request->validate([
            'first_name'     => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'      => ['sometimes', 'required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'personal_email' => ['sometimes', 'required', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:50'],
            'source'         => ['nullable', 'string', 'max:100'],
            'source_detail'  => ['nullable', 'string', 'max:255'],
            'notes'          => ['nullable', 'string', 'max:5000'],
            'tags'           => ['nullable', 'array'],
            'tags.*'         => ['string', 'max:100'],
        ]);

        $validated['updated_by'] = $user->id;
        $candidate->update($validated);

        return redirect()->back()->with('success', 'Candidate updated successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Advance Stage                                                      */
    /* ------------------------------------------------------------------ */

    public function advance(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $validated = $request->validate([
            'target_stage' => ['nullable', 'string', Rule::in(RecruitmentService::STAGES)],
        ]);

        try {
            $this->recruitmentService->advanceStage(
                $candidate,
                $validated['target_stage'] ?? null,
                $user->id,
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Candidate advanced to next stage.');
    }

    /* ------------------------------------------------------------------ */
    /*  Reject Application                                                 */
    /* ------------------------------------------------------------------ */

    public function rejectApplication(Request $request, HrApplication $application)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $application->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'] ?? null,
            'rejected_at' => now(),
            'rejected_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Application rejected.');
    }

    /* ------------------------------------------------------------------ */
    /*  Interviews                                                         */
    /* ------------------------------------------------------------------ */

    public function storeInterview(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $validated = $request->validate([
            'application_id'   => ['required', 'integer', 'exists:hr_applications,id'],
            'scheduled_at'     => ['required', 'date', 'after_or_equal:today'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'location'         => ['nullable', 'string', 'max:255'],
            'interview_type'   => ['required', 'string', Rule::in(['phone', 'video', 'in_person', 'panel'])],
            'interviewers'     => ['nullable', 'array'],
            'interviewers.*'   => ['integer', 'exists:users,id'],
            'notes'            => ['nullable', 'string', 'max:5000'],
        ]);

        // Verify the application belongs to this candidate
        $application = HrApplication::where('id', $validated['application_id'])
            ->where('candidate_id', $candidate->id)
            ->firstOrFail();

        HrInterview::create([
            'application_id'   => $application->id,
            'scheduled_at'     => $validated['scheduled_at'],
            'duration_minutes' => $validated['duration_minutes'],
            'location'         => $validated['location'] ?? null,
            'interview_type'   => $validated['interview_type'],
            'interviewers'     => $validated['interviewers'] ?? [],
            'status'           => 'scheduled',
            'notes'            => $validated['notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Interview scheduled successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  References                                                         */
    /* ------------------------------------------------------------------ */

    public function storeReference(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $validated = $request->validate([
            'application_id'      => ['required', 'integer', 'exists:hr_applications,id'],
            'referee_name'        => ['required', 'string', 'max:255'],
            'referee_email'       => ['nullable', 'email', 'max:255'],
            'referee_phone'       => ['nullable', 'string', 'max:50'],
            'referee_relationship' => ['required', 'string', 'max:255'],
        ]);

        // Verify the application belongs to this candidate
        $application = HrApplication::where('id', $validated['application_id'])
            ->where('candidate_id', $candidate->id)
            ->firstOrFail();

        HrReferenceCheck::create([
            'application_id'       => $application->id,
            'referee_name'         => $validated['referee_name'],
            'referee_email'        => $validated['referee_email'] ?? null,
            'referee_phone'        => $validated['referee_phone'] ?? null,
            'referee_relationship' => $validated['referee_relationship'],
            'status'               => 'pending',
            'requested_at'         => now(),
        ]);

        return redirect()->back()->with('success', 'Reference check request created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Offers                                                             */
    /* ------------------------------------------------------------------ */

    public function createOffer(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $candidate->load('applications.targetSite:id,name');

        $sites = Site::orderBy('name')->get(['id', 'name']);

        return Inertia::render('hr/candidates/create-offer', [
            'candidate' => $candidate,
            'sites' => $sites,
        ]);
    }

    public function storeOffer(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $validated = $request->validate([
            'application_id'     => ['required', 'integer', 'exists:hr_applications,id'],
            'position_title'     => ['required', 'string', 'max:255'],
            'position_role'      => ['nullable', 'string', 'max:100'],
            'proposed_start_date' => ['required', 'date', 'after_or_equal:today'],
            'employment_type'    => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'hours_per_week'     => ['required', 'numeric', 'min:1', 'max:60'],
            'hourly_rate'        => ['nullable', 'numeric', 'min:0'],
            'annual_salary'      => ['nullable', 'numeric', 'min:0'],
            'primary_site_id'    => ['required', 'integer', 'exists:sites,id'],
            'conditions'         => ['nullable', 'string', 'max:5000'],
        ]);

        // Verify the application belongs to this candidate
        $application = HrApplication::where('id', $validated['application_id'])
            ->where('candidate_id', $candidate->id)
            ->firstOrFail();

        // Prevent duplicate offers for the same application
        if ($application->offer()->exists()) {
            return redirect()->back()->with('error', 'An offer already exists for this application.');
        }

        HrOffer::create([
            'application_id'      => $application->id,
            'position_title'      => $validated['position_title'],
            'position_role'       => $validated['position_role'] ?? null,
            'proposed_start_date' => $validated['proposed_start_date'],
            'employment_type'     => $validated['employment_type'],
            'hours_per_week'      => $validated['hours_per_week'],
            'hourly_rate'         => $validated['hourly_rate'] ?? null,
            'annual_salary'       => $validated['annual_salary'] ?? null,
            'primary_site_id'     => $validated['primary_site_id'],
            'conditions'          => $validated['conditions'] ?? null,
            'approval_status'     => 'draft',
            'created_by'          => $user->id,
        ]);

        return redirect()->back()->with('success', 'Offer created successfully.');
    }

    public function sendOffer(Request $request, HrCandidate $candidate, HrOffer $offer)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        // Verify offer belongs to this candidate via application
        $application = $offer->application;
        abort_unless($application && $application->candidate_id === $candidate->id, 404);

        if ($offer->approval_status !== 'approved') {
            return redirect()->back()->with('error', 'Offer must be approved before sending.');
        }

        if ($offer->sent_at) {
            return redirect()->back()->with('error', 'Offer has already been sent.');
        }

        $offer->update([
            'sent_at' => now(),
            'updated_by' => $user->id,
        ]);

        // TODO: Dispatch notification/email to candidate with offer details

        return redirect()->back()->with('success', 'Offer sent to candidate.');
    }

    /* ------------------------------------------------------------------ */
    /*  Convert to Employee                                                */
    /* ------------------------------------------------------------------ */

    public function convertToEmployee(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);


        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:hr_offers,id'],
        ]);

        $offer = HrOffer::findOrFail($validated['offer_id']);

        // Verify offer belongs to this candidate
        $application = $offer->application;
        abort_unless($application && $application->candidate_id === $candidate->id, 404);

        if ($offer->response !== 'accepted') {
            return redirect()->back()->with('error', 'Cannot convert: offer has not been accepted.');
        }

        try {
            $profile = $this->recruitmentService->convertToEmployee($candidate, $offer, $user->id);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Employee profile created (#{$profile->id}).");
    }
}
