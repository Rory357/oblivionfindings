<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GovernanceVotingProfileService
{
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

        if (empty($reference) || str_contains(strtolower($reference), 'candidate') || str_contains(strtolower($reference), 'pending')) {
            throw new \InvalidArgumentException(
                'Profile activation rejected: an actual governing document reference (e.g. constitution or trust deed) is required for live activation.'
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
                        'Profile activation rejected: approval resolution must be carried.'
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
                    throw new \InvalidArgumentException(
                        'Profile activation rejected: resolution does not authorize this voting rules profile. '.$exception->getMessage(),
                        0,
                        $exception,
                    );
                }
            } elseif (empty($lockedProfile->approved_at) && empty($lockedProfile->approved_by_resolution_id)) {
                throw new \InvalidArgumentException(
                    'Profile activation rejected: approval authority evidence (carried resolution or formal approval record) is required.'
                );
            } elseif (
                trim((string) $reference) !== trim((string) $lockedProfile->governing_document_reference)
                || trim((string) $version) !== trim((string) $lockedProfile->governing_document_version)
            ) {
                throw new \InvalidArgumentException(
                    'Profile activation rejected: a previously approved profile cannot be re-activated against a different governing document without new bound approval authority.'
                );
            }

            // Deactivate any currently active profile for the same body / committee
            GovernanceVotingProfile::where('governing_body', $lockedProfile->governing_body)
                ->when(
                    $lockedProfile->board_committee_id,
                    fn ($q) => $q->where('board_committee_id', $lockedProfile->board_committee_id),
                    fn ($q) => $q->whereNull('board_committee_id')
                )
                ->where('id', '!=', $lockedProfile->id)
                ->update(['is_active' => false, 'effective_to' => now()]);

            $lockedProfile->update([
                'is_active' => true,
                'governing_document_reference' => $reference,
                'governing_document_version' => $version,
                'approved_by_resolution_id' => $lockedResolution?->id ?? $lockedProfile->approved_by_resolution_id,
                'approved_by_user_id' => $user->id,
                'approved_at' => $lockedProfile->approved_at ?? now(),
                'effective_from' => now(),
                'effective_to' => null,
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
}
