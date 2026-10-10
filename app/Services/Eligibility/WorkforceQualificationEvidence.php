<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Shift;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/** Explicit mapped qualification evidence. Does not change global HR matrix policy. */
final class WorkforceQualificationEvidence
{
    public function check(User $user, Collection $requirements, Shift $shift, bool $current = false): array
    {
        $check = function (?CurrentAuthorizationReads $reads) use ($user, $requirements, $shift): array {
            if ($reads) {
                $ids = $requirements->map(function ($requirement): int {
                    if (! $requirement instanceof HrComplianceRequirement || ! $requirement->id) {
                        throw new \RuntimeException('The configured qualification evidence is unavailable.');
                    }

                    return (int) $requirement->id;
                })->unique()->sort()->values();
                $requirements = $reads->query(HrComplianceRequirement::query()->whereIn('id', $ids)->orderBy('id'))->get();
                if ($requirements->count() !== $ids->count()) {
                    throw new \RuntimeException('The configured qualification evidence is unavailable.');
                }
            }
            foreach ($requirements as $requirement) {
                if (! $requirement instanceof HrComplianceRequirement || ! $requirement->is_active
                    || ! in_array($requirement->check_type, WorkforceRequirementMapping::CHECK_TYPES, true)) {
                    throw new \RuntimeException('The configured qualification evidence is unavailable.');
                }
            }

            return $this->checkWithReads($user, $requirements, $shift, $reads);
        };

        return $current ? CurrentAuthorizationReads::within($check) : $check(null);
    }

    private function query(Builder|Relation $query, ?CurrentAuthorizationReads $reads): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;

