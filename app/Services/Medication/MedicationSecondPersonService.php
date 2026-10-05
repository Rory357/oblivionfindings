<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Medication\Downtime\HistoricalSecondPersonPresence;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Ordinary dose confirmations use administration authority, not controlled-drug grants. */
final class MedicationSecondPersonService
{
    public function __construct(
        private readonly MedicationAdministratorCompetencyPolicy $competency,
        private readonly MedicationGovernanceScopeService $governance,
        private readonly WitnessPinService $pins,
    ) {}

    /** @return Collection<int, User> */
    public function candidatesForSite(int $siteId, CarbonInterface $at, ?int $excludeUserId = null): Collection
    {
        if ($siteId <= 0) {
            return collect();
        }

        return User::query()->staff()->whereNotNull('approved_at')
            ->whereHas('hrEmployeeProfile', fn (Builder $profile): Builder => $profile
                ->where(fn (Builder $site): Builder => $site->where('primary_site_id', $siteId)
                    ->orWhereJsonContains('secondary_site_ids', $siteId)))
            ->when($excludeUserId !== null, fn (Builder $users): Builder => $users->whereKeyNot($excludeUserId))
            ->with('hrEmployeeProfile')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $this->qualification($user, $siteId, $at, false) !== null)
            ->values();
    }

    /**
     * Use the governing command's sorted Shift/User locks. Never acquire a
     * second, differently ordered witness set after authorization has begun.
     *
     * @param  Collection<int, User>  $lockedUsers
     * @param  Collection<int, Shift>  $lockedPresenceShifts
     * @return array<string, mixed> Immutable basis for this confirmation.
     */
    public function authenticate(
        User $actor,
        int $siteId,
        int $witnessId,
        ?string $credential,
        CarbonInterface $at,
        Collection $lockedUsers,
        Collection $lockedPresenceShifts,
        string $witnessErrorKey = 'witnessed_by',
        string $credentialErrorKey = 'witness_credential',
    ): array {
        $evidence = $this->attestEligibility($actor, $siteId, $witnessId, $at, $lockedUsers, $lockedPresenceShifts, $witnessErrorKey);
        $witness = $evidence['witness'];
        $recorder = $lockedUsers->get((int) $actor->id);

        $this->pins->verify($witness, $credential, $credentialErrorKey, [
            'site_id' => $siteId,
            'surface' => $credentialErrorKey,
            'actor_id' => (int) $recorder->id,
        ]);

        return [
            'witness' => $witness,
            'witnessed_at' => Carbon::instance($at)->copy(),
            'method' => WitnessPinService::METHOD,
            ...array_diff_key($evidence, ['witness' => 1]),
        ];
    }

    /** Recheck retained own-login paper signatures without treating them as reusable credentials. */
    public function attestEligibility(
        User $actor,
        int $siteId,
        int $witnessId,
        CarbonInterface $at,
        Collection $lockedUsers,
        Collection $lockedPresenceShifts,
        string $witnessErrorKey = 'witnessed_by',
        bool $historical = false,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Medication second-person checks require the governing transaction.');
        }
        if ($witnessId <= 0 || $witnessId === (int) $actor->id) {
            throw ValidationException::withMessages([
                $witnessErrorKey => 'The second person must be a different eligible staff member.',
            ]);
        }

        $recorder = $lockedUsers->get((int) $actor->id);
        $witness = $lockedUsers->get($witnessId);
        abort_unless($recorder instanceof User && $witness instanceof User, 404);
        abort_unless($recorder->canDo('medications.administer.record'), 404);
        $profiles = $this->governance->lockCurrentStaffProfilesAtSite(
            $lockedUsers, [(int) $recorder->id, $witnessId], $siteId,
        );
        $witness->setRelation('hrEmployeeProfile', $profiles->get($witnessId));
        $qualification = $this->qualification($witness, $siteId, $at, true, $lockedPresenceShifts, $historical);
        abort_unless($qualification, 404);

        return ['witness' => $witness, ...$qualification];
    }

    /** @return array<string, mixed>|null */
    private function qualification(
        User $user,
        int $siteId,
        CarbonInterface $at,
        bool $lock,
        ?Collection $lockedPresenceShifts = null,
        bool $historical = false,
    ): ?array {
        if ($user->approved_at === null || in_array($user->role, ['client', 'next_of_kin'], true)
            || $user->hasRole('client', 'next_of_kin') || ! $user->canDo('medications.administer.record')) {
            return null;
        }
        $profile = $user->relationLoaded('hrEmployeeProfile')
            ? $user->getRelation('hrEmployeeProfile')
            : HrEmployeeProfile::query()->where('user_id', $user->id)->first();
        if (! $this->employedAtSite($profile, $siteId, $at)
            || ! $this->employedAtSite($profile, $siteId, now())) {
            return null;
        }

        // An exemption permits administration but does not establish the
        // current, unrestricted assessment needed to check another worker.
        $assessment = null;
        foreach ([$at, now()] as $moment) {
            $decision = $this->competency->evaluate($user, $siteId, $moment, $lock);
            if (! $decision['allowed'] || $decision['state'] !== 'valid' || ! $decision['assessment_id']) {
                return null;
            }
            $currentAssessment = MedicationCompetencyAssessment::query()
                ->whereKey((int) $decision['assessment_id'])->where('user_id', $user->id)
                ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
            if ($currentAssessment === null || $currentAssessment->restricted) {
                return null;
            }
            $assessment ??= $currentAssessment;
        }
        $presence = $this->presenceAtSite($user, $siteId, $at, $lock, $lockedPresenceShifts);
        $presence ??= $historical ? HistoricalSecondPersonPresence::fromLocked($user, $siteId, $at, $lockedPresenceShifts) : null;
        if ($presence === null) {
            return null;
        }

        return [
            'authority_permission' => 'medications.administer.record',
            'employment_profile_id' => (int) $profile->id,
            'competency_state' => 'valid',
            'competency_assessment_id' => (int) $assessment->id,
            ...$presence,
        ];
    }

    private function employedAtSite(?HrEmployeeProfile $profile, int $siteId, CarbonInterface $at): bool
    {
        $date = Carbon::instance($at)->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return $profile !== null && ! $profile->trashed() && $profile->is_active
            && ($profile->start_date === null || $profile->start_date->toDateString() <= $date)
            && ($profile->end_date === null || $profile->end_date->toDateString() >= $date)
            && ((int) $profile->primary_site_id === $siteId
                || collect($profile->secondary_site_ids ?? [])->contains(fn (mixed $id): bool => (int) $id === $siteId));
    }

    /** @return array<string, mixed>|null */
    private function presenceAtSite(
        User $user,
        int $siteId,
        CarbonInterface $at,
        bool $lock,
        ?Collection $lockedPresenceShifts,
    ): ?array {
        $storageAt = Carbon::instance($at)->copy()->utc();
        $attendance = HrAttendanceSession::query()->where('user_id', $user->id)->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $storageAt)
            ->where(function (Builder $coverage) use ($storageAt): void {
                $coverage->where(fn (Builder $open): Builder => $open->where('status', 'open')->whereNull('clock_out_at'))
                    ->orWhere(fn (Builder $closed): Builder => $closed->where('status', 'closed')->where('clock_out_at', '>=', $storageAt));
            })
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->latest('clock_in_at')->first();
        if ($attendance !== null) {
            return [
                'presence_source' => 'attendance_session',
                'presence_record_id' => (int) $attendance->id,
                'presence_started_at' => $this->storageInstant($attendance, 'clock_in_at')?->toIso8601String(),
                'presence_ends_at' => $this->storageInstant($attendance, 'clock_out_at')?->toIso8601String(),
            ];
        }
        if ($lock && $lockedPresenceShifts === null) {
            throw new LogicException('Second-person Shift evidence must be locked before authorization Users.');
        }
        $shift = $lock
            ? $lockedPresenceShifts->filter(fn (Shift $shift): bool => $this->shiftProvesPresence($shift, $user, $siteId, $storageAt))
                ->sortByDesc(fn (Shift $shift): string => $this->storageInstant($shift, 'starts_at')?->format('U.u') ?? '')->first()
            : Shift::query()->with('client:id,site_id')->where('user_id', $user->id)
                ->where('starts_at', '<=', $storageAt)->where('ends_at', '>=', $storageAt)
                ->whereIn('status', ['in_progress', 'active', 'clocked_in', 'started'])
                ->where(function (Builder $site) use ($siteId): void {
                    $site->where(function (Builder $direct) use ($siteId): void {
                        $direct->where('site_id', $siteId)->where(fn (Builder $resident): Builder => $resident
                            ->whereNull('client_id')->orWhereHas('client', fn (Builder $client): Builder => $client->where('site_id', $siteId)));
                    })->orWhere(fn (Builder $derived): Builder => $derived->whereNull('site_id')
                        ->whereHas('client', fn (Builder $client): Builder => $client->where('site_id', $siteId)));
                })->latest('starts_at')->first();
        if ($shift === null || ! $this->shiftProvesPresence($shift, $user, $siteId, $storageAt)) {
            return null;
        }

        return [
            'presence_source' => 'shift',
            'presence_record_id' => (int) $shift->id,
            'presence_started_at' => $this->storageInstant($shift, 'starts_at')->toIso8601String(),
            'presence_ends_at' => $this->storageInstant($shift, 'ends_at')->toIso8601String(),
        ];
    }

    private function shiftProvesPresence(Shift $shift, User $user, int $siteId, CarbonInterface $at): bool
    {
        $startsAt = $this->storageInstant($shift, 'starts_at');
        $endsAt = $this->storageInstant($shift, 'ends_at');
        $clientSiteId = $shift->client ? (int) $shift->client->site_id : null;

        return (int) $shift->user_id === (int) $user->id
            && in_array($shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)
            && $startsAt !== null && $endsAt !== null && $startsAt->lte($at) && $endsAt->gte($at)
            && (((int) $shift->site_id === $siteId && ($shift->client_id === null || $clientSiteId === $siteId))
                || ($shift->site_id === null && $clientSiteId === $siteId));
    }

    private function storageInstant(HrAttendanceSession|Shift $record, string $attribute): ?Carbon
    {
        $raw = $record->getRawOriginal($attribute);

        return filled($raw) ? Carbon::parse((string) $raw, config('app.timezone', 'UTC')) : null;
    }
}
