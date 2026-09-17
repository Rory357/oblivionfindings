<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class GovernanceVotingProfileService
{
    public const APPROVAL_SOURCE_RESOLUTION = 'resolution';

    public const APPROVAL_SOURCE_RECORDED = 'recorded_board_approval';

    /**
     * Check whether a board member is in the electorate for a resolution.
     */
    public function isMemberInElectorate(BoardMember $member, Resolution $resolution): bool
    {
        if (! $member->canVote()) {
            return false;
        }

        $committeeId = $resolution->board_committee_id ?? $resolution->meeting?->board_committee_id;
        if ($committeeId) {
            $membership = CommitteeMembership::where('board_committee_id', $committeeId)
                ->where('board_member_id', $member->id)
                ->active()
                ->first();

            return $membership !== null && $membership->canVote();
        }

        return true;
    }

    /**
     * Retrieve the current active voting profile for a governing body or committee.
     */
    public function getActiveProfile(string $governingBody = 'board', ?int $committeeId = null): ?GovernanceVotingProfile
    {
        return GovernanceVotingProfile::active()
            ->where('governing_body', $governingBody)
            ->when($committeeId, fn ($q) => $q->where('board_committee_id', $committeeId), fn ($q) => $q->whereNull('board_committee_id'))
            ->latest('id')
            ->first();
    }

    /**
     * Whether board voting can open for this body right now: an active
     * profile whose approval has been recorded.
     */
    public function votingIsSwitchedOn(string $governingBody = 'board', ?int $committeeId = null): bool
    {
        return (bool) $this->getActiveProfile($governingBody, $committeeId)?->isConfirmed();
    }

    /**
     * Get the latest profile or create a draft candidate default profile if none exists.
     */
    public function getOrCreateCandidateDefault(string $governingBody = 'board', ?int $committeeId = null): GovernanceVotingProfile
    {
        $existing = GovernanceVotingProfile::where('governing_body', $governingBody)
            ->when($committeeId, fn ($q) => $q->where('board_committee_id', $committeeId), fn ($q) => $q->whereNull('board_committee_id'))
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return GovernanceVotingProfile::create(GovernanceVotingProfile::candidateDefaults($governingBody, $committeeId));
    }

    /**
     * Create a new draft or proposed voting profile.
     */
    public function createProfile(array $attributes, User $creator): GovernanceVotingProfile
    {
        return GovernanceVotingProfile::create([
            ...GovernanceVotingProfile::candidateDefaults($attributes['governing_body'] ?? 'board', $attributes['board_committee_id'] ?? null),
            ...$attributes,
            'is_active' => false,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * True once voting rules for this governing body have ever been switched
     * on — any profile, current or replaced, whose approval was recorded (by
     * a passed resolution or the recorded board approval). A profile merely
     * flagged active without approval never allowed voting, so it doesn't
     * count. The first-time "record the board's approval" path is only
     * available before that.
     */
    public function hasEverBeenActive(string $governingBody = 'board', ?int $committeeId = null): bool
    {
        return GovernanceVotingProfile::query()
            ->where('governing_body', $governingBody)
            ->when($committeeId, fn ($q) => $q->where('board_committee_id', $committeeId), fn ($q) => $q->whereNull('board_committee_id'))
            ->where(function ($q) {
                $q->whereNotNull('approved_at')
                    ->orWhereNotNull('approved_by_resolution_id');
            })
            ->exists();
    }

    /**
     * Activate a voting profile.
     *
     * Rejects activation without an actual governing document reference and
     * explicit approval authority. A carried resolution only authorises the
     * exact profile, governing body, governing document and rule revision it
     * was bound to while it was a draft paper (see
     * GovernanceResolutionAuthorityService); motion wording never confers
     * authority. The binding is verified under row locks and consumed once.
     */
    public function activateProfile(
        GovernanceVotingProfile $profile,
        User $user,
        ?Resolution $approvedByResolution = null,
        ?string $documentReference = null,
        ?string $documentVersion = null
    ): GovernanceVotingProfile {
        $reference = $documentReference ?? $profile->governing_document_reference;

        if (! $this->isRealDocumentReference($reference)) {
            throw new \InvalidArgumentException(
                "The voting rules can't be switched on yet: enter the name of your actual governing document, such as your trust deed or constitution."
            );
        }

        $authority = app(GovernanceResolutionAuthorityService::class);

        $activated = DB::transaction(function () use ($profile, $user, $approvedByResolution, $documentReference, $documentVersion, $authority): GovernanceVotingProfile {
            $lockedProfile = GovernanceVotingProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $reference = $documentReference ?? $lockedProfile->governing_document_reference;
            $version = $documentVersion ?? $lockedProfile->governing_document_version;
            $lockedResolution = null;

            if ($approvedByResolution) {
                $lockedResolution = Resolution::query()->whereKey($approvedByResolution->getKey())->lockForUpdate()->first();

                if (
                    ! $lockedResolution
                    || ! $lockedResolution->isCarried()
                    || ! in_array($lockedResolution->status, ['closed', 'implemented', 'archived'], true)
                ) {
                    throw new \InvalidArgumentException(
                        "These voting rules can't be switched on: the resolution you chose hasn't passed."
                    );
                }

                try {
                    $authority->assertBodyMayApproveProfile($lockedResolution, $lockedProfile);
                    $authority->verifyAndConsume(
                        $lockedResolution,
                        GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
                        (int) $lockedProfile->getKey(),
                        $authority->votingProfileTerms($lockedProfile, $reference, $version),
                        (int) $user->getKey(),
                    );
                } catch (\DomainException $exception) {
                    // The authority service's message already says what's wrong
                    // and what to do, in plain words.
                    throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
                }
            } elseif (empty($lockedProfile->approved_at) && empty($lockedProfile->approved_by_resolution_id)) {
                throw new \InvalidArgumentException(
                    "These voting rules can't be switched on yet: the board's approval hasn't been recorded. Record the board's approval, or choose a resolution that passed and approves these rules."
                );
            } elseif (
                trim((string) $reference) !== trim((string) $lockedProfile->governing_document_reference)
                || trim((string) $version) !== trim((string) $lockedProfile->governing_document_version)
            ) {
                throw new \InvalidArgumentException(
                    'These voting rules were approved under a different governing document. To use them with this document, the board needs to pass a resolution that approves them.'
                );
            }

            $this->deactivateOthers($lockedProfile);

            $lockedProfile->update([
                'is_active' => true,
                'governing_document_reference' => $reference,
                'governing_document_version' => $version,
                'approved_by_resolution_id' => $lockedResolution?->id ?? $lockedProfile->approved_by_resolution_id,
                'approved_by_user_id' => $user->id,
                'approved_at' => $lockedProfile->approved_at ?? now(),
                'approval_source' => $lockedResolution
                    ? self::APPROVAL_SOURCE_RESOLUTION
                    : ($lockedProfile->approval_source ?? self::APPROVAL_SOURCE_RESOLUTION),
                'effective_from' => now(),
                'effective_to' => null,
            ]);

            return $lockedProfile->fresh();
        }, 3);

        $profile->refresh();

        return $activated;
    }

    /**
     * First-time switch-on (owner decision, GOV plain-language audit P0-4):
     * the chair or secretary records the board's EXISTING approval of its
     * voting rules — the governing document, the date the board approved
     * them and the minutes reference (optionally linked to the meeting) —
     * which activates the profile.
     *
     * Only allowed while no voting rules have ever been switched on for this
     * governing body. After that, changing the rules needs a passed
     * resolution linked to the new rules (activateProfile()).
     *
     * @param  array{governing_document_reference: string, governing_document_version?: ?string, approved_on: CarbonInterface, approval_minutes_reference: string, approval_meeting_id?: ?int}  $record
     */
    public function recordBoardApproval(GovernanceVotingProfile $profile, User $user, array $record): GovernanceVotingProfile
    {
        $reference = trim((string) ($record['governing_document_reference'] ?? ''));
        $version = trim((string) ($record['governing_document_version'] ?? '')) ?: null;
        $minutes = trim((string) ($record['approval_minutes_reference'] ?? ''));
        $approvedOn = $record['approved_on'] ?? null;
        $meetingId = isset($record['approval_meeting_id']) && $record['approval_meeting_id'] !== null
            ? (int) $record['approval_meeting_id']
            : null;

        if (! $this->isRealDocumentReference($reference)) {
            throw new \InvalidArgumentException('Enter the name of your governing document, such as your trust deed or constitution.');
        }

        if (! $approvedOn instanceof CarbonInterface) {
            throw new \InvalidArgumentException('Enter the date the board approved these voting rules.');
        }

        if ($approvedOn->isFuture()) {
            throw new \InvalidArgumentException("The date the board approved these voting rules can't be in the future.");
        }

        if ($minutes === '') {
            throw new \InvalidArgumentException('Enter where the approval is recorded, such as the minutes of the meeting.');
        }

        if ($meetingId !== null && ! GovernanceMeeting::query()->whereKey($meetingId)->exists()) {
            throw new \InvalidArgumentException('The meeting you chose no longer exists.');
        }

        $activated = DB::transaction(function () use ($profile, $user, $reference, $version, $minutes, $approvedOn, $meetingId): GovernanceVotingProfile {
            $lockedProfile = GovernanceVotingProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            // Lock every profile for this body so two people can't both
            // record a first approval at the same time.
            GovernanceVotingProfile::query()
                ->where('governing_body', $lockedProfile->governing_body)
                ->when(
                    $lockedProfile->board_committee_id,
                    fn ($q) => $q->where('board_committee_id', $lockedProfile->board_committee_id),
                    fn ($q) => $q->whereNull('board_committee_id')
                )
                ->lockForUpdate()
                ->get(['id']);

            if ($this->hasEverBeenActive($lockedProfile->governing_body, $lockedProfile->board_committee_id ? (int) $lockedProfile->board_committee_id : null)) {
                throw new \InvalidArgumentException(
                    'Voting rules have already been switched on for this board. To change them, the board needs to pass a resolution that approves the new rules.'
                );
            }

            $this->deactivateOthers($lockedProfile);

            $lockedProfile->update([
                'is_active' => true,
                'governing_document_reference' => $reference,
                'governing_document_version' => $version,
                'approved_by_resolution_id' => null,
                'approved_by_user_id' => $user->id,
                'approved_at' => $approvedOn->copy()->utc(),
                'approval_source' => self::APPROVAL_SOURCE_RECORDED,
                'approval_minutes_reference' => $minutes,
                'approval_meeting_id' => $meetingId,
                'effective_from' => now(),
                'effective_to' => null,
            ]);

            GovernanceAuditService::log('governance_rules.activated', 'GovernanceVotingProfile', (int) $lockedProfile->getKey(), [
                'method' => self::APPROVAL_SOURCE_RECORDED,
                'recorded_by' => $user->id,
                'document_reference' => $reference,
                'document_version' => $version,
                'approved_on' => $approvedOn->copy()->utc()->toIso8601String(),
                'minutes_reference' => $minutes,
                'meeting_id' => $meetingId,
            ]);

            return $lockedProfile->fresh();
        }, 3);

        $profile->refresh();

        return $activated;
    }

    /**
     * Calculate required quorum from entitled electorate count.
     * Supports majority_floor_plus_one, percentage, and fixed_count formulas.
     * When N=0, returns 0.
     */
    public function calculateQuorumRequired(int $totalEligible, ?GovernanceVotingProfile $profile = null): int
    {
        if ($totalEligible <= 0) {
            return 0;
        }

        if ($profile && $profile->quorum_mode === 'fixed_count' && is_numeric($profile->quorum_formula)) {
            return min($totalEligible, max(1, (int) $profile->quorum_formula));
        }

        if ($profile && $profile->quorum_mode === 'percentage' && is_numeric(rtrim($profile->quorum_formula, '%'))) {
            $pct = (float) rtrim($profile->quorum_formula, '%');
            return max(1, (int) ceil(($totalEligible * $pct) / 100));
        }

        return (int) floor($totalEligible / 2) + 1;
    }

    private function isRealDocumentReference(?string $reference): bool
    {
        $reference = strtolower(trim((string) $reference));

        return $reference !== '' && ! str_contains($reference, 'candidate') && ! str_contains($reference, 'pending');
    }

    /** Deactivate any currently active profile for the same body / committee. */
    private function deactivateOthers(GovernanceVotingProfile $lockedProfile): void
    {
        GovernanceVotingProfile::where('governing_body', $lockedProfile->governing_body)
            ->when(
                $lockedProfile->board_committee_id,
                fn ($q) => $q->where('board_committee_id', $lockedProfile->board_committee_id),
                fn ($q) => $q->whereNull('board_committee_id')
            )
            ->where('id', '!=', $lockedProfile->id)
            ->update(['is_active' => false, 'effective_to' => now()]);
    }
}
