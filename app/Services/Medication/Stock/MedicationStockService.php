<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\PharmacySupplyRules;
use App\Support\Medication\StockLotRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Caller owns client -> medication -> authorization locks; all changes take
 * stock -> order/lot (ascending id) locks inside that transaction.
 * This service supplies no authorization and never guesses dose conversions.
 */
final class MedicationStockService
{
    public function today(): string
    {
        return StockAvailability::today();
    }

    public function lockedStock(ClientMedication $medication): ClientMedicationStock
    {
        $this->assertTransaction();

        return ClientMedicationStock::query()->where('client_medication_id', $medication->id)->lockForUpdate()->firstOrFail();
    }

    /** Deliberate balance carry-forward, after Main connects every writer. No historic record is changed. */
    public function startLots(ClientMedicationStock $stock, User $actor, string $operationUuid): void
    {
        $this->reloadLockedStock($stock);
        if ($stock->lots_started_at !== null) {
            return;
        }
        if ($stock->on_hand === null) {
            throw ValidationException::withMessages(['quantity' => 'The stock balance is unknown. Count and reconcile it before setting up packs.']);
        }
        if (Qty::greaterThan($stock->on_hand, 0)) {
            $lot = MedicationStockLot::create([
                'client_medication_stock_id' => $stock->id,
                'batch_number' => $stock->batch_number,
                // Unknown historic metadata is never labelled "not printed".
                'expiry_date' => $stock->expiry_date?->toDateString(),
                'quantity_received' => $stock->on_hand,
                'quantity_remaining' => $stock->on_hand,
                'source' => 'recorded_balance',
                'source_reference' => 'Existing stock record '.$stock->id,
                'received_by' => null,
                'received_at' => now(),
                'notes' => 'Carried forward from the recorded balance. Original receipt date and pack checks are not known.',
            ]);
            $this->movement($lot, $actor, $operationUuid, 'opening', $stock->on_hand, '0.00', $stock->on_hand, 'Recorded balance carried forward; not a new receipt.');
        }
        $stock->forceFill(['lots_started_at' => now()])->saveFromPackLedger();
        AuditLogger::logOrFail('medications.stock.lots_started', $stock, ['actor_id' => $actor->id, 'recorded_balance' => $stock->on_hand]);
    }

