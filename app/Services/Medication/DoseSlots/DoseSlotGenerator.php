<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Models\MedicationDoseSlot;
use Carbon\CarbonImmutable;

/**
 * Materialises an order's dose slots (P01 foundation C3).
 *
 * Idempotent: running it again changes nothing. The past is never rewritten:
 * a slot already due, or one with an outcome, is never changed or removed. A
 * slot not yet due follows the order as it is now — an order change, pause or
 * stop updates it or marks it superseded, and a change back revives it.
 *
 * Call inside a transaction. It takes the order row lock (the slots' mutex),
 * inserts missing rows, then locks and reconciles; every read is a locking
 * read, so no stale snapshot hides another writer's row.
 */
final class DoseSlotGenerator
{
    /** Generated ahead: today and the next two New Zealand days. */
    public const HORIZON_DAYS = 2;

    private readonly DoseSlotRules $rules;

    public function __construct(private readonly DoseOrderTimelineFactory $timelines)
    {
        $this->rules = DoseSlotRules::forWorkerTimezone();
    }

    public function generateAhead(ClientMedication $order, ?CarbonImmutable $now = null): void
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $today = $now->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $lastDay = CarbonImmutable::parse($today, 'UTC')->addDays(self::HORIZON_DAYS)->toDateString();

        $this->generate($order, $today, $lastDay, $now);
    }

    /**
     * Reconcile the order's slots on the NZ days $from..$to (inclusive).
     */
    public function generate(ClientMedication $order, string $from, string $to, CarbonImmutable $now): void
    {
        $now = $now->utc();
        $order = $this->lockOrder($order);
        $computed = [];
        foreach ($this->rules->slotsBetween($this->timelines->forOrder($order), $from, $to) as $slot) {
            $computed[$slot->key()] = $slot;
        }

        $this->insertMissing($order, $computed, $now);

        $existing = MedicationDoseSlot::query()
            ->where('client_medication_id', $order->id)
            ->whereBetween('nz_date', [$from, $to])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($existing as $row) {
            if ($row->outcome !== null || ! $this->dueAfter($row, $now)) {
                continue;
            }

            $key = $order->id.':'.DoseOrderTimelineFactory::rawDate($row->getRawOriginal('nz_date')).':'.$row->ordered_time;
            $slot = $computed[$key] ?? null;
            $changes = $slot === null
                ? ($row->superseded_at === null ? ['superseded_at' => $this->stamp($now)] : [])
                : array_diff_assoc($this->slotAttributes($slot), $this->currentAttributes($row));

            if ($changes !== []) {
                MedicationDoseSlot::query()->whereKey($row->id)->update($changes + ['updated_at' => $this->stamp($now)]);
            }
        }
    }

    /**
     * Make sure the order's slots for one NZ day exist (insert only: an
     * existing row is never changed here).
     */
    public function ensureDay(ClientMedication $order, string $nzDate, CarbonImmutable $now): void
    {
        $order = $this->lockOrder($order);
        $computed = [];
        foreach ($this->rules->slotsOn($this->timelines->forOrder($order), $nzDate) as $slot) {
            $computed[$slot->key()] = $slot;
        }

        $this->insertMissing($order, $computed, $now->utc());
    }

    /**
     * The order row is the mutex for its slots. Every slot writer takes it
     * first, after the client, as every medication write already does
     * (client → medication → administration → slots), so two writers never
     * meet on the slot rows themselves. Held already by the recording,
     * correction and order-change paths; cheap to take again.
     */
    public function lockOrder(ClientMedication $order): ClientMedication
    {
        // The locked read is the latest committed row (plus this
        // transaction's own change), whatever snapshot the caller holds.
        return ClientMedication::withTrashed()->whereKey($order->id)->lockForUpdate()->first() ?? $order;
    }

    /**
     * @param  array<string, DoseSlot>  $computed
     */
    private function insertMissing(ClientMedication $order, array $computed, CarbonImmutable $now): void
    {
        if ($computed === []) {
            return;
        }

        $stamp = $this->stamp($now);
        $rows = [];
        foreach ($computed as $slot) {
            $rows[] = [
                'client_id' => (int) $order->client_id,
                'client_medication_id' => (int) $order->id,
                'nz_date' => $slot->nzDate,
                'ordered_time' => $slot->doseTime,
                // Sticky: a controlled order's history stays concealed.
                'controlled' => (bool) $order->controlled_drug,
                ...$this->slotAttributes($slot),
                'generated_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        MedicationDoseSlot::query()->insertOrIgnore($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function slotAttributes(DoseSlot $slot): array
    {
        return [
            'schedule_version_id' => ctype_digit($slot->versionKey) ? (int) $slot->versionKey : null,
            'due_at' => $this->stamp($slot->dueAt),
            'order_change_pending' => (int) $slot->orderChangePending,
            'dst_adjustment' => $slot->dstAdjustment,
            'self_managed' => (int) $slot->selfManaged,
            'last_day' => (int) $slot->lastDay,
            'superseded_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentAttributes(MedicationDoseSlot $row): array
    {
        $raw = fn (string $key) => $row->getRawOriginal($key);

        return [
            'schedule_version_id' => $raw('schedule_version_id') !== null ? (int) $raw('schedule_version_id') : null,
            'due_at' => DoseOrderTimelineFactory::rawInstant($raw('due_at'))?->format('Y-m-d H:i:s'),
            'order_change_pending' => (int) (bool) $raw('order_change_pending'),
            'dst_adjustment' => $raw('dst_adjustment'),
            'self_managed' => (int) (bool) $raw('self_managed'),
            'last_day' => (int) (bool) $raw('last_day'),
            'superseded_at' => $raw('superseded_at'),
        ];
    }

    private function dueAfter(MedicationDoseSlot $row, CarbonImmutable $now): bool
    {
        $dueAt = DoseOrderTimelineFactory::rawInstant($row->getRawOriginal('due_at'));

        return $dueAt !== null && $dueAt->greaterThan($now);
    }

    private function stamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
