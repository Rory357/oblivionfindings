<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseScheduleVersion;
use App\Models\MedicationDoseSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rebuilds one order's dose slots and outcomes on past New Zealand days
 * (P01 foundation C5). Additive and idempotent: running it again writes
 * nothing new.
 *
 * Which slots were owed:
 * - the order's schedule history: the audit log (DoseOrderHistoryReconstructor)
 *   up to when its schedule versions began being recorded (C3), the recorded
 *   versions and pauses from then on — same rules as live generation;
 * - nothing due before the order was entered (the timelines' rule, as in
 *   live generation), or after `now`;
 * - a recorded scheduled dose the history doesn't explain was still owed: it
 *   gets a slot at its time. A day whose records match none of the history's
 *   times follows the records (the history is incomplete for that day).
 *
 * What it writes:
 * - missing slots only, marked `reconstructed`; an existing slot — live or
 *   rebuilt — is never changed or removed;
 * - an outcome only on a slot without one, from the effective record for
 *   its minute (an approved correction replaces its original), as the live
 *   writer reads it. A slot the live writer already gave an outcome, and a
 *   superseded slot, are never touched.
 *
 * Each batch of days runs in its own transaction under the order lock (the
 * slots' mutex, as every slot writer), retried on deadlock. A dry run counts
 * what it would write and writes nothing.
 */
final class DoseSlotBackfill
{
    private readonly DoseSlotRules $rules;

    public function __construct(
        private readonly DoseOrderHistoryReconstructor $history,
        private readonly DoseOrderTimelineFactory $recorded,
        private readonly DoseSlotGenerator $generator,
    ) {
        $this->rules = DoseSlotRules::forWorkerTimezone();
    }

    /**
     * Rebuild the order's slots on the NZ days $from..$to (inclusive).
     *
     * @param  (callable(array{slots: int, outcomes: int, without_slot: int, days_from_records: int}): void)|null  $afterBatch
     *                                                                                                                   runs inside each batch's transaction, with that batch's counts
     * @return array{slots: int, outcomes: int, without_slot: int, days_from_records: int}
     */
    public function order(
        ClientMedication $order,
        string $from,
        string $to,
        CarbonImmutable $now,
        bool $dryRun = false,
        int $daysPerBatch = 31,
        ?callable $afterBatch = null,
    ): array {
        if (! DoseSlotRules::isCalendarDate($from) || ! DoseSlotRules::isCalendarDate($to) || $from > $to) {
            throw new InvalidArgumentException('The backfill period is two Y-m-d NZ days, first to last.');
        }

        $now = $now->utc();
        $sources = $this->sources($order);
        $totals = self::noCounts();

        $day = CarbonImmutable::parse($from, 'UTC');
        while ($day->toDateString() <= $to) {
            $batchEnd = $day->addDays(max(1, $daysPerBatch) - 1);
            if ($batchEnd->toDateString() > $to) {
                $batchEnd = CarbonImmutable::parse($to, 'UTC');
            }

            $batch = function () use ($order, $sources, $day, $batchEnd, $now, $dryRun, $afterBatch): array {
                $counts = $this->batch($order, $sources, $day->toDateString(), $batchEnd->toDateString(), $now, $dryRun);
                if ($afterBatch !== null) {
                    $afterBatch($counts);
                }

                return $counts;
            };
            // Retried on deadlock (1213): MySQL rolls the whole attempt back.
            $counts = $dryRun ? $batch() : DB::transaction($batch, 3);

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
            $day = $batchEnd->addDay();
        }

        return $totals;
    }

    /**
     * @return array{slots: int, outcomes: int, without_slot: int, days_from_records: int}
     */
    public static function noCounts(): array
    {
        return ['slots' => 0, 'outcomes' => 0, 'without_slot' => 0, 'days_from_records' => 0];
    }

    /**
     * Where the order's schedule comes from. Its recorded versions (C3) are
     * exact from when recording began; before that, the audit log.
     *
     * @return array{history: DoseOrderTimeline, recorded: DoseOrderTimeline|null, recordedFrom: CarbonImmutable|null}
     */
    private function sources(ClientMedication $order): array
    {
        $recordedFrom = DoseOrderTimelineFactory::rawInstant(
            MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->min('created_at'),
        );

        return [
            'history' => $this->history->forOrder($order),
            'recorded' => $recordedFrom === null ? null : $this->recorded->forOrder($order),
            'recordedFrom' => $recordedFrom,
        ];
    }

    /**
     * @param  array{history: DoseOrderTimeline, recorded: DoseOrderTimeline|null, recordedFrom: CarbonImmutable|null}  $sources
     * @return array{slots: int, outcomes: int, without_slot: int, days_from_records: int}
     */
    private function batch(ClientMedication $order, array $sources, string $from, string $to, CarbonImmutable $now, bool $dryRun): array
    {
        if (! $dryRun) {
            $this->generator->lockOrder($order);
        }
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $counts = self::noCounts();

        // What is there already: kept as it is, bar a missing outcome.
        $existing = [];
        $existingMinutes = [];
        MedicationDoseSlot::query()
            ->where('client_medication_id', $order->id)
            ->whereBetween('nz_date', [$from, $to])
            ->when(! $dryRun, fn ($query) => $query->lockForUpdate())
            ->orderBy('ordered_time')
            ->orderBy('id')
            ->get()
            ->each(function (MedicationDoseSlot $row) use ($order, &$existing, &$existingMinutes): void {
                $slot = self::row($row);
                $existing[$order->id.':'.$slot['nz_date'].':'.$slot['ordered_time']] = $slot;
                $existingMinutes[$slot['due_minute']] = true;
            });

        // What the history says was owed, per NZ day (the timelines already
        // owe nothing before the order was entered).
        $owed = [];
        foreach ($this->historySlots($sources, $from, $to) as $slot) {
            if ($slot->dueAt->greaterThan($now)) {
                continue;
            }
            $owed[$slot->nzDate][$slot->key()] = $slot;
        }

        // What was recorded, per NZ day and due minute.
        $evidence = $this->evidence($order, $from, $to, $timezone);
        $evidenceByDay = [];
        foreach ($evidence as $minute => $record) {
            $evidenceByDay[self::nzDate($minute, $timezone)][$minute] = $record;
        }

        $plan = [];
        $days = array_unique([...array_keys($owed), ...array_keys($evidenceByDay)]);
        sort($days);
        foreach ($days as $day) {
            $daySlots = $owed[$day] ?? [];
            $dayEvidence = $evidenceByDay[$day] ?? [];
            $slotMinutes = [];
            foreach ($daySlots as $slot) {
                $slotMinutes[self::minute($slot->dueAt)] = true;
            }
            $unmatched = array_filter(
                $dayEvidence,
                fn (string $minute): bool => ! isset($slotMinutes[$minute]) && ! isset($existingMinutes[$minute]),
                ARRAY_FILTER_USE_KEY,
            );

            // The records show a schedule the history doesn't: the history
            // is incomplete for this day, so the day follows the records.
            if ($daySlots !== [] && $dayEvidence !== [] && count($unmatched) === count($dayEvidence)) {
                $daySlots = [];
                $counts['days_from_records']++;
            }

            $plan += $daySlots;
            foreach (array_keys($unmatched) as $minute) {
                $slot = $this->slotFromRecord($order, $sources, $minute, $now, $timezone);
                if ($slot === null || isset($plan[$slot->key()]) || isset($existing[$slot->key()])) {
                    $counts['without_slot']++;

                    continue;
                }
                $plan[$slot->key()] = $slot;
            }
        }

        // Each record is the outcome of one slot at most. Records already
        // carried by a slot stay where they are.
        $used = [];
        foreach ($existing as $row) {
            if ($row['outcome_administration_id'] !== null) {
                $used[$row['outcome_administration_id']] = true;
            }
        }
        $recordFor = function (string $minute) use ($evidence, &$used): ?ClientMedicationAdministration {
            $record = $evidence[$minute] ?? null;
            if ($record === null || isset($used[(int) $record->id])) {
                return null;
            }
            $used[(int) $record->id] = true;

            return $record;
        };

        // A missing outcome on a slot that is there.
        foreach ($existing as $row) {
            if ($row['outcome'] !== null || $row['superseded_at'] !== null) {
                continue;
            }
            $record = $recordFor($row['due_minute']);
            if ($record === null) {
                continue;
            }
            $counts['outcomes']++;
            if (! $dryRun) {
                MedicationDoseSlot::query()
                    ->whereKey($row['id'])
                    ->whereNull('outcome')
                    ->whereNull('superseded_at')
                    ->update(self::outcome($record, $now) + ['updated_at' => self::stamp($now)]);
            }
        }

        // The missing slots, with their outcome where one was recorded.
        $missing = array_diff_key($plan, $existing);
        uasort($missing, fn (DoseSlot $a, DoseSlot $b): int => [$a->dueAt->getTimestamp(), $a->doseTime] <=> [$b->dueAt->getTimestamp(), $b->doseTime]);
        $rows = [];
        foreach ($missing as $slot) {
            $record = $recordFor(self::minute($slot->dueAt));
            if ($record !== null) {
                $counts['outcomes']++;
            }
            $rows[] = [
                'client_id' => (int) $order->client_id,
                'client_medication_id' => (int) $order->id,
                'schedule_version_id' => ctype_digit($slot->versionKey) ? (int) $slot->versionKey : null,
                'nz_date' => $slot->nzDate,
                'ordered_time' => $slot->doseTime,
                'due_at' => self::stamp($slot->dueAt),
                // Sticky, as live: a controlled order's history stays concealed.
                'controlled' => (bool) $order->controlled_drug,
                'order_change_pending' => (int) $slot->orderChangePending,
                'dst_adjustment' => $slot->dstAdjustment,
                'self_managed' => (int) $slot->selfManaged,
                'last_day' => (int) $slot->lastDay,
                'superseded_at' => null,
                ...($record === null
                    ? ['outcome' => null, 'outcome_administration_id' => null, 'outcome_at' => null]
                    : self::outcome($record, $now)),
                'generated_at' => self::stamp($now),
                'reconstructed' => true,
                'created_at' => self::stamp($now),
                'updated_at' => self::stamp($now),
            ];
        }
        $counts['slots'] = count($rows);
        if (! $dryRun && $rows !== []) {
            foreach (array_chunk($rows, 500) as $chunk) {
                MedicationDoseSlot::query()->insertOrIgnore($chunk);
            }
        }

        return $counts;
    }

    /**
     * The history's slots on these days: the audit-log history before the
     * order's versions began being recorded, the recorded ones after.
     *
     * @param  array{history: DoseOrderTimeline, recorded: DoseOrderTimeline|null, recordedFrom: CarbonImmutable|null}  $sources
     * @return list<DoseSlot>
     */
    private function historySlots(array $sources, string $from, string $to): array
    {
        $slots = [];
        foreach ($this->rules->slotsBetween($sources['history'], $from, $to) as $slot) {
            if ($sources['recordedFrom'] === null || $slot->dueAt->lessThan($sources['recordedFrom'])) {
                $slots[] = $slot;
            }
        }
        if ($sources['recorded'] !== null && $sources['recordedFrom'] !== null) {
            foreach ($this->rules->slotsBetween($sources['recorded'], $from, $to) as $slot) {
                if ($slot->dueAt->greaterThanOrEqualTo($sources['recordedFrom'])) {
                    $slots[] = $slot;
                }
            }
        }

        return $slots;
    }

    /**
     * A slot for a recorded scheduled dose the history doesn't explain, at
     * its recorded time. None when it isn't due yet or the order was as
     * needed (PRN) then.
     *
     * @param  array{history: DoseOrderTimeline, recorded: DoseOrderTimeline|null, recordedFrom: CarbonImmutable|null}  $sources
     */
    private function slotFromRecord(ClientMedication $order, array $sources, string $minute, CarbonImmutable $now, string $timezone): ?DoseSlot
    {
        $dueAt = CarbonImmutable::parse($minute.':00', 'UTC');
        $timeline = $sources['recorded'] !== null && $sources['recordedFrom'] !== null && $dueAt->greaterThanOrEqualTo($sources['recordedFrom'])
            ? $sources['recorded']
            : $sources['history'];
        if ($dueAt->greaterThan($now) || $timeline->versionInEffectAt($dueAt)?->isPrn === true) {
            return null;
        }
        $local = $dueAt->setTimezone($timezone);

        return new DoseSlot(
            orderId: (int) $order->id,
            versionKey: '',
            nzDate: $local->toDateString(),
            doseTime: $local->format('H:i'),
            dueAt: $dueAt,
        );
    }

    /**
     * The effective scheduled records for the order on these NZ days, keyed
     * by due minute (UTC, nearest minute — the live writer's match): the
     * latest record for a minute wins, and an approved correction replaces
     * its original (ClientMedicationAdministration::effectiveClinicalEvidence).
     *
     * @return array<string, ClientMedicationAdministration>
     */
    private function evidence(ClientMedication $order, string $from, string $to, string $timezone): array
    {
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->subSeconds(30);
        $end = CarbonImmutable::parse($to, $timezone)->endOfDay()->utc()->addSeconds(30);

        $byMinute = [];
        ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $order->client_id)
            ->where('client_medication_id', $order->id)
            ->whereBetween('scheduled_for', [self::stamp($start), self::stamp($end)])
            ->whereIn('status', DoseSlotOutcomeWriter::RECORDED_STATUSES)
            ->orderBy('id')
            ->get()
            ->each(function (ClientMedicationAdministration $record) use (&$byMinute, $from, $to, $timezone): void {
                $scheduledFor = DoseOrderTimelineFactory::rawInstant($record->getRawOriginal('scheduled_for'));
                if ($scheduledFor === null) {
                    return;
                }
                $minute = self::minute(DoseSlotOutcomeWriter::nearestMinute($scheduledFor));
                $nzDate = self::nzDate($minute, $timezone);
                if ($nzDate >= $from && $nzDate <= $to) {
                    $byMinute[$minute] = $record;
                }
            });

        return $byMinute;
    }

    /**
     * @return array{id: int, nz_date: string, ordered_time: string, due_minute: string, outcome: string|null, outcome_administration_id: int|null, superseded_at: mixed}
     */
    private static function row(MedicationDoseSlot $row): array
    {
        $administrationId = $row->getRawOriginal('outcome_administration_id');

        return [
            'id' => (int) $row->id,
            'nz_date' => (string) DoseOrderTimelineFactory::rawDate($row->getRawOriginal('nz_date')),
            'ordered_time' => (string) $row->ordered_time,
            'due_minute' => self::minute(DoseOrderTimelineFactory::rawInstant($row->getRawOriginal('due_at')) ?? CarbonImmutable::createFromTimestampUTC(0)),
            'outcome' => $row->outcome,
            'outcome_administration_id' => $administrationId === null ? null : (int) $administrationId,
            'superseded_at' => $row->getRawOriginal('superseded_at'),
        ];
    }

    /**
     * @return array{outcome: string, outcome_administration_id: int, outcome_at: string}
     */
    private static function outcome(ClientMedicationAdministration $record, CarbonImmutable $now): array
    {
        return [
            'outcome' => DoseSlotOutcomeWriter::outcomeOf($record),
            'outcome_administration_id' => (int) $record->id,
            'outcome_at' => self::stamp(
                DoseOrderTimelineFactory::rawInstant($record->getRawOriginal('administered_at'))
                    ?? DoseOrderTimelineFactory::rawInstant($record->getRawOriginal('created_at'))
                    ?? $now,
            ),
        ];
    }

    private static function minute(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i');
    }

    private static function nzDate(string $minute, string $timezone): string
    {
        return CarbonImmutable::parse($minute.':00', 'UTC')->setTimezone($timezone)->toDateString();
    }

    private static function stamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
