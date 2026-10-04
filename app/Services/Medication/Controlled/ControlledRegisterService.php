<?php

namespace App\Services\Medication\Controlled;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlledWitnessOverride;
use App\Models\ControlledWitnessRequest;
use App\Models\ControlledWorkflowEvent;
use App\Models\MedicationDestruction;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ControlledWitnessRequested;
use App\Services\AuditLogger;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationIncidentIntegrationService;
use App\Support\Medication\MedicationStockQuantity as Quantity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ControlledRegisterService
{
    public const MANAGE = 'medications.controlled.manage';

    private const STOCK_ACTIONS = ['count', 'movement', 'void', 'resolve', 'loss_report', 'destruction'];

    private const MANAGE_ACTIONS = ['void', 'resolve', 'loss_close', 'destruction_receipt', 'destruction_void', 'class_review', 'override_signoff'];

    private ?string $newPhotoPath = null;

    private bool $lastRequestWasReplay = false;

    public function lastRequestWasReplay(): bool
    {
        return $this->lastRequestWasReplay;
    }

    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly ControlledPolicy $policy,
        private readonly ControlledLocalTime $time,
    ) {}

    public function perform(User $actor, string $action, array $input): array
    {
        $capability = in_array($action, self::MANAGE_ACTIONS, true) ? self::MANAGE : MedicationGovernanceScopeService::CONTROLLED_CAPABILITY;
        if ($action === 'override_decide') {
            $capability = 'medications.controlled.override';
        }
        if ($action === 'witness_answer') {
            $capability = 'medications.controlled.witness';
        }
        $this->newPhotoPath = null;
        $this->lastRequestWasReplay = false;
        try {
            return $this->scope->forMedication(
                $actor, (int) $input['client_medication_id'], $capability,
                function (Client $client, ClientMedication $medication, User $lockedActor, Collection $lockedUsers) use ($action, $input): array {
                    abort_unless($medication->controlled_drug && $lockedActor->canDo('medications.controlled.view'), 404);
                    $medication->setRelation('client', $client);
                    $this->access->assertReadable($lockedActor, $client);
                    $auditEvents = [];
                    abort_if(isset($input['client_id']) && (int) $input['client_id'] !== (int) $client->id, 404);
                    abort_if(isset($input['site_id']) && (int) $input['site_id'] !== (int) $client->site_id, 404);
                    if ($action !== 'destruction' && isset($input['medication_name'])) {
                        abort_unless($input['medication_name'] === $medication->name, 404);
                    }
                    $stock = ClientMedicationStock::query()->where('client_medication_id', $medication->id)->lockForUpdate()->first();
                    $witness = null;
                    $second = null;
                    if (in_array($action, self::STOCK_ACTIONS, true) && ! ($action === 'resolve' && ($input['outcome'] ?? '') === 'escalate')) {
                        $this->assertPresent($lockedActor, (int) $client->site_id, true);
                        $witness = $this->scope->confirmedControlledWitness(
                            $lockedActor, $client, (int) ($input['witnessed_by'] ?? 0), $input['witness_credential'] ?? null,
                            lockedUsers: $lockedUsers, effectiveAt: now(),
                        );
                        if ($action === 'destruction' && ($input['method'] ?? '') === 'denaturing') {
                            if ((int) ($input['second_witness_id'] ?? 0) === (int) $witness->id) {
                                throw ValidationException::withMessages(['second_witness_id' => 'Choose two different witnesses.']);
                            }
                            $second = $this->scope->confirmedControlledWitness(
                                $lockedActor, $client, (int) ($input['second_witness_id'] ?? 0), $input['second_witness_credential'] ?? null,
                                'second_witness_id', 'second_witness_credential', lockedUsers: $lockedUsers, effectiveAt: now(),
                            );
                            if ($second->id === $witness->id) {
                                throw ValidationException::withMessages(['second_witness_id' => 'Choose two different witnesses.']);
                            }
                        }
                    }
                    // Credentials and current witness evidence precede the durable receipt.
                    // A valid replay does not need the original stock snapshot to remain current.
                    $binding = $input;
                    unset($binding['witness_credential'], $binding['second_witness_credential'], $binding['photo_upload']);
                    foreach (['quantity', 'expected_balance', 'actual_balance', 'recount_balance', 'correction_quantity', 'on_hand_before', 'on_hand_after'] as $quantityField) {
                        if (isset($binding[$quantityField])) {
                            $binding[$quantityField] = Quantity::normalize($binding[$quantityField]);
                        }
                    }
                    ksort($binding);
                    $fingerprint = hash('sha256', json_encode([$action, $binding], JSON_THROW_ON_ERROR));
                    $previous = DB::table('controlled_product_requests')->where('actor_id', $lockedActor->id)->where('request_uuid', $input['client_request_uuid'])->first();
                    if ($previous !== null) {
                        abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, 'This request was already used for different details.');
                        $this->lastRequestWasReplay = true;

                        return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                    }
                    // A new historical count needs retained stock; a recorded recount
                    // remains replayable when that recount cleared the stock to zero.
                    if ($action === 'count' && ($medication->deleted_at !== null || $medication->superseded_by !== null)) {
                        abort_unless($stock !== null && Quantity::greaterThan($stock->on_hand, 0), 404);
                    }
                    if (in_array($action, self::STOCK_ACTIONS, true)) {
                        $this->checkSnapshot($medication, $stock, $input);
                    }
                    $result = match ($action) {
                        'count' => $this->count($lockedActor, $medication, $stock, $witness, $input),
                        'movement' => $this->movement($lockedActor, $medication, $stock, $witness, $input),
                        'void' => $this->voidEntry($lockedActor, $medication, $stock, $witness, $input),
                        'resolve' => $this->resolve($lockedActor, $medication, $stock, $witness, $input),
                        'loss_report' => $this->loss($lockedActor, $medication, $stock, $witness, $input),
                        'loss_note', 'loss_notify', 'loss_close' => $this->lossFollowUp($lockedActor, $medication, $action, $input),
                        'destruction' => $this->destruction($lockedActor, $medication, $stock, $witness, $second, $input),
                        'destruction_receipt', 'destruction_void' => $this->destructionFollowUp($lockedActor, $medication, $stock, $witness, $action, $input),
                        'class_review' => $this->classReview($lockedActor, $medication, $input),
                        'witness_request', 'witness_answer', 'witness_cancel' => $this->request($lockedActor, $medication, $action, $input),
                        'override_request', 'override_decide', 'override_signoff' => $this->override($lockedActor, $medication, $action, $input, $auditEvents),
                        default => abort(404),
                    };
                    DB::table('controlled_product_requests')->insert([
                        'actor_id' => $lockedActor->id, 'request_uuid' => $input['client_request_uuid'],
                        'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    // The event-chain head is always the final lock. Retries above return the receipt.
                    $auditEvents[] = new MedicationEventData(
                        siteId: (int) $client->site_id, kind: 'controlled.'.$action, subjectType: 'controlled_medicine',
                        subjectId: (string) $medication->id, actorId: (int) $lockedActor->id,
                        occurredAt: CarbonImmutable::now('UTC'), summary: 'Controlled medicine '.$action.' recorded',
                        facts: ['request_uuid' => $input['client_request_uuid'], 'result' => $result],
                        clientId: (int) $client->id, controlled: true,
                    );
                    app(MedicationEventRecorder::class)->appendMany($auditEvents);

                    return $result;
                },
                authorizationUserIds: array_values(array_filter([(int) $actor->id, (int) ($input['witnessed_by'] ?? 0), (int) ($input['second_witness_id'] ?? 0), (int) ($input['witness_id'] ?? 0)])),
                authorizationEffectiveAt: now(),
                // Historical maintenance cannot create a dose or stock movement; only a witnessed count of retained positive stock is allowed.
                currentOnly: ! in_array($action, ['count', 'void', 'resolve', 'loss_note', 'loss_notify', 'loss_close', 'destruction_receipt', 'destruction_void', 'class_review', 'override_signoff'], true),
            );
        } catch (\Throwable $exception) {
            if ($this->newPhotoPath !== null) {
                Storage::disk('local')->delete($this->newPhotoPath);
            }
            throw $exception;
        }
    }

    /**
     * P06 owns aggregate/delivery locks and receipt UUID idempotency. This
     * writes the authoritative CD balance ONCE, then P06 attaches lot and
     * received-total evidence inside the same transaction. It must not add
     * stock a second time. A callback failure rolls the whole receipt back.
     */
    public function recordReceipt(User $actor, Client $client, ClientMedication $medication, ClientMedicationStock $stock, array $input, Collection $lockedUsers, Closure $attachLot): ClientControlledDrugEntry
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A controlled receipt must run in the governing delivery transaction.');
        }
        abort_unless($medication->controlled_drug && (int) $medication->client_id === (int) $client->id && (int) $stock->client_medication_id === (int) $medication->id, 404);
        $medication->setRelation('client', $client);
        $this->access->assertReadable($actor, $client);
        $this->assertPresent($actor, (int) $client->site_id, true);
        $witness = $this->scope->confirmedControlledWitness($actor, $client, (int) $input['witnessed_by'], $input['witness_credential'] ?? null, lockedUsers: $lockedUsers);
        $quantity = $this->positive($input, 'quantity');
        $entry = $this->write($actor, $medication, $stock, $witness, 'receipt', $quantity, Quantity::add($stock->on_hand, $quantity), 'Pharmacy delivery', [
            'pharmacy_order_id' => $input['pharmacy_order_id'] ?? null,
            'batch_number' => $input['batch_number'] ?? null, 'expiry_date' => $input['expiry_date'] ?? null,
            'source_type' => 'pharmacy_receipt', 'source_id' => $input['receipt_id'] ?? null,
        ]);
        $attachLot($entry, $quantity);

        return $entry;
    }

    public function assertPresent(User $actor, int $siteId, bool $lock = false): void
    {
        $at = now()->utc();
        $attendance = HrAttendanceSession::query()->where('user_id', $actor->id)->where('site_id', $siteId)->where('status', 'open')
            ->where('clock_in_at', '<=', $at)->whereNull('clock_out_at')->when($lock, fn ($q) => $q->lockForUpdate())->exists();
        $shifts = $actor->relationLoaded('controlledMedicationPresenceShifts') ? $actor->getRelation('controlledMedicationPresenceShifts') : collect();
        $shift = $shifts->contains(fn (Shift $s): bool => (int) $s->user_id === (int) $actor->id
            && ((int) $s->site_id === $siteId || ($s->site_id === null && (int) $s->client?->site_id === $siteId))
            && in_array($s->status, ['in_progress', 'active', 'clocked_in', 'started'], true)
            && $s->starts_at?->lte($at) && $s->ends_at?->gte($at));
        if (! $attendance && ! $shift) {
            throw ValidationException::withMessages(['presence' => 'You need to be clocked in at this house to record a controlled medicine check.']);
        }
    }

    private function checkSnapshot(ClientMedication $medication, ?ClientMedicationStock $stock, array $input): void
    {
        if ($stock === null || $stock->on_hand === null) {
            throw ValidationException::withMessages(['expected_balance' => 'No stock balance is recorded. Record a witnessed receipt first.']);
        }
        $latest = ClientControlledDrugEntry::query()->where('client_medication_id', $medication->id)->latest('id')->value('id');
        if (! isset($input['expected_balance']) || ! Quantity::equals($stock->on_hand, $input['expected_balance'])
            || ! array_key_exists('expected_entry_id', $input) || (int) $input['expected_entry_id'] !== (int) $latest) {
            abort(409, 'The register changed. Review the current balance and count again. Your entered values have been kept.');
        }
    }

    private function count(User $actor, ClientMedication $medication, ClientMedicationStock $stock, User $witness, array $input): array
    {
        $first = $this->nonnegative($input, 'actual_balance');
        $actual = Quantity::equals($first, $stock->on_hand) ? $first : $this->nonnegative($input, 'recount_balance');
        $differs = ! Quantity::equals($actual, $stock->on_hand);
        if ($differs) {
            $this->requiredText($input, 'notes');
            $this->requiredText($input, 'immediate_action_taken');
        }
        $expected = $stock->on_hand;
        $entry = $this->write($actor, $medication, $stock, $witness, 'balance_check', $actual, $actual, 'Witnessed count', [
            'first_count' => $first, 'recount' => Quantity::equals($first, $expected) ? null : $actual,
            'count_due_at' => $this->policy->countStatus($medication, now(), $stock->last_counted_at)['due_at'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);
        $stock->update(['last_counted_at' => now()]);
        $discrepancy = null;
        if ($differs) {
            $discrepancy = ClientControlledDrugDiscrepancy::create([
                'client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
                'service_context_id' => $medication->client->service_context_id,
                'count_entry_id' => $entry->id, 'owner_id' => $this->houseLead((int) $medication->client->site_id)?->id,
                'on_hand_before' => $expected, 'on_hand_after' => $actual,
                'difference' => Quantity::subtract($actual, $expected), 'reason' => 'Witnessed recount discrepancy',
                'reported_by' => $actor->id, 'witnessed_by' => $witness->id, 'notes' => $input['notes'],
                'immediate_action_taken' => $input['immediate_action_taken'], 'status' => 'open', 'reported_at' => now(),
            ]);
            app(MedicationIncidentIntegrationService::class)->handleControlledDiscrepancy($discrepancy, $actor->id);
        }
        ControlledWitnessRequest::query()->where('client_medication_id', $medication->id)->whereNull('closed_at')->where('purpose', 'count')->update(['closed_at' => now()]);
        $siteId = (int) $medication->client->site_id;
        DB::afterCommit(fn () => app(MedicationAlertSources::class)->controlledCheckRecorded($siteId));

        return ['message' => $differs ? 'Count saved. A discrepancy has been started.' : 'Count saved — matches the register.', 'entry_id' => $entry->id, 'counted_entry_id' => $entry->id, 'discrepancy_id' => $discrepancy?->id];
    }

    private function movement(User $actor, ClientMedication $medication, ClientMedicationStock $stock, User $witness, array $input): array
    {
        foreach (['on_hand_before' => 'expected_balance', 'on_hand_after' => 'actual_balance'] as $alias => $canonical) {
            if (isset($input[$alias]) && ! Quantity::equals($input[$alias], $input[$canonical])) {
                throw ValidationException::withMessages([$alias => 'This balance must match the witnessed register transition.']);
            }
        }
        $type = $input['movement_type'] ?? (($input['direction'] ?? '') === 'in' ? 'coming_back' : 'going_out');
        $this->choice($type, ['going_out', 'coming_back', 'breakage', 'spillage'], 'movement_type');
        $quantity = $this->positive($input, 'quantity');
        $after = $type === 'coming_back' ? Quantity::add($stock->on_hand, $quantity) : Quantity::subtract($stock->on_hand, $quantity);
        if (! Quantity::equals($this->nonnegative($input, 'actual_balance'), $after)) {
            throw ValidationException::withMessages(['actual_balance' => 'The counted balance does not match. Count again before saving this movement.']);
        }
        if (in_array($type, ['breakage', 'spillage'], true)) {
            $this->requiredText($input, 'notes');
        }
        $entry = $this->write($actor, $medication, $stock, $witness, $type === 'coming_back' ? 'transfer_in' : 'transfer_out', $quantity, $after, str_replace('_', ' ', $type), ['notes' => $input['notes'] ?? null]);

        return ['message' => 'Witnessed movement saved.', 'entry_id' => $entry->id];
    }

    private function voidEntry(User $actor, ClientMedication $medication, ClientMedicationStock $stock, User $witness, array $input): array
    {
        $original = $this->entryTarget($medication, (int) ($input['target_id'] ?? 0));
        $reason = $this->requiredText($input, 'notes');
        if ($original->entry_type === 'balance_check' || $original->reverses_entry_id !== null) {
            throw ValidationException::withMessages(['target_id' => 'Counts and reversal entries remain as evidence. Record a new witnessed count instead.']);
        }
        abort_if(ClientControlledDrugEntry::query()->where('reverses_entry_id', $original->id)->exists(), 409, 'This entry was already voided.');
        $delta = Quantity::subtract($original->on_hand_after, $original->on_hand_before);
        $reversal = $this->write($actor, $medication, $stock, $witness, 'reversal', Quantity::absoluteDifference($original->on_hand_after, $original->on_hand_before), Quantity::subtract($stock->on_hand, $delta), $reason, ['reverses_entry_id' => $original->id]);
        if (isset($input['correction_quantity'])) {
            $quantity = $this->positive($input, 'correction_quantity');
            $this->choice($input['correction_direction'] ?? '', ['in', 'out'], 'correction_direction');
            $after = $input['correction_direction'] === 'in' ? Quantity::add($stock->on_hand, $quantity) : Quantity::subtract($stock->on_hand, $quantity);
            $this->write($actor, $medication, $stock, $witness, 'correction', $quantity, $after, $reason, ['source_type' => 'entry_correction', 'source_id' => $original->id]);
        }
        $this->event($actor, $medication, 'entry', $original->id, 'void', ['reason' => $reason, 'reversal_id' => $reversal->id, 'witnessed_by' => $witness->id]);

        return ['message' => 'Entry voided. The original and witnessed correction remain in the register.', 'entry_id' => $reversal->id];
    }

    private function resolve(User $actor, ClientMedication $medication, ClientMedicationStock $stock, ?User $witness, array $input): array
    {
        $d = ClientControlledDrugDiscrepancy::query()->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->lockForUpdate()->findOrFail((int) ($input['target_id'] ?? 0));
        if (in_array((int) $actor->id, [(int) $d->reported_by, (int) $d->witnessed_by], true)) {
            throw ValidationException::withMessages(['target_id' => 'Someone who did not count or witness the count must resolve this discrepancy.']);
        }
        abort_unless(in_array($d->status, ['open', 'under_review'], true), 409, 'This discrepancy has already been resolved.');
        $outcome = $input['outcome'] ?? '';
        $this->choice($outcome, ['recount', 'recording', 'found', 'loss', 'escalate'], 'outcome');
        $notes = $this->requiredText($input, 'notes');
        if ($outcome === 'recount') {
            $actual = $this->nonnegative($input, 'actual_balance');
            $target = Quantity::subtract($stock->on_hand, $d->difference);
            if (! Quantity::equals($actual, $target)) {
                throw ValidationException::withMessages(['actual_balance' => 'This recount does not reconcile the original difference and subsequent register movements.']);
            }
            $this->write($actor, $medication, $stock, $witness, 'reconciliation', Quantity::absoluteDifference($actual, $stock->on_hand), $actual, $notes, ['source_type' => 'discrepancy', 'source_id' => $d->id]);
        } elseif ($outcome === 'found') {
            $quantity = $this->positive($input, 'quantity');
            if (! Quantity::greaterThan($d->on_hand_before, $d->on_hand_after)
                || ! Quantity::equals($quantity, Quantity::absoluteDifference($d->on_hand_before, $d->on_hand_after))) {
                throw ValidationException::withMessages(['quantity' => 'The found quantity must reconcile this discrepancy’s recorded shortfall.']);
            }
            $this->write($actor, $medication, $stock, $witness, 'reconciliation', $quantity, Quantity::add($stock->on_hand, $quantity), $notes, ['source_type' => 'discrepancy', 'source_id' => $d->id]);
        } elseif ($outcome === 'recording') {
            $target = Quantity::subtract($stock->on_hand, $d->difference);
            $void = $input;
            $void['target_id'] = (int) ($input['entry_id'] ?? 0);
            $this->voidEntry($actor, $medication, $stock, $witness, $void);
            if (! Quantity::equals($stock->on_hand, $target)) {
                throw ValidationException::withMessages(['entry_id' => 'This correction does not reconcile the recorded difference and later movements.']);
            }
        } elseif ($outcome === 'loss') {
            // The count already moved the register to actual stock: never subtract the same shortfall twice.
            if (! Quantity::greaterThan($d->on_hand_before, $d->on_hand_after)) {
                throw ValidationException::withMessages(['outcome' => 'This discrepancy is not a stock shortfall.']);
            }
            $quantity = Quantity::absoluteDifference($d->on_hand_before, $d->on_hand_after);
            $lossEntry = $this->write($actor, $medication, $stock, $witness, 'loss', $quantity, $stock->on_hand, $notes, ['source_type' => 'discrepancy', 'source_id' => $d->id]);
            $this->createLoss($actor, $medication, $quantity, $notes, $this->requiredText($input, 'immediate_action_taken'), $lossEntry->id, (bool) ($input['suspected_theft'] ?? false));
        }
        $d->update(['status' => $outcome === 'escalate' ? 'under_review' : 'closed', 'resolution_outcome' => $outcome,
            'resolution_notes' => $notes, 'resolved_by' => $outcome === 'escalate' ? null : $actor->id, 'resolved_at' => $outcome === 'escalate' ? null : now()]);
        $this->event($actor, $medication, 'discrepancy', $d->id, 'resolve', ['outcome' => $outcome, 'notes' => $notes, 'witnessed_by' => $witness?->id]);
        if ($d->incident_id && ($incident = $d->incident()->lockForUpdate()->first())) {
            // Append detail only. Incidents authority owns incident closure.
            $incident->update(['description' => $incident->description."\n\nControlled discrepancy update: ".$notes]);
        }
        if ($outcome !== 'escalate') {
            app(MedicationIncidentIntegrationService::class)->resolveControlledDiscrepancy($d, $notes, $actor->id);
        }

        return ['message' => $outcome === 'escalate' ? 'Discrepancy escalated — remains under review.' : 'Independent resolution saved.', 'target_id' => $d->id];
    }

    private function loss(User $actor, ClientMedication $medication, ClientMedicationStock $stock, User $witness, array $input): array
    {
        $quantity = $this->positive($input, 'quantity');
        $notes = $this->requiredText($input, 'notes');
        $immediate = $this->requiredText($input, 'immediate_action_taken');
        $entry = $this->write($actor, $medication, $stock, $witness, 'loss', $quantity, Quantity::subtract($stock->on_hand, $quantity), $notes);
        $loss = $this->createLoss($actor, $medication, $quantity, $notes, $immediate, $entry->id, (bool) ($input['suspected_theft'] ?? false));
        $loss->update(['accountable_officer_name' => $input['accountable_officer_name'] ?? null]);
        if (isset($input['discovered_at'])) {
            $loss->update(['discovered_at' => $this->time->parse($input['discovered_at'], 'discovered_at')]);
        }
        foreach (['police', 'regulator'] as $authority) {
            if ($input['reported_to_'.$authority] ?? false) {
                $reference = $this->requiredText($input, $authority.'_reference');
                $this->lossFollowUp($actor, $medication, 'loss_notify', ['target_id' => $loss->id, 'authority' => $authority, 'reference' => $reference, 'regulator_name' => $input['regulator_name'] ?? null, 'notes' => 'Notification recorded with the loss report.']);
            }
        }
        $this->event($actor, $medication, 'loss', $loss->id, 'reported', ['notes' => $notes, 'entry_id' => $entry->id, 'witnessed_by' => $witness->id]);

        return ['message' => 'Loss and witnessed register entry saved. Investigation started.', 'target_id' => $loss->id, 'entry_id' => $entry->id];
    }

    private function createLoss(User $actor, ClientMedication $medication, string $quantity, string $notes, string $immediate, ?int $entryId, bool $theft): ControlledDrugLossReport
    {
        $loss = ControlledDrugLossReport::create([
            'client_id' => $medication->client_id, 'client_medication_id' => $medication->id, 'medication_name' => $medication->name,
            'quantity_lost' => $quantity, 'unit' => $medication->stock?->unit ?? '', 'circumstances' => $notes,
            'immediate_action_taken' => $immediate, 'register_entry_id' => $entryId, 'suspected_theft' => $theft,
            'discovered_by' => $actor->id, 'discovered_at' => now(), 'investigation_status' => 'reported',
        ]);
        app(MedicationIncidentIntegrationService::class)->handleControlledLossReport($loss, $actor->id);

        return $loss;
    }

    private function lossFollowUp(User $actor, ClientMedication $medication, string $action, array $input): array
    {
        $loss = ControlledDrugLossReport::query()->where('client_id', $medication->client_id)->where('client_medication_id', $medication->id)->lockForUpdate()->findOrFail((int) ($input['target_id'] ?? 0));
        abort_unless(in_array($loss->investigation_status, ['reported', 'investigating'], true), 409, 'This loss investigation is already closed.');
        $notes = $this->requiredText($input, 'notes');
        if ($action === 'loss_close') {
            abort_unless($actor->hasRole('provider_manager'), 403);
            $theft = $loss->suspected_theft || (bool) ($input['suspected_theft'] ?? false) || ($input['resolution_outcome'] ?? '') === 'theft';
            if ($theft && (! $loss->reported_to_police || blank($loss->police_reference))) {
                throw ValidationException::withMessages(['police_reference' => 'Record the police notification and reference before closing a suspected theft.']);
            }
            if (! ($input['notifications_checked'] ?? false)) {
                throw ValidationException::withMessages(['notifications_checked' => 'Review the required notifications before closing this investigation.']);
            }
            $loss->update(['investigation_status' => 'resolved', 'resolution_outcome' => filled($input['resolution_outcome'] ?? null) ? $input['resolution_outcome'].': '.$notes : $notes,
                'suspected_theft' => $theft, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            app(MedicationIncidentIntegrationService::class)->resolveControlledLossReport($loss, $notes, $actor->id);
        } elseif ($action === 'loss_notify') {
            $authority = $input['authority'] ?? '';
            $this->choice($authority, ['police', 'regulator', 'pharmacy'], 'authority');
            $at = $this->time->parse($input['notified_at'] ?? null, 'notified_at', defaultNow: true);
            $reference = $this->requiredText($input, 'reference');
            $fields = match ($authority) {
                'police' => ['reported_to_police' => true, 'police_reference' => $reference, 'police_reported_at' => $at],
                'regulator' => ['reported_to_regulator' => true, 'regulator_name' => $input['regulator_name'] ?? 'Medicines Control', 'regulator_reference' => $reference, 'regulator_notified_at' => $at],
                'pharmacy' => ['reported_to_pharmacy' => true, 'pharmacy_name' => $reference, 'pharmacy_notified_at' => $at],
            };
            $loss->update($fields);
        } else {
            $loss->update(['investigation_status' => 'investigating']);
        }
        if ($loss->incident_id && ($incident = $loss->incident()->where('client_id', $medication->client_id)->lockForUpdate()->first())) {
            $incident->update(['description' => $incident->description."\n\nControlled loss investigation update: ".$notes]);
        }
        $this->event($actor, $medication, 'loss', $loss->id, $action, ['notes' => $notes, 'authority' => $input['authority'] ?? null, 'reference' => $input['reference'] ?? null, 'notified_at' => $input['notified_at'] ?? null,
            'ready_to_close' => $input['ready_to_close'] ?? false, 'resolution_outcome' => $input['resolution_outcome'] ?? null, 'suspected_theft' => $loss->suspected_theft]);

        return ['message' => 'Investigation update saved. Earlier notes remain in the history.', 'target_id' => $loss->id];
    }

    private function destruction(User $actor, ClientMedication $medication, ClientMedicationStock $stock, User $witness, ?User $second, array $input): array
    {
        $method = $input['method'] ?? 'pharmacy_return';
        $this->choice($method, ['pharmacy_return', 'denaturing'], 'method');
        $this->choice($input['reason'] ?? '', ['expired', 'ceased', 'contaminated', 'damaged', 'deceased', 'discharged', 'surplus'], 'reason');
        if ($method === 'denaturing') {
            if (! $this->policy->onsiteAllowed((int) $medication->client->site_id)) {
                throw ValidationException::withMessages(['method' => 'On-site denaturing is not allowed. Return to the pharmacy.']);
            }
            if ((int) ($input['second_witness_id'] ?? 0) === (int) $witness->id) {
                throw ValidationException::withMessages(['second_witness_id' => 'Choose two different witnesses.']);
            }
            abort_unless($second instanceof User, 422);
        }
        $quantity = $this->positive($input, 'quantity');
        $entry = $this->write($actor, $medication, $stock, $witness, 'disposal', $quantity, Quantity::subtract($stock->on_hand, $quantity), $input['reason'], ['second_witness_id' => $second?->id, 'notes' => $input['notes'] ?? null]);
        $destruction = MedicationDestruction::create([
            'client_id' => $medication->client_id, 'client_medication_id' => $medication->id, 'site_id' => $medication->client->site_id,
            'medication_name' => $medication->name, 'form' => $medication->form, 'strength' => $medication->dosage,
            'quantity' => $quantity, 'unit' => $stock->unit,
            'reason' => $input['reason'], 'disposal_method' => $method, 'is_controlled_drug' => true,
            'controlled_drug_class' => $medication->nz_controlled_class, 'destroyed_by' => $actor->id,
            'authorised_by_name' => $input['authorised_by_name'] ?? null, 'authorised_by_registration' => $input['authorised_by_registration'] ?? null,
            'witness_1_id' => $witness->id, 'witness_2_id' => $second?->id, 'destroyed_at' => now(),
            'notes' => $input['notes'] ?? null, 'register_entry_id' => $entry->id,
        ]);
        if (isset($input['photo_upload'])) {
            // A transaction retry reuses this request's path, rather than leaving an orphan from the failed attempt.
            $name = $actor->id.'-'.$input['client_request_uuid'].'.'.$input['photo_upload']->extension();
            $this->newPhotoPath = $input['photo_upload']->storeAs('medications/controlled-destructions', $name, 'local');
            $destruction->update(['photo_path' => $this->newPhotoPath]);
        }
        $this->event($actor, $medication, 'destruction', $destruction->id, 'recorded', ['method' => $method, 'entry_id' => $entry->id, 'witnessed_by' => $witness->id, 'second_witness_id' => $second?->id]);

        return ['message' => $method === 'pharmacy_return' ? 'Return recorded. Waiting for the pharmacist’s receipt.' : 'Witnessed destruction saved.', 'target_id' => $destruction->id, 'entry_id' => $entry->id];
    }

    private function destructionFollowUp(User $actor, ClientMedication $medication, ?ClientMedicationStock $stock, ?User $witness, string $action, array $input): array
    {
        $d = MedicationDestruction::query()->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->lockForUpdate()->findOrFail((int) ($input['target_id'] ?? 0));
        abort_if($d->voided_at !== null, 409, 'This destruction has already been voided.');
        if ($action === 'destruction_void') {
            $reason = $this->requiredText($input, 'notes');
            $d->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            AuditLogger::logOrFail('medications.destruction.void', $d, [
                'actor_id' => $actor->id, 'client_id' => $medication->client_id,
                'client_medication_id' => $medication->id, 'void_reason' => $reason,
                'void_stock_semantics' => MedicationDestruction::VOID_STOCK_SEMANTICS,
                'stock_effect_reversed' => false, 'requires_governed_stock_reconciliation' => true,
            ]);
            $result = ['message' => 'Destruction record voided. Stock and register balances were not changed; record any correction through witnessed reconciliation.',
                'target_id' => $d->id, 'entry_id' => $d->register_entry_id];
        } else {
            abort_unless($d->disposal_method === 'pharmacy_return' && $d->pharmacy_received_at === null, 409, 'A receipt already exists or does not apply.');
            $receivedAt = $this->time->parse($input['received_at'] ?? null, 'received_at', defaultNow: true);
            if ($receivedAt->lt($d->destroyed_at)) {
                throw ValidationException::withMessages(['received_at' => 'The receipt cannot precede the recorded return.']);
            }
            $d->update(['pharmacist_name' => $this->requiredText($input, 'pharmacist_name'), 'pharmacist_registration' => $this->requiredText($input, 'pharmacist_registration'), 'pharmacy_received_at' => $receivedAt]);
            $result = ['message' => 'Pharmacist’s receipt recorded.', 'target_id' => $d->id];
        }
        $this->event($actor, $medication, 'destruction', $d->id, $action, ['notes' => $input['notes'] ?? null, 'pharmacist_name' => $input['pharmacist_name'] ?? null, 'pharmacist_registration' => $input['pharmacist_registration'] ?? null]);

        return $result;
    }

    private function classReview(User $actor, ClientMedication $medication, array $input): array
    {
        $this->choice($input['nz_class'] ?? '', ['A', 'B', 'C'], 'nz_class');
        $source = $this->requiredText($input, 'source');
        $medication->forceFill(['nz_controlled_class' => $input['nz_class'], 'controlled_class_reviewed_by' => $actor->id, 'controlled_class_reviewed_at' => now(), 'controlled_class_source' => $source])->save();
        $this->event($actor, $medication, 'medicine', $medication->id, 'class_review', ['nz_class' => $input['nz_class'], 'source' => $source, 'notes' => $input['notes'] ?? null]);

        return ['message' => 'NZ class review saved. The legacy schedule value is preserved.'];
    }

    private function request(User $actor, ClientMedication $medication, string $action, array $input): array
    {
        if ($action === 'witness_request') {
            $this->assertPresent($actor, (int) $medication->client->site_id, true);
            $witnessId = (int) ($input['witness_id'] ?? 0);
            $eligible = app(ControlledMedicationTransportWitnessService::class)->eligibleWitnessesForSite((int) $medication->client->site_id, now(), $actor->id)->firstWhere('id', $witnessId);
            abort_unless($eligible && app(WitnessPinService::class)->isUsable($eligible), 404);
            $row = ControlledWitnessRequest::create(['site_id' => $medication->client->site_id, 'client_medication_id' => $medication->id, 'requested_by' => $actor->id, 'addressed_to' => $witnessId, 'purpose' => $input['purpose'] ?? 'count', 'reason' => $input['notes'] ?? null]);
            DB::afterCommit(fn () => User::find($witnessId)?->notify(new ControlledWitnessRequested($row->id, (int) $row->site_id)));
        } else {
            $row = ControlledWitnessRequest::query()->where('client_medication_id', $medication->id)->where('site_id', $medication->client->site_id)->lockForUpdate()->findOrFail((int) ($input['target_id'] ?? 0));
            abort_if($row->closed_at !== null, 409, 'This request is already closed.');
            if ($action === 'witness_answer') {
                abort_unless((int) $row->addressed_to === (int) $actor->id, 404);
                $this->assertPresent($actor, (int) $row->site_id, true);
                $this->choice($input['response'] ?? '', ['on_my_way', 'cant_come'], 'response');
                $row->update(['response' => $input['response'], 'reason' => $input['response'] === 'cant_come' ? $this->requiredText($input, 'notes') : null, 'answered_at' => now()]);
            } else {
                abort_unless((int) $row->requested_by === (int) $actor->id, 404);
                $row->update(['closed_at' => now()]);
            }
        }
        $this->event($actor, $medication, 'request', $row->id, $action, ['response' => $input['response'] ?? null, 'notes' => $input['notes'] ?? null, 'addressed_to' => $row->addressed_to]);

        return ['message' => 'Witness request saved.', 'target_id' => $row->id];
    }

    private function override(User $actor, ClientMedication $medication, string $action, array $input, array &$auditEvents): array
    {
        if ($action === 'override_request') {
            $this->assertPresent($actor, (int) $medication->client->site_id, true);
            $medicineIds = $this->overrideMedicines($actor, $medication, $input['medicine_ids'] ?? [$medication->id]);
            $row = ControlledWitnessOverride::create(['site_id' => $medication->client->site_id, 'client_medication_id' => $medication->id, 'medicine_ids' => $medicineIds, 'requested_by' => $actor->id, 'reason' => $this->requiredText($input, 'notes')]);
        } else {
            $row = ControlledWitnessOverride::query()->where('site_id', $medication->client->site_id)->lockForUpdate()->findOrFail((int) ($input['target_id'] ?? 0));
            abort_unless(in_array((int) $medication->id, array_map('intval', $row->medicine_ids), true), 404);
            if ($action === 'override_decide') {
                abort_unless($row->status === 'waiting', 409, 'This override already has a decision.');
                if ((int) $row->requested_by === (int) $actor->id) {
                    throw ValidationException::withMessages(['target_id' => 'A different manager must decide your request.']);
                }
                $this->choice($input['decision'] ?? '', ['approved', 'declined'], 'decision');
                $fields = ['status' => $input['decision'], 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $this->requiredText($input, 'notes')];
                if ($input['decision'] === 'approved') {
                    $start = $this->time->parse($input['starts_at'] ?? null, 'starts_at', defaultNow: true, allowFuture: true);
                    $end = $this->time->parse($input['expires_at'] ?? null, 'expires_at', allowFuture: true);
                    $shift = Shift::query()->where('site_id', $row->site_id)->where('starts_at', '<=', $start)->where('ends_at', '>', $start)->whereNotIn('status', ['cancelled'])->orderBy('ends_at')->first();
                    if (! $shift || ! $end->gt($start) || $end->gt($shift->ends_at) || $end->lte(now())) {
                        throw ValidationException::withMessages(['expires_at' => 'The override must expire within one rostered shift.']);
                    }
                    $medicineIds = $this->overrideMedicines($actor, $medication, $input['medicine_ids'] ?? $row->medicine_ids);
                    $next = Shift::query()->where('site_id', $row->site_id)->where('starts_at', '>=', $shift->ends_at)->whereNotIn('status', ['cancelled'])->orderBy('starts_at')->first();
                    if (! $next) {
                        throw ValidationException::withMessages(['expires_at' => 'Roster the next shift before granting an override so its follow-up has a real deadline.']);
                    }
                    $fields += ['starts_at' => $start, 'expires_at' => $end, 'followup_due_at' => $next->ends_at, 'medicine_ids' => $medicineIds];
                }
                $row->update($fields);
            } else {
                abort_unless($actor->hasRole('team_lead', 'provider_manager'), 403);
                $dose = ClientMedicationAdministration::query()->where('witness_override_id', $row->id)->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->lockForUpdate()->findOrFail((int) ($input['administration_id'] ?? 0));
                $count = $this->entryTarget($medication, (int) ($input['counted_entry_id'] ?? 0));
                $doseEntryId = ClientControlledDrugEntry::query()->where('client_medication_id', $medication->id)
                    ->where('client_id', $medication->client_id)->where('client_medication_administration_id', $dose->id)->max('id');
                if ($count->entry_type !== 'balance_check' || $count->witnessed_by === null || $count->recorded_at->lt($dose->administered_at)
                    || $doseEntryId === null || $count->id <= $doseEntryId
                    || ! in_array((int) $actor->id, [(int) $count->recorded_by, (int) $count->witnessed_by], true)
                    || ! Quantity::equals($count->on_hand_before, $count->on_hand_after)) {
                    throw ValidationException::withMessages(['counted_entry_id' => 'Take part in a matching witnessed count after this dose before signing it off.']);
                }
                abort_if(ControlledWorkflowEvent::query()->where('subject_type', 'override_dose')->where('subject_id', $dose->id)->where('action', 'signed_off')->exists(), 409, 'This dose is already signed off.');
                $this->event($actor, $medication, 'override_dose', $dose->id, 'signed_off', ['override_id' => $row->id, 'counted_entry_id' => $count->id, 'notes' => $this->requiredText($input, 'notes')]);
                $doseIds = ClientMedicationAdministration::query()->where('witness_override_id', $row->id)->pluck('id');
                $done = ControlledWorkflowEvent::query()->where('subject_type', 'override_dose')->whereIn('subject_id', $doseIds)->where('action', 'signed_off')->count();
                if ($row->expires_at?->lte(now()) && $done === $doseIds->count()) {
                    $row->update(['signed_off_at' => now()]);
                }
                app(MedicationFollowupService::class)->completeFromSource(
                    'witness-override:dose-'.$dose->id, $actor, 'witnessed_count_signed_off',
                    ['override_id' => $row->id, 'counted_entry_id' => $count->id, 'notes' => $input['notes']], $auditEvents,
                );
            }
        }
        $this->event($actor, $medication, 'override', $row->id, $action, ['notes' => $input['notes'] ?? null, 'decision' => $input['decision'] ?? null]);

        return ['message' => 'Witness override update saved.', 'target_id' => $row->id];
    }

    private function write(User $actor, ClientMedication $medication, ClientMedicationStock $stock, User $witness, string $type, string $quantity, string $after, string $reason, array $extra = []): ClientControlledDrugEntry
    {
        if (! Quantity::lessThanOrEqual('0', $after)) {
            throw ValidationException::withMessages(['quantity' => 'This movement would leave a negative balance. Review the register.']);
        }
        $entry = ClientControlledDrugEntry::create([
            'client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
            'service_context_id' => $medication->client->service_context_id, 'entry_type' => $type, 'quantity' => $quantity,
            'unit' => $stock->unit, 'on_hand_before' => $stock->on_hand, 'on_hand_after' => $after,
            'reason' => $reason, 'recorded_by' => $actor->id, 'witnessed_by' => $witness->id, 'recorded_at' => now(), ...$extra,
        ]);
        $stock->update(['on_hand' => $after]);
        AuditLogger::logOrFail('medications.controlled.entry.record', $entry, ['actor_id' => $actor->id, 'witnessed_by' => $witness->id, 'source' => 'P07', 'witness_method' => WitnessPinService::METHOD, 'on_hand_after' => $after]);

        return $entry;
    }

    private function overrideMedicines(User $actor, ClientMedication $anchor, array $submitted): array
    {
        $ids = array_values(array_unique(array_map('intval', $submitted)));
        $medicines = ClientMedication::query()->whereIn('id', $ids)->where('controlled_drug', true)->whereNull('superseded_by')
            ->whereHas('client', fn ($q) => $q->where('site_id', $anchor->client->site_id))->get();
        abort_unless(count($ids) > 0 && $medicines->count() === count($ids) && in_array((int) $anchor->id, $ids, true), 404);
        foreach ($medicines as $medicine) {
            $this->access->assertReadable($actor, $medicine->client);
            if ($medicine->witness_required) {
                throw ValidationException::withMessages(['medicine_ids' => 'An explicit order witness requirement cannot be overridden.']);
            }
        }

        return $ids;
    }

    private function entryTarget(ClientMedication $medication, int $id): ClientControlledDrugEntry
    {
        return ClientControlledDrugEntry::query()->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->lockForUpdate()->findOrFail($id);
    }

    private function event(User $actor, ClientMedication $medication, string $type, int $id, string $action, array $payload): void
    {
        ControlledWorkflowEvent::create(['site_id' => $medication->client->site_id, 'client_medication_id' => $medication->id, 'subject_type' => $type, 'subject_id' => $id, 'action' => $action, 'payload' => $payload, 'actor_id' => $actor->id, 'created_at' => now()]);
        AuditLogger::logOrFail('medications.controlled.'.$action, $medication, ['subject_type' => $type, 'subject_id' => $id, 'actor_id' => $actor->id]);
    }

    private function houseLead(int $siteId): ?User
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', 'team_lead'))->whereHas('hrEmployeeProfile', fn ($q) => $q->where('is_active', true)->where('primary_site_id', $siteId))->orderBy('id')->first();
    }

    private function requiredText(array $input, string $key): string
    {
        $text = trim((string) ($input[$key] ?? ''));
        if ($text === '') {
            throw ValidationException::withMessages([$key => 'Enter '.$key.' before saving.']);
        }

        return $text;
    }

    private function choice(string $value, array $choices, string $key): void
    {
        if (! in_array($value, $choices, true)) {
            throw ValidationException::withMessages([$key => 'Choose one of the listed options.']);
        }
    }

    private function nonnegative(array $input, string $key): string
    {
        if (! isset($input[$key]) || ! is_numeric($input[$key])) {
            throw ValidationException::withMessages([$key => 'Enter the counted quantity.']);
        }
        $value = Quantity::normalizeMovement($input[$key]);
        if (! Quantity::lessThanOrEqual('0', $value)) {
            throw ValidationException::withMessages([$key => 'Enter zero or more.']);
        }

        return $value;
    }

    private function positive(array $input, string $key): string
    {
        $value = $this->nonnegative($input, $key);
        if (! Quantity::greaterThan($value, '0')) {
            throw ValidationException::withMessages([$key => 'Enter a quantity greater than zero.']);
        }

        return $value;
    }
}
