<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\User;
use App\Models\Shift;
use Illuminate\Support\Collection;

class ComplianceMatrixService
{
    /**
     * Evaluate all active employees against compliance matrix.
     */
    public function evaluateAllStaff(?int $tenantId): int
    {
        $employees = User::staff()
            ->where('tenant_id', $tenantId)
            ->whereHas('staffProfile', fn($q) => $q->where('is_active', true))
            ->get();

        $count = 0;
        foreach ($employees as $user) {
            $this->evaluateStaff($user);
            $count++;
        }
        return $count;
    }

    /**
     * Evaluate a single staff member against the compliance matrix.
     */
    public function evaluateStaff(User $user): void
    {
        $requirements = $this->getApplicableRequirements($user);

        foreach ($requirements as $requirement) {
            $status = $this->checkRequirement($user, $requirement);

            HrStaffComplianceStatus::updateOrCreate(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'requirement_id' => $requirement->id,
                ],
                [
                    'status' => $status['status'],
                    'evidence_type' => $status['evidence_type'],
                    'evidence_id' => $status['evidence_id'],
                    'valid_from' => $status['valid_from'],
                    'expires_at' => $status['expires_at'],
                    'last_checked_at' => now(),
                    'next_check_at' => now()->addDay(),
                ]
            );
        }
    }

    /**
     * Check if a user can be assigned to a shift (real-time).
     */
    public function canAssignToShift(User $user, ?Shift $shift = null): array
    {
        $hardStopFailures = $this->getHardStopFailures($user);
        if ($hardStopFailures->isNotEmpty()) {
            return [
                'allowed' => false,
                'blocked' => true,
                'failures' => $hardStopFailures->toArray(),
                'warnings' => [],
            ];
        }

        $warnings = $this->getSoftWarnings($user);
        return [
            'allowed' => true,
            'blocked' => false,
            'failures' => [],
            'warnings' => $warnings->toArray(),
        ];
    }

    /**
     * Get hard-stop compliance failures for a user.
     */
    public function getHardStopFailures(User $user): Collection
    {
        return HrStaffComplianceStatus::where('user_id', $user->id)
            ->whereIn('status', ['expired', 'not_started'])
            ->whereHas('requirement', fn($q) => $q->where('hard_stop', true)->where('is_active', true))
            ->with('requirement:id,code,name')
            ->get()
            ->map(fn($s) => [
                'requirement' => $s->requirement->name,
                'code' => $s->requirement->code,
                'status' => $s->status,
                'expires_at' => $s->expires_at?->toDateString(),
            ]);
    }

    /**
     * Get soft warnings for a user (non-blocking).
     */
    public function getSoftWarnings(User $user): Collection
    {
        return HrStaffComplianceStatus::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('status', 'expiring_soon')
                  ->orWhere(function ($q2) {
                      $q2->whereIn('status', ['expired', 'not_started'])
                          ->whereHas('requirement', fn($q3) => $q3->where('hard_stop', false));
                  });
            })
            ->with('requirement:id,code,name')
            ->get()
            ->map(fn($s) => [
                'requirement' => $s->requirement->name,
                'code' => $s->requirement->code,
                'status' => $s->status,
                'expires_at' => $s->expires_at?->toDateString(),
            ]);
    }

    /**
     * Get compliance summary for a site.
     */
    public function getComplianceSummary(?int $tenantId, ?int $siteId = null): array
    {
        $query = HrStaffComplianceStatus::where('tenant_id', $tenantId);

        return [
            'compliant' => (clone $query)->where('status', 'compliant')->count(),
            'expiring_soon' => (clone $query)->where('status', 'expiring_soon')->count(),
            'expired' => (clone $query)->where('status', 'expired')->count(),
            'not_started' => (clone $query)->where('status', 'not_started')->count(),
        ];
    }

    protected function getApplicableRequirements(User $user): Collection
    {
        $roles = $user->roles->pluck('name')->toArray();

        return HrComplianceRequirement::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereHas('matrixEntries', function ($q) use ($roles) {
                $q->whereIn('role', $roles);
            })
            ->get();
    }

    protected function checkRequirement(User $user, HrComplianceRequirement $requirement): array
    {
        $result = [
            'status' => 'not_started',
            'evidence_type' => null,
            'evidence_id' => null,
            'valid_from' => null,
            'expires_at' => null,
        ];

        switch ($requirement->check_type) {
            case 'training_course':
                $record = $user->staffTrainingRecords()
                    ->where('training_course_id', $requirement->reference_id)
                    ->where('status', 'completed')
                    ->orderByDesc('completed_at')
                    ->first();

                if ($record) {
                    $result['evidence_type'] = 'training_record';
                    $result['evidence_id'] = $record->id;
                    $result['valid_from'] = $record->completed_at?->toDateString();

                    if ($requirement->validity_months) {
                        $expiresAt = $record->completed_at->addMonths($requirement->validity_months);
                        $result['expires_at'] = $expiresAt->toDateString();

                        if ($expiresAt->isPast()) {
                            $result['status'] = 'expired';
                        } elseif ($expiresAt->diffInDays(now()) <= $requirement->renewal_reminder_days) {
                            $result['status'] = 'expiring_soon';
                        } else {
                            $result['status'] = 'compliant';
                        }
                    } else {
                        $result['status'] = 'compliant';
                    }
                }
                break;

            case 'credential':
                $credential = $user->staffCredentials()
                    ->where('type', $requirement->code)
                    ->orderByDesc('issued_at')
                    ->first();

                if ($credential) {
                    $result['evidence_type'] = 'credential';
                    $result['evidence_id'] = $credential->id;
                    $result['valid_from'] = $credential->issued_at?->toDateString();
                    $result['expires_at'] = $credential->expires_at?->toDateString();

                    if ($credential->expires_at && $credential->expires_at->isPast()) {
                        $result['status'] = 'expired';
                    } elseif ($credential->expires_at && $credential->expires_at->diffInDays(now()) <= $requirement->renewal_reminder_days) {
                        $result['status'] = 'expiring_soon';
                    } else {
                        $result['status'] = 'compliant';
                    }
                }
                break;

            case 'background_check':
                $check = $user->staffBackgroundChecks()
                    ->where('check_type', 'police_check')
                    ->where('status', 'cleared')
                    ->orderByDesc('completed_at')
                    ->first();

                if ($check) {
                    $result['evidence_type'] = 'background_check';
                    $result['evidence_id'] = $check->id;
                    $result['valid_from'] = $check->completed_at?->toDateString();

                    if ($requirement->validity_months && $check->completed_at) {
                        $expiresAt = $check->completed_at->addMonths($requirement->validity_months);
                        $result['expires_at'] = $expiresAt->toDateString();

                        if ($expiresAt->isPast()) {
                            $result['status'] = 'expired';
                        } elseif ($expiresAt->diffInDays(now()) <= $requirement->renewal_reminder_days) {
                            $result['status'] = 'expiring_soon';
                        } else {
                            $result['status'] = 'compliant';
                        }
                    } else {
                        $result['status'] = 'compliant';
                    }
                }
                break;

            case 'policy_attestation':
                $attestation = HrPolicyAttestation::where('user_id', $user->id)
                    ->where('policy_id', $requirement->reference_id)
                    ->whereHas('policyVersion', fn($q) => $q->where('is_current', true))
                    ->orderByDesc('attested_at')
                    ->first();

                if ($attestation) {
                    $result['evidence_type'] = 'attestation';
                    $result['evidence_id'] = $attestation->id;
                    $result['valid_from'] = $attestation->attested_at?->toDateString();
                    $result['status'] = 'compliant';

                    if ($requirement->validity_months) {
                        $expiresAt = $attestation->attested_at->addMonths($requirement->validity_months);
                        $result['expires_at'] = $expiresAt->toDateString();

                        if ($expiresAt->isPast()) {
                            $result['status'] = 'expired';
                        } elseif ($expiresAt->diffInDays(now()) <= $requirement->renewal_reminder_days) {
                            $result['status'] = 'expiring_soon';
                        }
                    }
                }
                break;

            case 'manual':
                $existing = HrStaffComplianceStatus::where('user_id', $user->id)
                    ->where('requirement_id', $requirement->id)
                    ->where('evidence_type', 'manual')
                    ->first();

                if ($existing && $existing->status === 'compliant') {
                    return [
                        'status' => $existing->status,
                        'evidence_type' => 'manual',
                        'evidence_id' => $existing->evidence_id,
                        'valid_from' => $existing->valid_from?->toDateString(),
                        'expires_at' => $existing->expires_at?->toDateString(),
                    ];
                }
                break;
        }

        return $result;
    }
}
