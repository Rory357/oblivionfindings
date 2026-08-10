<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecruitmentService
{
    /**
     * Ordered recruitment pipeline stages.
     */
    public const STAGES = [
        'new',
        'screening',
        'interview_scheduled',
        'interview_completed',
        'reference_check',
        'offer_pending',
        'offer_sent',
        'offer_accepted',
        'onboarding',
        'hired',
    ];

    /**
     * Terminal (non-advanceable) statuses.
     */
    public const TERMINAL = ['withdrawn', 'rejected', 'hired'];

    public function __construct(
        private readonly OnboardingService $onboardingService,
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public function createCandidate(array $data, ?int $createdBy = null): HrCandidate
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $email = strtolower(trim((string) ($data['personal_email'] ?? '')));

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new \InvalidArgumentException('First name, last name, and personal email are required.');
        }

        $duplicate = HrCandidate::query()
            ->where('personal_email', $email)
            ->whereNotIn('status', ['withdrawn', 'rejected'])
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('A candidate with this email already exists in the active pipeline.');
        }

        return HrCandidate::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'preferred_name' => $data['preferred_name'] ?? null,
            'personal_email' => $email,
            'personal_phone' => $data['personal_phone'] ?? null,
            'source' => $data['source'] ?? 'direct',
            'source_detail' => $data['source_detail'] ?? null,
            'status' => 'new',
            'current_stage_entered_at' => now(),
            'privacy_consent_given_at' => $data['privacy_consent_given_at'] ?? now(),
            'privacy_consent_ip' => $data['privacy_consent_ip'] ?? request()?->ip(),
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? [],
            'created_by' => $createdBy,
        ])->fresh();
    }

    /**
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function createApplication(HrCandidate $candidate, array $data): HrApplication
    {
        if (in_array($candidate->status, self::TERMINAL, true)) {
            throw new \LogicException("Cannot create an application for candidate in '{$candidate->status}' status.");
        }

        $positionTitle = trim((string) ($data['position_title'] ?? ''));
        if ($positionTitle === '') {
            throw new \InvalidArgumentException('Position title is required for applications.');
        }

        $requisition = null;
        if (isset($data['requisition_id'])) {
            $requisition = HrJobRequisition::query()->find($data['requisition_id']);
            if (! $requisition) {
                throw new \InvalidArgumentException('The selected requisition is unavailable.');
            }
        }

        $targetSiteId = isset($data['target_site_id']) && is_numeric($data['target_site_id'])
            ? (int) $data['target_site_id']
            : ($requisition?->site_id ? (int) $requisition->site_id : null);
        if ($targetSiteId === null || $targetSiteId <= 0) {
            throw new \InvalidArgumentException('A target Site is required for every application.');
        }
        if ($requisition && (int) $requisition->site_id !== $targetSiteId) {
            throw new \InvalidArgumentException('The application Site must match the requisition Site.');
        }
        $siteIsAvailable = Site::query()
            ->whereKey($targetSiteId)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->exists();
        if (! $siteIsAvailable) {
            throw new \InvalidArgumentException('The selected Site is unavailable.');
        }

        $duplicate = HrApplication::query()
            ->where('candidate_id', $candidate->id)
            ->where('position_title', $positionTitle)
            ->where('target_site_id', $targetSiteId)
            ->whereNotIn('status', ['rejected', 'withdrawn', 'hired'])
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('An active application already exists for this position.');
        }

        return HrApplication::create([
            'candidate_id' => $candidate->id,
            'requisition_id' => $requisition?->id,
            'interview_kit_id' => $data['interview_kit_id'] ?? null,
            'position_title' => $positionTitle,
            'position_role' => $data['position_role'] ?? 'support_worker',
            'target_site_id' => $targetSiteId,
            'cv_storage_path' => $data['cv_storage_path'] ?? null,
            'cv_original_name' => $data['cv_original_name'] ?? null,
            'cover_letter' => $data['cover_letter'] ?? null,
            'screening_answers' => $data['screening_answers'] ?? $data['answers'] ?? null,
            'candidate_tracking_token' => $data['candidate_tracking_token'] ?? null,
            'status' => 'active',
        ]);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function advanceStage(
        HrCandidate $candidate,
        ?string $targetStage,
        int $advancedBy,
        ?string $scorecardOverrideReason = null,
    ): HrCandidate {
        if (in_array($candidate->status, self::TERMINAL, true)) {
            throw new \LogicException("Cannot advance candidate in terminal status '{$candidate->status}'.");
        }

        $currentIndex = array_search($candidate->status, self::STAGES, true);
        if ($currentIndex === false) {
            throw new \InvalidArgumentException("Unknown current stage '{$candidate->status}'.");
        }

        $resolvedTarget = $targetStage ?? (self::STAGES[$currentIndex + 1] ?? null);
        $targetIndex = array_search($resolvedTarget, self::STAGES, true);

        if ($resolvedTarget === null || $targetIndex === false) {
            throw new \InvalidArgumentException('Target stage is invalid.');
        }

        if ($targetIndex <= $currentIndex) {
            throw new \InvalidArgumentException("Cannot move candidate backward from '{$candidate->status}' to '{$resolvedTarget}'.");
        }

        $scorecardOverride = $this->assertStagePrerequisites(
            $candidate,
            $resolvedTarget,
            $advancedBy,
            $scorecardOverrideReason,
        );

        return DB::transaction(function () use ($candidate, $resolvedTarget, $advancedBy, $scorecardOverride) {
            $candidate->update([
                'status' => $resolvedTarget,
                'current_stage_entered_at' => now(),
                'updated_by' => $advancedBy,
            ]);

            $applicationStatus = match ($resolvedTarget) {
                'offer_sent', 'offer_accepted' => 'offered',
                'hired' => 'hired',
                default => 'active',
            };

            $candidate->applications()
                ->whereNotIn('status', ['rejected', 'withdrawn', 'hired'])
                ->update(['status' => $applicationStatus]);

            if ($scorecardOverride) {
                AuditLogger::log('recruitment.scorecard_quorum_overridden', $candidate, $scorecardOverride);
            }

            return $candidate->fresh();
        });
    }

    /**
     * Immediately invalidate an unanswered sent offer. Resend is the only
     * intentional revival path and will mint a fresh token.
     */
    public function forceExpireOffer(HrOffer $offer, int $actorId, string $reason): HrOffer
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('An expiry reason is required.');
        }
        if (! $offer->sent_at) {
            throw new \LogicException('Only a sent offer can be expired.');
        }
        if ($offer->response !== null) {
            throw new \LogicException('Accepted, declined, or withdrawn offers cannot be expired.');
        }
        if ($offer->expired_by !== null && $offer->candidate_portal_token === null) {
            throw new \LogicException('This offer is already explicitly expired. Use resend to revive it.');
        }

        return DB::transaction(function () use ($offer, $actorId, $reason) {
            $offer->update([
                'candidate_portal_token' => null,
                'portal_expires_at' => now()->subSecond(),
                'expired_by' => $actorId,
                'expiry_reason' => $reason,
                'updated_by' => $actorId,
            ]);

            AuditLogger::log('recruitment.offer_force_expired', $offer, [
                'actor_id' => $actorId,
                'reason' => $reason,
                'expired_at' => $offer->portal_expires_at?->toDateTimeString(),
            ]);

            return $offer->fresh();
        });
    }

    /**
     * @throws \LogicException
     */
    public function rejectCandidate(HrCandidate $candidate, string $reason, int $rejectedBy): HrCandidate
    {
        if (in_array($candidate->status, self::TERMINAL, true)) {
            throw new \LogicException("Candidate already in terminal status '{$candidate->status}'.");
        }

        $candidate->update([
            'status' => 'rejected',
            'current_stage_entered_at' => now(),
            'updated_by' => $rejectedBy,
        ]);

        $candidate->applications()
            ->whereNotIn('status', ['rejected', 'withdrawn'])
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

        return $candidate->fresh();
    }

    /**
     * @throws \LogicException
     */
    public function convertToEmployee(HrCandidate $candidate, HrOffer $offer, int $convertedBy): HrEmployeeProfile
    {
        if (! in_array($candidate->status, ['offer_accepted', 'onboarding'], true)) {
            throw new \LogicException('Candidate must be in offer_accepted/onboarding stage before conversion.');
        }

        if ($offer->response !== 'accepted') {
            throw new \LogicException('Offer response must be accepted before conversion.');
        }

        return DB::transaction(function () use ($candidate, $offer, $convertedBy) {
            $candidate->loadMissing('documents');
            $workEmail = $offer->work_email ?: $candidate->personal_email;
            $roleName = $offer->position_role ?: 'support_worker';
            $existingUserId = User::query()->where('email', $workEmail)->value('id');

            // Guard: never hijack a profile already linked to a *different* candidate.
            $existingProfile = HrEmployeeProfile::query()
                ->whereHas('user', fn ($q) => $q->where('email', $workEmail))
                ->first();
            if (
                $existingProfile
                && $existingProfile->candidate_id
                && (int) $existingProfile->candidate_id !== (int) $candidate->id
            ) {
                throw new \LogicException('This email is already linked to another converted candidate.');
            }

            // Single source of truth for the User + profile write (+ role,
            // onboarding, invite, event). Recruitment is just one door into it.
            $profile = app(EmployeeIntakeService::class)->intake(
                name: $candidate->full_name,
                email: $workEmail,
                roleName: $roleName,
                profileAttributes: [
                    'position_id' => $offer->position_id,
                    'position_title' => $offer->position_title,
                    'position_role' => $roleName,
                    'employment_type' => $offer->employment_type,
                    'hours_per_week' => $offer->hours_per_week,
                    'hourly_rate' => $offer->hourly_rate,
                    'annual_salary' => $offer->annual_salary,
                    'primary_site_id' => $offer->primary_site_id,
                    'start_date' => $offer->proposed_start_date,
                    'personal_email' => $candidate->personal_email,
                    'personal_phone' => $candidate->personal_phone,
                    'work_email' => $workEmail,
                    'offer_id' => $offer->id,
                    'candidate_id' => $candidate->id,
                ],
                actorId: $convertedBy,
                startOnboarding: true,
                sendInvite: true,
                source: 'recruitment',
                authorizedExistingUserId: $existingUserId ? (int) $existingUserId : null,
            );

            // Recruitment-specific follow-through (candidate lifecycle + docs).
            $this->advanceStage($candidate, 'hired', $convertedBy);
            $offer->application()->update(['status' => 'hired']);
            $this->maybeCloseFilledRequisition($offer, $convertedBy);
            $this->transferCandidateDocuments($candidate, $profile, $convertedBy);

            // The work email/login is now provisioned — record it on the offer so
            // the stubbed flag carries a real signal (idempotent on re-convert).
            $offer->update([
                'work_email' => $workEmail,
                'work_email_provisioned' => true,
            ]);

            return $profile->fresh();
        });
    }

    /**
     * Auto-close a requisition once every opening is filled. Only fires from
     * still-open states (draft/published/paused) — a manually closed or
     * approval-pending requisition is left alone. Declines deliberately do NOT
     * reopen or decrement anything: the seat simply remains open.
     */
    protected function maybeCloseFilledRequisition(HrOffer $offer, int $actorId): void
    {
        $requisition = $offer->application?->requisition;
        if (! $requisition || ! in_array($requisition->status, ['draft', 'published', 'paused'], true)) {
            return;
        }

        $hired = HrApplication::query()
            ->where('requisition_id', $requisition->id)
            ->where('status', 'hired')
            ->count();

        if ($hired >= max(1, (int) $requisition->openings)) {
            $requisition->update([
                'status' => 'closed',
                'updated_by' => $actorId,
            ]);
        }
    }

    /**
     * Generate the onboarding checklist on conversion — but only once per profile
     * (so re-running convert is idempotent), and never let a missing onboarding
     * template abort the whole conversion (the hire still succeeds; HR can start
     * onboarding manually).
     */
    protected function maybeGenerateOnboardingChecklist(HrEmployeeProfile $profile, int $convertedBy): void
    {
        $alreadyOnboarding = HrOnboardingChecklist::query()
            ->where('employee_profile_id', $profile->id)
            ->exists();

        if ($alreadyOnboarding) {
            return;
        }

        try {
            $this->onboardingService->generateChecklist($profile, $convertedBy);
        } catch (\RuntimeException $exception) {
            Log::warning('Onboarding checklist not auto-generated on conversion', [
                'employee_profile_id' => $profile->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Copy candidate recruitment documents to the employee profile's HR documents.
     */
    protected function transferCandidateDocuments(HrCandidate $candidate, HrEmployeeProfile $profile, int $createdBy): void
    {
        $candidateDocs = $candidate->documents ?? collect();
        if ($candidateDocs->isEmpty()) {
            return;
        }

        // Map candidate document categories to HrDocument categories
        $categoryMap = [
            'cv' => 'other',
            'cover_letter' => 'other',
            'qualification' => 'certificate',
            'certification' => 'certificate',
            'police_vetting' => 'certificate',
            'first_aid' => 'certificate',
            'driver_licence' => 'certificate',
            'reference_letter' => 'other',
            'portfolio' => 'other',
            'other' => 'other',
        ];

        foreach ($candidateDocs as $doc) {
            HrDocument::create([
                'employee_profile_id' => $profile->id,
                'title' => $doc->title ?: ($doc->category_label.' - '.$doc->original_name),
                'category' => $categoryMap[$doc->category] ?? 'other',
                'folder' => 'Recruitment',
                'storage_disk' => 'private',
                'storage_path' => $doc->storage_path,
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'expires_at' => $doc->expires_at,
                'created_by' => $createdBy,
                'uploaded_by' => $doc->uploaded_by,
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    public function getPipelineSummary(array $candidateIds): array
    {
        $counts = HrCandidate::query()
            ->whereKey($candidateIds)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $summary = [];
        foreach (self::STAGES as $stage) {
            $summary[$stage] = (int) ($counts[$stage] ?? 0);
        }
        foreach (self::TERMINAL as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /**
     * @throws \LogicException
     */
    protected function assertStagePrerequisites(
        HrCandidate $candidate,
        string $targetStage,
        int $advancedBy,
        ?string $scorecardOverrideReason,
    ): ?array {
        $application = $candidate->applications()->with(['interviews.scores', 'referenceChecks', 'offer'])->latest('id')->first();

        if (! $application && ! in_array($targetStage, ['screening'], true)) {
            throw new \LogicException('Candidate has no application record for stage advancement.');
        }

        match ($targetStage) {
            'interview_completed' => $this->assertCompletedInterview($application),
            'reference_check' => $this->assertReferenceRequested($application),
            'offer_pending' => $this->assertReferencesComplete($application),
            'offer_sent' => $this->assertOfferApproved($application),
            'offer_accepted', 'onboarding', 'hired' => $this->assertOfferAccepted($application),
            default => null,
        };

        $targetIndex = array_search($targetStage, self::STAGES, true);
        $currentIndex = array_search($candidate->status, self::STAGES, true);
        $interviewScheduledIndex = array_search('interview_scheduled', self::STAGES, true);
        $interviewCompletedIndex = array_search('interview_completed', self::STAGES, true);

        if (
            $targetIndex !== false
            && $currentIndex !== false
            && $currentIndex >= $interviewScheduledIndex
            && $currentIndex <= $interviewCompletedIndex
            && $targetIndex > $interviewCompletedIndex
        ) {
            return $this->assertScorecardQuorum(
                $candidate,
                $application,
                $targetStage,
                $advancedBy,
                $scorecardOverrideReason,
            );
        }

        return null;
    }

    protected function assertScorecardQuorum(
        HrCandidate $candidate,
        ?HrApplication $application,
        string $targetStage,
        int $advancedBy,
        ?string $overrideReason,
    ): ?array {
        $interview = $application?->interviews
            ->where('status', 'completed')
            ->sortByDesc(fn ($item) => sprintf('%020d-%020d', $item->scheduled_at?->getTimestamp() ?? 0, $item->id))
            ->first();

        if (! $interview) {
            throw new \LogicException('Scorecard quorum cannot be met because there is no completed interview.');
        }

        $assignedIds = collect($interview->interviewers ?? [])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($assignedIds->isEmpty()) {
            throw new \LogicException('Scorecard quorum cannot be met because the completed interview has no assigned interviewers.');
        }

        $submittedIds = $interview->scores
            ->whereNotNull('submitted_at')
            ->pluck('interviewer_user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();
        $missingIds = $assignedIds->diff($submittedIds)->values();

        if ($missingIds->isEmpty()) {
            return null;
        }

        $overrideReason = trim((string) $overrideReason);
        if ($overrideReason === '') {
            throw new \LogicException('Scorecard quorum is incomplete. Every assigned interviewer must submit before the candidate advances, or a manager must provide an override reason.');
        }

        return [
            'actor_id' => $advancedBy,
            'application_id' => $application?->id,
            'interview_id' => $interview->id,
            'target_stage' => $targetStage,
            'missing_interviewer_ids' => $missingIds->all(),
            'reason' => $overrideReason,
        ];
    }

    protected function assertCompletedInterview(?HrApplication $application): void
    {
        $hasCompletedInterview = $application?->interviews
            ->where('status', 'completed')
            ->isNotEmpty();

        if (! $hasCompletedInterview) {
            throw new \LogicException('At least one completed interview is required.');
        }
    }

    protected function assertReferenceRequested(?HrApplication $application): void
    {
        if ($application?->referenceChecks?->isEmpty() ?? true) {
            throw new \LogicException('At least one reference check must be requested.');
        }
    }

    protected function assertReferencesComplete(?HrApplication $application): void
    {
        $references = $application?->referenceChecks ?? collect();
        if ($references->isEmpty() || $references->where('status', '!=', 'completed')->isNotEmpty()) {
            throw new \LogicException('All reference checks must be completed before offer stage.');
        }
    }

    protected function assertOfferApproved(?HrApplication $application): void
    {
        $offer = $application?->offer;
        if (! $offer || $offer->approval_status !== 'approved') {
            throw new \LogicException('An approved offer is required before offer_sent stage.');
        }
    }

    protected function assertOfferAccepted(?HrApplication $application): void
    {
        $offer = $application?->offer;
        if (! $offer || $offer->response !== 'accepted') {
            throw new \LogicException('Offer must be accepted before this stage.');
        }
    }

    protected function generateEmployeeNumber(): string
    {
        $prefix = (string) config('hr.employee_number_prefix', 'EMP');
        $latestId = (int) (HrEmployeeProfile::query()->max('id') ?? 0) + 1;

        return $prefix.str_pad((string) $latestId, 5, '0', STR_PAD_LEFT);
    }
}
