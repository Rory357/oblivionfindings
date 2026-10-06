<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\StockLotRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait HistoricalPackEvidence
{
    /** Read preview and locked replay use the same exact, immutable physical contract. */
    public function validateHistoricalEvidence(ClientMedication $order, array $evidence, bool $lock = false): array
    {
        if ($lock) {
            $this->assertTransaction();
        }
        $stock = ClientMedicationStock::where('client_medication_id', $order->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless((int) ($evidence['stock_id'] ?? 0) === (int) $stock->id, 404);
        if ($stock->lots_started_at === null || ($evidence['unit'] ?? null) !== $stock->unit
            || ! in_array($evidence['settlement'] ?? null, ['deduct_now', 'covered_by_count'], true)) {
            $this->packError('Historical stock needs an exact pack record, counted unit and explicit settlement.');
        }
        $at = CarbonImmutable::parse($evidence['occurred_at'], 'UTC')->utc();
        if ($at->gt(now())) {
            $this->packError('A historical stock movement cannot occur in the future.');
        }
        $day = $at->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $removed = Qty::normalize($evidence['quantity_removed'] ?? 0);
        $waste = Qty::normalize($evidence['quantity_wasted'] ?? 0);
        if (! Qty::greaterThan($removed, 0) || Qty::greaterThan(0, $waste) || ! Qty::greaterThan($removed, $waste)
            || (Qty::greaterThan($waste, 0) && blank($evidence['waste_reason'] ?? null))) {
            $this->packError('Retain the actual removed quantity, amount wasted and reason; do not infer them from dose text.');
        }
        $lots = MedicationStockLot::where('client_medication_stock_id', $stock->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $lines = $evidence['lines'] ?? [];
        if (! $lines || count(array_unique(array_column($lines, 'lot_id'))) !== count($lines)) {
            $this->packError('Retain each actual historical pack exactly once.');
        }
        $count = null;
        if ($evidence['settlement'] === 'covered_by_count') {
            $count = MedicationStockCountRecord::where('client_medication_stock_id', $stock->id)
                ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($evidence['closing_count_id'] ?? 0);
            if (! in_array($count->state, ['counted', 'reviewed'], true) || $count->coverage_start === null || $count->coverage_end === null
                || $at->lt($count->coverage_start) || $at->gt($count->coverage_end) || $at->gt($count->counted_at)
                || ! in_array((int) ($evidence['paper_entry_id'] ?? 0), array_map('intval', $count->covered_paper_entry_ids ?? []), true)
                || ($order->controlled_drug && (! $count->controlled_entry_id || ! $count->witnessed_by))) {
                $this->packError('This closing count does not explicitly cover this paper dose and its physical interval.');
            }
        }
        $sum = '0.00';
        $wastedSum = '0.00';
        foreach ($lines as &$line) {
            $lot = $lots->get((int) ($line['lot_id'] ?? 0));
            abort_unless($lot, 404);
            $quantity = Qty::normalize($line['quantity'] ?? 0);
            $lineWaste = Qty::normalize($line['quantity_wasted'] ?? 0);
            $closing = Qty::normalize($line['closing_quantity'] ?? -1);
            if ((int) ($line['revision'] ?? -1) !== $lot->revision || ! Qty::greaterThan($quantity, 0)
                || Qty::greaterThan(0, $lineWaste) || Qty::greaterThan($lineWaste, $quantity)
                || Qty::greaterThan(0, $closing) || $lot->received_at->gt($at)
                || ! StockLotRules::usable([...$lot->toArray(), 'quantity_remaining' => $quantity], $day)) {
                $this->packError('The actual pack, revision, usable-at-dose evidence or physical quantity does not match.');
            }
            if ($count) {
                $countLine = collect($count->lines)->firstWhere('lot_id', $lot->id);
                if (! $countLine) {
                    $this->packError('The reviewed count does not retain this exact pack.');
                }
                $revision = $countLine['settled_revision'] ?? (($countLine['revision'] ?? -1) + ($count->state === 'reviewed' && ! Qty::equals($countLine['expected'], $countLine['counted']) ? 1 : 0));
                if (! $countLine || (int) $revision !== $lot->revision || ! Qty::equals($countLine['counted'], $closing)
                    || ! Qty::equals($lot->quantity_remaining, $closing)) {
                    $this->packError('Pack movements after the closing count require a new exact settlement review.');
                }
            } elseif (Qty::greaterThan($quantity, $lot->quantity_remaining) || ! Qty::equals(Qty::subtract($lot->quantity_remaining, $quantity), $closing)) {
                $this->packError('The reviewed closing quantity must match this exact new deduction.');
            }
            $line = [...$line, 'quantity' => $quantity, 'quantity_wasted' => $lineWaste, 'closing_quantity' => $closing];
            $sum = Qty::add($sum, $quantity);
            $wastedSum = Qty::add($wastedSum, $lineWaste);
        }
        unset($line);
        if (! Qty::equals($sum, $removed) || ! Qty::equals($wastedSum, $waste)) {
            $this->packError('Exact pack removals and waste must add up to the immutable physical dose evidence.');
        }

        return [...$evidence, 'quantity_removed' => $removed, 'quantity_wasted' => $waste, 'lines' => $lines];
    }

    /** Called only after canonical clinical/witness checks, never from a caller timestamp flag. */
    public function controlledHistoricalEntries(ClientMedicationAdministration $admin, User $actor, array $evidence, User $witness): array
    {
        $evidence = $this->validateHistoricalEvidence($admin->medication, $evidence, true);
        $stock = $this->lockedStock($admin->medication);
        abort_unless($admin->medication->controlled_drug && (int) $admin->administered_by === (int) $actor->id
            && (int) $witness->id !== (int) $actor->id && (int) $admin->witnessed_by === (int) $witness->id, 404);
        $covered = $evidence['settlement'] === 'covered_by_count';
        $before = Qty::normalize($stock->on_hand);
        $given = Qty::subtract($evidence['quantity_removed'], $evidence['quantity_wasted']);
        $afterGiven = $covered ? $before : Qty::subtract($before, $given);
        $afterWaste = $covered ? $before : Qty::subtract($afterGiven, $evidence['quantity_wasted']);
        if (Qty::greaterThan(0, $afterWaste)) {
            $this->packError('The exact historical dose would leave a negative controlled balance.');
        }
        $common = [
            'client_id' => $admin->client_id, 'client_medication_id' => $admin->client_medication_id,
            'client_medication_administration_id' => $admin->id, 'shift_id' => $admin->shift_id,
            'service_context_id' => $admin->service_context_id, 'unit' => $stock->unit,
            'recorded_by' => $actor->id, 'witnessed_by' => $witness->id, 'recorded_at' => $admin->administered_at,
            'stock_balance_scope' => $covered ? 'counted_history' : 'house', 'stock_count_record_id' => $evidence['closing_count_id'] ?? null,
            'source_type' => 'paper_recovery', 'source_id' => $evidence['paper_entry_id'], 'notes' => $admin->notes,
        ];
        $entry = ClientControlledDrugEntry::create([...$common, 'entry_type' => 'administered', 'quantity' => $given,
            'on_hand_before' => $before, 'on_hand_after' => $afterGiven,
            'reason' => $covered ? 'Historical paper dose already included in the explicitly covering witnessed pack count.' : 'Historical paper dose settled from exact physical packs.']);
        $waste = Qty::greaterThan($evidence['quantity_wasted'], 0) ? ClientControlledDrugEntry::create([...$common,
            'entry_type' => 'disposal', 'quantity' => $evidence['quantity_wasted'], 'on_hand_before' => $afterGiven, 'on_hand_after' => $afterWaste,
            'reason' => $evidence['waste_reason']]) : null;
        foreach (array_filter([$entry, $waste]) as $part) {
            AuditLogger::logOrFail('medications.controlled.entry.record', $part, ['actor_id' => $actor->id, 'paper_entry_id' => $evidence['paper_entry_id'], 'stock_count_record_id' => $evidence['closing_count_id'] ?? null]);
        }

        return ['administered' => $entry, 'waste' => $waste];
    }

    public function historicalDose(ClientMedicationAdministration $admin, User $actor, array $evidence, array $controlledEntries = []): void
    {
        $evidence = $this->validateHistoricalEvidence($admin->medication, $evidence, true);
        $stock = $this->lockedStock($admin->medication);
        abort_unless($admin->status === 'given' && (int) $admin->administered_by === (int) $actor->id, 404);
        if (MedicationStockMovement::where('administration_id', $admin->id)->exists()) {
            return;
        }
        $covered = $evidence['settlement'] === 'covered_by_count';
        if ($admin->medication->controlled_drug) {
            $entry = $controlledEntries['administered'] ?? null;
            abort_unless($entry && (int) $entry->client_medication_administration_id === (int) $admin->id, 404);
            $this->assertPhysicalBalance($stock, $entry->on_hand_before);
        }
        $lots = $this->lots($stock)->keyBy('id');
        $operations = ['given' => (string) Str::uuid(), 'waste' => (string) Str::uuid()];
        foreach ($evidence['lines'] as $line) {
            $lot = $lots[$line['lot_id']];
            foreach (['given' => Qty::subtract($line['quantity'], $line['quantity_wasted']), 'waste' => $line['quantity_wasted']] as $kind => $quantity) {
                if (! Qty::greaterThan($quantity, 0)) {
                    continue;
                }
                $before = $lot->quantity_remaining;
                $after = $covered ? $before : Qty::subtract($before, $quantity);
                if (! $covered) {
                    $this->settleLot($lot, $after);
                }
                $part = $controlledEntries[$kind === 'given' ? 'administered' : 'waste'] ?? null;
                $this->linkedMovement($lot, $actor, $operations[$kind], 'historical_'.$kind, $quantity, $before, $after,
                    $covered ? 'Already included in the explicitly covering closing count.' : 'Exact physical paper dose settlement.',
                    ['administration_id' => $admin->id, 'controlled_entry_id' => $part?->id,
                        'stock_count_record_id' => $evidence['closing_count_id'] ?? null, 'evidence' => $evidence]);
            }
        }
        if ($admin->medication->controlled_drug) {
            $last = $controlledEntries['waste'] ?? $controlledEntries['administered'];
            $this->assertPhysicalBalance($stock, $last->on_hand_after);
            $stock->forceFill(['on_hand' => $last->on_hand_after])->saveFromPackLedger();
            $this->stockChangedAfterCommit();
        } elseif (! $covered) {
            $this->refreshBalance($stock);
        }
    }

    public function recoveryOptions(ClientMedication $order, ?MedicationPaperEntry $paperEntry = null): array
    {
        $stock = ClientMedicationStock::where('client_medication_id', $order->id)->with('lots')->first();
        if (! $stock || $stock->lots_started_at === null) {
            return ['stock_id' => $stock?->id, 'unit' => $stock?->unit, 'lots' => [], 'reviewed_counts' => []];
        }
        $lots = $stock->lots->keyBy('id');
        $counts = $paperEntry ? MedicationStockCountRecord::where('client_medication_stock_id', $stock->id)
            ->whereIn('state', ['counted', 'reviewed'])->whereNotNull('coverage_start')->whereNotNull('coverage_end')->orderByDesc('id')->get()
            ->filter(fn ($count) => in_array((int) $paperEntry->id, array_map('intval', $count->covered_paper_entry_ids ?? []), true)
                && $paperEntry->given_at->betweenIncluded($count->coverage_start, $count->coverage_end)
                && $paperEntry->given_at->lte($count->counted_at)
                && (! $order->controlled_drug || ($count->controlled_entry_id && $count->witnessed_by))
                && collect($count->lines)->every(function ($line) use ($lots, $count): bool {
                    $lot = $lots->get($line['lot_id']);
                    $revision = $line['settled_revision'] ?? ($line['revision'] + ($count->state === 'reviewed' && ! Qty::equals($line['expected'], $line['counted']) ? 1 : 0));

                    return $lot && (int) $revision === $lot->revision && Qty::equals($lot->quantity_remaining, $line['counted']);
                })) : collect();

        return ['stock_id' => $stock->id, 'unit' => $stock->unit,
            'lots' => $lots->map->only(['id', 'batch_number', 'expiry_date', 'state', 'quantity_remaining', 'revision'])->values()->all(),
            'reviewed_counts' => $counts->map(fn ($count) => ['id' => $count->id, 'label' => 'Covering physical count '.$count->id,
                'counted_at' => $count->counted_at->toIso8601String(),
                'lines' => collect($count->lines)->map(fn ($line) => ['lot_id' => $line['lot_id'],
                    'revision' => $lots[$line['lot_id']]->revision, 'closing_quantity' => $line['counted']])->all()])->values()->all()];
    }

    private function countCoverage(ClientMedicationStock $stock, array $input, mixed $countedAt): array
    {
        $ids = array_values(array_unique(array_map('intval', $input['covered_paper_entry_ids'] ?? [])));
        if ($ids === []) {
            if (! empty($input['coverage_start']) || ! empty($input['coverage_end'])) {
                $this->packError('A covering interval must identify the retained paper entries it includes.');
            }

            return [];
        }
        if (empty($input['coverage_start']) || empty($input['coverage_end'])) {
            $this->packError('Record the exact paper interval covered by this physical count.');
        }
        foreach (['coverage_start', 'coverage_end'] as $field) {
            if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', (string) $input[$field])) {
                $this->packError('Covering count times need an explicit time-zone offset.');
            }
        }
        $start = CarbonImmutable::parse($input['coverage_start'])->utc();
        $end = CarbonImmutable::parse($input['coverage_end'])->utc();
        if ($end->lt($start) || $end->gt(CarbonImmutable::parse($countedAt))) {
            $this->packError('The covering paper interval must end before the physical count.');
        }
        $entries = MedicationPaperEntry::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($entries->count() === count($ids), 404);
        foreach ($entries as $entry) {
            abort_unless((int) $entry->client_medication_id === (int) $stock->client_medication_id
                && (int) $entry->client_id === (int) $stock->medication->client_id, 404);
            if ($entry->outcome !== 'given' || ! $entry->given_at->betweenIncluded($start, $end)
                || (int) ($entry->stock_evidence['stock_id'] ?? 0) !== (int) $stock->id
                || ($entry->stock_evidence['unit'] ?? null) !== $stock->unit
                || $entry->posting()->exists()) {
                $this->packError('Count coverage requires the exact retained, unposted paper dose, stock unit and physical interval.');
            }
        }

        return ['coverage_start' => $start, 'coverage_end' => $end, 'covered_paper_entry_ids' => $ids];
    }
}
