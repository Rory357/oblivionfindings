<?php

namespace App\Services\Medication\Controlled;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledWitnessOverride;
use App\Models\ControlledWorkflowEvent;
use App\Models\Shift;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationCompetencyRestrictionRules;
use App\Services\Medication\Recording\RecordingContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Waives only a configured CD dose witness; no register or clinical confirmation is waived. */
final class ControlledDoseOverrideService
{
    public function __construct(
        private readonly MedicationAdministratorCompetencyPolicy $competency,
        private readonly MedicationCompetencyRestrictionRules $restrictions,
    ) {}

    /** Read-only hint; the governing record transaction rechecks and locks this grant. */
    public function preview(User $actor, ClientMedication $medication, CarbonInterface $at): ?ControlledWitnessOverride
    {
        return $this->find($actor, $medication->client, $medication, ['status' => 'given'], $at, false);
    }

    /** Caller holds Client, medicine, sorted presence/User/profile and Site locks already. */
    public function forAdministration(User $actor, Client $client, ClientMedication $medication, array $data,
        CarbonInterface $at, Collection $lockedPresenceShifts): ?ControlledWitnessOverride
    {
        $this->requireTransaction();

        return $this->find($actor, $client, $medication, $data, $at, true, $lockedPresenceShifts);
    }

    /** Append dose evidence and source-owned lead work before the caller's final P09 head lock. */
    public function recordApplied(?ControlledWitnessOverride $override, ClientMedicationAdministration $dose,
        User $actor, ClientMedication $medication): void
    {
        if ($override === null) {
            return;
        }
        $this->requireTransaction();
        $client = $medication->client;
        if (! $dose->exists || (int) $dose->client_id !== (int) $client->id
            || (int) $dose->client_medication_id !== (int) $medication->id
            || (int) $dose->administered_by !== (int) $actor->id
            || (int) $dose->witness_override_id !== (int) $override->id
            || $dose->status !== 'given' || $dose->witnessed_by !== null
            || $dose->witnessed_at !== null || $dose->witness_method !== null
            || $dose->second_person_status !== RecordingContract::SECOND_OVERRIDE_PENDING
            || ! $this->covers($override, $actor, $client, $medication, $dose->administered_at, CarbonImmutable::now('UTC'))) {
            $this->unavailable();
        }
        $event = ControlledWorkflowEvent::query()->where('subject_type', 'override_dose')
            ->where('subject_id', $dose->id)->where('action', 'recorded_under_override')->first();
        if ($event === null) {
            ControlledWorkflowEvent::create([
                'site_id' => $client->site_id, 'client_medication_id' => $medication->id,
                'subject_type' => 'override_dose', 'subject_id' => $dose->id,
                'action' => 'recorded_under_override', 'actor_id' => $actor->id, 'created_at' => now(),
                'payload' => ['override_id' => $override->id, 'administration_id' => $dose->id,
                    'requested_by' => $override->requested_by, 'decided_by' => $override->decided_by,
                    'decided_at' => $override->decided_at->toIso8601String(),
                    'starts_at' => $override->starts_at->toIso8601String(), 'expires_at' => $override->expires_at->toIso8601String(),
                    'administered_at' => $dose->administered_at->toIso8601String(), 'second_person_status' => $dose->second_person_status],
            ]);
        }
        app(MedicationFollowupService::class)->ensureForSource('witness-override', 'dose-'.$dose->id,
            $client, $medication, $dose, null, $override->followup_due_at,
            ['override_id' => $override->id, 'source_url' => '/emar/safety/witness-overrides']);
    }

    private function find(User $actor, Client $client, ClientMedication $medication, array $data,
        CarbonInterface $at, bool $lock, ?Collection $lockedPresenceShifts = null): ?ControlledWitnessOverride
    {
        $selected = $data['witness_override_id'] ?? null;
        $now = CarbonImmutable::now('UTC');
        if ((int) $medication->client_id !== (int) $client->id || ! $medication->controlled_drug
            || $medication->witness_required || ! $medication->requiresWitness()
            || ($data['status'] ?? null) !== 'given' || ! empty($data['witnessed_by'])
            || filter_var($data['queued_offline'] ?? false, FILTER_VALIDATE_BOOL)
            || filled($data['captured_offline_at'] ?? null)) {
            if (filled($selected)) {
                $this->unavailable();
            }

            return null;
        }
        $query = ControlledWitnessOverride::query()->where('site_id', $client->site_id)
            ->where('requested_by', $actor->id)->where('status', 'approved')
            ->where('starts_at', '<=', $now)->where('expires_at', '>', $now)
            ->whereNotNull('decided_at')->whereNotNull('decided_by')->where('decided_by', '!=', $actor->id)
            ->whereJsonContains('medicine_ids', (int) $medication->id);
        if (filled($selected)) {
            $query->whereKey((int) $selected);
        }
        $override = $query->orderByDesc('decided_at')->orderByDesc('id')
            ->when($lock, fn (Builder $q): Builder => $q->lockForUpdate())->first();
        if ($override === null || ! $this->covers($override, $actor, $client, $medication, $at, $now)
            || ! $this->eligible($actor, $client, $medication, $at, $now, $lock, $lockedPresenceShifts)) {
            if (filled($selected)) {
                $this->unavailable();
            }

            return null;
        }

        return $override;
    }

