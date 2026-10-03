<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationSecondPersonConfirmation;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Notifications\MedicationSecondPersonConfirmationNotification;
use App\Services\AuditLogger;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Recording\RecordingContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * P01 C7 / PIN-2: a named, eligible second person answers in their own login.
 * This is always explicitly unverified until answered. It never substitutes
 * for controlled-drug witnessing, an attempt lock, or a missing colleague.
 * Authorization is single-organisation, current Site/person/permission based.
 */
final class ForgottenWitnessPinService
{
    public const METHOD = 'pin_forgotten';

    public const CONFIRMED_METHOD = 'own_session_confirmation';

    public const CONFIRM_WITHIN_MINUTES = 30;

    public const REVIEW_DISPUTED = 'second_person_disputed';

    public const REVIEW_EXPIRED = 'second_person_confirmation_expired';

    public function __construct(
        private readonly ControlledMedicationTransportWitnessService $witnesses,
        private readonly WitnessPinService $pins,
        private readonly WitnessPinSettings $settings,
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly SecondPersonFollowupAdapter $followups,
    ) {}

    public function available(): bool
    {
        return (bool) config('medications.witness_pin.forgotten_fallback_enabled', false)
            && $this->followups->available();
    }

    /** @return array<string, mixed> Eligibility is evidence, never credential authentication. */
    public function prepare(
        User $actor,
        Client $client,
        ClientMedication $medication,
        ?string $kind,
        int $witnessId,
        CarbonInterface $at,
        Collection $lockedUsers,
        Collection $lockedPresenceShifts,
    ): array {
        if (! $this->available()) {
            throw ValidationException::withMessages([
                'witness_credential' => 'Forgotten-PIN confirmation is not configured. Ask the colleague to reset their PIN in Settings › Witness PIN.',
            ]);
        }
        if ($medication->controlled_drug || $kind === RecordingContract::SECOND_WITNESS) {
            throw ValidationException::withMessages([
                'witness_credential' => 'The forgotten-PIN fallback is not allowed for a witness-required medicine. Ask an eligible witness to enter their PIN.',
            ]);
        }
        if (! in_array($kind, [
            RecordingContract::SECOND_RULE,
            RecordingContract::SECOND_AMOUNT,
            RecordingContract::SECOND_COSIGNER,
        ], true)) {
            throw ValidationException::withMessages(['witnessed_by' => 'This dose does not need a second person.']);
        }
        $evidence = $this->witnesses->attestEligibility(
            $actor, (int) $client->site_id, $witnessId, $at,
            lockedUsers: $lockedUsers,
            lockedPresenceShifts: $lockedPresenceShifts,
        );
        $pin = UserWitnessPin::query()->where('user_id', $witnessId)->lockForUpdate()->first();
        if ($this->pins->statusOf($pin) !== WitnessPinService::STATUS_SET
            || RateLimiter::tooManyAttempts($this->pins->attemptBudgetKey((int) $actor->id, $pin), $this->settings->maxAttempts())) {
            throw ValidationException::withMessages([
                'witness_credential' => 'This colleague’s PIN is not available. A locked, reset, expired or unset PIN cannot use the forgotten-PIN fallback. They can reset it in Settings › Witness PIN, or choose another eligible colleague.',
            ]);
        }

        $this->pins->assertCredentialConfigured($pin, 'witness_credential');

        return [
            'success' => true,
            // Used internally only for the restricted-recorder competency check.
            // No witnessed_by is written to the dose before an actual answer.
            'witnessed_by' => $witnessId,
            'witness_method' => self::METHOD,
            'eligibility_evidence' => collect($evidence)->except('witness')->all(),
        ];
    }

