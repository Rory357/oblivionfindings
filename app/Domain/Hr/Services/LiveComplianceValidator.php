<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Live validation of hard-stop compliance requirements against source records.
 *
 * This supplements the cached compliance status (refreshed nightly) by
 * re-checking live source data for hard-stop requirements at assignment time.
 * It does NOT replace the nightly evaluation — it adds a real-time safety net
 * for the highest-severity requirements only.
 *
 * Performance: loads only hard-stop requirements applicable to the user's roles,
 * then batch-loads the relevant source records in a single query per check_type.
 */
class LiveComplianceValidator
{
    /**
     * Validate all hard-stop requirements for a user against live source data.
     *
     * @return array{passed: bool, failures: array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>}
     */
    public function validateHardStops(User $user): array
    {
        $requirements = $this->getHardStopRequirements($user);

        if ($requirements->isEmpty()) {
            return ['passed' => true, 'failures' => []];
        }

        $failures = [];

        // Group requirements by check_type to batch source record lookups.
        $grouped = $requirements->groupBy('check_type');

        foreach ($grouped as $checkType => $reqs) {
            $typeFailures = match ($checkType) {
                'training_course' => $this->validateTrainingRequirements($user, $reqs),
                'credential' => $this->validateCredentialRequirements($user, $reqs),
                'background_check' => $this->validateBackgroundCheckRequirements($user, $reqs),
                'policy_attestation' => $this->validateAttestationRequirements($user, $reqs),
                'manual' => $this->validateManualRequirements($user, $reqs),
                default => [],
            };

            $failures = array_merge($failures, $typeFailures);
        }

        return [
            'passed' => empty($failures),
            'failures' => $failures,
        ];
    }

    /**
     * Get all active hard-stop requirements applicable to the user's roles.
     */
    protected function getHardStopRequirements(User $user): Collection
    {
        $roles = $user->roles->pluck('name')->toArray();

        if (empty($roles)) {
            return collect();
        }

        return HrComplianceRequirement::query()
            ->when($user->tenant_id !== null, fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->where('is_active', true)
            ->where('hard_stop', true)
            ->whereHas('matrixEntries', fn ($q) => $q->whereIn('role', $roles))
            ->get();
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateTrainingRequirements(User $user, Collection $requirements): array
    {
        $referenceIds = $requirements->pluck('reference_id')->filter()->unique()->values()->all();

        // Batch load: best completed record per training course.
        $records = $user->staffTrainingRecords()
            ->whereIn('training_course_id', $referenceIds)
            ->whereIn('status', ['completed', 'passed'])
            ->orderByDesc('completed_at')
            ->get()
            ->keyBy('training_course_id');

        $failures = [];

        foreach ($requirements as $req) {
            $record = $records->get($req->reference_id);

            if (! $record) {
                $failures[] = $this->failure($req, "{$req->name} training is missing or not completed.");
                continue;
            }

            if ($req->validity_months && $record->completed_at) {
                $expiresAt = $record->completed_at->copy()->addMonths($req->validity_months);
                if ($expiresAt->isPast()) {
                    $failures[] = $this->failure($req, "{$req->name} training expired on {$expiresAt->format('j M Y')}.", $expiresAt);
                }
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateCredentialRequirements(User $user, Collection $requirements): array
    {
        $codes = $requirements->pluck('code')->filter()->unique()->values()->all();

        // Batch load: latest credential per type.
        $credentials = $user->staffCredentials()
            ->whereIn('type', $codes)
            ->orderByDesc('issued_at')
            ->get()
            ->keyBy('type');

        $failures = [];

        foreach ($requirements as $req) {
            $credential = $credentials->get($req->code);

            if (! $credential) {
                $failures[] = $this->failure($req, "{$req->name} credential is missing.");
                continue;
            }

            if ($credential->expires_at && $credential->expires_at->isPast()) {
                $failures[] = $this->failure($req, "{$req->name} expired on {$credential->expires_at->format('j M Y')}.", $credential->expires_at);
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateBackgroundCheckRequirements(User $user, Collection $requirements): array
    {
        // Background checks match on check_type='police_check' with status='clear'/'cleared'.
        // Source column is check_date (not completed_at).
        $check = $user->staffBackgroundChecks()
            ->where('check_type', 'police_check')
            ->whereIn('status', ['clear', 'cleared'])
            ->orderByDesc('check_date')
            ->first();

        $failures = [];

        foreach ($requirements as $req) {
            if (! $check) {
                $failures[] = $this->failure($req, "{$req->name} is missing or not cleared.");
                continue;
            }

            $checkDate = $check->check_date ?? $check->issue_date;

            if ($req->validity_months && $checkDate) {
                $expiresAt = $checkDate->copy()->addMonths($req->validity_months);
                if ($expiresAt->isPast()) {
                    $failures[] = $this->failure($req, "{$req->name} expired on {$expiresAt->format('j M Y')}.", $expiresAt);
                }
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateAttestationRequirements(User $user, Collection $requirements): array
    {
        $policyIds = $requirements->pluck('reference_id')->filter()->unique()->values()->all();

        // Batch load: latest attestation per policy with current version.
        $attestations = HrPolicyAttestation::where('user_id', $user->id)
            ->whereIn('policy_id', $policyIds)
            ->whereHas('policyVersion', fn ($q) => $q->where('is_current', true))
            ->orderByDesc('attested_at')
            ->get()
            ->keyBy('policy_id');

        $failures = [];

        foreach ($requirements as $req) {
            $attestation = $attestations->get($req->reference_id);

            if (! $attestation) {
                $failures[] = $this->failure($req, "{$req->name} policy attestation is missing or outdated.");
                continue;
            }

            if ($req->validity_months && $attestation->attested_at) {
                $expiresAt = $attestation->attested_at->copy()->addMonths($req->validity_months);
                if ($expiresAt->isPast()) {
                    $failures[] = $this->failure($req, "{$req->name} attestation expired on {$expiresAt->format('j M Y')}.", $expiresAt);
                }
            }
        }

        return $failures;
    }

    /**
     * Manual requirements rely on the cached status record — no live source to check.
     *
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateManualRequirements(User $user, Collection $requirements): array
    {
        $statuses = HrStaffComplianceStatus::where('user_id', $user->id)
            ->whereIn('requirement_id', $requirements->pluck('id'))
            ->where('evidence_type', 'manual')
            ->get()
            ->keyBy('requirement_id');

        $failures = [];

        foreach ($requirements as $req) {
            $status = $statuses->get($req->id);

            if (! $status || $status->status !== 'compliant') {
                $failures[] = $this->failure($req, "{$req->name} has not been manually verified.");
            }
        }

        return $failures;
    }

    /**
     * @return array{requirement: string, code: string, reason: string, expires_at: string|null}
     */
    protected function failure(HrComplianceRequirement $req, string $reason, ?Carbon $expiresAt = null): array
    {
        return [
            'requirement' => $req->name,
            'code' => $req->code,
            'reason' => $reason,
            'expires_at' => $expiresAt?->toDateString(),
        ];
    }
}
