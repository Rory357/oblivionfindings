<?php

namespace App\Services\Medication\Recording;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\User;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\DoseSlots\DoseOrderTimelineFactory;
use App\Services\Medication\MedicationErrorReporter;
use App\Services\Medication\MedicationSecondPersonService;
use App\Services\Medication\RefusalEscalationPolicy;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationIncidentIntegrationService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The parts of the P01 recording contract EnhancedMarService::recordAdministration
 * applies on top of its existing checks. Kept here so the record path reads
 * as before and each rule is testable on its own:
 *
 *  - the shape of "what was given" (as ordered / less / more) and of the
 *    follow-up and effect-check times;
 *  - what the second person is for, and whether "Not confirmed by a second
 *    person" may be recorded — only for a medication rule or a smaller amount,
 *    and only when the roster shows nobody else who can confirm (Stephan Q2);
 *  - a re-offer after a refusal (NF-11): allowed on the same NZ day while the
 *    refusal's follow-up is open (Main, P01 Q8);
 *  - the refusal follow-up, the "more than ordered" medication error with its
 *    one linked incident, and the house-lead review flags (P01 Q2).
 *
 * Nothing here weakens a check: a controlled medicine or a restricted
 * co-signer still needs its second person, and every result is decided by
 * the server, never by the client's say-so.
 */
final class RecordingContractEnforcer
{
    public function __construct(
        private readonly ControlledMedicationTransportWitnessService $witnesses,
        private readonly WitnessPinService $pins,
        private readonly RefusalEscalationPolicy $refusalEscalation,
        private readonly MedicationErrorReporter $errors,
    ) {}

    /**
     * Shape checks that need no lock. Returns an EnhancedMarService error
     * result, or null when the request is well formed.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: false, error: string, error_field: string}|null
     */
    public function validate(array $data, ClientMedication $medication, CarbonInterface $administeredAt): ?array
    {
        $status = $data['status'] ?? null;
        $given = $status === 'given';
        $mode = $data['amount_mode'] ?? null;
        $ordered = $this->orderedAmount($medication);
        $quantity = $this->decimal($data['quantity_given'] ?? null);

        if ($given && $mode === RecordingContract::AMOUNT_LESS) {
            if ($quantity === null || $quantity <= 0) {
                return $this->error('quantity_given', 'Nothing given? Record it as refused or withheld instead.');
            }
            if ($ordered !== null && $quantity >= $ordered) {
                return $this->error('quantity_given', 'That isn’t less than the order ('.$this->amountLabel($ordered, $medication).'). If more was given, record “More than ordered was given”.');
            }
            if (blank($data['amount_reason'] ?? null)) {
                return $this->error('amount_reason', 'Choose why less was given.');
            }
        }

        if ($given && $mode === RecordingContract::AMOUNT_MORE) {
            if ($quantity === null || $quantity <= 0 || ($ordered !== null && $quantity <= $ordered)) {
                return $this->error('quantity_given', $ordered !== null
                    ? 'Enter the amount actually given — more than '.$this->amountLabel($ordered, $medication).'.'
                    : 'Enter the amount actually given.');
            }
            if (blank($data['more_severity'] ?? null)) {
                return $this->error('more_severity', 'Choose how serious it seems.');
            }
            if (blank($data['more_immediate_action'] ?? null)) {
                return $this->error('more_immediate_action', 'Say what you did straight away.');
            }
            if (blank($data['notes'] ?? null)) {
                return $this->error('notes', 'Say what happened — it goes into the medication error report.');
            }
        }

        if (filled($data['effect_check_due_at'] ?? null)) {
            if (! $given || ! $medication->is_prn) {
                return $this->error('effect_check_due_at', 'An effect check is only set for an as-needed dose that was given.');
            }
            if ($this->instant($data['effect_check_due_at'])->lt($this->minute($administeredAt))) {
                return $this->error('effect_check_due_at', 'Choose a check time after the dose was given.');
            }
        }

        if (filled($data['follow_up_due_at'] ?? null)) {
            if ($status !== 'refused' || $medication->is_prn) {
                return $this->error('follow_up_due_at', 'A follow-up time is only set for a refused dose.');
            }
            if ($this->instant($data['follow_up_due_at'])->lt($this->minute($administeredAt))) {
                return $this->error('follow_up_due_at', 'Choose a follow-up time after the refusal.');
            }
        }

        if (filled($data['reoffer_of_id'] ?? null)
            && ($medication->is_prn || ! in_array($status, ['given', 'refused'], true))) {
            return $this->error('reoffer_of_id', 'A re-offer is recorded as given or refused again, for a scheduled dose.');
        }

        return null;
    }

