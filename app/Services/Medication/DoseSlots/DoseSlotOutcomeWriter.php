<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Writes a dose slot's outcome from the administrations recorded for it, in
 * the same transaction as the administration write (P01 foundation C3).
 *
 * It runs from ClientMedicationAdministration's model events, so every
 * recording path — Meds today, the MAR, the API and offline sync, guided
 * rounds, transport, My Day, the client profile — and every correction,
 * approval, rejection or delete reaches it. A scheduled administration maps to
 * exactly one slot (its order and due minute); PRN and unscheduled doses map
 * to none.
 *
 * The outcome is the slot's effective clinical evidence, the same rule as
 * ClientMedicationAdministration::scopeEffectiveClinicalEvidence(): the
 * latest approved correction replaces its original; a pending or rejected
 * correction changes nothing. Lock order, as every medication write: the
 * order row, the slot's administration rows, then the slot rows.
 */
final class DoseSlotOutcomeWriter
{
    public const RECORDED_STATUSES = ['given', 'refused', 'withheld', 'missed'];

    /** Withheld because the person was away (NF-13) reads as Away. */
    public const AWAY_REASON_CODES = ['absent', 'social_leave', 'hospitalised', 'transferred'];

    public function __construct(private readonly DoseSlotGenerator $generator) {}

    /**
     * Before an administration row is written (saving, deleting, restoring):
     * take its order row first, so the record's own row lock never comes
     * before the order's — whatever the caller locked. Re-taking a lock the
     * caller already holds (every recording and correction path) is free.
     */
    public function lockOrderOf(ClientMedicationAdministration $administration): void
    {
        $orderId = $administration->client_medication_id;
        if (is_numeric($orderId) && (int) $orderId > 0) {
            ClientMedication::withTrashed()->whereKey((int) $orderId)->lockForUpdate()->first(['id']);
        }
    }

    public function syncFor(ClientMedicationAdministration $administration): void
    {
        DB::transaction(function () use ($administration): void {
            $rootId = (bool) $administration->is_correction && $administration->corrected_of_id !== null
                ? (int) $administration->corrected_of_id
                : (int) $administration->id;
            // Which slot: the root's order and scheduled time, which never
            // change for a record, so they are read without a lock (a lock
            // here, before the order's, would invert the lock order). A
            // force-deleted record is gone; its own values still say which slot.
            $root = ClientMedicationAdministration::withTrashed()
                ->whereKey($rootId)
                ->first(['id', 'client_id', 'client_medication_id', 'scheduled_for'])
                ?? ($rootId === (int) $administration->id ? $administration : null);
            $scheduledFor = DoseOrderTimelineFactory::rawInstant($root?->getRawOriginal('scheduled_for') ?? $root?->getAttributes()['scheduled_for'] ?? null);
            if ($root === null || $root->client_medication_id === null || $scheduledFor === null) {
                return;
            }

            // Lock order, as every medication write: the order row (the
            // slots' mutex, already held by this record's own write — see
            // lockOrderOf), then the slot's administration rows, then slots.
            $order = ClientMedication::withTrashed()->whereKey($root->client_medication_id)->lockForUpdate()->first();
            // A record whose person isn't the order's person is not evidence
            // for that order's doses: it never writes another person's slot.
            if ($order === null || (int) $order->client_id !== (int) $root->client_id) {
                return;
            }

            // A slot is due on a whole minute; the dose's scheduled time is
            // matched to the nearest one, and evidence is read for that minute.
            $dueAt = self::nearestMinute($scheduledFor);
            [$originals, $corrections] = $this->lockEvidence(
                (int) $root->client_id,
                (int) $root->client_medication_id,
                $dueAt->subSeconds(30),
                $dueAt->addSeconds(29),
            );

            $now = CarbonImmutable::now()->utc();
            try {
                $this->generator->ensureDay(
                    $order,
                    $dueAt->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
                    $now,
                );
            } catch (InvalidArgumentException|LogicException $unexpectedData) {
                // Unreadable order history must never block recording a dose:
                // report it, write no outcome. Database errors still propagate.
                report($unexpectedData);

                return;
            }

            $slots = MedicationDoseSlot::query()
                ->where('client_medication_id', $order->id)
                ->where('due_at', $dueAt->format('Y-m-d H:i:s'))
                ->whereNull('superseded_at')
                ->orderBy('due_at')
                ->orderBy('ordered_time')
                ->lockForUpdate()
                ->get();
            if ($slots->isEmpty()) {
                return;
            }

            $winner = $this->effectiveRecord($originals, $corrections);
            $evidenceIds = $originals->pluck('id')->merge($corrections->pluck('id'))->map(fn ($id): int => (int) $id)->all();
            $slot = $slots->first(fn (MedicationDoseSlot $candidate): bool => in_array((int) $candidate->outcome_administration_id, $evidenceIds, true))
                ?? $slots->first(fn (MedicationDoseSlot $candidate): bool => $candidate->outcome === null)
                ?? $slots->first();

            $outcome = $winner === null ? [
                'outcome' => null,
                'outcome_administration_id' => null,
                'outcome_at' => null,
            ] : [
                'outcome' => self::outcomeOf($winner),
                'outcome_administration_id' => (int) $winner->id,
                'outcome_at' => (DoseOrderTimelineFactory::rawInstant($winner->getRawOriginal('administered_at'))
                    ?? DoseOrderTimelineFactory::rawInstant($winner->getRawOriginal('created_at'))
                    ?? $now)->format('Y-m-d H:i:s'),
            ];

            MedicationDoseSlot::query()->whereKey($slot->id)->update($outcome + ['updated_at' => $now->format('Y-m-d H:i:s')]);
        });
    }

