<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Fleet\ResidentTransportJourneyScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ControlledMedicationTransportWitnessService
{
    public function __construct(
        private readonly ResidentTransportJourneyScope $scope,
        private readonly MedicationAdministratorCompetencyPolicy $competency,
    ) {}

    /** @return Collection<int, User> */
    public function eligibleWitnessesForSite(
        int $siteId,
        CarbonInterface $effectiveAt,
        ?int $excludeUserId = null,
    ): Collection {
        return $this->scope->medicationWitnessesForSite($siteId, $excludeUserId)
            ->filter(fn (User $user): bool => $this->qualification(
                $user,
                $siteId,
                $effectiveAt,
                false,
            ) !== null)
            ->values();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return Collection<int, User>
     */
    public function eligibleWitnessesForSites(
        array $siteIds,
        CarbonInterface $effectiveAt,
        ?int $excludeUserId = null,
    ): Collection {
        return collect($siteIds)
            ->map(fn (int $siteId): Collection => $this->eligibleWitnessesForSite(
                $siteId,
                $effectiveAt,
                $excludeUserId,
            ))
            ->flatten(1)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Authenticate an in-person second checker and return durable provenance.
     * The candidate is resolved through the exact Site before credentials or
     * clinical qualification are evaluated, so forged foreign IDs remain 404.
     *
     * @return array{
     *   witness: User,
     *   witnessed_at: CarbonInterface,
     *   method: string,
     *   authority_permission: string,
     *   employment_profile_id: int,
     *   competency_state: string,
     *   competency_assessment_id: int,
     *   presence_source: string,
     *   presence_record_id: int,
     *   presence_started_at: string,
     *   presence_ends_at: ?string
     * }
     */
    public function authenticate(
        User $actor,
        int $siteId,
        int $witnessId,
        string $credential,
        CarbonInterface $effectiveAt,
    ): array {
        abort_unless($witnessId > 0 && $witnessId !== (int) $actor->id, 404);

        $candidate = $this->scope->medicationWitnessesForSite($siteId, $actor->id)
            ->firstWhere('id', $witnessId);
        abort_unless($candidate, 404);

        $witness = User::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        $qualification = $this->qualification($witness, $siteId, $effectiveAt, true);
        abort_unless($qualification, 404);

        if (blank($credential)) {
            throw ValidationException::withMessages([
                'witness_credential' => 'The second checker must enter their password or PIN.',
            ]);
        }
        if (! Hash::check($credential, (string) $witness->password)) {
            throw ValidationException::withMessages([
                'witness_credential' => 'The second checker password or PIN did not match.',
            ]);
        }

        return [
            'witness' => $witness,
            'witnessed_at' => Carbon::instance($effectiveAt)->copy(),
            'method' => 'password',
            ...$qualification,
        ];
    }

    /**
     * @return array{
     *   authority_permission: string,
     *   employment_profile_id: int,
     *   competency_state: string,
     *   competency_assessment_id: int,
     *   presence_source: string,
     *   presence_record_id: int,
     *   presence_started_at: string,
     *   presence_ends_at: ?string
     * }|null
     */
    private function qualification(
        User $witness,
        int $siteId,
        CarbonInterface $effectiveAt,
        bool $lockForUpdate,
    ): ?array {
        if (
            $witness->approved_at === null
            || in_array($witness->role, ['client', 'next_of_kin'], true)
            || $witness->roles()->whereIn('name', ['client', 'next_of_kin'])->exists()
            || ! $witness->canDo('medications.controlled.witness')
        ) {
            return null;
        }

        $employmentProfile = HrEmployeeProfile::query()
            ->withTrashed()
            ->where('user_id', $witness->id)
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();
        if (! $this->isCurrentAtSite($employmentProfile, $siteId, $effectiveAt)) {
            return null;
        }

        $decision = $this->competency->evaluate(
            $witness,
            $siteId,
            $effectiveAt,
            $lockForUpdate,
        );
        if (! $decision['allowed'] || $decision['state'] !== 'valid' || ! $decision['assessment_id']) {
            return null;
        }

        $assessment = MedicationCompetencyAssessment::query()
            ->whereKey((int) $decision['assessment_id'])
            ->where('user_id', $witness->id)
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();
        if (! $assessment?->can_witness_controlled) {
            return null;
        }

        $presence = $this->presenceAtSite($witness, $siteId, $effectiveAt, $lockForUpdate);
        if ($presence === null) {
            return null;
        }

        return [
            'authority_permission' => 'medications.controlled.witness',
            'employment_profile_id' => (int) $employmentProfile->id,
            'competency_state' => (string) $decision['state'],
            'competency_assessment_id' => (int) $assessment->id,
            ...$presence,
        ];
    }

    private function isCurrentAtSite(
        ?HrEmployeeProfile $profile,
        int $siteId,
        CarbonInterface $effectiveAt,
    ): bool {
        if ($profile === null || $profile->trashed() || ! $profile->is_active) {
            return false;
        }

        $day = Carbon::instance($effectiveAt)->copy()->startOfDay();
        if (
            ($profile->start_date !== null && $profile->start_date->copy()->startOfDay()->gt($day))
            || ($profile->end_date !== null && $profile->end_date->copy()->startOfDay()->lt($day))
        ) {
            return false;
        }

        return (int) $profile->primary_site_id === $siteId
            || collect($profile->secondary_site_ids ?? [])->contains(
                fn (mixed $candidate): bool => (int) $candidate === $siteId,
            );
    }

    /**
     * @return array{
     *   presence_source: string,
     *   presence_record_id: int,
     *   presence_started_at: string,
     *   presence_ends_at: ?string
     * }|null
     */
    private function presenceAtSite(
        User $witness,
        int $siteId,
        CarbonInterface $effectiveAt,
        bool $lockForUpdate,
    ): ?array {
        $attendance = HrAttendanceSession::query()
            ->open()
            ->where('user_id', $witness->id)
            ->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $effectiveAt)
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->latest('clock_in_at')
            ->first();
        if ($attendance) {
            return [
                'presence_source' => 'attendance_session',
                'presence_record_id' => (int) $attendance->id,
                'presence_started_at' => $attendance->clock_in_at->toIso8601String(),
                'presence_ends_at' => null,
            ];
        }

        $shift = Shift::query()
            ->where('user_id', $witness->id)
            ->where('starts_at', '<=', $effectiveAt)
            ->where('ends_at', '>=', $effectiveAt)
            ->whereIn('status', ['in_progress', 'active', 'clocked_in', 'started'])
            ->where(function (Builder $presenceSite) use ($siteId): void {
                $presenceSite
                    ->where(function (Builder $directSite) use ($siteId): void {
                        $directSite->where('site_id', $siteId)
                            ->where(function (Builder $resident): void {
                                $resident->whereNull('client_id')
                                    ->orWhereHas('client', fn (Builder $client) => $client->whereColumn(
                                        $client->qualifyColumn('site_id'),
                                        'shifts.site_id',
                                    ));
                            });
                    })
                    ->orWhere(function (Builder $clientSite) use ($siteId): void {
                        $clientSite->whereNull('site_id')
                            ->whereHas('client', fn (Builder $client): Builder => $client->where('site_id', $siteId));
                    });
            })
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->orderByDesc('starts_at')
            ->first();
        if (! $shift) {
            return null;
        }

        return [
            'presence_source' => 'shift',
            'presence_record_id' => (int) $shift->id,
            'presence_started_at' => $shift->starts_at->toIso8601String(),
            'presence_ends_at' => $shift->ends_at->toIso8601String(),
        ];
    }
}