    /**
     * What the second person on a "given" dose is for, strongest first: a
     * witness (controlled or witness-required medicine), a restricted
     * worker's co-signer, a medication rule's second person, or a smaller
     * amount than ordered.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $adminRules
     */
    public function secondPersonKind(array $data, ClientMedication $medication, array $adminRules, bool $requiresCosigner, bool $controlledWitnessWaived = false): ?string
    {
        if (($data['status'] ?? null) !== 'given') {
            return null;
        }

        return match (true) {
            ($medication->witness_required || ! $controlledWitnessWaived) && $medication->requiresWitness() => RecordingContract::SECOND_WITNESS,
            $requiresCosigner => RecordingContract::SECOND_COSIGNER,
            (bool) ($adminRules['requires_countersign'] ?? false) => RecordingContract::SECOND_RULE,
            ($data['amount_mode'] ?? null) === RecordingContract::AMOUNT_LESS => RecordingContract::SECOND_AMOUNT,
            default => null,
        };
    }

    /** Only a medication rule's second person or a smaller amount may go unconfirmed (Q2). */
    public function mayGoUnconfirmed(?string $kind): bool
    {
        return in_array($kind, [RecordingContract::SECOND_RULE, RecordingContract::SECOND_AMOUNT], true);
    }

    /**
     * True when nobody else on shift at the person's house can confirm a dose
     * now: no colleague present, qualified and holding a usable witness PIN.
     * Ordinary confirmations and controlled witnesses use their own authority.
     */
    public function nobodyCanConfirm(Client $client, int $recorderId, CarbonInterface $at, bool $controlledDrug = false): bool
    {
        $siteId = (int) $client->site_id;
        if ($siteId <= 0) {
            return true;
        }

        $eligible = $controlledDrug
            ? $this->witnesses->eligibleWitnessesForSite($siteId, $at, $recorderId)
            : app(MedicationSecondPersonService::class)->candidatesForSite($siteId, $at, $recorderId);
        if ($eligible->isEmpty()) {
            return true;
        }

        return $this->pins->pickerRows($eligible)
            ->every(fn (array $row): bool => $row['witness_pin'] !== WitnessPinService::STATUS_SET);
    }

    /**
     * The amount recorded as given: the order's own amount when "as ordered",
     * the worker's figure for less or more. Null when the order has no
     * structured amount (its dosage text stays the record).
     *
     * @param  array<string, mixed>  $data
     */
    public function quantityGiven(array $data, ClientMedication $medication): ?string
    {
        if (($data['status'] ?? null) !== 'given' || ($data['amount_mode'] ?? null) === null) {
            return null;
        }

        if (in_array($data['amount_mode'], [RecordingContract::AMOUNT_LESS, RecordingContract::AMOUNT_MORE], true)) {
            $quantity = $this->decimal($data['quantity_given'] ?? null);

            return $quantity === null ? null : $this->movement($quantity);
        }

        $ordered = $this->orderedAmount($medication);

        return $ordered === null ? null : $this->movement($ordered);
    }

