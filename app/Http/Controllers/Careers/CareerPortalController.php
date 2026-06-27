<?php

namespace App\Http\Controllers\Careers;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Notifications\ApplicationConfirmationNotification;
use App\Domain\Hr\Notifications\JobApplicationReceivedNotification;
use App\Domain\Hr\Notifications\OfferResponseAckNotification;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\RecruitmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CareerPortalController extends Controller
{
    public function __construct(
        private readonly RecruitmentService $recruitmentService,
        private readonly HrWebhookService $webhookService,
    ) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $positionRole = trim((string) $request->query('position_role', ''));
        $employmentType = trim((string) $request->query('employment_type', ''));
        $siteId = $request->integer('site');

        $baseQuery = HrJobRequisition::query()
            ->with(['site:id,name'])
            ->where('status', 'published')
            ->where(function ($query) {
                $query->whereNull('closing_at')->orWhereDate('closing_at', '>=', today());
            });

        $roleOptions = (clone $baseQuery)
            ->whereNotNull('position_role')
            ->distinct('position_role')
            ->orderBy('position_role')
            ->pluck('position_role')
            ->filter()
            ->values();

        $employmentTypeOptions = (clone $baseQuery)
            ->distinct('employment_type')
            ->orderBy('employment_type')
            ->pluck('employment_type')
            ->filter()
            ->values();

        $siteOptions = (clone $baseQuery)
            ->whereNotNull('site_id')
            ->with('site:id,name')
            ->get()
            ->pluck('site')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($site) => ['id' => $site->id, 'name' => $site->name]);

        $jobs = $baseQuery
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->when($positionRole !== '', fn ($query) => $query->where('position_role', $positionRole))
            ->when($employmentType !== '', fn ($query) => $query->where('employment_type', $employmentType))
            ->when($siteId > 0, fn ($query) => $query->where('site_id', $siteId))
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HrJobRequisition $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'slug' => $job->slug,
                'position_role' => $job->position_role,
                'employment_type' => $job->employment_type,
                'summary' => $job->summary,
                'site' => $job->site ? [
                    'id' => $job->site->id,
                    'name' => $job->site->name,
                ] : null,
                'published_at' => optional($job->published_at)->toDateString(),
                'closing_at' => optional($job->closing_at)->toDateString(),
            ])
            ->values();

        return Inertia::render('careers/index', [
            'jobs' => $jobs,
            'options' => [
                'position_roles' => $roleOptions,
                'employment_types' => $employmentTypeOptions,
                'sites' => $siteOptions,
            ],
            'filters' => [
                'search' => $search,
                'position_role' => $positionRole !== '' ? $positionRole : null,
                'employment_type' => $employmentType !== '' ? $employmentType : null,
                'site' => $siteId > 0 ? $siteId : null,
            ],
        ]);
    }

    public function showApply(Request $request, HrJobRequisition $job)
    {
        if ($job->status !== 'published') {
            abort(404);
        }

        if ($job->closing_at && $job->closing_at->isPast()) {
            abort(404);
        }

        $job->load('site:id,name');

        return Inertia::render('careers/apply', [
            'job' => [
                'id' => $job->id,
                'title' => $job->title,
                'slug' => $job->slug,
                'position_role' => $job->position_role,
                'employment_type' => $job->employment_type,
                'summary' => $job->summary,
                'description' => $job->description,
                'requirements' => $job->requirements,
                'responsibilities' => $job->responsibilities,
                'screening_questions' => collect($job->screening_questions ?? [])
                    ->map(fn ($q) => is_string($q) ? trim($q) : '')
                    ->filter()
                    ->values()
                    ->all(),
                'site' => $job->site ? [
                    'id' => $job->site->id,
                    'name' => $job->site->name,
                ] : null,
                'closing_at' => optional($job->closing_at)->toDateString(),
            ],
            'trackingDefaults' => [
                'source_channel' => (string) $request->query('source', 'career_page'),
            ],
        ]);
    }

    public function submitApplication(Request $request, HrJobRequisition $job)
    {
        if ($job->status !== 'published') {
            abort(404);
        }

        if ($job->closing_at && $job->closing_at->isPast()) {
            return redirect()->back()->withErrors(['application' => 'This job is no longer accepting applications.']);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'personal_email' => ['required', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:50'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'privacy_consent' => ['accepted'],
            'source_channel' => ['nullable', 'string', Rule::in(['career_page', 'linkedin', 'seek', 'indeed', 'referral', 'agency', 'social', 'other'])],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'screening_answers' => ['nullable', 'array', 'max:50'],
            'screening_answers.*.question' => ['required_with:screening_answers', 'string', 'max:1000'],
            'screening_answers.*.answer' => ['nullable', 'string', 'max:5000'],
        ]);

        // Keep only configured questions, in the requisition's order — never trust
        // the client to define which questions were asked.
        $configuredQuestions = collect($job->screening_questions ?? [])
            ->map(fn ($q) => is_string($q) ? trim($q) : '')
            ->filter();
        $submittedAnswers = collect($validated['screening_answers'] ?? [])
            ->mapWithKeys(fn ($row) => [trim((string) ($row['question'] ?? '')) => trim((string) ($row['answer'] ?? ''))]);
        $screeningAnswers = $configuredQuestions
            ->map(fn ($q) => ['question' => $q, 'answer' => $submittedAnswers->get($q, '')])
            ->values()
            ->all();

        $sourceChannel = $validated['source_channel'] ?? 'career_page';
        $sourceReference = trim((string) ($validated['source_reference'] ?? ''));
        $sourceDetail = "job:{$job->slug}";
        if ($sourceReference !== '') {
            $sourceDetail .= "|ref:{$sourceReference}";
        }

        try {
            $candidate = $this->recruitmentService->createCandidate([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'preferred_name' => $validated['preferred_name'] ?? null,
                'personal_email' => $validated['personal_email'],
                'personal_phone' => $validated['personal_phone'] ?? null,
                'source' => $sourceChannel,
                'source_detail' => $sourceDetail,
                'privacy_consent_given_at' => now(),
                'privacy_consent_ip' => $request->ip(),
            ], $job->tenant_id, null);

            $applicationData = [
                'position_title' => $job->title,
                'position_role' => $job->position_role,
                'target_site_id' => $job->site_id,
                'cover_letter' => $validated['cover_letter'] ?? null,
                'requisition_id' => $job->id,
                'interview_kit_id' => $job->default_interview_kit_id,
                'screening_answers' => $screeningAnswers !== [] ? $screeningAnswers : null,
                // Lets the candidate track their application at /careers/application/{token}
                // (the confirmation email links here).
                'candidate_tracking_token' => Str::random(48),
            ];

            if ($request->hasFile('cv')) {
                $cvPath = $request->file('cv')->store("candidates/{$candidate->id}/cv", 'private');
                $applicationData['cv_storage_path'] = $cvPath;
                $applicationData['cv_original_name'] = $request->file('cv')->getClientOriginalName();
            }

            $application = $this->recruitmentService->createApplication($candidate, $applicationData);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return redirect()->back()->withErrors(['application' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->back()->withErrors(['application' => 'Application could not be submitted.']);
        }

        $this->dispatchApplicationNotifications($job, $candidate, $application);

        return redirect()
            ->route('careers.apply', ['job' => $job->slug])
            ->with('success', 'Thanks, your application has been received.');
    }

    /**
     * Confirm to the candidate + alert the requisition's hiring manager that an
     * application landed. Best-effort — a mail failure must not fail the apply.
     */
    private function dispatchApplicationNotifications(HrJobRequisition $job, HrCandidate $candidate, HrApplication $application): void
    {
        try {
            if ($candidate->personal_email) {
                Notification::route('mail', $candidate->personal_email)
                    ->notify(new ApplicationConfirmationNotification($job, $candidate, $application));
            }

            $manager = $job->hiringManager()->first();
            if ($manager) {
                $manager->notify(new JobApplicationReceivedNotification($job, $candidate, $application));
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function showOffer(Request $request, string $token)
    {
        $offer = HrOffer::query()
            ->with(['application.candidate:id,first_name,last_name,personal_email,personal_phone', 'primarySite:id,name'])
            ->where('candidate_portal_token', $token)
            ->whereNotNull('sent_at')
            ->first();

        if (! $offer) {
            return Inertia::render('careers/offer-response', [
                'valid' => false,
                'expired' => false,
            ]);
        }

        $expired = (bool) ($offer->portal_expires_at && $offer->portal_expires_at->isPast());

        return Inertia::render('careers/offer-response', [
            'valid' => true,
            'expired' => $expired,
            'offer' => [
                'position_title' => $offer->position_title,
                'position_role' => $offer->position_role,
                'employment_type' => $offer->employment_type,
                'proposed_start_date' => optional($offer->proposed_start_date)->toDateString(),
                'hours_per_week' => $offer->hours_per_week !== null ? (float) $offer->hours_per_week : null,
                'hourly_rate' => $offer->hourly_rate !== null ? (float) $offer->hourly_rate : null,
                'annual_salary' => $offer->annual_salary !== null ? (float) $offer->annual_salary : null,
                'conditions' => $offer->conditions,
                'response' => $offer->response,
                'response_at' => optional($offer->response_at)->toDateTimeString(),
                'site_name' => $offer->primarySite?->name,
            ],
            'candidate' => [
                'name' => trim(($offer->application?->candidate?->first_name ?? '') . ' ' . ($offer->application?->candidate?->last_name ?? '')),
                'email' => $offer->application?->candidate?->personal_email,
            ],
            'token' => $token,
        ]);
    }

    public function respondToOffer(Request $request, string $token)
    {
        $offer = HrOffer::query()
            ->with('application.candidate')
            ->where('candidate_portal_token', $token)
            ->whereNotNull('sent_at')
            ->first();

        if (! $offer) {
            return redirect()->route('careers.offer.show', ['token' => $token])->with('error', 'Offer link is invalid.');
        }

        if ($offer->portal_expires_at && $offer->portal_expires_at->isPast()) {
            return redirect()->route('careers.offer.show', ['token' => $token])->with('error', 'Offer link has expired.');
        }

        if ($offer->response !== null) {
            return redirect()->route('careers.offer.show', ['token' => $token])->with('error', 'This offer has already been responded to.');
        }

        $validated = $request->validate([
            'response' => ['required', 'string', Rule::in(['accepted', 'declined', 'withdrawn'])],
            'signature_name' => ['nullable', 'string', 'max:255'],
            'response_notes' => ['nullable', 'string', 'max:5000'],
            // boolean (not the implicit `accepted` rule) so declining/withdrawing
            // doesn't get blocked for lacking terms acceptance — the accept branch
            // below enforces terms_accepted only when actually accepting.
            'terms_accepted' => ['nullable', 'boolean'],
        ]);

        $response = $validated['response'];

        if ($response === 'accepted') {
            if (empty($validated['signature_name'])) {
                return redirect()->back()->withErrors(['signature_name' => 'Please enter your full name as a digital signature.']);
            }

            if (! $request->boolean('terms_accepted')) {
                return redirect()->back()->withErrors(['terms_accepted' => 'You must accept the terms to sign this offer.']);
            }
        }

        $offer->update([
            'response' => $response,
            'response_notes' => $validated['response_notes'] ?? null,
            'response_at' => now(),
            'signed_full_name' => $response === 'accepted' ? $validated['signature_name'] : null,
            'signed_at' => $response === 'accepted' ? now() : null,
            'signed_ip' => $response === 'accepted' ? $request->ip() : null,
        ]);

        $application = $offer->application;
        $candidate = $application?->candidate;

        if ($candidate) {
            if ($response === 'accepted') {
                $candidate->update([
                    'status' => 'offer_accepted',
                    'current_stage_entered_at' => now(),
                ]);

                $application?->update([
                    'status' => 'offered',
                ]);
            } else {
                $candidate->update([
                    'status' => $response === 'withdrawn' ? 'withdrawn' : 'rejected',
                    'current_stage_entered_at' => now(),
                ]);

                $application?->update([
                    'status' => $response === 'withdrawn' ? 'withdrawn' : 'rejected',
                    'rejection_reason' => $response === 'declined' ? ($validated['response_notes'] ?? 'Candidate declined offer') : $application->rejection_reason,
                ]);
            }

            // Acknowledge the candidate (mirrors the in-app respondOffer path).
            if (in_array($response, ['accepted', 'declined'], true) && $candidate->personal_email) {
                try {
                    Notification::route('mail', $candidate->personal_email)
                        ->notify(new OfferResponseAckNotification($offer, $candidate, $response));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        // Same domain event the in-app respondOffer path emits, so both
        // acceptance routes are observable identically by integrations.
        try {
            $this->webhookService->publish($offer->application?->tenant_id, 'recruitment.offer.responded', [
                'offer_id' => $offer->id,
                'application_id' => $application?->id,
                'candidate_id' => $candidate?->id,
                'response' => $offer->response,
                'response_at' => optional($offer->response_at)->toDateTimeString(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('careers.offer.show', ['token' => $token])
            ->with('success', 'Your response has been recorded. Thank you.');
    }
}

