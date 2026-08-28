<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Fleet\ResidentTransportJourneyScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ControlledMedicationTransportWitnessService
{
    public function __construct(
        private readonly ResidentTransportJourneyScope $scope,
        private readonly MedicationAdministratorCompetencyPolicy $competency,
        private readonly AuthorizationEvidenceLockService $authorizationEvidence,
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
     * @param  null|Closure(User): void  $beforeCredentialCheck
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
        ?string $credential,
        CarbonInterface $effectiveAt,
        string $witnessErrorKey = 'witnessed_by',
        string $credentialErrorKey = 'witness_credential',
        ?Closure $beforeCredentialCheck = null,
        ?Collection $lockedUsers = null,
        ?Collection $lockedPresenceShifts = null,
    ): array {
        if ($witnessId <= 0 || $witnessId === (int) $actor->id) {
            throw ValidationException::withMessages([
                $witnessErrorKey => 'The witness must be a different eligible staff member.',
            ]);
        }

        $candidate = $this->scope->medicationWitnessesForSite($siteId, $actor->id)
            ->firstWhere('id', $witnessId);
        abort_unless($candidate, 404);

        $lockedUsers ??= $this->authorizationEvidence->lockForUsers(
            [(int) $actor->id, (int) $candidate->id],
            ['medications.controlled.witness'],
        );
        abort_unless(
            $lockedUsers->has((int) $actor->id)
            && $lockedUsers->has((int) $candidate->id),
            404,
        );
        /** @var User $witness */
        $witness = $lockedUsers->get((int) $candidate->id);
        if (
            $lockedPresenceShifts === null
            && $witness->relationLoaded('controlledMedicationPresenceShifts')
        ) {
            $candidatePresenceShifts = $witness->getRelation('controlledMedicationPresenceShifts');
            $lockedPresenceShifts = $candidatePresenceShifts instanceof Collection
                ? $candidatePresenceShifts
                : null;
        }
        $qualification = $this->qualification(
            $witness,
            $siteId,
            $effectiveAt,
            true,
            now(),
            $lockedPresenceShifts,
        );
        abort_unless($qualification, 404);
        $beforeCredentialCheck?->__invoke($witness);

        if (blank($credential)) {
            throw ValidationException::withMessages([
                $credentialErrorKey => 'The second checker must enter their password or PIN.',
            ]);
        }
        if (! Hash::check($credential, (string) $witness->password)) {
            throw ValidationException::withMessages([
                $credentialErrorKey => 'The second checker password or PIN did not match.',
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
        ?CarbonInterface $currentEmploymentAt = null,
        ?Collection $lockedPresenceShifts = null,
    ): ?array {
        if (
            $witness->approved_at === null
            || in_array($witness->role, ['client', 'next_of_kin'], true)
            || $witness->hasRole('client', 'next_of_kin')
            || ! $witness->canDo('medications.controlled.witness')
        ) {
            return null;
        }

        $employmentProfile = HrEmployeeProfile::query()
            ->withTrashed()
            ->where('user_id', $witness->id)
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();
        if (
            ! $this->isCurrentAtSite($employmentProfile, $siteId, $effectiveAt)
            || ($currentEmploymentAt !== null
                && ! $this->isCurrentAtSite($employmentProfile, $siteId, $currentEmploymentAt))
        ) {
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
        if (! $this->isValidWitnessAssessment($assessment, $witness, $effectiveAt)) {
            return null;
        }

        $presence = $this->presenceAtSite(
            $witness,
            $siteId,
            $effectiveAt,
            $lockForUpdate,
            $lockedPresenceShifts,
        );
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

        $clinicalDate = Carbon::instance($effectiveAt)
            ->copy()
            ->timezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString();
        if (
            ($profile->start_date !== null && $profile->start_date->toDateString() > $clinicalDate)
            || ($profile->end_date !== null && $profile->end_date->toDateString() < $clinicalDate)
        ) {
            return false;
        }

        return (int) $profile->primary_site_id === $siteId
            || collect($profile->secondary_site_ids ?? [])->contains(
                fn (mixed $candidate): bool => (int) $candidate === $siteId,
            );
    }

    private function isValidWitnessAssessment(
        ?MedicationCompetencyAssessment $assessment,
        User $witness,
        CarbonInterface $effectiveAt,
    ): bool {
        if (
            $assessment === null
            || (int) $assessment->user_id !== (int) $witness->id
            || $assessment->status !== 'passed'
            || ! $assessment->can_witness_controlled
            || $assessment->assessor_id === null
            || (int) $assessment->assessor_id === (int) $witness->id
            || $assessment->assessor_declared_at === null
            || $assessment->staff_acknowledged_at === null
            || $assessment->assessment_date === null
            || $assessment->expiry_date === null
        ) {
            return false;
        }

        $moment = Carbon::instance($effectiveAt)->copy();
        $clinicalDate = $moment
            ->copy()
            ->timezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString();

        return $assessment->assessment_date->toDateString() <= $clinicalDate
            && $assessment->expiry_date->toDateString() >= $clinicalDate
            && $assessment->assessor_declared_at->lte($moment)
            && $assessment->staff_acknowledged_at->lte($moment);
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
        ?Collection $lockedPresenceShifts = null,
    ): ?array {
        // Attendance and Shift timestamps are stored in UTC. Query bindings do
        // not convert a Carbon instance to the connection timezone, so a
        // worker-local effective moment can otherwise miss a genuinely current
        // witness (or match the wrong boundary around a DST/UTC offset).
        $storageMoment = Carbon::instance($effectiveAt)->copy()->utc();

        $attendance = HrAttendanceSession::query()
            ->where('user_id', $witness->id)
            ->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $storageMoment)
            ->where(function (Builder $coverage) use ($storageMoment): void {
                $coverage->where(function (Builder $open): void {
                    $open->where('status', 'open')->whereNull('clock_out_at');
                })->orWhere(function (Builder $closed) use ($storageMoment): void {
                    $closed->where('status', 'closed')->where('clock_out_at', '>=', $storageMoment);
                });
            })
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->latest('clock_in_at')
            ->first();
        if ($attendance) {
            $clockedInAt = $this->attendanceStorageInstant($attendance, 'clock_in_at');
            $clockedOutAt = $this->attendanceStorageInstant($attendance, 'clock_out_at');
            if ($clockedInAt === null) {
                return null;
            }

            return [
                'presence_source' => 'attendance_session',
                'presence_record_id' => (int) $attendance->id,
                'presence_started_at' => $clockedInAt->toIso8601String(),
                'presence_ends_at' => $clockedOutAt?->toIso8601String(),
            ];
        }

        if ($lockForUpdate && $lockedPresenceShifts === null) {
            throw new LogicException(
                'Controlled-medication witness Shift evidence must be locked before authorization Users.',
            );
        }

        $shift = $lockForUpdate
            ? $lockedPresenceShifts
                ->filter(fn (Shift $shift): bool => $this->shiftProvesPresence(
                    $shift,
                    (int) $witness->id,
                    $siteId,
                    $storageMoment,
                ))
                ->sortByDesc(fn (Shift $shift): string => $this->shiftStorageInstant(
                    $shift,
                    'starts_at',
                )?->format('U.u') ?? '')
                ->first()
            : $this->presenceShiftQuery((int) $witness->id, $siteId, $storageMoment)
                ->orderByDesc('starts_at')
                ->first();
        if (! $shift) {
            return null;
        }
        $startsAt = $this->shiftStorageInstant($shift, 'starts_at');
        $endsAt = $this->shiftStorageInstant($shift, 'ends_at');
        if ($startsAt === null || $endsAt === null) {
            return null;
        }

        return [
            'presence_source' => 'shift',
            'presence_record_id' => (int) $shift->id,
            'presence_started_at' => $startsAt->toIso8601String(),
            'presence_ends_at' => $endsAt->toIso8601String(),
        ];
    }

    /**
     * Freeze every potentially qualifying witness Shift in ascending Shift-ID
     * order before any authorization User mutex. Explicit aggregate Shift IDs
     * join the same ordered set, even when they are not themselves current
     * witness-presence evidence, so actor/witness cross-pairs cannot invert
     * Shift A and Shift B around the complete User batch.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $additionalShiftIds
     * @return Collection<int, Shift>
     */
    public function lockPresenceShiftsAtSite(
        array $userIds,
        int $siteId,
        CarbonInterface $effectiveAt,
        array $additionalShiftIds = [],
    ): Collection {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Controlled-medication presence Shifts must be locked in the governing transaction.');
        }

        $ids = collect($userIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $explicitShiftIds = collect($additionalShiftIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        if ($siteId <= 0 || ($ids->isEmpty() && $explicitShiftIds->isEmpty())) {
            throw new LogicException('Controlled-medication presence requires a Site and at least one User or Shift.');
        }

        $storageMoment = Carbon::instance($effectiveAt)->copy()->utc();

        return Shift::query()
            ->with('client:id,site_id')
            ->where(function (Builder $candidates) use ($ids, $siteId, $storageMoment, $explicitShiftIds): void {
                if ($explicitShiftIds->isNotEmpty()) {
                    $candidates->whereIn('id', $explicitShiftIds->all());
                }
                if ($ids->isNotEmpty()) {
                    $method = $explicitShiftIds->isNotEmpty() ? 'orWhere' : 'where';
                    $candidates->{$method}(function (Builder $eligible) use ($ids, $siteId, $storageMoment): void {
                        $this->applyPresenceShiftScope($eligible, $ids->all(), $siteId, $storageMoment);
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Shift $shift): int => (int) $shift->id);
    }

    /** @param array<int, int> $userIds */
    private function applyPresenceShiftScope(
        Builder $query,
        array $userIds,
        int $siteId,
        CarbonInterface $storageMoment,
    ): void {
        $query
            ->whereIn('user_id', $userIds)
            ->where('starts_at', '<=', $storageMoment)
            ->where('ends_at', '>=', $storageMoment)
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
            });
    }

    private function presenceShiftQuery(
        int $userId,
        int $siteId,
        CarbonInterface $storageMoment,
    ): Builder {
        $query = Shift::query();
        $this->applyPresenceShiftScope($query, [$userId], $siteId, $storageMoment);

        return $query;
    }

    private function shiftProvesPresence(
        Shift $shift,
        int $userId,
        int $siteId,
        CarbonInterface $storageMoment,
    ): bool {
        $startsAt = $this->shiftStorageInstant($shift, 'starts_at');
        $endsAt = $this->shiftStorageInstant($shift, 'ends_at');
        if (
            (int) $shift->user_id !== $userId
            || ! in_array($shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)
            || $startsAt === null
            || $endsAt === null
            || $startsAt->gt($storageMoment)
            || $endsAt->lt($storageMoment)
        ) {
            return false;
        }

        $clientSiteId = $shift->client ? (int) $shift->client->site_id : null;

        return ((int) $shift->site_id === $siteId
                && ($shift->client_id === null || $clientSiteId === $siteId))
            || ($shift->site_id === null && $clientSiteId === $siteId);
    }

    private function shiftStorageInstant(Shift $shift, string $attribute): ?Carbon
    {
        $raw = $shift->getRawOriginal($attribute);

        return filled($raw)
            ? Carbon::parse((string) $raw, config('app.timezone', 'UTC'))
            : null;
    }

    private function attendanceStorageInstant(HrAttendanceSession $attendance, string $attribute): ?Carbon
    {
        $raw = $attendance->getRawOriginal($attribute);

        return filled($raw)
            ? Carbon::parse((string) $raw, config('app.timezone', 'UTC'))
            : null;
    }
}