    /**
     * P1-3: the dose text for less or more than ordered — the amount actually
     * given, never the order's own dosage. Null otherwise (the caller's text
     * stands).
     *
     * @param  array<string, mixed>  $data
     */
    public function doseGivenText(array $data, ClientMedication $medication): ?string
    {
        if (($data['status'] ?? null) !== 'given'
            || ! in_array($data['amount_mode'] ?? null, [RecordingContract::AMOUNT_LESS, RecordingContract::AMOUNT_MORE], true)) {
            return null;
        }
        $quantity = $this->decimal($data['quantity_given'] ?? null);

        return $quantity === null ? null : $this->amountLabel($quantity, $medication);
    }

    /**
     * NF-18: how much controlled stock a "given" dose uses when the worker
     * didn't say. Only the order's own amount (or the worker's less/more
     * figure) in the stock's own unit counts; anything else must be entered.
     *
     * @param  array<string, mixed>  $data
     */
    public function stockQuantity(array $data, ClientMedication $medication, ?string $stockUnit): ?string
    {
        $doseUnit = $this->unitKey($medication->dose_unit);
        if ($doseUnit === null || $doseUnit !== $this->unitKey($stockUnit)) {
            return null;
        }

        $mode = $data['amount_mode'] ?? RecordingContract::AMOUNT_AS_ORDERED;
        $quantity = $mode === RecordingContract::AMOUNT_AS_ORDERED
            ? $this->orderedAmount($medication)
            : $this->decimal($data['quantity_given'] ?? null);

        return $quantity === null || $quantity <= 0 ? null : $this->movement($quantity);
    }

    /**
     * P0-2: what a controlled "given" dose takes off the stock, and how much
     * of that was not given (witnessed waste). Everything taken comes off, so
     * the register is never left above the physical count:
     *
     *  - less or more than ordered: how many were taken from the stock is
     *    entered, never worked out from the order;
     *  - what was taken can't be less than what was given;
     *  - more taken than given: the rest is the dose's witnessed waste;
     *  - less than ordered when the dose and stock are counted in different
     *    units can't be balanced here, so it is refused until P07b.
     *
     * The dose amount is only compared when the dialog said how much was
     * given (amount_mode); a caller that doesn't (Fleet, the older API)
     * records what it took, as before.
     *
     * @param  array<string, mixed>  $data  quantity_administered already normalised
     * @return array{removed: string, wasted: ?string}|array{success: false, error: string, error_field: string}
     */
    public function controlledStockUse(array $data, ClientMedication $medication, ?string $stockUnit): array
    {
        $mode = $data['amount_mode'] ?? null;
        $partial = in_array($mode, [RecordingContract::AMOUNT_LESS, RecordingContract::AMOUNT_MORE], true);
        $comparable = $this->unitKey($medication->dose_unit) !== null
            && $this->unitKey($medication->dose_unit) === $this->unitKey($stockUnit);

        if ($mode === RecordingContract::AMOUNT_LESS && ! $comparable) {
            return $this->error(
                'amount_mode',
                'Less than ordered can’t be recorded for this controlled medicine here: its dose and stock are counted in different units. '
                    .'Record what was removed and report the remainder through Controlled drugs › Loss.',
            );
        }

        $entered = ($data['quantity_administered'] ?? null) !== null
            ? MedicationStockQuantity::normalizeMovement($data['quantity_administered'])
            : null;
        if ($partial && $entered === null) {
            return $this->error('quantity_administered', 'Enter how many were taken from the controlled-drug stock for this dose.');
        }

        $removed = $entered ?? $this->stockQuantity($data, $medication, $stockUnit);
        if ($removed === null || ! MedicationStockQuantity::greaterThan($removed, 0)) {
            return $this->error('quantity_administered', 'Enter how many were taken from the controlled-drug stock for this dose.');
        }

        $given = $mode !== null && $comparable ? $this->stockQuantity($data, $medication, $stockUnit) : null;
        if ($given === null) {
            return ['removed' => $removed, 'wasted' => null];
        }
        if (MedicationStockQuantity::greaterThan($given, $removed)) {
            return $this->error(
                'quantity_administered',
                'More was given than was taken from the stock. Enter how many were taken from the controlled-drug stock for this dose.',
            );
        }

        return [
            'removed' => $removed,
            'wasted' => MedicationStockQuantity::greaterThan($removed, $given)
                ? MedicationStockQuantity::subtract($removed, $given)
                : null,
        ];
    }

