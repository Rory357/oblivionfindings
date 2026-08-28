<?php

namespace App\Services\Medication;

use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class MedicationAdministratorCompetencyPolicy
{
    /**
     * Resolve the one authoritative medication-administrator competency state.
     *
     * A passed assessment is valid only when it has a finite expiry date that
     * covers the effective moment. Otherwise only an independently approved,
     * finite, site-scoped medication-administration exemption can allow work.
     * Permissions are deliberately not part of this clinical decision.
     *
     * @return array{
     *     allowed: bool,
     *     state: string,
     *     message: ?string,
     *     valid_until: ?CarbonInterface,
     *     assessment_id: ?int,
     *     exemption_id: ?int
     * }
     */
    public function evaluate(
        User $user,
        ?int $siteId,
        CarbonInterface $effectiveAt,
        bool $lockForUpdate = false,
    ): array {
        $moment = Carbon::instance($effectiveAt)->copy();

        if ($lockForUpdate) {
            // All application mutation paths for assessments/exemptions use the
            // same user-row lock. This also serializes the no-record state.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        }

        $latest = $this->latestAssessment($user, $moment, $lockForUpdate);
        $assessmentFailure = $this->assessmentFailure($latest, $user, $moment);

        if ($assessmentFailure === null) {
            $validUntil = Carbon::parse(
                $latest->expiry_date->toDateString(),
                config('app.worker_timezone', 'Pacific/Auckland'),
            )->endOfDay();

            return $this->decision(
                true,
                'valid',
                null,
                $validUntil,
                (int) $latest->id,
            );
        }

        $exemption = $this->effectiveExemption($user, $siteId, $moment, $lockForUpdate);
        if ($exemption !== null) {
            return $this->decision(
                true,
                'exempt',
                null,
                $exemption->expires_at,
                $latest?->id ? (int) $latest->id : null,
                (int) $exemption->id,
            );
        }

        return $this->decision(
            false,
            $assessmentFailure['state'],
            $assessmentFailure['message'],
            null,
            $latest?->id ? (int) $latest->id : null,
        );
    }

    private function latestAssessment(
        User $user,
        CarbonInterface $moment,
        bool $lockForUpdate,
    ): ?MedicationCompetencyAssessment {
        if (! $lockForUpdate && $user->relationLoaded('medicationCompetencyAssessments')) {
            return $user->medicationCompetencyAssessments
                ->filter(fn (MedicationCompetencyAssessment $assessment): bool => $this->assessmentWasEstablishedAt(
                    $assessment,
                    $user,
                    $moment,
                ))
                ->sortByDesc(fn (MedicationCompetencyAssessment $assessment) => [
                    $assessment->assessment_date?->format('Y-m-d') ?? '',
                    $assessment->id,
                ])
                ->first();
        }

        // Date-only competency boundaries follow the worker's clinical day,
        // while timestamp columns are persisted in the application timezone.
        // Query bindings do not normalize a non-application Carbon timezone,
        // so bind an explicit storage-time copy for exact instants.
        $storageMoment = $this->storageMoment($moment);

        return MedicationCompetencyAssessment::query()
            ->where('user_id', $user->id)
            ->whereDate('assessment_date', '<=', $this->clinicalDate($moment))
            ->whereNotNull('assessor_id')
            ->where('assessor_id', '!=', $user->id)
            ->whereNotNull('assessor_declared_at')
            ->where('assessor_declared_at', '<=', $storageMoment)
            ->whereNotNull('staff_acknowledged_at')
            ->where('staff_acknowledged_at', '<=', $storageMoment)
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /** @return array{state: string, message: string}|null */
    private function assessmentFailure(
        ?MedicationCompetencyAssessment $assessment,
        User $user,
        CarbonInterface $moment,
    ): ?array {
        if ($assessment === null) {
            return [
                'state' => 'unassessed',
                'message' => 'No medication competency assessment is on file.',
            ];
        }

        if (! $this->assessmentWasEstablishedAt($assessment, $user, $moment)) {
            return [
                'state' => 'unassessed',
                'message' => 'No medication competency assessment was established at this time.',
            ];
        }

        if ($assessment->status !== 'passed') {
            return [
                'state' => 'failed',
                'message' => 'Medication competency is recorded as "'.str_replace('_', ' ', (string) $assessment->status).'".',
            ];
        }

        if ($assessment->expiry_date === null) {
            return [
                'state' => 'missing_expiry',
                'message' => 'Medication competency has no expiry date.',
            ];
        }

        if ($assessment->expiry_date->toDateString() < $this->clinicalDate($moment)) {
            return [
                'state' => 'expired',
                'message' => 'Medication competency expired on '.$assessment->expiry_date->format('j M Y').'.',
            ];
        }

        return null;
    }

    private function assessmentWasEstablishedAt(
        MedicationCompetencyAssessment $assessment,
        User $user,
        CarbonInterface $moment,
    ): bool {
        return (int) $assessment->user_id === (int) $user->id
            && $assessment->assessment_date !== null
            && $assessment->assessment_date->toDateString() <= $this->clinicalDate($moment)
            && $assessment->assessor_id !== null
            && (int) $assessment->assessor_id !== (int) $user->id
            && $assessment->assessor_declared_at !== null
            && $assessment->assessor_declared_at->lte($moment)
            && $assessment->staff_acknowledged_at !== null
            && $assessment->staff_acknowledged_at->lte($moment);
    }

    private function clinicalDate(CarbonInterface $moment): string
    {
        return Carbon::instance($moment)
            ->copy()
            ->timezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString();
    }

    private function storageMoment(CarbonInterface $moment): CarbonInterface
    {
        return Carbon::instance($moment)
            ->copy()
            ->timezone(config('app.timezone', 'UTC'));
    }

    private function effectiveExemption(
        User $user,
        ?int $siteId,
        CarbonInterface $moment,
        bool $lockForUpdate,
    ): ?MedicationCompetencyExemption {
        if (! $siteId) {
            return null;
        }

        if (! $lockForUpdate && $user->relationLoaded('medicationCompetencyExemptions')) {
            return $user->medicationCompetencyExemptions
                ->filter(fn (MedicationCompetencyExemption $exemption) => $this->isEffectiveExemption(
                    $exemption,
                    $user,
                    $siteId,
                    $moment,
                ))
                ->sortByDesc('expires_at')
                ->first();
        }

        $storageMoment = $this->storageMoment($moment);
        $exemptions = MedicationCompetencyExemption::query()
            ->where('user_id', $user->id)
            ->where('site_id', $siteId)
            ->where('scope', MedicationCompetencyExemption::SCOPE_ADMINISTRATION)
            ->whereNull('revoked_at')
            ->where('approved_at', '<=', $storageMoment)
            ->where('starts_at', '<=', $storageMoment)
            ->where('expires_at', '>=', $storageMoment)
            ->orderByDesc('expires_at')
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->get();

        return $exemptions->first(fn (MedicationCompetencyExemption $exemption) => $this->isEffectiveExemption(
            $exemption,
            $user,
            $siteId,
            $moment,
        ));
    }

    private function isEffectiveExemption(
        MedicationCompetencyExemption $exemption,
        User $user,
        int $siteId,
        CarbonInterface $moment,
    ): bool {
        return (int) $exemption->user_id === (int) $user->id
            && (int) $exemption->site_id === $siteId
            && $exemption->scope === MedicationCompetencyExemption::SCOPE_ADMINISTRATION
            && filled(trim((string) $exemption->reason))
            && $exemption->approved_by !== null
            && (int) $exemption->approved_by !== (int) $user->id
            && $exemption->approved_at !== null
            && $exemption->approved_at->lte($moment)
            && $exemption->starts_at !== null
            && $exemption->starts_at->lte($moment)
            && $exemption->expires_at !== null
            && $exemption->expires_at->gte($moment)
            && $exemption->expires_at->gt($exemption->starts_at)
            && $exemption->revoked_at === null;
    }

    /**
     * @return array{
     *     allowed: bool,
     *     state: string,
     *     message: ?string,
     *     valid_until: ?CarbonInterface,
     *     assessment_id: ?int,
     *     exemption_id: ?int
     * }
     */
    private function decision(
        bool $allowed,
        string $state,
        ?string $message,
        ?CarbonInterface $validUntil,
        ?int $assessmentId,
        ?int $exemptionId = null,
    ): array {
        return [
            'allowed' => $allowed,
            'state' => $state,
            'message' => $message,
            'valid_until' => $validUntil,
            'assessment_id' => $assessmentId,
            'exemption_id' => $exemptionId,
        ];
    }
}