    /** One delivery can contain several printed batches; the caller transaction commits all packs or none. */
    public function receiveDelivery(ClientMedicationStock $stock, User $actor, array $data, ?MedicationPharmacyOrder $order = null): array
    {
        $this->requireLots($stock);
        $packs = $data['packs'] ?? [collect($data)->only([
            'quantity', 'batch_number', 'batch_not_printed', 'expiry_month', 'expiry_not_printed', 'short_expiry_reason',
        ])->all()];
        try {
            $total = '0.00';
            foreach ($packs as $pack) {
                $total = Qty::add($total, $pack['quantity']);
            }
            // Validate the combined usable balance before creating any pack.
            Qty::add($stock->availableQuantity() ?? '0.00', $total);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['packs' => $error->getMessage()]);
        }
        if ($order) {
            try {
                $expected = $order->quantity_dispensed ?? $order->quantity_ordered;
                $next = PharmacySupplyRules::afterReceipt($order->status, $expected, $order->quantity_received ?? 0, $total);
                if ($next['status'] === 'part_received') {
                    if (! in_array($data['delivery_outcome'] ?? null, ['still_to_come', 'closed_short'], true)) {
                        throw new InvalidArgumentException('Fewer arrived than the pharmacy sent. Say whether the rest is coming.');
                    }
                    if ($data['delivery_outcome'] === 'closed_short' && trim((string) ($data['closure_reason'] ?? '')) === '') {
                        throw new InvalidArgumentException('Enter why no more is coming before closing this order short.');
                    }
                }
            } catch (InvalidArgumentException $error) {
                throw ValidationException::withMessages(['delivery_outcome' => $error->getMessage()]);
            }
        }
        $lotIds = [];
        foreach ($packs as $index => $pack) {
            try {
                $lotIds[] = $this->receive($stock, $actor, [...$data, ...$pack], $order)->id;
            } catch (ValidationException $error) {
                // Preserve per-pack field positions when a later label fails; the outer transaction rolls back every pack.
                if (isset($data['packs'])) {
                    throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(
                        fn ($messages, $field) => ['packs.'.$index.'.'.$field => $messages],
                    )->all());
                }
                throw $error;
            }
        }
        if ($order && $order->status === 'part_received' && ($data['delivery_outcome'] ?? null) === 'closed_short') {
            $order->forceFill(['status' => 'closed_short', 'closed_at' => now(), 'closed_by' => $actor->id,
                'closure_reason' => trim($data['closure_reason'])])->save();
            AuditLogger::logOrFail('medications.stock.supply_order_closed_short', $order, [
                'actor_id' => $actor->id, 'reason' => $data['closure_reason'], 'request_uuid' => $data['request_uuid'],
            ]);
        }
        return ['lot_id' => $lotIds[0], 'lot_ids' => $lotIds, 'quantity_received' => $total];
    }

    /** Creates exactly one counted pack; pharmacy total and balance change together. */
    public function receive(ClientMedicationStock $stock, User $actor, array $data, ?MedicationPharmacyOrder $order = null): MedicationStockLot
    {
        $this->requireLots($stock);
        $medication = $stock->medication;
        if ($medication->controlled_drug) {
            throw ValidationException::withMessages(['quantity' => 'The house lead receives controlled medicines through the witnessed register.']);
        }
        $this->assertActive($medication);
        $quantity = Qty::normalize($data['quantity']);
        if (! Qty::greaterThan($quantity, 0)) {
            throw ValidationException::withMessages(['quantity' => 'Count what arrived and enter a quantity above zero.']);
        }
        $expiry = $this->receiptExpiry($data);
        $batch = trim((string) ($data['batch_number'] ?? ''));
        if ($batch === '' && ! ($data['batch_not_printed'] ?? false)) {
            throw ValidationException::withMessages(['batch_number' => 'Enter the batch, or choose Not printed on the pack.']);
        }
        if ($batch !== '' && ($data['batch_not_printed'] ?? false)) {
            throw ValidationException::withMessages(['batch_number' => 'Use the printed batch, or choose Not printed; do not save both.']);
        }
        if ($order) {
            if ((int) $order->client_medication_id !== (int) $medication->id || (int) $order->client_id !== (int) $medication->client_id) {
                abort(404);
            }
            try {
                $next = PharmacySupplyRules::afterReceipt($order->status, $order->quantity_dispensed ?? $order->quantity_ordered, $order->quantity_received ?? 0, $quantity);
            } catch (InvalidArgumentException $error) {
                throw ValidationException::withMessages(['quantity' => $error->getMessage()]);
            }
        }
        $lot = MedicationStockLot::create([
            'client_medication_stock_id' => $stock->id,
            'batch_number' => $batch === '' ? null : $batch,
            'batch_not_printed' => (bool) ($data['batch_not_printed'] ?? false),
            'expiry_date' => $expiry,
            'expiry_not_printed' => (bool) ($data['expiry_not_printed'] ?? false),
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
            'source' => $order ? 'pharmacy_order' : $data['source'],
            'source_reference' => $data['source_reference'] ?? null,
            'pharmacy_order_id' => $order?->id,
            'received_by' => $actor->id,
            'received_at' => now(),
            'short_expiry_reason' => $data['short_expiry_reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->movement($lot, $actor, $data['request_uuid'], 'received', $quantity, '0.00', $quantity, 'Counted delivery received.', $data['notes'] ?? null);
        if ($order) {
            $order->forceFill([
                ...$next,
                'received_by' => $actor->id,
                'delivered_at' => $next['status'] === 'delivered' ? now() : null,
                'delivery_notes' => $data['notes'] ?? $order->delivery_notes,
            ])->save();
        }
        $this->refreshBalance($stock);
        AuditLogger::logOrFail('medications.stock.pack_received', $lot, [
            'actor_id' => $actor->id, 'client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
            'quantity' => $quantity, 'pharmacy_order_id' => $order?->id, 'request_uuid' => $data['request_uuid'],
        ]);

        return $lot;
    }

    /**
     * P07 contract: call after the witnessed register entry AND its balance
     * write, within their existing governing transaction. Never increments
     * the authoritative controlled balance. Witness policy stays with P07.
     */
    public function controlledReceiptAfterRegister(ClientMedicationStock $stock, User $actor, array $data, \App\Models\ClientControlledDrugEntry $entry, bool $witnessPolicySatisfied, ?MedicationPharmacyOrder $order = null): MedicationStockLot
    {
        $this->requireLots($stock);
        $med = $stock->medication;
        $quantity = Qty::normalize($data['quantity']);
        if (! $med->controlled_drug || ! $witnessPolicySatisfied || ! $entry->exists || $entry->entry_type !== 'receipt'
            || (int) $entry->client_id !== (int) $med->client_id || (int) $entry->client_medication_id !== (int) $med->id
            || (int) $entry->recorded_by !== (int) $actor->id || $entry->unit !== $stock->unit
            || ! Qty::greaterThan($quantity, 0) || ! Qty::equals($entry->quantity, $quantity)) {
            throw ValidationException::withMessages(['quantity' => 'The controlled receipt does not match its witnessed register entry.']);
        }
        $existing = MedicationStockLot::where('controlled_entry_id', $entry->id)->first();
        if ($existing) {
            abort_unless((int) $existing->client_medication_stock_id === (int) $stock->id
                && Qty::equals($existing->quantity_received, $quantity), 404);
            return $existing;
        }
        $lots = $this->lots($stock);
        $physicalBefore = $lots->reduce(fn (string $sum, $lot): string => Qty::add($sum, $lot->quantity_remaining), '0.00');
        if (! $med->controlled_drug || ! $witnessPolicySatisfied || ! $entry->exists || $entry->entry_type !== 'receipt'
            || (int) $entry->client_id !== (int) $med->client_id || (int) $entry->client_medication_id !== (int) $med->id
            || (int) $entry->recorded_by !== (int) $actor->id || $entry->unit !== $stock->unit
            || ! Qty::greaterThan($quantity, 0) || ! Qty::equals($entry->quantity, $quantity)
            || ! Qty::equals($entry->on_hand_before, $physicalBefore)
            || ! Qty::equals($entry->on_hand_after, Qty::add($physicalBefore, $quantity))
            || ! Qty::equals($stock->on_hand, $entry->on_hand_after)) {
            throw ValidationException::withMessages(['quantity' => 'The controlled receipt does not match its witnessed register entry and stock balance.']);
        }
        if ($order && ((int) $order->client_id !== (int) $med->client_id || (int) $order->client_medication_id !== (int) $med->id
            || (int) $entry->pharmacy_order_id !== (int) $order->id)) {
            abort(404);
        }
        $expiry = $this->receiptExpiry($data);
        $batch = trim((string) ($data['batch_number'] ?? ''));
        if (($batch === '') !== (bool) ($data['batch_not_printed'] ?? false)) {
            throw ValidationException::withMessages(['batch_number' => 'Enter the batch or choose Not printed on the pack.']);
        }
        $lot = MedicationStockLot::create([
            'client_medication_stock_id' => $stock->id, 'controlled_entry_id' => $entry->id,
            'batch_number' => $batch === '' ? null : $batch, 'batch_not_printed' => (bool) ($data['batch_not_printed'] ?? false),
            'expiry_date' => $expiry, 'expiry_not_printed' => (bool) ($data['expiry_not_printed'] ?? false),
            'quantity_received' => $quantity, 'quantity_remaining' => $quantity,
            'source' => $order ? 'pharmacy_order' : $data['source'], 'source_reference' => $data['source_reference'] ?? null,
            'pharmacy_order_id' => $order?->id, 'received_by' => $actor->id, 'received_at' => now(),
            'short_expiry_reason' => $data['short_expiry_reason'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);
        $this->movement($lot, $actor, $data['request_uuid'], 'controlled_receipt', $quantity, '0.00', $quantity,
            'Received against witnessed register entry '.$entry->id.'.', $data['notes'] ?? null);
        AuditLogger::logOrFail('medications.stock.controlled_pack_linked', $lot, ['actor_id' => $actor->id, 'controlled_entry_id' => $entry->id]);
        return $lot;
    }

    /** P01 contract: call once, inside the administration transaction, after its duplicate guards. */
    public function ordinaryDose(ClientMedicationAdministration $administration, User $actor, int|float|string $quantityInStockUnit): void
    {
        $this->assertTransaction();
        $medication = $administration->medication;
        if (! $medication || $medication->controlled_drug || $administration->status !== 'given') {
            throw new LogicException('Ordinary stock allocation requires a given, non-controlled administration.');
        }
        $stock = $this->lockedStock($medication);
        $this->requireLots($stock);
        if (MedicationStockMovement::query()->where('administration_id', $administration->id)->where('kind', 'given')->exists()) {
            return;
        }
        try {
            $allocation = StockLotRules::allocate($this->lots($stock)->toArray(), $quantityInStockUnit, $this->today());
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['quantity_administered' => $error->getMessage()]);
        }
        $operation = (string) \Illuminate\Support\Str::uuid();
        foreach ($allocation as $line) {
            $lot = MedicationStockLot::findOrFail($line['lot_id']);
            $lot->forceFill(['quantity_remaining' => $line['after'], 'revision' => $lot->revision + 1])->save();
            $this->movement($lot, $actor, $operation, 'given', $line['quantity'], $line['before'], $line['after'], 'Given from stock.', administrationId: $administration->id);
        }
        $this->refreshBalance($stock);
    }

    /** Per-pack movement; controlled movements remain owned by P07. */
    public function move(ClientMedicationStock $stock, User $actor, array $data): MedicationStockMovement
    {
        $this->requireLots($stock);
        if ($stock->medication->controlled_drug) {
            throw ValidationException::withMessages(['quantity' => 'Use the controlled-drug register for this movement.']);
        }
        $lot = $this->lots($stock)->firstWhere('id', (int) $data['lot_id']);
        abort_unless($lot, 404);
        $before = $lot->quantity_remaining;
        $quantity = Qty::normalize($data['quantity']);
        if (! Qty::greaterThan($quantity, 0) || Qty::greaterThan($quantity, $before)) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity above zero, no more than remains in this pack.']);
        }
        $kind = $data['kind'];
        if (! in_array($kind, ['going_out', 'returned_pharmacy', 'removed_expired', 'damaged', 'quarantined'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a stock movement.']);
        }
        if ($kind === 'going_out' && ! StockLotRules::usable($lot->toArray(), $this->today())) {
            throw ValidationException::withMessages(['lot_id' => 'This pack is expired or out of use. Choose a usable pack.']);
        }
        $after = $kind === 'quarantined' ? $before : Qty::subtract($before, $quantity);
        if ($kind === 'quarantined' && ! Qty::equals($quantity, $before)) {
            throw ValidationException::withMessages(['quantity' => 'Quarantine takes the whole remaining pack out of use.']);
        }
        $lot->forceFill(['quantity_remaining' => $after, 'revision' => $lot->revision + 1, 'state' => $kind === 'quarantined' ? 'quarantined' : $lot->state])->save();
        $move = $this->movement($lot, $actor, $data['request_uuid'], $kind, $quantity, $before, $after, $data['reason'], $data['notes'] ?? null);
        $this->refreshBalance($stock);
        AuditLogger::logOrFail('medications.stock.movement', $move, ['actor_id' => $actor->id, 'stock_id' => $stock->id]);

        return $move;
    }

    /** One return closes its outward movement; quantity used away is preserved as evidence. */
    public function comingBack(ClientMedicationStock $stock, User $actor, array $data): MedicationStockMovement
    {
        $this->requireLots($stock);
        if ($stock->medication->controlled_drug) {
            throw ValidationException::withMessages(['quantity' => 'Use the controlled-drug register for this return.']);
        }
        $out = MedicationStockMovement::query()->whereKey($data['return_of_id'])
            ->where('kind', 'going_out')
            ->whereHas('lot', fn ($query) => $query->where('client_medication_stock_id', $stock->id))->lockForUpdate()->firstOrFail();
        if (MedicationStockMovement::query()->where('return_of_id', $out->id)->exists()) {
            throw ValidationException::withMessages(['return_of_id' => 'This supply has already been recorded coming back.']);
        }
        $returned = Qty::normalize($data['quantity']);
        $used = Qty::normalize($data['used_away']);
        if (Qty::greaterThan(0, $returned) || Qty::greaterThan(0, $used) || ! Qty::equals(Qty::add($returned, $used), $out->quantity)) {
            throw ValidationException::withMessages(['quantity' => 'What came back plus what was used while away must equal what went out. Record any unexplained difference with the house lead.']);
        }
        $lot = $this->lots($stock)->firstWhere('id', $out->medication_stock_lot_id);
        $before = $lot->quantity_remaining;
        $after = Qty::add($before, $returned);
        $lot->forceFill(['quantity_remaining' => $after, 'revision' => $lot->revision + 1])->save();
        $move = $this->movement($lot, $actor, $data['request_uuid'], 'coming_back', $returned, $before, $after,
            $data['reason'], 'Used while away: '.Qty::display($used).' '.$stock->unit.'. '.($data['notes'] ?? ''), returnOfId: $out->id);
        $this->refreshBalance($stock);

        return $move;
    }

    /** Expected values are never supplied in a blind-count form; captured from locked packs at submit. */
    public function count(ClientMedicationStock $stock, User $actor, array $data): MedicationStockCountRecord
    {
        $this->requireLots($stock);
        if ($stock->medication->controlled_drug) {
            throw ValidationException::withMessages(['lines' => 'Controlled counts keep their witnessed register process.']);
        }
        $lots = $this->lots($stock)->filter(fn ($lot) => Qty::greaterThan($lot->quantity_remaining, 0));
        if ($lots->isEmpty() && ! ($data['confirm_empty'] ?? false)) {
            throw ValidationException::withMessages(['confirm_empty' => 'Confirm that you physically checked and there are no packs to count.']);
        }
        $submitted = collect($data['lines'])->keyBy('lot_id');
        if ($submitted->count() !== count($data['lines']) || $submitted->keys()->map(fn ($id) => (int) $id)->sort()->values()->all() !== $lots->pluck('id')->sort()->values()->all()) {
            throw ValidationException::withMessages(['lines' => 'The packs changed. Reload the list and count every remaining pack.']);
        }
        $different = false;
        $lines = [];
        foreach ($lots as $lot) {
            if ((int) ($submitted[$lot->id]['revision'] ?? -1) !== $lot->revision) {
                throw ValidationException::withMessages(['lines' => 'Stock moved while you counted. Reload the packs and count again.']);
            }
            $counted = Qty::normalize($submitted[$lot->id]['quantity']);
            if (Qty::greaterThan(0, $counted)) {
                throw ValidationException::withMessages(['lines' => 'Counts cannot be negative.']);
            }
            $different = $different || ! Qty::equals($counted, $lot->quantity_remaining);
            $lines[] = ['lot_id' => $lot->id, 'expected' => $lot->quantity_remaining, 'counted' => $counted, 'revision' => $lot->revision];
        }
        if ($different && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the difference for the house lead. Your counts are kept on this form.']);
        }
        $record = MedicationStockCountRecord::create([
            'client_medication_stock_id' => $stock->id, 'request_uuid' => $data['request_uuid'],
            'lines' => $lines, 'reason' => $data['reason'] ?? null, 'counted_by' => $actor->id,
            'counted_at' => now(), 'state' => $different ? 'needs_review' : 'counted',
        ]);
        // Counts with a difference wait for review; they never silently rewrite supply.
        if (! $different) {
            $stock->forceFill(['last_counted_at' => $record->counted_at])->saveFromPackLedger();
        }
        AuditLogger::logOrFail('medications.stock.counted', $record, ['actor_id' => $actor->id, 'stock_id' => $stock->id, 'needs_review' => $different]);

        return $record;
    }

    public function reviewCount(ClientMedicationStock $stock, MedicationStockCountRecord $record, User $actor, string $reason, string $operationUuid): void
    {
        $this->requireLots($stock);
        $freshRecord = MedicationStockCountRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
        $record->setRawAttributes($freshRecord->getAttributes(), true);
        abort_unless((int) $record->client_medication_stock_id === (int) $stock->id, 404);
        if ($record->state !== 'needs_review') {
            throw ValidationException::withMessages(['reason' => 'This count has already been reviewed.']);
        }
        $lots = $this->lots($stock)->keyBy('id');
        $countedIds = collect($record->lines)->pluck('lot_id')->sort()->values()->all();
        $remainingIds = $lots->filter(fn ($lot) => Qty::greaterThan($lot->quantity_remaining, 0))->pluck('id')->sort()->values()->all();
        if ($countedIds !== $remainingIds) {
            throw ValidationException::withMessages(['reason' => 'The packs changed after this count. Count again before changing the balance.']);
        }
        foreach ($record->lines as $line) {
            $lot = $lots->get($line['lot_id']);
            if (! $lot || $lot->revision !== $line['revision']) {
                throw ValidationException::withMessages(['reason' => 'Stock moved after this count. Count again before changing the balance.']);
            }
        }
        foreach ($record->lines as $line) {
            if (Qty::equals($line['expected'], $line['counted'])) {
                continue;
            }
            $lot = $lots[$line['lot_id']];
            $lot->forceFill(['quantity_remaining' => $line['counted'], 'revision' => $lot->revision + 1])->save();
            $this->movement($lot, $actor, $operationUuid, 'count_correction', Qty::subtract($line['counted'], $line['expected']), $line['expected'], $line['counted'], $reason);
        }
        $record->forceFill(['state' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason])->save();
        $stock->forceFill(['last_counted_at' => $record->counted_at])->saveFromPackLedger();
        $this->refreshBalance($stock);
        AuditLogger::logOrFail('medications.stock.count_reviewed', $record, ['actor_id' => $actor->id, 'reason' => $reason]);
    }

    public function refreshBalance(ClientMedicationStock $stock): void
    {
        if (app(StockAvailability::class)->controlled($stock)) {
            throw new LogicException('The controlled register owns its physical balance.');
        }
        $this->requireLots($stock);
        $lots = $this->lots($stock);
        $usable = $lots->filter(fn ($lot) => StockLotRules::usable($lot->toArray(), $this->today()));
        $first = $usable->sortBy(fn ($lot) => $lot->expiry_date?->toDateString() ?? '9999-12-31')->first();
        $stock->forceFill([
            'on_hand' => StockLotRules::onHand($lots->toArray(), $this->today()),
            'batch_number' => $first?->batch_number,
            'expiry_date' => $first?->expiry_date,
        ])->saveFromPackLedger();
    }

    private function receiptExpiry(array $data): ?string
    {
        $expiry = null;
        if (! ($data['expiry_not_printed'] ?? false)) {
            try {
                $expiry = StockLotRules::monthExpiry((string) ($data['expiry_month'] ?? ''));
            } catch (InvalidArgumentException $error) {
                throw ValidationException::withMessages(['expiry_month' => $error->getMessage()]);
            }
            if ($expiry < $this->today()) {
                throw ValidationException::withMessages(['expiry_month' => 'This pack has expired. Keep it out of use and contact the pharmacy.']);
            }
            if ($expiry <= CarbonImmutable::parse($this->today())->addDays(7)->toDateString()
                && trim((string) ($data['short_expiry_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['short_expiry_reason' => 'This expires within 7 days. Explain why it is being accepted.']);
            }
        } elseif (trim((string) ($data['expiry_month'] ?? '')) !== '') {
            throw ValidationException::withMessages(['expiry_month' => 'Use the printed expiry, or choose Not printed; do not save both.']);
        }

        return $expiry;
    }

    private function lots(ClientMedicationStock $stock)
    {
        return MedicationStockLot::query()->where('client_medication_stock_id', $stock->id)->orderBy('id')->lockForUpdate()->get();
    }

    private function movement(MedicationStockLot $lot, User $actor, string $operation, string $kind, int|float|string $quantity, int|float|string $before, int|float|string $after, string $reason, ?string $notes = null, ?int $administrationId = null, ?int $returnOfId = null): MedicationStockMovement
    {
        return MedicationStockMovement::create([
            'medication_stock_lot_id' => $lot->id, 'operation_uuid' => $operation, 'kind' => $kind,
            'quantity' => Qty::normalize($quantity), 'balance_before' => $before, 'balance_after' => $after,
            'reason' => $reason, 'notes' => $notes, 'recorded_by' => $actor->id, 'recorded_at' => now(),
            'administration_id' => $administrationId, 'return_of_id' => $returnOfId,
        ]);
    }

    private function reloadLockedStock(ClientMedicationStock $stock): void
    {
        $this->assertTransaction();
        $locked = ClientMedicationStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $stock->setRawAttributes($locked->getAttributes(), true);
        $stock->unsetRelation('lots');
    }

    private function requireLots(ClientMedicationStock $stock): void
    {
        // One stock mutex precedes every lot lock, including callers holding
        // an old model snapshot. A dose and a movement cannot lose each other.
        $this->reloadLockedStock($stock);
        if ($stock->lots_started_at === null) {
            throw ValidationException::withMessages(['quantity' => 'Pack-level stock is not set up yet. The house lead must check the recorded balance first.']);
        }
    }

    private function assertActive(ClientMedication $medication): void
    {
        if (! $medication->active || $medication->state !== 'active' || $medication->superseded_by !== null) {
            throw ValidationException::withMessages(['client_medication_id' => 'This medicine is no longer active. Check the current order before receiving.']);
        }
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stock changes must be inside the canonical medication transaction.');
        }
    }
}