    private function covers(ControlledWitnessOverride $override, User $actor, Client $client,
        ClientMedication $medication, CarbonInterface $at, CarbonInterface $now): bool
    {
        return $override->status === 'approved' && (int) $override->site_id === (int) $client->site_id
            && (int) $override->requested_by === (int) $actor->id
            && in_array((int) $medication->id, array_map('intval', $override->medicine_ids ?? []), true)
            && $override->decided_by !== null && (int) $override->decided_by !== (int) $actor->id
            && $override->decided_at !== null && $override->decided_at->lte($at) && $override->decided_at->lte($now)
            && $override->starts_at !== null && $override->expires_at !== null && $override->followup_due_at !== null
            && $override->starts_at->lte($at) && $override->expires_at->gt($at)
            && $override->starts_at->lte($now) && $override->expires_at->gt($now)
            && $medication->controlled_drug && ! $medication->witness_required && $medication->requiresWitness();
    }

    private function eligible(User $actor, Client $client, ClientMedication $medication, CarbonInterface $at,
        CarbonInterface $now, bool $lock, ?Collection $lockedPresenceShifts): bool
    {
        $siteId = (int) $client->site_id;
        if ($actor->approved_at === null || in_array($actor->role, ['client', 'next_of_kin'], true)
            || $actor->hasRole('client', 'next_of_kin') || ! $actor->canDo('medications.administer.record')
            || ! $actor->canDo('medications.controlled.record') || ! $actor->canDo('medications.controlled.view')) {
            return false;
        }
        $profile = $actor->relationLoaded('hrEmployeeProfile') ? $actor->hrEmployeeProfile
            : HrEmployeeProfile::query()->where('user_id', $actor->id)->first();
        foreach ([$at, $now] as $moment) {
            $date = CarbonImmutable::instance($moment)->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
            if ($profile === null || $profile->trashed() || ! $profile->is_active
                || ($profile->start_date !== null && $profile->start_date->toDateString() > $date)
                || ($profile->end_date !== null && $profile->end_date->toDateString() < $date)
                || ((int) $profile->primary_site_id !== $siteId && ! in_array($siteId, array_map('intval', $profile->secondary_site_ids ?? []), true))) {
                return false;
            }
            $decision = $this->competency->evaluate($actor, $siteId, $moment, $lock);
            if (! $decision['allowed']) {
                return false;
            }
            $violation = $this->restrictions->violation($decision, $medication, $siteId, $moment, null);
            if ($violation !== null && $violation['state'] !== 'restricted_cosigner_required') {
                return false;
            }
            if (! $this->present($actor, $siteId, $moment, $lock, $lockedPresenceShifts)) {
                return false;
            }
        }

        return true;
    }

    private function present(User $actor, int $siteId, CarbonInterface $at, bool $lock, ?Collection $shifts): bool
    {
        $at = CarbonImmutable::instance($at)->utc();
        $attendance = HrAttendanceSession::query()->where('user_id', $actor->id)->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $at)->where(fn (Builder $q): Builder => $q
            ->where(fn (Builder $open): Builder => $open->where('status', 'open')->whereNull('clock_out_at'))
            ->orWhere(fn (Builder $closed): Builder => $closed->where('status', 'closed')->where('clock_out_at', '>=', $at)))
            ->when($lock, fn (Builder $q): Builder => $q->lockForUpdate())->exists();
        if ($attendance) {
            return true;
        }
        if (! $lock) {
            $shifts = Shift::query()->with('client:id,site_id')->where('user_id', $actor->id)
                ->where('starts_at', '<=', $at)->where('ends_at', '>=', $at)
                ->whereIn('status', ['in_progress', 'active', 'clocked_in', 'started'])->get();
        }
        if ($shifts === null) {
            throw new LogicException('Override dose presence must reuse the governing Shift locks.');
        }

        return $shifts->contains(function (Shift $shift) use ($actor, $siteId, $at): bool {
            $start = $shift->getRawOriginal('starts_at');
            $end = $shift->getRawOriginal('ends_at');

            return (int) $shift->user_id === (int) $actor->id
                && in_array($shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)
                && filled($start) && filled($end)
                && CarbonImmutable::parse($start, config('app.timezone', 'UTC'))->lte($at)
                && CarbonImmutable::parse($end, config('app.timezone', 'UTC'))->gte($at)
                && (((int) $shift->site_id === $siteId && ($shift->client_id === null || (int) $shift->client?->site_id === $siteId))
                    || ($shift->site_id === null && (int) $shift->client?->site_id === $siteId));
        });
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['witness_override_id' => 'No active approved witness override covers you, this medicine and this dose time.']);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Apply a witness override inside the governing administration transaction.');
        }
    }
}