    /** Called in the dose transaction, after the new dose exists, never on a replay. */
    public function start(ClientMedicationAdministration $dose, array $evidence): MedicationSecondPersonConfirmation
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A PIN-2 nomination must commit with its dose.');
        }
        $confirmation = MedicationSecondPersonConfirmation::query()->create([
            'administration_id' => $dose->id,
            'nominated_user_id' => $evidence['witnessed_by'],
            'recorded_by' => $dose->administered_by,
            'site_id' => $dose->client->site_id,
            'status' => MedicationSecondPersonConfirmation::PENDING,
            'due_at' => now()->addMinutes(self::CONFIRM_WITHIN_MINUTES),
            'eligibility_evidence' => $evidence['eligibility_evidence'],
        ]);
        AuditLogger::logOrFail('medications.second_person.nominated', $dose, [
            'actor_id' => (int) $dose->administered_by,
            'confirmation_id' => (int) $confirmation->id,
            'nominated_user_id' => (int) $confirmation->nominated_user_id,
            'due_at' => $confirmation->due_at->toIso8601String(),
        ]);
        $followupId = $this->followups->start($confirmation, $dose);
        // The database notification commits with the dose. No duplicate bell
        // item or lost hand-off if transport delivery fails after the commit.
        User::query()->findOrFail($confirmation->nominated_user_id)
            ->notify(new MedicationSecondPersonConfirmationNotification($followupId));

        return $confirmation;
    }

    /** Pending work in the named colleague's own login, filtered before projection. */
    public function pendingFor(User $actor): Collection
    {
        if (! $this->mayRead($actor)) {
            return collect();
        }
        $rows = MedicationSecondPersonConfirmation::query()
            ->where('nominated_user_id', $actor->id)
            ->where('status', MedicationSecondPersonConfirmation::PENDING)
            ->with(['administration.client', 'administration.medication', 'nominatedUser:id,name'])
            ->orderBy('due_at')->get();
        $clientIds = $this->access->readableClientIds(
            $actor, $rows->pluck('administration.client_id')->unique(),
        );

        return $rows->filter(fn ($row): bool => $this->canonical($row)
            && in_array((int) $row->administration->client_id, $clientIds, true))->values();
    }

    /** Named owner only; current person/Site/permission checks precede any response. */
    public function readable(User $actor, int $id): MedicationSecondPersonConfirmation
    {
        abort_unless($this->mayRead($actor), 403);
        $row = MedicationSecondPersonConfirmation::query()
            ->where('nominated_user_id', $actor->id)
            ->with(['administration.client', 'administration.medication', 'nominatedUser:id,name'])
            ->find($id);
        abort_unless($row !== null && $this->canonical($row), 404);
        $this->access->client($actor, (int) $row->administration->client_id);

        return $row;
    }

    /** @return array{status: string, replayed: bool} Late replies commit expiry before returning. */
    public function respond(User $actor, int $id, bool $wasThere): array
    {
        $snapshot = $this->readable($actor, $id);

        return DB::transaction(function () use ($actor, $snapshot, $wasThere): array {
            $client = Client::query()->whereKey($snapshot->administration->client_id)->lockForUpdate()->firstOrFail();
            $medication = ClientMedication::query()->whereKey($snapshot->administration->client_medication_id)
                ->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            $users = $this->scope->lockControlledWitnessUsers([(int) $actor->id]);
            $currentActor = $users->get((int) $actor->id);
            abort_unless($currentActor->canDo('medications.view') && $currentActor->canDo('medications.controlled.witness'), 403);
            $profiles = $this->scope->lockCurrentStaffProfilesAtSite($users, [(int) $actor->id], (int) $client->site_id);
            $currentActor->setRelation('hrEmployeeProfile', $profiles->get((int) $actor->id));
            $this->scope->lockCurrentMedicationSite((int) $client->site_id);
            $this->access->assertReadable($currentActor, $client);
            $dose = ClientMedicationAdministration::query()->whereKey($snapshot->administration_id)->lockForUpdate()->firstOrFail();
            $row = MedicationSecondPersonConfirmation::query()->whereKey($snapshot->id)
                ->where('nominated_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $dose->setRelation('client', $client);
            $dose->setRelation('medication', $medication);
            $row->setRelation('administration', $dose);
            abort_unless($this->canonical($row), 404);

            $answer = $wasThere ? MedicationSecondPersonConfirmation::CONFIRMED : MedicationSecondPersonConfirmation::DISPUTED;
            if ($row->status !== MedicationSecondPersonConfirmation::PENDING) {
                if ($row->status === $answer || $row->status === MedicationSecondPersonConfirmation::EXPIRED) {
                    return ['status' => $row->status, 'replayed' => true];
                }
                throw ValidationException::withMessages(['was_there' => 'Your answer is already recorded and cannot be changed here.']);
            }
            if ($row->due_at->lte(now())) {
                $this->finish($row, $dose, MedicationSecondPersonConfirmation::EXPIRED, null);
                $events = [$this->eventData($row, $dose, null)];
                $this->followups->complete($row, null, $events);
                app(MedicationEventRecorder::class)->appendMany($events);

                return ['status' => MedicationSecondPersonConfirmation::EXPIRED, 'replayed' => false];
            }
            $this->finish($row, $dose, $answer, (int) $actor->id);
            $events = [$this->eventData($row, $dose, (int) $actor->id)];
            $this->followups->complete($row, $currentActor, $events);
            app(MedicationEventRecorder::class)->appendMany($events);

            return ['status' => $answer, 'replayed' => false];
        }, 5);
    }

    /** System expiry never depends on a person opening the notification. */
    public function expireDue(): int
    {
        $count = 0;
        MedicationSecondPersonConfirmation::query()->where('status', MedicationSecondPersonConfirmation::PENDING)
            ->where('due_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$count): void {
                foreach ($rows as $snapshot) {
                    $count += (int) DB::transaction(function () use ($snapshot): bool {
                        $doseSnapshot = ClientMedicationAdministration::withTrashed()->find($snapshot->administration_id);
                        if ($doseSnapshot === null) {
                            return false;
                        }
                        $client = Client::withTrashed()->whereKey($doseSnapshot->client_id)->lockForUpdate()->firstOrFail();
                        $medication = ClientMedication::withTrashed()->whereKey($doseSnapshot->client_medication_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
                        $dose = ClientMedicationAdministration::withTrashed()->whereKey($doseSnapshot->id)->lockForUpdate()->firstOrFail();
                        $row = MedicationSecondPersonConfirmation::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                        if ($row->status !== MedicationSecondPersonConfirmation::PENDING || $row->due_at->isFuture()) {
                            return false;
                        }
                        $dose->setRelation('client', $client);
                        $dose->setRelation('medication', $medication);
                        $this->finish($row, $dose, MedicationSecondPersonConfirmation::EXPIRED, null);
                        $events = [$this->eventData($row, $dose, null)];
                        $this->followups->complete($row, null, $events);
                        app(MedicationEventRecorder::class)->appendMany($events);

                        return true;
                    }, 5);
                }
            });

        return $count;
    }

    /**
     * LAST after all domain writes/receipt. No PIN, credential hash or pepper
     * enters the clinical chain. Calling again for a replay is prohibited.
     */
    public function appendEvent(MedicationSecondPersonConfirmation $row, ClientMedicationAdministration $dose, ?int $actorId): void
    {
        app(MedicationEventRecorder::class)->append($this->eventData($row, $dose, $actorId));
    }

    public function eventData(MedicationSecondPersonConfirmation $row, ClientMedicationAdministration $dose, ?int $actorId): MedicationEventData
    {
        $client = $dose->client;

        return new MedicationEventData(
            siteId: (int) $client->site_id,
            kind: 'second_person.'.$row->status,
            subjectType: 'second_person_confirmation',
            subjectId: (string) $row->id,
            actorId: $actorId,
            occurredAt: CarbonImmutable::now('UTC'),
            summary: match ($row->status) {
                MedicationSecondPersonConfirmation::PENDING => 'Second-person confirmation requested.',
                MedicationSecondPersonConfirmation::CONFIRMED => 'Second-person confirmation recorded.',
                MedicationSecondPersonConfirmation::DISPUTED => 'Second-person confirmation disputed.',
                default => 'Second-person confirmation expired.',
            },
            facts: [
                'administration_id' => (int) $dose->id,
                'nominated_user_id' => (int) $row->nominated_user_id,
                'due_at' => $row->due_at->toIso8601String(),
                'status' => $row->status,
            ],
            clientId: $client->trashed() ? null : (int) $client->id,
        );
    }

    private function finish(MedicationSecondPersonConfirmation $row, ClientMedicationAdministration $dose, string $status, ?int $actorId): void
    {
        $row->forceFill(['status' => $status, 'responded_at' => $actorId !== null ? now() : null])->save();
        if ($status === MedicationSecondPersonConfirmation::CONFIRMED) {
            $dose->forceFill([
                'second_person_status' => RecordingContract::SECOND_VERIFIED,
                'witnessed_by' => $row->nominated_user_id,
                'witnessed_at' => now(),
                'witness_method' => self::CONFIRMED_METHOD,
            ])->save();
        } else {
            // One flag on the original record: the existing P01/P08a lead
            // follow-up reads it. No separate duplicate clinical task or dose.
            $dose->forceFill([
                'second_person_status' => $status,
                'review_required' => true,
                'review_reason_key' => $status === MedicationSecondPersonConfirmation::DISPUTED ? self::REVIEW_DISPUTED : self::REVIEW_EXPIRED,
                'review_reason' => $status === MedicationSecondPersonConfirmation::DISPUTED
                    ? 'Second person says they were not there — check this dose.'
                    : 'Second person did not confirm within 30 minutes — check this dose.',
                'review_flagged_at' => now(),
                'review_flagged_by' => $row->recorded_by,
            ])->save();
        }
        AuditLogger::logOrFail('medications.second_person.'.$status, $dose, [
            'actor_id' => $actorId,
            'confirmation_id' => (int) $row->id,
            'nominated_user_id' => (int) $row->nominated_user_id,
        ], systemActor: $actorId === null);
    }

    private function mayRead(User $actor): bool
    {
        if ($actor->approved_at === null || ! $actor->canDo('medications.view')
            || ! $actor->canDo('medications.controlled.witness')) {
            return false;
        }
        $day = now()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return HrEmployeeProfile::query()->where('user_id', $actor->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $day))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $day))->exists();
    }

    private function canonical(MedicationSecondPersonConfirmation $row): bool
    {
        $dose = $row->administration;
        $medication = $dose?->medication;

        return $dose !== null && $dose->client !== null && $medication !== null
            && (int) $medication->client_id === (int) $dose->client_id
            && (int) $row->site_id === (int) $dose->client->site_id
            && (int) $row->recorded_by === (int) $dose->administered_by
            && (int) $row->nominated_user_id !== (int) $dose->administered_by
            && ! $medication->controlled_drug
            && $dose->status === 'given'
            && ClientMedicationAdministration::query()->whereKey($dose->id)->effectiveClinicalEvidence()->exists();
    }
}
