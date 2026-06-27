<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCandidateDocument;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrInterviewScore;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Domain\Hr\Models\HrTalentPool;
use App\Domain\Hr\Notifications\CandidateHiredNotification;
use App\Domain\Hr\Notifications\OfferResponseAckNotification;
use App\Domain\Hr\Notifications\OfferSentNotification;
use App\Domain\Hr\Notifications\RejectionNotification;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CandidateController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly RecruitmentService $recruitmentService,
        private readonly HrWebhookService $webhookService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Create / Store                                                     */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $sites = Site::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name']);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $siteRule = Rule::exists('sites', 'id');
        if ($tenantId !== null) {
            $siteRule = $siteRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }

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
            'target_site_id'  => ['nullable', 'integer', $siteRule],
            'cover_letter'    => ['nullable', 'string', 'max:10000'],
            'cv'              => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        $candidateData = Arr::only($validated, [
            'first_name',
            'last_name',
            'preferred_name',
            'personal_email',
            'personal_phone',
            'source',
            'source_detail',
            'notes',
            'tags',
        ]);

        $candidate = null;

        try {
            DB::transaction(function () use (&$candidate, $candidateData, $tenantId, $user, $validated, $request) {
                $candidate = $this->recruitmentService->createCandidate(
                    $candidateData,
                    $tenantId,
                    $user->id,
                );

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
            });
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return redirect()->back()->withErrors(['candidate' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);
        }

        if (! $candidate) {
            return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $candidate->tenant_id);

        $candidate->load([
            'applications.targetSite:id,name',
            'applications.requisition:id,title,slug',
            'applications.interviewKit:id,name,role,criteria',
            'applications.interviews.completedBy:id,name',
            'applications.interviews.scores.interviewer:id,name',
            'applications.referenceChecks',
            'applications.offer.approvedBy:id,name',
            'applications.offer.primarySite:id,name',
            'documents.uploader:id,name',
            'creator:id,name',
        ]);

        $candidateData = [
            'id' => $candidate->id,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'preferred_name' => $candidate->preferred_name,
            'personal_email' => $candidate->personal_email,
            'personal_phone' => $candidate->personal_phone,
            'source' => $candidate->source,
            'source_detail' => $candidate->source_detail,
            'notes' => $candidate->notes,
            'created_at' => optional($candidate->created_at)->toDateString(),
            'applications' => $candidate->applications->map(function (HrApplication $application) use ($candidate) {
                $candidateStage = (string) $candidate->status;
                $status = match ($candidateStage) {
                    'hired' => 'hired',
                    'rejected' => 'rejected',
                    'withdrawn' => 'withdrawn',
                    'offer_sent', 'offer_accepted' => 'offered',
                    default => 'active',
                };

                return [
                    'id' => $application->id,
                    'position_title' => $application->position_title,
                    'position_role' => $application->position_role,
                    'stage' => $candidateStage,
                    'status' => $status,
                    'job_posting' => $application->requisition ? [
                        'id' => $application->requisition->id,
                        'title' => $application->requisition->title,
                        'slug' => $application->requisition->slug,
                        'department' => $application->requisition->department,
                        'location' => null,
                    ] : null,
                    'cover_letter' => $application->cover_letter,
                    'screening_answers' => $application->screening_answers,
                    'cv_original_name' => $application->cv_original_name,
                    'interview_kit' => $application->interviewKit ? [
                        'id' => $application->interviewKit->id,
                        'name' => $application->interviewKit->name,
                        'role' => $application->interviewKit->role,
                        'criteria' => $application->interviewKit->criteria ?? [],
                    ] : null,
                    'applied_at' => optional($application->created_at)->toDateString(),
                    'target_site' => $application->targetSite ? [
                        'id' => $application->targetSite->id,
                        'name' => $application->targetSite->name,
                    ] : null,
                    'interviews' => $application->interviews->map(fn (HrInterview $interview) => [
                        'id' => $interview->id,
                        'type' => $interview->interview_type,
                        'scheduled_at' => optional($interview->scheduled_at)->toDateTimeString(),
                        'interviewer_name' => $interview->completedBy?->name,
                        'status' => $interview->status,
                        'outcome' => $interview->outcome,
                        'notes' => $interview->notes,
                        'scores' => $interview->scores->map(fn (HrInterviewScore $score) => [
                            'id' => $score->id,
                            'criteria_scores' => $score->criteria_scores ?? [],
                            'overall_score' => $score->overall_score !== null ? (float) $score->overall_score : null,
                            'recommendation' => $score->recommendation,
                            'notes' => $score->notes,
                            'submitted_at' => optional($score->submitted_at)->toDateTimeString(),
                            'interviewer_name' => $score->interviewer?->name,
                        ])->values(),
                    ])->values(),
                    'reference_checks' => $application->referenceChecks->map(fn (HrReferenceCheck $reference) => [
                        'id' => $reference->id,
                        'referee_name' => $reference->referee_name,
                        'referee_relationship' => $reference->referee_relationship,
                        'referee_phone' => $reference->referee_phone,
                        'referee_email' => $reference->referee_email,
                        'status' => $reference->status,
                        'outcome' => $reference->reference_notes,
                        'checked_at' => optional($reference->verified_at)->toDateTimeString(),
                        'notes' => $reference->reference_notes,
                    ])->values(),
                    'offer' => $application->offer ? [
                        'id' => $application->offer->id,
                        'position_title' => $application->offer->position_title,
                        'position_role' => $application->offer->position_role,
                        'employment_type' => $application->offer->employment_type,
                        'proposed_start_date' => optional($application->offer->proposed_start_date)->toDateString(),
                        'hours_per_week' => $application->offer->hours_per_week !== null ? (float) $application->offer->hours_per_week : null,
                        'hourly_rate' => $application->offer->hourly_rate !== null ? (float) $application->offer->hourly_rate : null,
                        'annual_salary' => $application->offer->annual_salary !== null ? (float) $application->offer->annual_salary : null,
                        'approval_status' => $application->offer->approval_status,
                        'approved_at' => optional($application->offer->approved_at)->toDateTimeString(),
                        'approved_by' => $application->offer->approvedBy?->name,
                        'sent_at' => optional($application->offer->sent_at)->toDateTimeString(),
                        'portal_expires_at' => optional($application->offer->portal_expires_at)->toDateTimeString(),
                        'response' => $application->offer->response,
                        'response_at' => optional($application->offer->response_at)->toDateTimeString(),
                        'response_notes' => $application->offer->response_notes,
                        'signed_full_name' => $application->offer->signed_full_name,
                        'signed_at' => optional($application->offer->signed_at)->toDateTimeString(),
                        'offer_letter_name' => $application->offer->offer_letter_name,
                        'offer_letter_id' => $application->offer->offer_letter_path ? $application->offer->id : null,
                        'portal_url' => $application->offer->candidate_portal_token
                            ? route('careers.offer.show', ['token' => $application->offer->candidate_portal_token])
                            : null,
                        'primary_site' => $application->offer->primarySite ? [
                            'id' => $application->offer->primarySite->id,
                            'name' => $application->offer->primarySite->name,
                        ] : null,
                    ] : null,
                ];
            })->values(),
        ];

        // Build activity log from interviews, offers, and status changes
        $activityLog = [];
        foreach ($candidate->applications as $app) {
            $activityLog[] = [
                'type' => 'application',
                'description' => "Applied for {$app->position_title}",
                'timestamp' => optional($app->created_at)->diffForHumans() ?? '',
            ];
            foreach ($app->interviews as $interview) {
                $activityLog[] = [
                    'type' => 'interview',
                    'description' => ucfirst($interview->interview_type) . " interview {$interview->status}",
                    'timestamp' => optional($interview->scheduled_at)->diffForHumans() ?? '',
                    'actor' => $interview->completedBy?->name,
                ];
            }
            if ($app->offer) {
                $activityLog[] = [
                    'type' => 'offer',
                    'description' => "Offer created - {$app->offer->position_title}",
                    'timestamp' => optional($app->offer->created_at)->diffForHumans() ?? '',
                ];
                if ($app->offer->response) {
                    $activityLog[] = [
                        'type' => 'offer',
                        'description' => "Offer {$app->offer->response}",
                        'timestamp' => optional($app->offer->response_at)->diffForHumans() ?? '',
                    ];
                }
            }
        }

        $documents = $candidate->documents->map(fn (HrCandidateDocument $doc) => [
            'id' => $doc->id,
            'category' => $doc->category,
            'category_label' => $doc->category_label,
            'title' => $doc->title,
            'original_name' => $doc->original_name,
            'mime_type' => $doc->mime_type,
            'formatted_size' => $doc->formatted_size,
            'uploaded_by' => $doc->uploader?->name,
            'notes' => $doc->notes,
            'expires_at' => $doc->expires_at?->toDateString(),
            'is_expired' => $doc->isExpired(),
            'created_at' => $doc->created_at?->toDateString(),
        ])->values();

        // Option data for the in-page Offer wizard (manager-only surface).
        $offerTenantId = $this->resolveHrTenantIdForUser($user);
        $offerSites = Site::query()
            ->when($offerTenantId !== null, fn ($query) => $query->where('tenant_id', $offerTenantId))
            ->orderBy('name')
            ->get(['id', 'name']);
        $offerRoles = collect(['support_worker', 'team_lead', 'coordinator', 'provider_manager', 'admin'])
            ->map(fn ($role) => ['value' => $role, 'label' => str($role)->replace('_', ' ')->title()->toString()])
            ->values()
            ->toArray();

        return Inertia::render('hr/candidates/show', [
            'candidate' => $candidateData,
            'documents' => $documents,
            'documentCategories' => HrCandidateDocument::CATEGORIES,
            'activityLog' => $activityLog,
            'totalDaysInPipeline' => $candidate->created_at ? (int) $candidate->created_at->diffInDays(now()) : 0,
            'stages' => RecruitmentService::STAGES,
            'offerSites' => $offerSites,
            'offerRoles' => $offerRoles,
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $candidate->tenant_id);


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

    public function advanceApplication(Request $request, HrApplication $application)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        $validated = $request->validate([
            'target_stage' => ['nullable', 'string', Rule::in(RecruitmentService::STAGES)],
        ]);

        try {
            $candidate = $application->candidate()->firstOrFail();
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            // Opt-in only — a respectful decline email is never sent by default.
            'send_decline_email' => ['nullable', 'boolean'],
            'decline_message' => ['nullable', 'string', 'max:2000'],
            // Keep a strong candidate warm in the talent pool instead of purging.
            'add_to_pool' => ['nullable', 'boolean'],
        ]);

        $application->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        $candidate = $application->candidate()->first();

        if ($candidate && $request->boolean('add_to_pool')) {
            $this->poolCandidate($candidate, $user->id, 'Strong but not selected', $application->requisition_id, null);
        }

        if ($candidate && $candidate->personal_email && $request->boolean('send_decline_email')) {
            try {
                Notification::route('mail', $candidate->personal_email)
                    ->notify(new RejectionNotification($candidate, $application, $validated['decline_message'] ?? null));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->back()->with('success', 'Application rejected.');
    }

    /**
     * Bulk advance/reject N selected candidates. Per-row (a prerequisite failure
     * on one candidate doesn't abort the batch) with a summary flash. Tenant-scoped
     * — foreign-tenant ids are silently excluded by the scoped query.
     */
    public function bulkAction(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['advance', 'reject'])],
            'candidate_ids' => ['required', 'array', 'min:1'],
            'candidate_ids.*' => ['integer'],
            'target_stage' => ['nullable', 'string', Rule::in(RecruitmentService::STAGES)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $candidates = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('id', $validated['candidate_ids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            try {
                if ($validated['action'] === 'advance') {
                    $this->recruitmentService->advanceStage($candidate, $validated['target_stage'] ?? null, $user->id);
                } else {
                    $candidate->update(['status' => 'rejected', 'current_stage_entered_at' => now(), 'updated_by' => $user->id]);
                    $candidate->applications()
                        ->whereNotIn('status', ['rejected', 'withdrawn', 'hired'])
                        ->update(['status' => 'rejected', 'rejection_reason' => $validated['reason'] ?? null]);
                }
                $done++;
            } catch (\Throwable $exception) {
                $skipped++;
            }
        }

        $verb = $validated['action'] === 'advance' ? 'advanced' : 'rejected';
        $message = "{$done} candidate(s) {$verb}".($skipped > 0 ? ", {$skipped} skipped" : '').'.';

        return redirect()->back()->with('success', $message);
    }

    /* ------------------------------------------------------------------ */
    /*  Talent pool                                                        */
    /* ------------------------------------------------------------------ */

    public function addToPool(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $candidate->tenant_id);

        $requisitionRule = Rule::exists('hr_job_requisitions', 'id')
            ->where(fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'requisition_id' => ['nullable', 'integer', $requisitionRule],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
        ]);

        $this->poolCandidate($candidate, $user->id, $validated['reason'] ?? 'Kept warm', $validated['requisition_id'] ?? null, $validated['tags'] ?? null);

        return redirect()->back()->with('success', "{$candidate->full_name} added to the talent pool.");
    }

    public function reactivatePool(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $candidate->tenant_id);

        $requisitionRule = Rule::exists('hr_job_requisitions', 'id')
            ->where(fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q);

        $validated = $request->validate([
            'requisition_id' => ['required', 'integer', $requisitionRule],
        ]);

        $requisition = HrJobRequisition::query()->findOrFail($validated['requisition_id']);

        $duplicate = HrApplication::query()
            ->where('candidate_id', $candidate->id)
            ->where('requisition_id', $requisition->id)
            ->whereNotIn('status', ['rejected', 'withdrawn'])
            ->exists();
        if ($duplicate) {
            return redirect()->back()->with('error', 'This candidate already has an active application for that requisition.');
        }

        try {
            $this->recruitmentService->createApplication($candidate, [
                'position_title' => $requisition->title,
                'position_role' => $requisition->position_role,
                'requisition_id' => $requisition->id,
                'target_site_id' => $requisition->site_id,
                'interview_kit_id' => $requisition->default_interview_kit_id,
            ]);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        $candidate->update(['status' => 'new', 'current_stage_entered_at' => now(), 'updated_by' => $user->id]);
        $candidate->talentPoolMembership()->delete();

        return redirect()->back()->with('success', "{$candidate->full_name} re-activated into {$requisition->title}.");
    }

    /** Idempotent pool membership write (one per candidate). */
    private function poolCandidate(HrCandidate $candidate, int $userId, string $reason, ?int $requisitionId, ?array $tags): void
    {
        HrTalentPool::query()->updateOrCreate(
            ['candidate_id' => $candidate->id],
            [
                'tenant_id' => $candidate->tenant_id,
                'requisition_id' => $requisitionId,
                'reason' => $reason,
                'pooled_by' => $userId,
                'tags' => $tags,
            ],
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Interviews                                                         */
    /* ------------------------------------------------------------------ */

    public function storeInterview(Request $request, HrApplication $application)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);


        $validated = $request->validate([
            'scheduled_at'     => ['required', 'date', 'after_or_equal:today'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'location'         => ['nullable', 'string', 'max:255'],
            'interview_type'   => ['required', 'string', Rule::in(['phone', 'video', 'in_person', 'panel'])],
            'interviewers'     => ['nullable', 'array'],
            'interviewers.*'   => ['integer', 'exists:users,id'],
            'notes'            => ['nullable', 'string', 'max:5000'],
        ]);

        $interview = HrInterview::create([
            'application_id'   => $application->id,
            'scheduled_at'     => $validated['scheduled_at'],
            'duration_minutes' => $validated['duration_minutes'],
            'location'         => $validated['location'] ?? null,
            'interview_type'   => $validated['interview_type'],
            'interviewers'     => $validated['interviewers'] ?? [],
            'status'           => 'scheduled',
            'notes'            => $validated['notes'] ?? null,
        ]);

        try {
            app(\App\Domain\Hr\Services\InterviewNotificationService::class)->sendInvites($interview);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()->back()->with('success', 'Interview scheduled — calendar invites emailed to the candidate and panel.');
    }

    public function updateInterview(Request $request, HrInterview $interview)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $interview->application()->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['scheduled', 'completed', 'cancelled', 'no_show'])],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'outcome' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $interview->update([
            'status' => $validated['status'],
            'rating' => $validated['rating'] ?? $interview->rating,
            'outcome' => $validated['outcome'] ?? $interview->outcome,
            'notes' => $validated['notes'] ?? $interview->notes,
            'completed_by' => in_array($validated['status'], ['completed', 'cancelled', 'no_show'], true) ? $user->id : null,
        ]);

        return redirect()->back()->with('success', 'Interview updated.');
    }

    public function scoreInterview(Request $request, HrInterview $interview)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $interview->application()->with('interviewKit')->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        $validated = $request->validate([
            'overall_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'recommendation' => ['nullable', 'string', Rule::in(['strong_yes', 'yes', 'maybe', 'no', 'strong_no'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'criteria_scores' => ['nullable', 'array'],
            'criteria_scores.*.label' => ['required_with:criteria_scores', 'string', 'max:255'],
            'criteria_scores.*.score' => ['required_with:criteria_scores', 'numeric', 'min:0', 'max:100'],
            'criteria_scores.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $criteriaScores = $this->normalizeCriteriaScores(
                $validated['criteria_scores'] ?? null,
                (array) ($application->interviewKit?->criteria ?? []),
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->withErrors(['criteria_scores' => $exception->getMessage()]);
        }

        $overallScore = $validated['overall_score'] ?? null;
        if ($overallScore === null && $criteriaScores !== null) {
            $overallScore = $this->calculateWeightedScore($criteriaScores, (array) ($application->interviewKit?->criteria ?? []));
        }

        if ($overallScore === null && $criteriaScores === null) {
            return redirect()->back()->withErrors(['overall_score' => 'Provide an overall score or structured criteria scores.']);
        }

        HrInterviewScore::query()->updateOrCreate(
            [
                'interview_id' => $interview->id,
                'interviewer_user_id' => $user->id,
            ],
            [
                'kit_id' => $application->interview_kit_id,
                'criteria_scores' => $criteriaScores,
                'overall_score' => $overallScore,
                'recommendation' => $validated['recommendation'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'submitted_at' => now(),
            ],
        );

        if ($interview->status === 'scheduled') {
            $interview->update([
                'status' => 'completed',
                'completed_by' => $user->id,
            ]);
        }

        return redirect()->back()->with('success', 'Interview scorecard saved.');
    }

    /* ------------------------------------------------------------------ */
    /*  References                                                         */
    /* ------------------------------------------------------------------ */

    public function storeReference(Request $request, HrApplication $application)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);


        $validated = $request->validate([
            'referee_name'        => ['required', 'string', 'max:255'],
            'referee_email'       => ['nullable', 'email', 'max:255'],
            'referee_phone'       => ['nullable', 'string', 'max:50'],
            'referee_relationship' => ['required', 'string', 'max:255'],
        ]);

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

    public function updateReference(Request $request, HrReferenceCheck $reference)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $reference->application()->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['pending', 'requested', 'contacted', 'completed'])],
            'reference_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = $validated['status'];
        $isCompleted = $status === 'completed';
        $isContacted = in_array($status, ['contacted', 'completed'], true);

        $reference->update([
            'status' => $status,
            'received_at' => $isContacted ? ($reference->received_at ?? now()) : null,
            'verified_at' => $isCompleted ? now() : null,
            'verified_by' => $isCompleted ? $user->id : null,
            'reference_notes' => $validated['reference_notes'] ?? $reference->reference_notes,
        ]);

        return redirect()->back()->with('success', 'Reference check updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Offers                                                             */
    /* ------------------------------------------------------------------ */

    public function createOffer(Request $request, HrApplication $application)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);


        $application->load([
            'candidate.documents',
            'requisition:id,title,slug',
            'interviews.scores',
            'referenceChecks',
        ]);

        $candidate = $application->candidate;

        $sites = Site::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name']);
        $roles = collect(['support_worker', 'team_lead', 'coordinator', 'provider_manager', 'admin'])
            ->map(fn ($role) => ['value' => $role, 'label' => str($role)->replace('_', ' ')->title()->toString()])
            ->values()
            ->toArray();

        return Inertia::render('hr/candidates/create-offer', [
            'application' => [
                'id' => $application->id,
                'position_title' => $application->position_title,
                'position_role' => $application->position_role,
                'stage' => $candidate?->status,
                'candidate' => [
                    'id' => $candidate?->id,
                    'first_name' => $candidate?->first_name,
                    'last_name' => $candidate?->last_name,
                    'personal_email' => $candidate?->personal_email,
                    'personal_phone' => $candidate?->personal_phone,
                    'source' => $candidate?->source,
                ],
                'job_posting' => $application->requisition ? [
                    'title' => $application->requisition->title,
                    'department' => $application->requisition->department,
                    'location' => null,
                    'salary_range_min' => null,
                    'salary_range_max' => null,
                    'show_salary' => false,
                ] : null,
                'interviews' => $application->interviews->map(fn ($i) => [
                    'type' => $i->interview_type,
                    'status' => $i->status,
                    'rating' => $i->rating,
                    'outcome' => $i->outcome,
                    'scores' => $i->scores->map(fn ($s) => [
                        'overall_score' => $s->overall_score ? (float) $s->overall_score : null,
                        'recommendation' => $s->recommendation,
                    ])->values(),
                ])->values(),
                'reference_checks' => $application->referenceChecks->map(fn ($r) => [
                    'referee_name' => $r->referee_name,
                    'status' => $r->status,
                ])->values(),
                'documents' => $candidate?->documents?->map(fn ($d) => [
                    'category' => $d->category,
                    'category_label' => $d->category_label,
                    'original_name' => $d->original_name,
                ])->values() ?? [],
            ],
            'sites' => $sites,
            'roles' => $roles,
        ]);
    }

    public function storeOffer(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $applicationRule = Rule::exists('hr_applications', 'id');
        $siteRule = Rule::exists('sites', 'id');
        if ($tenantId !== null) {
            $applicationRule = $applicationRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
            $siteRule = $siteRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }


        $positionRule = Rule::exists('hr_positions', 'id');
        if ($tenantId !== null) {
            $positionRule = $positionRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }

        $validated = $request->validate([
            'application_id'     => ['required', 'integer', $applicationRule],
            'position_title'     => ['required', 'string', 'max:255'],
            'position_role'      => ['nullable', 'string', 'max:100'],
            'position_id'        => ['nullable', 'integer', $positionRule],
            'proposed_start_date' => ['required', 'date', 'after_or_equal:today'],
            'employment_type'    => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'hours_per_week'     => ['required', 'numeric', 'min:1', 'max:60'],
            'hourly_rate'        => ['nullable', 'numeric', 'min:0'],
            'annual_salary'      => ['nullable', 'numeric', 'min:0'],
            'primary_site_id'    => ['required', 'integer', $siteRule],
            'conditions'         => ['nullable', 'string', 'max:5000'],
            'offer_letter'       => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:20480'],
        ]);

        $application = HrApplication::query()
            ->with('candidate')
            ->where('id', $validated['application_id'])
            ->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);
        abort_unless($application->candidate, 404);

        if (in_array($application->candidate->status, RecruitmentService::TERMINAL, true)) {
            return redirect()->back()->with('error', 'Cannot create an offer for a terminal candidate stage.');
        }

        // Prevent duplicate offers for the same application
        if ($application->offer()->exists()) {
            return redirect()->back()->with('error', 'An offer already exists for this application.');
        }

        // Handle offer letter upload
        $letterPath = null;
        $letterName = null;
        if ($request->hasFile('offer_letter')) {
            $file = $request->file('offer_letter');
            $letterPath = $file->store("offers/{$application->id}", 'private');
            $letterName = $file->getClientOriginalName();
        }

        HrOffer::create([
            'application_id'      => $application->id,
            'position_title'      => $validated['position_title'],
            'position_role'       => ($validated['position_role'] ?? null) ?: ($application->position_role ?: 'support_worker'),
            'position_id'         => $validated['position_id'] ?? $application->requisition?->position_id,
            'proposed_start_date' => $validated['proposed_start_date'],
            'employment_type'     => $validated['employment_type'],
            'hours_per_week'      => $validated['hours_per_week'],
            'hourly_rate'         => $validated['hourly_rate'] ?? null,
            'annual_salary'       => $validated['annual_salary'] ?? null,
            'primary_site_id'     => $validated['primary_site_id'],
            'conditions'          => $validated['conditions'] ?? null,
            'offer_letter_path'   => $letterPath,
            'offer_letter_name'   => $letterName,
            'approval_status'     => 'draft',
            'created_by'          => $user->id,
        ]);

        if ($application->candidate) {
            try {
                $this->recruitmentService->advanceStage($application->candidate, 'offer_pending', $user->id);
            } catch (\Throwable) {
                // Offer can still be drafted even if pipeline prerequisites are not fully met yet.
            }
        }

        return redirect()->back()->with('success', 'Offer created successfully.');
    }

    public function sendOffer(Request $request, HrOffer $offer)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);


        $application = $offer->application()->with('candidate')->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        if ($offer->approval_status !== 'approved') {
            return redirect()->back()->with('error', 'Offer must be approved before sending.');
        }

        if ($offer->sent_at) {
            return redirect()->back()->with('error', 'Offer has already been sent.');
        }

        try {
            DB::transaction(function () use ($offer, $application, $user) {
                $offer->update([
                    'sent_at' => now(),
                    'approval_status' => 'approved',
                    'candidate_portal_token' => $offer->candidate_portal_token ?: Str::random(64),
                    'portal_expires_at' => now()->addDays(14),
                    'updated_by' => $user->id,
                ]);

                if ($application->candidate) {
                    $this->recruitmentService->advanceStage($application->candidate, 'offer_sent', $user->id);
                }
            });
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->back()->with('error', 'Offer could not be sent.');
        }

        $this->webhookService->publish($application->tenant_id, 'recruitment.offer.sent', [
            'offer_id' => $offer->id,
            'application_id' => $application->id,
            'candidate_id' => $application->candidate?->id,
            'candidate_name' => $application->candidate?->full_name,
            'position_title' => $offer->position_title,
            'sent_at' => optional($offer->sent_at)->toDateTimeString(),
        ]);

        $this->emailOfferLink($offer->fresh(), $application->candidate);

        return redirect()->back()->with('success', 'Offer sent — portal link emailed to the candidate.');
    }

    /**
     * Re-deliver the offer portal link (refreshing expiry) without re-advancing
     * the pipeline. Used when the original email bounced or expired.
     */
    public function resendOffer(Request $request, HrOffer $offer)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $offer->application()->with('candidate')->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        if (! $offer->sent_at) {
            return redirect()->back()->with('error', 'Send the offer before resending the link.');
        }

        if ($offer->response !== null) {
            return redirect()->back()->with('error', 'This offer has already been responded to.');
        }

        $offer->update([
            'candidate_portal_token' => $offer->candidate_portal_token ?: Str::random(64),
            'portal_expires_at' => now()->addDays(14),
            'updated_by' => $user->id,
        ]);

        $this->emailOfferLink($offer->fresh(), $application->candidate);

        return redirect()->back()->with('success', 'Offer link resent to the candidate.');
    }

    /**
     * Best-effort delivery of the offer-portal link to the (non-User) candidate.
     * A mail failure must not 500 the manager's request — the Resend action
     * exists precisely to recover from a failed send.
     */
    private function emailOfferLink(?HrOffer $offer, ?HrCandidate $candidate): void
    {
        if (! $offer || ! $candidate || ! $candidate->personal_email || ! $offer->candidate_portal_token) {
            return;
        }

        try {
            Notification::route('mail', $candidate->personal_email)
                ->notify(new OfferSentNotification($offer, $candidate));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function downloadOfferLetter(Request $request, HrOffer $offer)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        // Tenant guard — every sibling offer action asserts this; the letter
        // download must not be a cross-tenant IDOR hole.
        $application = $offer->application()->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        abort_unless($offer->offer_letter_path, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        abort_unless($disk->exists($offer->offer_letter_path), 404);

        return $disk->download($offer->offer_letter_path, $offer->offer_letter_name ?? 'offer-letter.pdf');
    }

    public function approveOffer(Request $request, HrOffer $offer)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $offer->application()->with('candidate')->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        if ($offer->approval_status === 'approved') {
            return redirect()->back()->with('success', 'Offer already approved.');
        }

        $offer->update([
            'approval_status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'updated_by' => $user->id,
        ]);

        $this->webhookService->publish($application->tenant_id, 'recruitment.offer.approved', [
            'offer_id' => $offer->id,
            'application_id' => $application->id,
            'candidate_id' => $application->candidate?->id,
            'position_title' => $offer->position_title,
            'approved_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Offer approved.');
    }

    public function respondOffer(Request $request, HrOffer $offer)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $offer->application()->with('candidate')->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);

        $validated = $request->validate([
            'response' => ['required', 'string', Rule::in(['accepted', 'declined', 'withdrawn'])],
            'response_notes' => ['nullable', 'string', 'max:5000'],
            'signature_name' => ['nullable', 'string', 'max:255'],
            // Boolean, not `accepted`: the dialog always posts this (default false)
            // for unsigned acceptances. The must-be-true rule is enforced by the
            // signature guard below only when a signature is actually applied.
            'terms_accepted' => ['nullable', 'boolean'],
        ]);

        if (! $offer->sent_at) {
            return redirect()->back()->with('error', 'Offer must be sent before recording a response.');
        }

        if ($offer->response === 'accepted') {
            return redirect()->back()->with('error', 'Offer has already been accepted.');
        }

        $response = $validated['response'];
        $isAccepted = $response === 'accepted';
        $signatureIp = $request->ip();

        if ($isAccepted && ! empty($validated['signature_name']) && ! $request->boolean('terms_accepted')) {
            return redirect()->back()->withErrors(['terms_accepted' => 'Terms must be accepted when applying a signature.']);
        }

        try {
            DB::transaction(function () use ($offer, $application, $response, $validated, $user, $signatureIp) {
                $offer->update([
                    'response' => $response,
                    'response_notes' => $validated['response_notes'] ?? null,
                    'response_at' => now(),
                    'signed_full_name' => $response === 'accepted' ? ($validated['signature_name'] ?? null) : null,
                    'signed_at' => $response === 'accepted' && ! empty($validated['signature_name']) ? now() : null,
                    'signed_ip' => $response === 'accepted' && ! empty($validated['signature_name']) ? $signatureIp : null,
                    'updated_by' => $user->id,
                ]);

                $candidate = $application->candidate;
                if (! $candidate) {
                    return;
                }

                if ($response === 'accepted') {
                    $this->recruitmentService->advanceStage($candidate, 'offer_accepted', $user->id);
                    return;
                }

                $terminalStatus = $response === 'withdrawn' ? 'withdrawn' : 'rejected';
                $candidate->update([
                    'status' => $terminalStatus,
                    'current_stage_entered_at' => now(),
                    'updated_by' => $user->id,
                ]);

                $application->update([
                    'status' => $terminalStatus,
                    'rejection_reason' => $response === 'declined'
                        ? trim((string) ($validated['response_notes'] ?? 'Offer declined by candidate'))
                        : $application->rejection_reason,
                ]);
            });
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->back()->with('error', 'Offer response could not be recorded.');
        }

        $this->webhookService->publish($application->tenant_id, 'recruitment.offer.responded', [
            'offer_id' => $offer->id,
            'application_id' => $application->id,
            'candidate_id' => $application->candidate?->id,
            'response' => $offer->response,
            'response_at' => optional($offer->response_at)->toDateTimeString(),
        ]);

        // Acknowledge the candidate's response (before any convert attempt, so a
        // convert failure never swallows the ack).
        $this->ackOfferResponse($offer, $application->candidate, (string) $offer->response);

        // Accepting an offer flows straight into employment + onboarding — but
        // minting a login is a segregation-of-duties step. Only auto-convert when
        // the actor also holds hr.employees.manage; otherwise the acceptance is
        // saved and the hire waits at offer_accepted for account provisioning.
        // The conversion is idempotent (firstOrCreate user / updateOrCreate
        // profile / onboarding generated at most once), so a later Convert is safe.
        if ($offer->response === 'accepted' && ! $user->canDo('hr.employees.manage')) {
            return redirect()->back()->with('success', 'Offer accepted. Account provisioning is pending — a user with employee-management rights can Convert to finish the hire.');
        }

        if ($offer->response === 'accepted') {
            $candidate = $application->candidate?->fresh();

            if ($candidate) {
                try {
                    $profile = $this->recruitmentService->convertToEmployee($candidate, $offer->fresh(), $user->id);

                    $this->webhookService->publish($application->tenant_id, 'recruitment.offer.converted', [
                        'offer_id' => $offer->id,
                        'application_id' => $application->id,
                        'candidate_id' => $candidate->id,
                        'employee_profile_id' => $profile->id,
                        'converted_by' => $user->id,
                    ]);

                    $this->notifyHiringManagerOfHire($application, $candidate);

                    return redirect()->back()->with('success', 'Offer accepted — employee profile created and onboarding started.');
                } catch (\Throwable $exception) {
                    report($exception);

                    // The acceptance is saved; conversion can be retried via the
                    // Convert action without losing anything.
                    return redirect()->back()->with('success', 'Offer accepted. Converting to an employee could not complete automatically — use Convert to finish.');
                }
            }
        }

        return redirect()->back()->with('success', 'Offer response recorded.');
    }

    /* ------------------------------------------------------------------ */
    /*  Convert to Employee                                                */
    /* ------------------------------------------------------------------ */

    public function convertToEmployee(Request $request, HrOffer $offer)
    {
        $user = $request->user();
        // Converting mints a User login — gated by hr.employees.manage in addition
        // to hr.recruitment.manage (segregation of duties; the route enforces both).
        abort_unless($user && $user->canDo('hr.recruitment.manage') && $user->canDo('hr.employees.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $application = $offer->application()->with('candidate')->firstOrFail();
        $this->assertHrTenantAccess($tenantId, $application->tenant_id);
        $candidate = $application->candidate;
        abort_unless($candidate, 404);

        if ($offer->response !== 'accepted') {
            return redirect()->back()->with('error', 'Cannot convert: offer has not been accepted.');
        }

        try {
            $profile = $this->recruitmentService->convertToEmployee($candidate, $offer, $user->id);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $this->webhookService->publish($application->tenant_id, 'recruitment.offer.converted', [
            'offer_id' => $offer->id,
            'application_id' => $application->id,
            'candidate_id' => $candidate->id,
            'employee_profile_id' => $profile->id,
            'converted_by' => $user->id,
        ]);

        $this->notifyHiringManagerOfHire($application, $candidate);

        return redirect()->back()->with('success', "Employee profile created (#{$profile->id}).");
    }

    /** Best-effort candidate acknowledgement of an accepted/declined offer. */
    private function ackOfferResponse(HrOffer $offer, ?HrCandidate $candidate, string $response): void
    {
        if (! $candidate || ! $candidate->personal_email || ! in_array($response, ['accepted', 'declined'], true)) {
            return;
        }

        try {
            Notification::route('mail', $candidate->personal_email)
                ->notify(new OfferResponseAckNotification($offer, $candidate, $response));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Best-effort hiring-manager notification when a candidate is converted. */
    private function notifyHiringManagerOfHire(HrApplication $application, HrCandidate $candidate): void
    {
        try {
            $requisition = $application->requisition()->with('hiringManager')->first();
            $manager = $requisition?->hiringManager;
            if ($manager) {
                $manager->notify(new CandidateHiredNotification($candidate, $requisition));
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param array<int, array<string, mixed>>|null $criteriaScores
     * @param array<int, mixed> $kitCriteria
     * @return array<int, array{label: string, score: float, weight: float|null}>|null
     */
    private function normalizeCriteriaScores(?array $criteriaScores, array $kitCriteria): ?array
    {
        if (! is_array($criteriaScores) || $criteriaScores === []) {
            return null;
        }

        $normalized = collect($criteriaScores)
            ->map(function ($row) {
                $label = trim((string) ($row['label'] ?? ''));
                $score = $row['score'] ?? null;
                $weight = $row['weight'] ?? null;

                if ($label === '' || $score === null) {
                    return null;
                }

                return [
                    'label' => $label,
                    'score' => round((float) $score, 2),
                    'weight' => $weight !== null ? round((float) $weight, 2) : null,
                ];
            })
            ->filter()
            ->values();

        if ($normalized->isEmpty()) {
            return null;
        }

        $kitCriteriaLabels = collect($kitCriteria)
            ->map(fn ($criterion) => trim((string) ($criterion['label'] ?? '')))
            ->filter()
            ->values();

        if ($kitCriteriaLabels->isNotEmpty()) {
            $submittedLabels = $normalized->pluck('label');
            $invalid = $submittedLabels->diff($kitCriteriaLabels);
            if ($invalid->isNotEmpty()) {
                throw new \InvalidArgumentException('Criteria scores contain unknown criteria labels.');
            }

            $missing = $kitCriteriaLabels->diff($submittedLabels);
            if ($missing->isNotEmpty()) {
                throw new \InvalidArgumentException('Provide scores for every criterion configured in the interview kit.');
            }
        }

        return $normalized->all();
    }

    /**
     * @param array<int, array{label: string, score: float, weight: float|null}> $criteriaScores
     * @param array<int, mixed> $kitCriteria
     */
    private function calculateWeightedScore(array $criteriaScores, array $kitCriteria): float
    {
        $weightsByLabel = collect($kitCriteria)
            ->mapWithKeys(function ($criterion) {
                $label = trim((string) ($criterion['label'] ?? ''));
                if ($label === '') {
                    return [];
                }

                $weight = $criterion['weight'] ?? null;

                return [$label => is_numeric($weight) ? (float) $weight : null];
            });

        $weighted = collect($criteriaScores)->map(function (array $row) use ($weightsByLabel) {
            $weight = $row['weight'];
            if ($weight === null) {
                $resolvedWeight = $weightsByLabel[$row['label']] ?? null;
                $weight = is_numeric($resolvedWeight) ? (float) $resolvedWeight : 1.0;
            }

            return [
                'score' => (float) $row['score'],
                'weight' => max(0.0, (float) $weight),
            ];
        });

        $totalWeight = (float) $weighted->sum('weight');
        if ($totalWeight <= 0.0) {
            return round((float) $weighted->avg('score'), 2);
        }

        $weightedTotal = (float) $weighted->sum(fn (array $row) => $row['score'] * $row['weight']);

        return round($weightedTotal / $totalWeight, 2);
    }

    /* ================================================================== */
    /*  CANDIDATE DOCUMENTS                                                */
    /* ================================================================== */

    public function storeDocument(Request $request, HrCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $candidate->tenant_id);

        $applicationRule = Rule::exists('hr_applications', 'id')
            ->where(fn ($q) => $q->where('candidate_id', $candidate->id));

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
            'category' => ['required', 'string', Rule::in(array_keys(HrCandidateDocument::CATEGORIES))],
            'application_id' => ['nullable', 'integer', $applicationRule],
            'notes' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // Carry the application context: explicit param wins, else fall back to the
        // candidate's most-recent active application so the doc isn't orphaned.
        $applicationId = $validated['application_id']
            ?? $candidate->applications()
                ->whereNotIn('status', ['rejected', 'withdrawn'])
                ->latest('id')
                ->value('id');

        $file = $request->file('file');
        $path = $file->store("candidates/{$candidate->id}/documents", 'private');

        $categoryLabel = HrCandidateDocument::CATEGORIES[$validated['category']] ?? $validated['category'];

        HrCandidateDocument::create([
            'tenant_id' => $candidate->tenant_id,
            'candidate_id' => $candidate->id,
            'application_id' => $applicationId,
            'category' => $validated['category'],
            'title' => $categoryLabel . ' - ' . $file->getClientOriginalName(),
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $user->id,
            'notes' => $validated['notes'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function downloadDocument(Request $request, HrCandidateDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        abort_unless($disk->exists($document->storage_path), 404);

        return $disk->download($document->storage_path, $document->original_name);
    }

    public function destroyDocument(Request $request, HrCandidateDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

        \Illuminate\Support\Facades\Storage::disk('private')->delete($document->storage_path);
        $document->delete();

        return redirect()->back()->with('success', 'Document deleted.');
    }
}
