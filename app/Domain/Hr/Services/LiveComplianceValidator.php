<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
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
    public function __construct(
        private ?ComplianceRequirementApplicabilityService $applicability = null,
    ) {}

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

        // A manager-approved exemption is an explicit, audited override of a
        // hard-stop. Apply it before live source checks so the shift decision
        // matches the compliance worklist and the promise made by the waiver UI.
        $exemptRequirementIds = HrStaffComplianceStatus::query()
            ->where('user_id', $user->id)
            ->whereIn('requirement_id', $requirements->pluck('id'))
            ->whereNotNull('exemption_reason')
            ->whereNotNull('exempted_at')
            ->where(function ($query): void {
                $query->whereNull('exempted_until')
                    ->orWhere('exempted_until', '>', now());
            })
            ->pluck('requirement_id');
        $requirements = $requirements
            ->reject(fn (HrComplianceRequirement $requirement) => $exemptRequirementIds->contains($requirement->id))
            ->values();

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
                'driver_licence' => $this->validateDriverLicenceRequirements($user, $reqs),
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
        return $this->applicability()->forUser($user, true);
    }

    private function applicability(): ComplianceRequirementApplicabilityService
    {
        return $this->applicability ??= app(ComplianceRequirementApplicabilityService::class);
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateTrainingRequirements(User $user, Collection $requirements): array
    {
        $legacyRefs = $requirements->pluck('reference_id')->filter()->unique()->values()->all();

        // Resolve canonical HrCourse ids for these requirements in one query
        // (requirement id → hr_course_id) via the HrCourse back-link.
        $hrCourseByReq = HrCourse::query()
            ->whereIn('compliance_requirement_id', $requirements->pluck('id')->all() ?: [0])
            ->pluck('id', 'compliance_requirement_id');
        $hrCourseIds = $hrCourseByReq->values()->all();

        // Batch load completed records matching EITHER link (canonical or legacy),
        // most-recent first. Matching either guarantees no wrongful shift block.
        $records = collect();
        if ($legacyRefs || $hrCourseIds) {
            $records = $user->staffTrainingRecords()
                ->whereIn('status', ['completed', 'passed'])
                ->where(function ($q) use ($legacyRefs, $hrCourseIds) {
                    if ($legacyRefs) {
                        $q->orWhereIn('training_course_id', $legacyRefs);
                    }
                    if ($hrCourseIds) {
                        $q->orWhereIn('hr_course_id', $hrCourseIds);
                    }
                })
                ->orderByDesc('completed_at')
                ->get();
        }

        $failures = [];

        foreach ($requirements as $req) {
            $hrCourseId = $hrCourseByReq->get($req->id);
            $record = $records->first(fn ($r) => ($hrCourseId && (int) $r->hr_course_id === (int) $hrCourseId)
                || ($req->reference_id && (int) $r->training_course_id === (int) $req->reference_id));

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
     * Driver-licence requirements validate against the live HrDriverEligibility
     * record: a missing record, a suspended driver, or an expired licence blocks
     * assignment to a driving shift.
     *
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateDriverLicenceRequirements(User $user, Collection $requirements): array
    {
        $record = $user->hrDriverEligibility;
        $failures = [];

        foreach ($requirements as $req) {
            if (! $record) {
                $failures[] = $this->failure($req, "{$req->name}: no driver eligibility record.");

                continue;
            }

            if ($record->status === 'suspended') {
                $failures[] = $this->failure($req, "{$req->name}: driving privileges suspended.");

                continue;
            }

            if ($record->licence_expires_at && $record->licence_expires_at->isPast()) {
                $failures[] = $this->failure($req, "{$req->name} expired on {$record->licence_expires_at->format('j M Y')}.", $record->licence_expires_at);
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
            ->get()
            ->keyBy('requirement_id');

        $failures = [];

        foreach ($requirements as $req) {
            $status = $statuses->get($req->id);

            if (! $status
                || ! in_array($status->status, ['compliant', 'expiring_soon'], true)
                || ($status->evidence_type !== 'manual' && blank($status->exemption_reason))
            ) {
                $failures[] = $this->failure($req, "{$req->name} has not been manually verified.");

                continue;
            }

            if ($status->exempted_until?->isPast()) {
                $failures[] = $this->failure(
                    $req,
                    "{$req->name} exemption expired on {$status->exempted_until->format('j M Y')}.",
                    $status->exempted_until,
                );

                continue;
            }

            if ($status->expires_at?->isPast()) {
                $failures[] = $this->failure(
                    $req,
                    "{$req->name} expired on {$status->expires_at->format('j M Y')}.",
                    $status->expires_at,
                );
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