    public static function nearestMinute(CarbonImmutable $instant): CarbonImmutable
    {
        $instant = $instant->utc();
        $minute = $instant->startOfMinute();

        return $instant->second >= 30 ? $minute->addMinute() : $minute;
    }

    public static function outcomeOf(ClientMedicationAdministration $record): string
    {
        $status = (string) $record->status;

        return $status === 'withheld' && in_array((string) $record->reason_code, self::AWAY_REASON_CODES, true)
            ? 'away'
            : $status;
    }

    /**
     * The originals recorded for the slot's minute and their corrections,
     * read with locks so a concurrent writer's committed rows are seen.
     *
     * @return array{0: Collection<int, ClientMedicationAdministration>, 1: Collection<int, ClientMedicationAdministration>}
     */
    private function lockEvidence(int $clientId, int $orderId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $originals = ClientMedicationAdministration::query()
            ->where('client_id', $clientId)
            ->where('client_medication_id', $orderId)
            ->whereBetween('scheduled_for', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->where(fn ($query) => $query->where('is_correction', false)->orWhereNull('is_correction'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $corrections = $originals->isEmpty()
            ? collect()
            : ClientMedicationAdministration::query()
                ->whereIn('corrected_of_id', $originals->pluck('id'))
                ->where('is_correction', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        return [$originals, $corrections];
    }

    /**
     * @param  Collection<int, ClientMedicationAdministration>  $originals
     * @param  Collection<int, ClientMedicationAdministration>  $corrections
     */
    private function effectiveRecord(Collection $originals, Collection $corrections): ?ClientMedicationAdministration
    {
        $effective = $originals->map(function (ClientMedicationAdministration $original) use ($corrections): ClientMedicationAdministration {
            $approved = $corrections
                ->filter(fn (ClientMedicationAdministration $correction): bool => (int) $correction->corrected_of_id === (int) $original->id
                    && (int) $correction->client_id === (int) $original->client_id
                    && (int) $correction->client_medication_id === (int) $original->client_medication_id
                    && $correction->correction_status === 'approved')
                ->sortBy([
                    fn ($a, $b) => (string) $b->getRawOriginal('correction_approved_at') <=> (string) $a->getRawOriginal('correction_approved_at'),
                    fn ($a, $b) => (int) $b->id <=> (int) $a->id,
                ]);

            return $approved->first() ?? $original;
        });

        return $effective
            ->filter(fn (ClientMedicationAdministration $record): bool => in_array($record->status, self::RECORDED_STATUSES, true))
            ->sortByDesc(fn (ClientMedicationAdministration $record): int => (int) $record->id)
            ->first();
    }
}
