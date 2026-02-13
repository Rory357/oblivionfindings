<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Models\User;
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
     * Create a new candidate with privacy consent recorded.
     *
     * @param  array  $data  Candidate attributes (first_name, last_name, personal_email, etc.)
     * @param  int    $tenantId
     * @param  int    $createdBy  User ID of the recruiter
     * @return HrCandidate
     */
    public function createCandidate(array $data, ?int $tenantId, int $createdBy): HrCandidate
    {
        // TODO: Validate required fields (first_name, last_name, personal_email)
        // TODO: Check for duplicate candidates by email within tenant
        // TODO: Record privacy consent timestamp and IP from request

        return DB::transaction(function () use ($data, $tenantId, $createdBy) {
            $candidate = HrCandidate::create([
                'tenant_id' => $tenantId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'preferred_name' => $data['preferred_name'] ?? null,
                'personal_email' => $data['personal_email'],
                'personal_phone' => $data['personal_phone'] ?? null,
                'source' => $data['source'] ?? 'direct',
                'source_detail' => $data['source_detail'] ?? null,
                'status' => 'new',
                'current_stage_entered_at' => now(),
                'privacy_consent_given_at' => $data['privacy_consent_given_at'] ?? now(),
                'privacy_consent_ip' => $data['privacy_consent_ip'] ?? request()?->ip(),
                'tags' => $data['tags'] ?? [],
                'created_by' => $createdBy,
            ]);

            // TODO: Create initial HrApplication if position details are provided in $data
            // TODO: Fire CandidateCreated event for notification listeners
            // TODO: Log audit trail entry

            return $candidate;
        });
    }

    /**
     * Create an application for an existing candidate.
     *
     * @param  HrCandidate  $candidate
     * @param  array        $data  Application attributes (position_title, position_role, target_site_id, etc.)
     * @return HrApplication
     */
    public function createApplication(HrCandidate $candidate, array $data): HrApplication
    {
        // TODO: Validate that candidate is not in a terminal status
        // TODO: Check for duplicate active applications for the same position
        // TODO: Handle CV file upload and store path

        return HrApplication::create([
            'tenant_id' => $candidate->tenant_id,
            'candidate_id' => $candidate->id,
            'position_title' => $data['position_title'],
            'position_role' => $data['position_role'] ?? null,
            'target_site_id' => $data['target_site_id'] ?? null,
            'cv_storage_path' => $data['cv_storage_path'] ?? null,
            'cv_original_name' => $data['cv_original_name'] ?? null,
            'cover_letter' => $data['cover_letter'] ?? null,
            'answers' => $data['answers'] ?? null,
            'status' => 'new',
        ]);
    }

    /**
     * Advance candidate to the next recruitment stage.
     *
     * Enforces ordered stage progression via STAGES constant.
     * Prevents advancement from terminal statuses.
     *
     * @param  HrCandidate  $candidate
     * @param  string|null  $targetStage  Explicit stage to advance to (must be after current). If null, advances to next sequential stage.
     * @param  int          $advancedBy   User ID performing the advancement
     * @return HrCandidate
     *
     * @throws \InvalidArgumentException If target stage is invalid or behind current stage
     * @throws \LogicException           If candidate is in a terminal status
     */
    public function advanceStage(HrCandidate $candidate, ?string $targetStage, int $advancedBy): HrCandidate
    {
        // TODO: Verify candidate is not in a terminal status (withdrawn/rejected/hired)
        // TODO: If targetStage is null, determine next sequential stage from STAGES
        // TODO: Validate that targetStage is ahead of current stage in the pipeline
        // TODO: Check stage-specific prerequisites:
        //       - 'interview_completed' requires at least one completed interview record
        //       - 'reference_check' requires at least one reference check initiated
        //       - 'offer_pending' requires all reference checks completed
        //       - 'offer_sent' requires an approved HrOffer record
        //       - 'offer_accepted' requires offer response = 'accepted'
        // TODO: Update candidate status and current_stage_entered_at
        // TODO: Update associated application status to match
        // TODO: Fire StageAdvanced event for notification listeners
        // TODO: Log audit trail entry with old -> new stage transition

        $currentIndex = array_search($candidate->status, self::STAGES);
        $targetIndex = $targetStage
            ? array_search($targetStage, self::STAGES)
            : ($currentIndex !== false ? $currentIndex + 1 : false);

        if ($targetIndex === false || $targetIndex <= $currentIndex) {
            throw new \InvalidArgumentException(
                "Cannot advance from '{$candidate->status}' to '{$targetStage}'."
            );
        }

        if (in_array($candidate->status, self::TERMINAL, true)) {
            throw new \LogicException(
                "Cannot advance candidate in terminal status '{$candidate->status}'."
            );
        }

        $candidate->update([
            'status' => self::STAGES[$targetIndex],
            'current_stage_entered_at' => now(),
            'updated_by' => $advancedBy,
        ]);

        return $candidate->fresh();
    }

    /**
     * Reject a candidate with a reason.
     *
     * @param  HrCandidate  $candidate
     * @param  string       $reason
     * @param  int          $rejectedBy  User ID
     * @return HrCandidate
     */
    public function rejectCandidate(HrCandidate $candidate, string $reason, int $rejectedBy): HrCandidate
    {
        // TODO: Validate candidate is not already in a terminal status
        // TODO: Update all active applications to 'rejected' with rejection_reason
        // TODO: Fire CandidateRejected event for notification (e.g. email to candidate)
        // TODO: Log audit trail entry
        // TODO: Schedule GDPR/privacy data retention reminder

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
     * Convert an accepted candidate into an employee (User + HrEmployeeProfile).
     *
     * Creates a User account, employee profile, and triggers onboarding checklist
     * generation. Links back to the candidate and offer records.
     *
     * @param  HrCandidate  $candidate
     * @param  HrOffer      $offer
     * @param  int          $convertedBy  User ID performing the conversion
     * @return HrEmployeeProfile
     *
     * @throws \LogicException If candidate status is not 'offer_accepted' or offer is not accepted
     */
    public function convertToEmployee(HrCandidate $candidate, HrOffer $offer, int $convertedBy): HrEmployeeProfile
    {
        // TODO: Validate candidate is at 'offer_accepted' stage
        // TODO: Validate offer response is 'accepted'
        // TODO: Create User account with appropriate role (from offer position_role)
        // TODO: Provision work email if not already done on the offer
        // TODO: Create HrEmployeeProfile linked to user, candidate, and offer
        // TODO: Copy relevant fields from offer (position_title, hours_per_week, hourly_rate, etc.)
        // TODO: Generate onboarding checklist via OnboardingService
        // TODO: Advance candidate to 'hired' terminal status
        // TODO: Fire EmployeeCreated event
        // TODO: Log audit trail entry

        return DB::transaction(function () use ($candidate, $offer, $convertedBy) {
            $user = User::create([
                'tenant_id' => $candidate->tenant_id,
                'name' => $candidate->full_name,
                'email' => $offer->work_email ?? $candidate->personal_email,
                'password' => bcrypt(str()->random(32)),
            ]);

            $profile = HrEmployeeProfile::create([
                'tenant_id' => $candidate->tenant_id,
                'user_id' => $user->id,
                'position_title' => $offer->position_title,
                'position_role' => $offer->position_role,
                'employment_type' => $offer->employment_type,
                'hours_per_week' => $offer->hours_per_week,
                'hourly_rate' => $offer->hourly_rate,
                'annual_salary' => $offer->annual_salary,
                'primary_site_id' => $offer->primary_site_id,
                'start_date' => $offer->proposed_start_date,
                'personal_email' => $candidate->personal_email,
                'personal_phone' => $candidate->personal_phone,
                'is_active' => true,
                'offer_id' => $offer->id,
                'candidate_id' => $candidate->id,
                'created_by' => $convertedBy,
            ]);

            $this->advanceStage($candidate, 'hired', $convertedBy);

            $this->onboardingService->generateChecklist($profile, $convertedBy);

            return $profile;
        });
    }

    /**
     * Get pipeline summary counts for a tenant.
     *
     * @param  int  $tenantId
     * @return array<string, int>  Stage => count mapping
     */
    public function getPipelineSummary(?int $tenantId): array
    {
        // TODO: Query candidates grouped by status for the tenant
        // TODO: Include terminal statuses in a separate section
        // TODO: Optionally filter by date range for active pipeline vs. historical

        $counts = HrCandidate::where('tenant_id', $tenantId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $summary = [];
        foreach (self::STAGES as $stage) {
            $summary[$stage] = $counts[$stage] ?? 0;
        }
        foreach (self::TERMINAL as $status) {
            $summary[$status] = $counts[$status] ?? 0;
        }

        return $summary;
    }
}
