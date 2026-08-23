<?php

namespace App\Domain\Hr\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Models\Client;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Defines the one compatible-link policy for HR employee intake.
 *
 * An active employee profile may be explicitly updated in place. Converting a
 * profileless login into a staff identity is a merge and therefore requires a
 * distinct offer creator/approver plus the candidate's signed acceptance.
 * Portal, client, family, board, privileged, and former-employee identities
 * are never repurposed by this path.
 */
final class EmployeeIdentityLinkPolicy
{
    private const EXTERNAL_PERSONA_ROLES = ['client', 'next_of_kin'];

    private const ADMIN_LEVEL_THRESHOLD = 100;

    /**
     * @return array<string, bool|int|string|null>
     */
    public function authorize(
        User $user,
        ?HrEmployeeProfile $profile,
        string $roleName,
        ?int $identityLinkOfferId,
        int $primarySiteId,
    ): array {
        $this->assertNoIncompatibleAccountKind($user);
        $this->assertRoleCompatible($user, $roleName);

        if ($profile) {
            if ($profile->trashed() || ! $profile->is_active) {
                throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
            }

            if ($identityLinkOfferId !== null) {
                if ((int) $profile->offer_id !== $identityLinkOfferId || ! $profile->candidate_id) {
                    throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
                }

                return [
                    'identity_link_policy' => 'existing_recruitment_identity_replay',
                    'identity_link_profile_id' => (int) $profile->id,
                    'identity_link_offer_id' => (int) $profile->offer_id,
                    'identity_link_candidate_id' => (int) $profile->candidate_id,
                    'identity_link_requested_by' => null,
                    'identity_link_approved_by' => null,
                    'identity_link_approved_at' => null,
                    'identity_link_candidate_signed_at' => null,
                ];
            }

            return [
                'identity_link_policy' => 'existing_active_employee_profile',
                'identity_link_profile_id' => (int) $profile->id,
                'identity_link_offer_id' => null,
                'identity_link_candidate_id' => null,
                'identity_link_requested_by' => null,
                'identity_link_approved_by' => null,
                'identity_link_approved_at' => null,
                'identity_link_candidate_signed_at' => null,
            ];
        }

        return $this->acceptedCandidateEvidence(
            $user,
            $roleName,
            $identityLinkOfferId,
            $primarySiteId,
        );
    }

    private function assertNoIncompatibleAccountKind(User $user): void
    {
        $incompatible = $user->permissionOverrides()->exists()
            || $user->portalClients()->withTrashed()->exists()
            || Client::withTrashed()->where('user_id', $user->id)->exists()
            || NextOfKin::withTrashed()->where('user_id', $user->id)->exists()
            || BoardMember::withTrashed()->where('user_id', $user->id)->exists();

        if ($incompatible) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }
    }

    private function assertRoleCompatible(User $user, string $roleName): void
    {
        $roleNames = collect([$user->role, ...$user->roles()->pluck('roles.name')->all()])
            ->filter()
            ->unique();

        if ($roleNames->intersect(self::EXTERNAL_PERSONA_ROLES)->isNotEmpty()) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }

        $hasAdminGradeRole = Role::query()
            ->whereIn('name', $roleNames)
            ->where(function ($query): void {
                $query->where('name', 'admin')
                    ->orWhere('level', '>=', self::ADMIN_LEVEL_THRESHOLD);
            })
            ->exists();
        $unexpectedRoles = $roleNames->reject(fn (string $existingRole) => $existingRole === $roleName);

        if ($hasAdminGradeRole || $unexpectedRoles->isNotEmpty()) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function acceptedCandidateEvidence(
        User $user,
        string $roleName,
        ?int $identityLinkOfferId,
        int $primarySiteId,
    ): array {
        if (! $identityLinkOfferId) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }

        // Pre-read identifiers without treating them as authority, then lock the
        // recruitment graph in one deterministic candidate -> application ->
        // offer order and revalidate every relationship and decision below.
        $offerPreflight = HrOffer::query()->find($identityLinkOfferId, ['id', 'application_id']);
        $applicationPreflight = $offerPreflight
            ? HrApplication::query()->find($offerPreflight->application_id, ['id', 'candidate_id'])
            : null;
        if (! $offerPreflight || ! $applicationPreflight) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }

        $candidate = HrCandidate::query()
            ->whereKey($applicationPreflight->candidate_id)
            ->lockForUpdate()
            ->first();
        $application = HrApplication::query()
            ->whereKey($applicationPreflight->id)
            ->lockForUpdate()
            ->first();
        $offer = HrOffer::query()
            ->whereKey($offerPreflight->id)
            ->lockForUpdate()
            ->first();

        $candidateEmail = $candidate ? Str::lower(trim((string) $candidate->personal_email)) : '';
        $accountEmail = Str::lower(trim((string) $user->email));
        $candidateName = $candidate ? $this->normalName($candidate->full_name) : '';
        $signedName = $offer ? $this->normalName($offer->signed_full_name) : '';
        $expectedRole = $offer ? (string) ($offer->position_role ?: 'support_worker') : '';

        $valid = $candidate
            && $application
            && $offer
            && (int) $application->candidate_id === (int) $candidate->id
            && (int) $offer->application_id === (int) $application->id
            && in_array($candidate->status, ['offer_accepted', 'onboarding', 'hired'], true)
            && in_array($application->status, ['offer_accepted', 'onboarding', 'hired'], true)
            && $offer->approval_status === 'approved'
            && $offer->approved_at !== null
            && $offer->approved_by !== null
            && $offer->created_by !== null
            && (int) $offer->approved_by !== (int) $offer->created_by
            && $offer->sent_at !== null
            && $offer->response === 'accepted'
            && $offer->response_at !== null
            && $offer->signed_at !== null
            && $offer->approved_at->lte($offer->sent_at)
            && $offer->sent_at->lte($offer->response_at)
            && $offer->response_at->lte($offer->signed_at)
            && $candidateName !== ''
            && $signedName === $candidateName
            && $candidateEmail !== ''
            && $accountEmail === $candidateEmail
            && (int) $application->target_site_id === $primarySiteId
            && (int) $offer->primary_site_id === $primarySiteId
            && (string) ($application->position_role ?: 'support_worker') === $expectedRole
            && $expectedRole === $roleName;

        if (! $valid) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }

        return [
            'identity_link_policy' => 'accepted_candidate_two_person_evidence',
            'identity_link_profile_id' => null,
            'identity_link_offer_id' => (int) $offer->id,
            'identity_link_candidate_id' => (int) $candidate->id,
            'identity_link_requested_by' => (int) $offer->created_by,
            'identity_link_approved_by' => (int) $offer->approved_by,
            'identity_link_approved_at' => $offer->approved_at->toIso8601String(),
            'identity_link_candidate_signed_at' => $offer->signed_at->toIso8601String(),
        ];
    }

    private function normalName(?string $name): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim((string) $name)));
    }
}