        return $reads ? $reads->query($query) : $query;
    }

    /** Validate explicitly mapped operational requirements through the same HR evidence readers. */
    private function checkWithReads(User $user, Collection $requirements, Shift $shift, ?CurrentAuthorizationReads $reads): array
    {
        $window = WorkforceQualificationDutyWindow::forShift($shift);

        if ($requirements->isEmpty()) {
            return ['passed' => true, 'failures' => []];
        }

        // A manager-approved exemption is an explicit, audited override of a
        // hard-stop. Apply it before live source checks so the shift decision
        // matches the compliance worklist and the promise made by the waiver UI.
        $exemptRequirementIds = $this->query(HrStaffComplianceStatus::query(), $reads)
            ->where('user_id', $user->id)
            ->whereIn('requirement_id', $requirements->pluck('id'))
            ->whereNotNull('exemption_reason')
            ->whereNotNull('exempted_at')
            ->where('exempted_at', '<=', $window->startsAt)
            ->where(function ($query) use ($window): void {
                $query->whereNull('exempted_until')
                    ->orWhereDate('exempted_until', '>=', $window->lastDutyDate());
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
                'training_course' => $this->validateTrainingRequirements($user, $reqs, $window, $reads),
                'credential' => $this->validateCredentialRequirements($user, $reqs, $window, $reads),
                'background_check' => $this->validateBackgroundCheckRequirements($user, $reqs, $window, $reads),
                'policy_attestation' => $this->validateAttestationRequirements($user, $reqs, $window, $reads),
                'driver_licence' => $this->validateDriverLicenceRequirements($user, $reqs, $window, $reads),
                'manual' => $this->validateManualRequirements($user, $reqs, $window, $reads),
                default => $reqs->map(fn (HrComplianceRequirement $requirement) => $this->failure(
                    $requirement,
                    "{$requirement->name} evidence check is not configured.",
                    status: 'unknown',
                ))->all(),
            };

            $failures = array_merge($failures, $typeFailures);
        }

        return [
            'passed' => empty($failures),
            'failures' => $failures,
        ];
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateTrainingRequirements(User $user, Collection $requirements, WorkforceQualificationDutyWindow $window, ?CurrentAuthorizationReads $reads): array
    {
        $legacyRefs = $requirements->pluck('reference_id')->filter()->unique()->values()->all();

        // Resolve canonical HrCourse ids for these requirements in one query
        // (requirement id â†’ hr_course_id) via the HrCourse back-link.
        $hrCourseByReq = $this->query(HrCourse::query(), $reads)
            ->whereIn('compliance_requirement_id', $requirements->pluck('id')->all() ?: [0])
            ->pluck('id', 'compliance_requirement_id');
        $hrCourseIds = $hrCourseByReq->values()->all();

        // Batch load completed records matching EITHER link (canonical or legacy),
        // most-recent first. Matching either guarantees no wrongful shift block.
        $records = collect();
        if ($legacyRefs || $hrCourseIds) {
            $records = $this->query($user->staffTrainingRecords(), $reads)
                ->whereIn('status', ['completed', 'passed'])
                ->whereNotNull('completed_at')
                ->where('completed_at', '<=', $window->startsAt)
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
                $failures[] = $this->failure($req, "{$req->name} training is missing or not completed.", status: 'not_started');

                continue;
            }

            if ($record->expires_at && $window->expired($record->expires_at)) {
                $failures[] = $this->failure($req, "{$req->name} training expired before this duty ends.", $record->expires_at);

                continue;
            }

            if ($req->validity_months && $record->completed_at) {
                $expiresAt = $record->completed_at->copy()->addMonths($req->validity_months);
                if ($window->expired($expiresAt)) {
                    $failures[] = $this->failure($req, "{$req->name} training expired on {$expiresAt->format('j M Y')}.", $expiresAt);
                }
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateCredentialRequirements(User $user, Collection $requirements, WorkforceQualificationDutyWindow $window, ?CurrentAuthorizationReads $reads): array
    {
        $codes = $requirements->pluck('code')->filter()->unique()->values()->all();

        // Batch load: latest credential per type.
        $credentials = $this->query($user->staffCredentials(), $reads)
            ->whereIn('type', $codes)
            ->orderByDesc('issued_at')
            ->get()
            ->groupBy('type');

        $failures = [];

        foreach ($requirements as $req) {
            $candidates = $credentials->get($req->code, collect())
                ->reject(fn ($credential) => $window->notYetValid($credential->issued_at, dateOnly: true));
            $credential = $candidates->first(fn ($credential) => ! $window->expired($credential->expires_at, dateOnly: true))
                ?? $candidates->first();

            if (! $credential) {
                $failures[] = $this->failure($req, "{$req->name} credential is missing or not valid at the start of this duty.", status: 'not_started');

                continue;
            }

            if ($window->expired($credential->expires_at, dateOnly: true)) {
                $failures[] = $this->failure($req, "{$req->name} expired on {$credential->expires_at->format('j M Y')}.", $credential->expires_at);
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateBackgroundCheckRequirements(User $user, Collection $requirements, WorkforceQualificationDutyWindow $window, ?CurrentAuthorizationReads $reads): array
    {
        // Background checks match on check_type='police_check' with status='clear'/'cleared'.
        // Source column is check_date (not completed_at).
        $check = $this->query($user->staffBackgroundChecks(), $reads)
            ->where('check_type', 'police_check')
            ->whereIn('status', ['clear', 'cleared'])
            ->where(function ($query) use ($window): void {
                $startDate = $window->startsAt->setTimezone((string) (config('app.worker_timezone') ?: (config('app.timezone') ?: 'UTC')))->toDateString();
                $query->whereDate('check_date', '<=', $startDate)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('check_date')->whereDate('issue_date', '<=', $startDate));
            })
            ->orderByDesc('check_date')
            ->first();

        $failures = [];

        foreach ($requirements as $req) {
            if (! $check) {
                $failures[] = $this->failure($req, "{$req->name} is missing or not cleared.", status: 'not_started');

                continue;
            }

            $checkDate = $check->check_date ?? $check->issue_date;

            if ($window->expired($check->expires_at, dateOnly: true)) {
                $failures[] = $this->failure($req, "{$req->name} expired before this duty ends.", $check->expires_at);

                continue;
            }

            if ($req->validity_months && $checkDate) {
                $expiresAt = $checkDate->copy()->addMonths($req->validity_months);
                if ($window->expired($expiresAt, dateOnly: true)) {
                    $failures[] = $this->failure($req, "{$req->name} expired on {$expiresAt->format('j M Y')}.", $expiresAt);
                }
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateAttestationRequirements(User $user, Collection $requirements, WorkforceQualificationDutyWindow $window, ?CurrentAuthorizationReads $reads): array
    {
        $policyIds = $requirements->pluck('reference_id')->filter()->unique()->values()->all();

        // Batch load: latest attestation per policy with current version.
        $attestations = $this->query(HrPolicyAttestation::query(), $reads)->where('user_id', $user->id)
            ->whereIn('policy_id', $policyIds)
            ->where('attested_at', '<=', $window->startsAt)
            ->whereHas('policyVersion', fn ($q) => $q->where('is_current', true))
            ->orderByDesc('attested_at')
            ->get()
            ->keyBy('policy_id');

        $failures = [];

        foreach ($requirements as $req) {
            $attestation = $attestations->get($req->reference_id);

            if (! $attestation) {
                $failures[] = $this->failure($req, "{$req->name} policy attestation is missing or outdated.", status: 'not_started');

                continue;
            }

            if ($req->validity_months && $attestation->attested_at) {
                $expiresAt = $attestation->attested_at->copy()->addMonths($req->validity_months);
                if ($window->expired($expiresAt)) {
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
    protected function validateDriverLicenceRequirements(User $user, Collection $requirements, WorkforceQualificationDutyWindow $window, ?CurrentAuthorizationReads $reads): array
    {
        $record = $this->query(HrDriverEligibility::query(), $reads)->where('user_id', $user->id)->first();
        $failures = [];

        foreach ($requirements as $req) {
            if (! $record) {
                $failures[] = $this->failure($req, "{$req->name}: no driver eligibility record.", status: 'not_started');

                continue;
            }

            if ($record->status === 'suspended') {
                $failures[] = $this->failure($req, "{$req->name}: driving privileges suspended.");

                continue;
            }

            if ($window->expired($record->licence_expires_at, dateOnly: true)) {
                $failures[] = $this->failure($req, "{$req->name} expired on {$record->licence_expires_at->format('j M Y')}.", $record->licence_expires_at);
            }
        }

        return $failures;
    }

    /**
     * Manual requirements rely on the cached status record â€” no live source to check.
     *
     * @return array<int, array{requirement: string, code: string, reason: string, expires_at: string|null}>
     */
    protected function validateManualRequirements(User $user, Collection $requirements, WorkforceQualificationDutyWindow $window, ?CurrentAuthorizationReads $reads): array
    {
        $statuses = $this->query(HrStaffComplianceStatus::query(), $reads)->where('user_id', $user->id)
            ->whereIn('requirement_id', $requirements->pluck('id'))
            ->get()
            ->keyBy('requirement_id');

        $failures = [];

        foreach ($requirements as $req) {
            $status = $statuses->get($req->id);
            $statusForDuty = $window->statusFor($status);

            if (! $status
                || ! in_array($statusForDuty, ['compliant', 'expiring_soon'], true)
                || ($status->evidence_type !== 'manual' && blank($status->exemption_reason))
            ) {
                $failures[] = $this->failure($req, "{$req->name} has not been manually verified.",
                    status: in_array($statusForDuty, ['compliant', 'expiring_soon'], true) ? 'unknown' : $statusForDuty);

                continue;
            }

            if ($window->expired($status->exempted_until, dateOnly: true)) {
                $failures[] = $this->failure(
                    $req,
                    "{$req->name} exemption expired on {$status->exempted_until->format('j M Y')}.",
                    $status->exempted_until,
                );

                continue;
            }

            if ($window->expired($status->expires_at, dateOnly: true)) {
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
    protected function failure(HrComplianceRequirement $req, string $reason, ?CarbonInterface $expiresAt = null, string $status = 'expired'): array
    {
        return [
            'requirement_id' => (int) $req->id,
            'requirement' => $req->name,
            'code' => $req->code,
            'reason' => $reason,
            'expires_at' => $expiresAt?->toDateString(),
            'status' => $status,
        ];
    }
}