    /** A stock-precision quantity, or null when it doesn't fit two decimals. */
    private function movement(float $quantity): ?string
    {
        try {
            return MedicationStockQuantity::normalizeMovement(
                rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.'),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The refusal a re-offer follows, when it may still be re-offered: the
     * slot's current record is that refusal, it was made today (NZ, by the
     * server's clock — never a time the device sends), and its follow-up is
     * still open. Returns the follow-up (locked).
     */
    public function openReofferFollowUp(
        ClientMedicationAdministration $existing,
        int $reofferOfId,
    ): ?MedicationRefusalFollowup {
        $rootId = $existing->is_correction && $existing->corrected_of_id !== null
            ? (int) $existing->corrected_of_id
            : (int) $existing->id;
        if ($existing->status !== 'refused' || ! in_array($reofferOfId, [$rootId, (int) $existing->id], true)) {
            return null;
        }

        $refusedAt = DoseOrderTimelineFactory::rawInstant($existing->getRawOriginal('administered_at'));
        $tz = (string) config('app.worker_timezone', 'Pacific/Auckland');
        if ($refusedAt === null
            || $refusedAt->setTimezone($tz)->toDateString()
                !== CarbonImmutable::now()->setTimezone($tz)->toDateString()) {
            return null;
        }

        return MedicationRefusalFollowup::query()
            ->whereIn('client_medication_administration_id', array_values(array_unique([$rootId, (int) $existing->id])))
            ->whereNull('follow_up_completed_at')
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /** Close the refusal's follow-up with what the re-offer recorded. */
    public function completeReofferFollowUp(
        MedicationRefusalFollowup $followUp,
        ClientMedicationAdministration $reoffer,
        int $userId,
    ): void {
        $at = DoseOrderTimelineFactory::rawInstant($reoffer->getRawOriginal('administered_at') ?? $reoffer->administered_at)
            ?? CarbonImmutable::now();
        $time = $at->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->format('g:i a');

        $followUp->forceFill([
            'follow_up_completed_at' => now(),
            'follow_up_completed_by' => $userId,
            'follow_up_outcome' => $reoffer->status === 'given'
                ? 'Given after re-offer at '.$time.'.'
                : 'Refused again at '.$time.'.',
        ])->save();
    }

    /**
     * The follow-up a scheduled refusal creates, owned by the person who
     * recorded it, with the time they chose. Repeated refusals escalate as
     * the refusal follow-up form does (Settings › Rounds & timing).
     */
    public function createRefusalFollowUp(
        Client $client,
        ClientMedication $medication,
        ClientMedicationAdministration $refusal,
        int $userId,
        CarbonInterface $dueAt,
    ): MedicationRefusalFollowup {
        $attributes = [
            'client_id' => $client->id,
            'client_medication_administration_id' => $refusal->id,
            'reason_category' => 'personal_choice',
            'detailed_reason' => $refusal->notes,
            'follow_up_action' => RecordingContract::REFUSAL_FOLLOW_UP_ACTION,
            'follow_up_due_at' => CarbonImmutable::instance($dueAt)->utc(),
            'created_by' => $userId,
            'owner_id' => $userId,
        ];

        $recentRefusals = $this->refusalEscalation->countFor((int) $client->id, (int) $medication->id);
        if ($this->refusalEscalation->escalates($recentRefusals)) {
            $attributes['escalated_to_manager'] = true;
            $attributes['escalated_at'] = now();
            $attributes['gp_notification_required'] = true;
        }

        $followUp = MedicationRefusalFollowup::query()->create($attributes);

        if (! empty($attributes['escalated_to_manager'])) {
            app(MedicationIncidentIntegrationService::class)->handleRefusalEscalation($followUp, $recentRefusals);
        }

        return $followUp;
    }

    /**
     * "More than ordered was given": one medication error, with one linked
     * incident, for this dose. A replay of the same dose finds the error it
     * already raised (unique per dose).
     *
     * @param  array<string, mixed>  $data
     */
    public function raiseMoreThanOrderedError(
        Client $client,
        ClientMedication $medication,
        ClientMedicationAdministration $administration,
        User $reporter,
        array $data,
    ): MedicationError {
        $existing = MedicationError::query()
            ->where('client_medication_administration_id', $administration->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $ordered = $this->orderedAmount($medication);
        $given = $this->decimal($data['quantity_given'] ?? null);
        $summary = $ordered !== null && $given !== null
            ? 'More than ordered was given: '.$this->amountLabel($given, $medication).' instead of '.$this->amountLabel($ordered, $medication).'.'
            : 'More than ordered was given.';

        return $this->errors->report(
            $client,
            (int) $client->site_id,
            $reporter,
            [
                'client_medication_id' => $medication->id,
                'client_medication_administration_id' => $administration->id,
                'error_type' => 'wrong_dose',
                'severity' => (string) $data['more_severity'],
                'reached_client' => 'yes',
                'description' => $summary.' '.trim((string) ($data['notes'] ?? '')),
                'immediate_action' => trim((string) ($data['more_immediate_action'] ?? '')) ?: null,
            ],
            createIncident: true,
        );
    }

    /** The error a dose raised, for the response and replays. */
    public function errorFor(ClientMedicationAdministration $administration): ?MedicationError
    {
        return MedicationError::query()
            ->where('client_medication_administration_id', $administration->id)
            ->first(['id', 'reference_number', 'client_incident_id']);
    }

    /**
     * The house lead's review flag for a dose recorded "Not confirmed by a
     * second person" (P01 Q2): set on the record itself; C6 lists it.
     *
     * @return array<string, mixed>
     */
    public function notConfirmedReviewAttributes(string $kind, int $userId): array
    {
        $key = $kind === RecordingContract::SECOND_AMOUNT
            ? RecordingContract::REVIEW_PARTIAL_DOSE_NOT_CONFIRMED
            : RecordingContract::REVIEW_SECOND_PERSON_NOT_CONFIRMED;

        return [
            'review_required' => true,
            'review_reason_key' => $key,
            'review_reason' => $key === RecordingContract::REVIEW_PARTIAL_DOSE_NOT_CONFIRMED
                ? 'Partial dose not confirmed by a second person — nobody else on shift (from the roster).'
                : 'Not confirmed by a second person — nobody else on shift (from the roster).',
            'review_flagged_at' => now(),
            'review_flagged_by' => $userId,
        ];
    }

    public function orderedAmount(ClientMedication $medication): ?float
    {
        $amount = $this->decimal($medication->dose_amount);

        return $amount !== null && $amount > 0 ? $amount : null;
    }

    private function amountLabel(float $amount, ClientMedication $medication): string
    {
        $number = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        $unit = trim((string) $medication->dose_unit);

        return $unit === '' ? $number : $number.' '.$unit;
    }

    private function unitKey(?string $unit): ?string
    {
        $unit = Str::lower(trim((string) $unit));

        return $unit === '' ? null : Str::singular($unit);
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function instant(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value, (string) config('app.worker_timezone', 'Pacific/Auckland'))->utc();
    }

    private function minute(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->utc()->startOfMinute();
    }

    /** @return array{success: false, error: string, error_field: string} */
    private function error(string $field, string $message): array
    {
        return ['success' => false, 'error' => $message, 'error_field' => $field];
    }
}
