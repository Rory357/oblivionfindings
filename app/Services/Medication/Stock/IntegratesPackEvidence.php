<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDestruction;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\PharmacySupplyRules;
use App\Support\Medication\StockLotRules;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Internal atomic adapters. Authorization and witness policy belong to the canonical caller. */
trait IntegratesPackEvidence
{
    public function controlledDeliveryAfterRegister(ClientMedicationStock $stock, User $actor, array $data, ClientControlledDrugEntry $entry, ?MedicationPharmacyOrder $order = null): array
    {
        $this->requireLots($stock);
        $med = $stock->medication;
        $this->assertActive($med);
        $packs = $data['packs'] ?? [collect($data)->only(['quantity', 'batch_number', 'batch_not_printed', 'expiry_month', 'expiry_not_printed', 'short_expiry_reason'])->all()];
        if ($packs === []) {
            $this->packError('Enter the actual counted packs for this controlled receipt.');
        }
        $total = collect($packs)->reduce(fn ($sum, $pack) => Qty::add($sum, $pack['quantity']), '0.00');
        abort_unless($med->controlled_drug && (int) $entry->client_medication_id === (int) $med->id
            && (int) $entry->client_id === (int) $med->client_id && (int) $entry->recorded_by === (int) $actor->id, 404);
        if ($entry->entry_type !== 'receipt' || ! $entry->witnessed_by || $entry->unit !== $stock->unit
            || ! Qty::equals($entry->quantity, $total)) {
            $this->packError('The counted packs must match the witnessed receipt quantity and unit.');
        }
        $existing = MedicationStockLot::where('controlled_entry_id', $entry->id)->orderBy('id')->get();
        if ($existing->isNotEmpty()) {
            if (! Qty::equals($existing->reduce(fn ($sum, $lot) => Qty::add($sum, $lot->quantity_received), '0.00'), $total) || $existing->contains(fn ($lot) => (int) $lot->client_medication_stock_id !== (int) $stock->id)) {
                $this->packError('The register receipt already has different pack evidence.');
            }

            return ['entry_id' => $entry->id, 'lot_id' => $existing->first()->id, 'lot_ids' => $existing->modelKeys(), 'quantity_received' => $total];
        }
        if (! Qty::equals($this->physicalQuantity($stock), $entry->on_hand_before)
            || ! Qty::equals(Qty::add($entry->on_hand_before, $total), $entry->on_hand_after)
            || ! Qty::equals($stock->on_hand, $entry->on_hand_after)) {
            $this->packError('The pack total does not match the witnessed register transition.');
        }
        $next = null;
        if ($order) {
            abort_unless((int) $order->client_id === (int) $med->client_id && (int) $order->client_medication_id === (int) $med->id
                && (int) $entry->pharmacy_order_id === (int) $order->id, 404);
            try {
                $next = PharmacySupplyRules::afterReceipt($order->status, $order->quantity_dispensed ?? $order->quantity_ordered, $order->quantity_received ?? 0, $total);
            } catch (InvalidArgumentException $error) {
                $this->packError($error->getMessage());
            }
            if ($next['status'] === 'part_received' && ! in_array($data['delivery_outcome'] ?? null, ['still_to_come', 'closed_short'], true)) {
                $this->packError('Say whether the outstanding pharmacy supply is still coming.');
            }
            if (($data['delivery_outcome'] ?? null) === 'closed_short' && blank($data['closure_reason'] ?? null)) {
                $this->packError('Enter why no more pharmacy supply is coming.');
            }
        }
        $ids = [];
        foreach ($packs as $index => $pack) {
            $quantity = Qty::normalize($pack['quantity']);
            $batch = trim((string) ($pack['batch_number'] ?? ''));
            if (! Qty::greaterThan($quantity, 0) || (($batch === '') !== (bool) ($pack['batch_not_printed'] ?? false))) {
                throw ValidationException::withMessages(['packs.'.$index => 'Enter the counted quantity and printed batch, or explicitly record Not printed.']);
            }
            try {
                $expiry = $this->receiptExpiry($pack);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $key) => [
                    (isset($data['packs']) ? 'packs.'.$index.'.' : '').$key => $messages,
                ])->all());
            }
            $lot = MedicationStockLot::create([
                'client_medication_stock_id' => $stock->id, 'controlled_entry_id' => $entry->id,
                'batch_number' => $batch ?: null, 'batch_not_printed' => (bool) ($pack['batch_not_printed'] ?? false),
                'expiry_date' => $expiry, 'expiry_not_printed' => (bool) ($pack['expiry_not_printed'] ?? false),
                'quantity_received' => $quantity, 'quantity_remaining' => $quantity,
                'source' => $order ? 'pharmacy_order' : $data['source'], 'source_reference' => $data['source_reference'] ?? null,
                'pharmacy_order_id' => $order?->id, 'received_by' => $actor->id, 'received_at' => $entry->recorded_at,
                'short_expiry_reason' => $pack['short_expiry_reason'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);
            $this->linkedMovement($lot, $actor, $data['request_uuid'], 'controlled_receipt', $quantity, '0.00', $quantity, $entry->reason ?? 'Witnessed receipt', ['controlled_entry_id' => $entry->id]);
            $ids[] = $lot->id;
        }
        if ($order) {
            $order->forceFill([...$next, 'received_by' => $actor->id, 'delivered_at' => $next['status'] === 'delivered' ? now() : null])->save();
            if ($next['status'] === 'part_received' && ($data['delivery_outcome'] ?? null) === 'closed_short') {
                $order->forceFill(['status' => 'closed_short', 'closed_at' => now(), 'closed_by' => $actor->id, 'closure_reason' => $data['closure_reason']])->save();
            }
        }
        $this->assertPhysicalBalance($stock, $entry->on_hand_after);
        $this->stockChangedAfterCommit();
        AuditLogger::logOrFail('medications.stock.controlled_delivery_linked', $entry, ['actor_id' => $actor->id, 'lot_ids' => $ids, 'quantity' => $total]);

        return ['entry_id' => $entry->id, 'lot_id' => $ids[0], 'lot_ids' => $ids, 'quantity_received' => $total];
    }

    /** Applies one authoritative house-register transition, including zero-delta loss evidence. */
    public function controlledTransition(ClientMedicationStock $stock, User $actor, ClientControlledDrugEntry $entry, array $input): void
    {
        if ($stock->lots_started_at === null) {
            return;
        }
        $this->requireLots($stock);
        $this->assertPhysicalBalance($stock, $entry->on_hand_before);
        $operation = (string) Str::uuid();
        if ($entry->entry_type === 'reversal') {
            $original = MedicationStockMovement::where('controlled_entry_id', $entry->reverses_entry_id)->orderBy('id')->lockForUpdate()->get();
            if ($original->isEmpty()) {
                $this->packError('This older entry has no exact pack allocation to reverse. Record a new witnessed pack count.');
            }
            $lots = $this->lots($stock)->keyBy('id');
            foreach ($original as $movement) {
                if (MedicationStockMovement::where('return_of_id', $movement->id)->exists() || $movement->transit_log_id !== null) {
                    $this->packError('This movement has subsequent custody or return evidence. Use a witnessed physical pack count.');
                }
                if (MedicationStockMovement::where('reverses_movement_id', $movement->id)->exists()) {
                    $this->packError('The original pack movement has already been reversed.');
                }
                $lot = $lots->get($movement->medication_stock_lot_id);
                abort_unless($lot, 404);
                $before = $lot->quantity_remaining;
                $after = Qty::subtract($before, Qty::subtract($movement->balance_after, $movement->balance_before));
                if (Qty::greaterThan(0, $after)) {
                    $this->packError('Later pack use prevents reversing this receipt. Reconcile the physical packs with a witnessed count.');
                }
                $this->settleLot($lot, $after);
                $this->linkedMovement($lot, $actor, $operation, 'controlled_reversal', Qty::subtract($after, $before), $before, $after, $entry->reason, ['controlled_entry_id' => $entry->id, 'reverses_movement_id' => $movement->id]);
            }
        } elseif ($entry->entry_type === 'balance_check' || ($entry->entry_type === 'reconciliation' && ($input['outcome'] ?? null) === 'recount')) {
            $this->controlledPackCount($stock, $actor, $entry, $input, $operation);
        } elseif ($entry->entry_type === 'transfer_in' || ! Qty::equals($entry->on_hand_before, $entry->on_hand_after)) {
            $adding = $entry->entry_type === 'transfer_in' || Qty::greaterThan($entry->on_hand_after, $entry->on_hand_before);
            $quantity = Qty::absoluteDifference($entry->on_hand_after, $entry->on_hand_before);
            $lines = $this->exactLines($stock, $input['pack_lines'] ?? [], $quantity, $entry->entry_type === 'transfer_in');
            foreach ($lines as [$lot, $line]) {
                $before = $lot->quantity_remaining;
                $after = $adding ? Qty::add($before, $line['quantity']) : Qty::subtract($before, $line['quantity']);
                if (Qty::greaterThan(0, $after)) {
                    $this->packError('A movement cannot remove more than remains in the selected pack.');
                }
                $return = null;
                if ($adding && $entry->entry_type === 'transfer_in') {
                    $return = MedicationStockMovement::whereKey($line['return_of_id'] ?? 0)->where('medication_stock_lot_id', $lot->id)
                        ->where('kind', 'controlled_transfer_out')->lockForUpdate()->firstOrFail();
                    if (($return->evidence['movement_type'] ?? 'going_out') !== 'going_out'
                        || MedicationStockMovement::where('reverses_movement_id', $return->id)->exists()
                        || MedicationStockMovement::where('return_of_id', $return->id)->exists()
                        || Qty::greaterThan(0, $line['used_away'] ?? '0.00')
                        || ! Qty::equals(Qty::add($line['quantity'], $line['used_away'] ?? '0.00'), $return->quantity)) {
                        $this->packError('Returned plus used-away quantities must reconcile the original outward pack exactly once.');
                    }
                }
                if ($entry->entry_type === 'transfer_out' && ($input['movement_type'] ?? 'going_out') === 'going_out'
                    && ! StockLotRules::usable($lot->toArray(), $this->today())) {
                    $this->packError('Only usable packs can go out for a person.');
                }
                $this->settleLot($lot, $after);
                $this->linkedMovement($lot, $actor, $operation, 'controlled_'.$entry->entry_type, $line['quantity'], $before, $after, $entry->reason,
                    ['controlled_entry_id' => $entry->id, 'return_of_id' => $return?->id, 'destruction_id' => $input['destruction_id'] ?? null, 'evidence' => [...$line, 'movement_type' => $input['movement_type'] ?? null]]);
            }
        } else {
            // The earlier witnessed count already applied the shortfall. Link the loss to that count without another subtraction.
            if ($entry->source_type === 'discrepancy') {
                $countEntry = ClientControlledDrugDiscrepancy::whereKey($entry->source_id)->value('count_entry_id');
                $count = MedicationStockCountRecord::where('controlled_entry_id', $countEntry)->first();
                if (! $count) {
                    $this->packError('The counted loss has no retained pack-count evidence.');
                }
                foreach ($this->lots($stock) as $lot) {
                    $this->linkedMovement($lot, $actor, $operation, 'controlled_loss_counted', '0.00', $lot->quantity_remaining, $lot->quantity_remaining, $entry->reason,
                        ['controlled_entry_id' => $entry->id, 'stock_count_record_id' => $count->id]);
                }
            }
        }
        $this->assertPhysicalBalance($stock, $entry->on_hand_after);
        $this->stockChangedAfterCommit();
    }

    private function controlledPackCount(ClientMedicationStock $stock, User $actor, ClientControlledDrugEntry $entry, array $input, string $operation): void
    {
        $lots = $this->lots($stock)->keyBy('id');
        $submitted = collect($input['pack_lines'] ?? [])->keyBy('lot_id');
        if ($submitted->count() !== count($input['pack_lines'] ?? []) || $submitted->keys()->map(fn ($id) => (int) $id)->sort()->values()->all() !== $lots->keys()->sort()->values()->all()) {
            $this->packError('Count every pack, including expired, quarantined and empty packs. The pack list has changed.');
        }
        $total = '0.00';
        $lines = [];
        foreach ($lots as $lot) {
            $line = $submitted[$lot->id];
            if ((int) ($line['revision'] ?? -1) !== $lot->revision) {
                $this->packError('Stock moved while the packs were counted. Reload and count again.');
            }
            $counted = Qty::normalize($line['quantity']);
            if (Qty::greaterThan(0, $counted)) {
                $this->packError('Pack counts cannot be negative.');
            }
            $total = Qty::add($total, $counted);
            $lines[] = ['lot_id' => $lot->id, 'expected' => $lot->quantity_remaining, 'counted' => $counted, 'revision' => $lot->revision,
                'settled_revision' => $lot->revision + (Qty::equals($counted, $lot->quantity_remaining) ? 0 : 1)];
        }
        if (! Qty::equals($total, $entry->on_hand_after)) {
            $this->packError('The individual physical packs must add up to the witnessed register count.');
        }
        $coverage = $this->countCoverage($stock, $input, $entry->recorded_at);
        $record = MedicationStockCountRecord::create([
            'client_medication_stock_id' => $stock->id, 'request_uuid' => $input['client_request_uuid'],
            'controlled_entry_id' => $entry->id, 'witnessed_by' => $entry->witnessed_by, 'counted_by' => $actor->id,
            'counted_at' => $entry->recorded_at, 'state' => 'counted', 'lines' => $lines, 'reason' => $input['notes'] ?? null, ...$coverage,
        ]);
        foreach ($lines as $line) {
            $lot = $lots[$line['lot_id']];
            if (! Qty::equals($line['expected'], $line['counted'])) {
                $this->settleLot($lot, $line['counted']);
            }
            $this->linkedMovement($lot, $actor, $operation, 'controlled_count', Qty::subtract($line['counted'], $line['expected']), $line['expected'], $line['counted'], $entry->reason,
                ['controlled_entry_id' => $entry->id, 'stock_count_record_id' => $record->id]);
        }
    }

    public function controlledDoseAfterRegister(ClientMedicationAdministration $admin, User $actor, ClientControlledDrugEntry $entry, ?ClientControlledDrugEntry $waste = null, array $evidence = []): void
    {
        $stock = $this->lockedStock($admin->medication);
        if ($stock->lots_started_at === null) {
            return;
        }
        $this->requireLots($stock);
        if (MedicationStockMovement::where('administration_id', $admin->id)->exists()) {
            return;
        }
        $this->assertPhysicalBalance($stock, $entry->on_hand_before);
        $removed = Qty::add($entry->quantity, $waste?->quantity ?? '0.00');
        $lines = $this->exactLines($stock, $evidence['pack_lines'] ?? [], $removed);
        $wasteSum = '0.00';
        foreach ($lines as [$lot, $line]) {
            if (! array_key_exists('quantity_wasted', $line) || Qty::greaterThan(0, $line['quantity_wasted'])
                || Qty::greaterThan($line['quantity_wasted'], $line['quantity'])
                || Qty::greaterThan($line['quantity'], $lot->quantity_remaining)
                || $lot->received_at->gt($admin->administered_at)
                || ! StockLotRules::usable($lot->toArray(), $this->today())) {
                $this->packError('Enter the actual selected pack and its witnessed waste; expired or unavailable packs cannot be used.');
            }
            $wasteSum = Qty::add($wasteSum, $line['quantity_wasted']);
        }
        if (! Qty::equals($wasteSum, $waste?->quantity ?? '0.00')) {
            $this->packError('Exact pack waste must equal the witnessed dose waste.');
        }
        $operations = ['given' => (string) Str::uuid(), 'waste' => (string) Str::uuid()];
        foreach ($lines as [$lot, $line]) {
            foreach (['given' => Qty::subtract($line['quantity'], $line['quantity_wasted']), 'waste' => $line['quantity_wasted']] as $kind => $quantity) {
                if (! Qty::greaterThan($quantity, 0)) {
                    continue;
                }
                $part = $kind === 'given' ? $entry : $waste;
                abort_unless($part && (int) $part->client_medication_administration_id === (int) $admin->id && $part->unit === $stock->unit, 404);
                $before = $lot->quantity_remaining;
                $after = Qty::subtract($before, $quantity);
                $this->settleLot($lot, $after);
                $this->linkedMovement($lot, $actor, $operations[$kind], 'controlled_'.$kind, $quantity, $before, $after, $part->reason ?? 'Controlled dose',
                    ['administration_id' => $admin->id, 'controlled_entry_id' => $part->id, 'evidence' => $line]);
            }
        }
        $this->assertPhysicalBalance($stock, ($waste ?? $entry)->on_hand_after);
        $this->stockChangedAfterCommit();
    }

    /** Physical disposal does not authorize administration of an unverified order. */
    public function canDestroyRetainedSupply(ClientMedication $order, ?ClientMedicationStock $stock, bool $lock = false): bool
    {
        if ($order->deleted_at !== null || $order->superseded_by !== null) {
            return false;
        }

        return ($order->state === 'active' && $order->active)
            || $this->hasRetainedCeasedPacks($order, $stock, $lock);
    }

    /** Retained physical supply may be destroyed after a canonical cessation. */
    public function hasRetainedCeasedPacks(ClientMedication $order, ?ClientMedicationStock $stock, bool $lock = false): bool
    {
        if ($order->state !== 'ceased' || $order->active || $order->ceased_at === null
            || $order->deleted_at !== null || $order->superseded_by !== null
            || $stock === null || (int) $stock->client_medication_id !== (int) $order->id
            || $stock->lots_started_at === null || ! Qty::greaterThan($stock->on_hand ?? 0, 0)) {
            return false;
        }
        $lots = $lock ? $this->lots($stock) : $stock->lots;

        return $lots->contains(fn ($lot) => Qty::greaterThan($lot->quantity_remaining, 0));
    }

    /** Caller must already have canonical person/order read authority. */
    public function packOptions(ClientMedication $order): array
    {
        $stock = ClientMedicationStock::where('client_medication_id', $order->id)->with('lots')->first();

        return ['stock_id' => $stock?->id, 'unit' => $stock?->unit, 'lots_started' => $stock?->lots_started_at !== null,
            'physical_on_hand' => $stock?->on_hand, 'usable_on_hand' => $stock ? app(StockAvailability::class)->usableQuantity($stock) : null,
            'outward' => $stock ? MedicationStockMovement::where('kind', 'controlled_transfer_out')
                ->whereHas('lot', fn ($q) => $q->where('client_medication_stock_id', $stock->id))
                ->whereDoesntHave('returns')->whereDoesntHave('reversal')
                ->orderBy('id')->get()->filter(fn ($move) => ($move->evidence['movement_type'] ?? 'going_out') === 'going_out')
                ->map(fn ($move) => ['id' => $move->id, 'lot_id' => $move->medication_stock_lot_id, 'quantity' => $move->quantity,
                    'recorded_at' => $move->recorded_at->toIso8601String(), 'reason' => $move->reason])->values()->all() : [],
            'paper_entries' => $stock ? MedicationPaperEntry::where('client_medication_id', $order->id)->where('client_id', $order->client_id)->where('outcome', 'given')->whereNotNull('stock_evidence')->whereDoesntHave('posting')->orderBy('id')->get()->filter(fn ($entry) => (int) ($entry->stock_evidence['stock_id'] ?? 0) === (int) $stock->id)->map(fn ($entry) => ['id' => $entry->id, 'label' => 'Paper entry '.$entry->id, 'occurred_at' => $entry->given_at->toIso8601String()])->values()->all() : [],
            'lots' => $stock?->lots->map(fn ($lot) => [...$lot->only(['id', 'batch_number', 'batch_not_printed', 'expiry_date', 'expiry_not_printed', 'state', 'quantity_remaining', 'revision']),
                'usable' => StockLotRules::usable($lot->toArray(), $this->today())])->values()->all() ?? []];
    }

    public function removeForDestruction(ClientMedicationStock $stock, User $actor, MedicationDestruction $destruction, array $lines, string $operation): void
    {
        $this->requireLots($stock);
        abort_unless(! $stock->medication->controlled_drug && (int) $destruction->client_medication_id === (int) $stock->client_medication_id
            && (int) $destruction->client_id === (int) $stock->medication->client_id, 404);
        foreach ($this->exactLines($stock, $lines, $destruction->quantity) as [$lot, $line]) {
            if (Qty::greaterThan($line['quantity'], $lot->quantity_remaining)) {
                $this->packError('The destruction exceeds what physically remains in this pack.');
            }
            $before = $lot->quantity_remaining;
            $after = Qty::subtract($before, $line['quantity']);
            $this->settleLot($lot, $after);
            $this->linkedMovement($lot, $actor, $operation, 'destruction', $line['quantity'], $before, $after, $destruction->reason,
                ['destruction_id' => $destruction->id, 'evidence' => $line]);
        }
        $this->refreshBalance($stock);
    }

    public function validateCorrectionLines(ClientMedicationStock $stock, array $lines, int|float|string $quantity): array
    {
        $this->requireLots($stock);
        $this->exactLines($stock, $lines, $quantity);

        return $lines;
    }

    public function revisedCorrectionLines(ClientMedicationStock $stock, array $lines): array
    {
        $lots = $this->lots($stock)->keyBy('id');

        return array_map(fn ($line) => [...$line, 'revision' => $lots[$line['lot_id']]->revision], $lines);
    }

    public function physicalQuantity(ClientMedicationStock $stock): string
    {
        return $this->lots($stock)->reduce(fn ($sum, $lot) => Qty::add($sum, $lot->quantity_remaining), '0.00');
    }

    private function assertPhysicalBalance(ClientMedicationStock $stock, int|float|string $expected): void
    {
        if (! Qty::equals($this->physicalQuantity($stock), $expected)) {
            $this->packError('Physical packs and the controlled register do not reconcile. Take a witnessed pack count before continuing.');
        }
    }

    private function exactLines(ClientMedicationStock $stock, array $submitted, int|float|string $quantity, bool $allowZero = false): array
    {
        $lots = $this->lots($stock)->keyBy('id');
        if (count($submitted) === 0 || count(array_unique(array_column($submitted, 'lot_id'))) !== count($submitted)) {
            $this->packError('Select each actual pack once and enter its quantity.');
        }
        $total = '0.00';
        $result = [];
        foreach ($submitted as $line) {
            $lot = $lots->get((int) ($line['lot_id'] ?? 0));
            abort_unless($lot, 404);
            if ((int) ($line['revision'] ?? -1) !== $lot->revision
                || ($allowZero ? Qty::greaterThan(0, $line['quantity'] ?? -1) : ! Qty::greaterThan($line['quantity'] ?? 0, 0))) {
                $this->packError('The selected pack changed, or its quantity is not above zero. Reload the pack list.');
            }
            $total = Qty::add($total, $line['quantity']);
            $result[] = [$lot, $line];
        }
        if (! Qty::equals($total, $quantity)) {
            $this->packError('The selected pack quantities must equal the recorded physical movement.');
        }

        return $result;
    }

    private function settleLot(MedicationStockLot $lot, int|float|string $after): void
    {
        $lot->forceFill(['quantity_remaining' => Qty::normalize($after), 'revision' => $lot->revision + 1])->save();
    }

    private function linkedMovement(MedicationStockLot $lot, User $actor, string $operation, string $kind, int|float|string $quantity, int|float|string $before, int|float|string $after, string $reason, array $links = []): MedicationStockMovement
    {
        return MedicationStockMovement::create([
            'medication_stock_lot_id' => $lot->id, 'operation_uuid' => $operation, 'kind' => $kind,
            'quantity' => Qty::normalize($quantity), 'balance_before' => $before, 'balance_after' => $after,
            'reason' => $reason, 'recorded_by' => $actor->id, 'recorded_at' => now(), ...$links,
        ]);
    }

    private function packError(string $message): never
    {
        throw ValidationException::withMessages(['pack_lines' => $message]);
    }
}
