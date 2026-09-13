<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
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
     * Rejects activation without explicit governing document authority and approval evidence.
     */
    public function activateProfile(
        GovernanceVotingProfile $profile,
        User $user,
        ?Resolution $approvedByResolution = null,
        ?string $documentReference = null,
        ?string $documentVersion = null
    ): GovernanceVotingProfile {
        $reference = $documentReference ?? $profile->governing_document_reference;
        $version = $documentVersion ?? $profile->governing_document_version;

        if (empty($reference) || str_contains(strtolower($reference), 'candidate') || str_contains(strtolower($reference), 'pending')) {
            throw new \InvalidArgumentException(
                'Profile activation rejected: an actual governing document reference (e.g. constitution or trust deed) is required for live activation.'
            );
        }

        if ($approvedByResolution) {
            if (! $approvedByResolution->isCarried()) {
                throw new \InvalidArgumentException(
                    'Profile activation rejected: approval resolution must be carried.'
                );
            }

            // The resolution must actually authorize voting rules or the governance profile
            $resText = strtolower($approvedByResolution->title . ' ' . ($approvedByResolution->exact_motion ?? '') . ' ' . ($approvedByResolution->purpose ?? ''));
            $docRef = strtolower($reference);
            $isUnrelated = str_contains($resText, 'catering')
                || str_contains($resText, 'hospitality')
                || str_contains($resText, 'dinner')
                || str_contains($resText, 'lunch')
                || str_contains($resText, 'event');

            $hasAuthorityMatch = ! $isUnrelated && (
                str_contains($resText, 'voting')
                || str_contains($resText, 'rules')
                || str_contains($resText, 'constitution')
                || str_contains($resText, 'charter')
                || str_contains($resText, 'standing orders')
                || str_contains($resText, 'governance profile')
                || str_contains($resText, 'profile')
                || str_contains($resText, 'resolution')
                || (! empty($docRef) && str_contains($resText, $docRef))
            );

            if (! $hasAuthorityMatch) {
                throw new \InvalidArgumentException(
                    'Profile activation rejected: resolution does not authorize voting rules or governance profile approval.'
                );
            }
        } elseif (empty($profile->approved_at) && empty($profile->approved_by_resolution_id)) {
            throw new \InvalidArgumentException(
                'Profile activation rejected: approval authority evidence (carried resolution or formal approval record) is required.'
            );
        }

        // Deactivate any currently active profile for the same body / committee
        GovernanceVotingProfile::where('governing_body', $profile->governing_body)
            ->when(
                $profile->board_committee_id,
                fn ($q) => $q->where('board_committee_id', $profile->board_committee_id),
                fn ($q) => $q->whereNull('board_committee_id')
            )
            ->where('id', '!=', $profile->id)
            ->update(['is_active' => false, 'effective_to' => now()]);

        $profile->update([
            'is_active' => true,
            'governing_document_reference' => $reference,
            'governing_document_version' => $version,
            'approved_by_resolution_id' => $approvedByResolution?->id ?? $profile->approved_by_resolution_id,
            'approved_by_user_id' => $user->id,
            'approved_at' => $profile->approved_at ?? now(),
            'effective_from' => now(),
            'effective_to' => null,
        ]);

        return $profile->fresh();
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

        $formula = $profile?->quorum_mode ?? $profile?->quorum_formula ?? 'majority_floor_plus_one';

        return match ($formula) {
            'majority_floor_plus_one' => (int) floor($totalEligible / 2) + 1,
            'percentage' => max(1, (int) ceil(($totalEligible * ($profile->quorum_percentage ?? 50)) / 100)),
            'fixed_count' => min($totalEligible, max(1, (int) ($profile->quorum_fixed_count ?? ceil($totalEligible / 2)))),
            default => (int) floor($totalEligible / 2) + 1,
        };
    }
}
