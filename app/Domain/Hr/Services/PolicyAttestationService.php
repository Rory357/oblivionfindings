<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PolicyAttestationService
{
    public function __construct(private readonly HrCurrentStaffService $currentStaff) {}

    public function attest(
        User $user,
        HrPolicy|int $policy,
        ?string $ipAddress,
        ?string $userAgent = null,
        string $method = 'checkbox',
    ): HrPolicyAttestation {
        abort_unless($this->currentStaff->isCurrent($user), 404);

        $policyId = $policy instanceof HrPolicy ? $policy->getKey() : $policy;
        $policy = HrPolicy::query()->with('currentVersion')->findOrFail($policyId);

        if (! $policy->is_active) {
            throw ValidationException::withMessages([
                'policy' => 'This policy is archived and cannot be attested.',
            ]);
        }
        if (! $policy->requires_attestation) {
            throw ValidationException::withMessages([
                'policy' => 'This policy does not require attestation.',
            ]);
        }
        if (! $policy->currentVersion) {
            throw ValidationException::withMessages([
                'policy' => 'This policy has no published version to attest.',
            ]);
        }

        $attestation = HrPolicyAttestation::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'policy_version_id' => $policy->currentVersion->id,
            ],
            [
                'policy_id' => $policy->id,
                'attested_at' => now(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 255, ''),
                'attestation_method' => $method,
            ],
        );

        if (! $attestation->wasRecentlyCreated) {
            throw ValidationException::withMessages([
                'policy' => 'You have already attested to this version of the policy.',
            ]);
        }

        return $attestation;
    }
}
