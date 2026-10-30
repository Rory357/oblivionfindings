<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\FleetMedicationTransitLog;
use App\Models\MedicationStockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MedicationIncidentIntegrationService;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\StockLotRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait TransitPackEvidence
{
    /** Exact outbound packs leave house supply once; retained allocations account for custody. */
    public function packTransit(FleetMedicationTransitLog $log, User $actor, array $input, ?User $witness): void
    {
        $stock = ClientMedicationStock::where('client_medication_id', $log->medication_id)->lockForUpdate()->first();
        if (! $stock || $stock->lots_started_at === null) {
            return;
        }
        $this->requireLots($stock);
        $this->assertTransitBinding($log, $stock);
        if (DB::table('medication_stock_transit_allocations')->where('transit_log_id', $log->id)->exists()) {
            return;
        }
        $quantity = collect($input['pack_lines'] ?? [])->reduce(fn ($sum, $line) => Qty::add($sum, $line['quantity']), '0.00');
        $lines = $this->exactLines($stock, $input['pack_lines'] ?? [], $quantity);
        $entry = null;
        if ($stock->medication->controlled_drug) {
            $this->assertTransitWitness($actor, $witness);
            $this->assertPhysicalBalance($stock, $stock->on_hand);
            $entry = $this->transitEntry($stock, $log, $actor, $witness, 'transfer_out', $quantity, $stock->on_hand, Qty::subtract($stock->on_hand, $quantity), 'house', 'Exact packs taken on the journey.');
        }
        $operation = $input['request_uuid'] ?? (string) Str::uuid();
        foreach ($lines as [$lot, $line]) {
            if ($lot->received_at->gt($log->packed_at) || ! StockLotRules::usable($lot->toArray(), $log->packed_at->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())
                || Qty::greaterThan($line['quantity'], $lot->quantity_remaining)) {
                $this->packError('Only sufficient usable packs can be taken on the journey.');
            }
            $before = $lot->quantity_remaining;
            $after = Qty::subtract($before, $line['quantity']);
            $this->settleLot($lot, $after);
            $movement = $this->linkedMovement($lot, $actor, $operation, 'transit_out', $line['quantity'], $before, $after, 'Taken on the journey.',
                ['transit_log_id' => $log->id, 'controlled_entry_id' => $entry?->id, 'evidence' => $line]);
            DB::table('medication_stock_transit_allocations')->insert([
                'transit_log_id' => $log->id, 'medication_stock_lot_id' => $lot->id, 'outward_movement_id' => $movement->id,
                'quantity_out' => $line['quantity'], 'quantity_used' => '0.00', 'quantity_returned' => '0.00', 'quantity_missing' => '0.00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->stockChangedAfterCommit();
        if ($entry) {
            $this->assertPhysicalBalance($stock, $entry->on_hand_after);
            $stock->forceFill(['on_hand' => $entry->on_hand_after])->saveFromPackLedger();
        } else {
            $this->refreshBalance($stock);
        }
    }

    /** The shared clinical writer calls this instead of a second house deduction. */
    public function transitDose(ClientMedicationAdministration $admin, User $actor, int|float|string $quantity, FleetMedicationTransitLog $log, ?User $witness = null, int|float|string $quantityWasted = '0.00', array $packLines = []): void
    {
        $stock = $this->lockedStock($admin->medication);
        $this->requireLots($stock);
        $this->assertTransitBinding($log, $stock);
        abort_unless((int) $admin->client_id === (int) $log->client_id && $admin->status === 'given' && ! $log->returned_to_house_at, 404);
        if (MedicationStockMovement::where('administration_id', $admin->id)->exists()) {
            return;
        }
        $quantity = Qty::normalize($quantity);
        $wasted = Qty::normalize($quantityWasted);
        if (! Qty::greaterThan($quantity, 0) || Qty::greaterThan(0, $wasted) || ! Qty::greaterThan($quantity, $wasted)) {
            $this->packError('Enter the actual removed transit quantity and witnessed waste.');
        }
        $allocations = $this->transitAllocations($stock, $log)->filter(fn ($a) => $a->closed_at === null);
        $available = $allocations->reduce(fn ($sum, $a) => Qty::add($sum, Qty::subtract($a->quantity_out, $a->quantity_used)), '0.00');
        if (Qty::greaterThan($quantity, $available)) {
            $this->packError('The given amount exceeds what remains in journey custody.');
        }
        $entries = [];
        if ($admin->medication->controlled_drug) {
            $this->assertTransitWitness($actor, $witness);
            $entries['given'] = $this->transitEntry($stock, $log, $actor, $witness, 'administered', Qty::subtract($quantity, $wasted), $available, Qty::subtract($available, Qty::subtract($quantity, $wasted)), 'transit', 'Given from the retained journey packs.', $admin);
            if (Qty::greaterThan($wasted, 0)) {
                $entries['waste'] = $this->transitEntry($stock, $log, $actor, $witness, 'disposal', $wasted, $entries['given']->on_hand_after, Qty::subtract($available, $quantity), 'transit', 'Witnessed waste from the retained journey packs.', $admin);
            }
        }
        $allocations = $allocations->keyBy('medication_stock_lot_id');
        $lines = $this->exactLines($stock, $packLines, $quantity);
        $wasteSum = '0.00';
        foreach ($lines as [$lot, $line]) {
            $allocation = $allocations->get($lot->id);
            abort_unless($allocation, 404);
            $away = Qty::subtract($allocation->quantity_out, $allocation->quantity_used);
            if (! array_key_exists('quantity_wasted', $line) || Qty::greaterThan(0, $line['quantity_wasted'])
                || Qty::greaterThan($line['quantity_wasted'], $line['quantity']) || Qty::greaterThan($line['quantity'], $away)
                || $log->packed_at->gt($admin->administered_at)
                || ! StockLotRules::usable([...$lot->toArray(), 'quantity_remaining' => $away], $admin->administered_at->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())) {
                $this->packError('Retain the actual usable outbound pack and its removed and wasted quantities at the clinical instant.');
            }
            $wasteSum = Qty::add($wasteSum, $line['quantity_wasted']);
        }
        if (! Qty::equals($wasteSum, $wasted)) {
            $this->packError('Actual journey pack waste must equal the clinical stock waste.');
        }
        $operations = ['given' => (string) Str::uuid(), 'waste' => (string) Str::uuid()];
        foreach ($lines as [$lot, $line]) {
            $allocation = $allocations[$lot->id];
            foreach (['given' => Qty::subtract($line['quantity'], $line['quantity_wasted']), 'waste' => $line['quantity_wasted']] as $kind => $used) {
                if (! Qty::greaterThan($used, 0)) {
                    continue;
                }
                $this->linkedMovement($lot, $actor, $operations[$kind], 'transit_'.$kind, $used, $lot->quantity_remaining, $lot->quantity_remaining, 'Used from the selected outward journey pack.',
                    ['administration_id' => $admin->id, 'controlled_entry_id' => ($entries[$kind] ?? null)?->id, 'transit_log_id' => $log->id,
                        'evidence' => [...$line, 'outward_movement_id' => $allocation->outward_movement_id]]);
            }
            DB::table('medication_stock_transit_allocations')->where('id', $allocation->id)->update([
                'quantity_used' => Qty::add($allocation->quantity_used, $line['quantity']), 'updated_at' => now(),
            ]);
        }
        AuditLogger::logOrFail('medications.stock.transit_dose', $admin, ['actor_id' => $actor->id, 'transit_log_id' => $log->id, 'quantity_removed' => $quantity]);
    }

    /** Every outward allocation is closed by returned + recorded used + explicitly unexplained quantity. */
    public function returnTransit(FleetMedicationTransitLog $log, User $actor, array $input, ?User $witness): void
    {
        $stock = ClientMedicationStock::where('client_medication_id', $log->medication_id)->lockForUpdate()->first();
        if (! $stock || $stock->lots_started_at === null) {
            return;
        }
        $this->requireLots($stock);
        $this->assertTransitBinding($log, $stock);
        $allocations = $this->transitAllocations($stock, $log)->keyBy('medication_stock_lot_id');
        $submitted = collect($input['return_lines'] ?? [])->keyBy('lot_id');
        if ($submitted->count() !== count($input['return_lines'] ?? []) || $submitted->keys()->map(fn ($id) => (int) $id)->sort()->values()->all() !== $allocations->keys()->sort()->values()->all()) {
            $this->packError('Reconcile every actual pack taken on the journey.');
        }
        $total = '0.00';
        $missing = '0.00';
        foreach ($allocations as $lotId => $allocation) {
            $line = $submitted[$lotId];
            if ($allocation->closed_at !== null || Qty::greaterThan(0, $line['quantity'] ?? -1) || Qty::greaterThan(0, $line['missing'] ?? 0)
                || ! Qty::equals(Qty::add(Qty::add($line['quantity'], $line['missing'] ?? 0), $allocation->quantity_used), $allocation->quantity_out)
                || (Qty::greaterThan($line['missing'] ?? 0, 0) && blank($input['reconciliation_reason'] ?? null))) {
                $this->packError('Returned, clinically used and explicitly unexplained quantities must equal the exact outward pack.');
            }
            $total = Qty::add($total, $line['quantity']);
            $missing = Qty::add($missing, $line['missing'] ?? '0.00');
        }
        $entry = null;
        if ($stock->medication->controlled_drug) {
            $this->assertTransitWitness($actor, $witness);
            $this->assertPhysicalBalance($stock, $stock->on_hand);
            $entry = $this->transitEntry($stock, $log, $actor, $witness, 'transfer_in', $total, $stock->on_hand, Qty::add($stock->on_hand, $total), 'house', 'Exact packs returned from journey custody.');
        }
        $loss = null;
        $lossEntry = null;
        if ($entry && Qty::greaterThan($missing, 0)) {
            if (blank($input['immediate_action_taken'] ?? null)) {
                $this->packError('Record the immediate action taken for the unexplained controlled journey shortfall.');
            }
            // These units already left house supply at packing. This is custody loss, never a second house subtraction.
            $lossEntry = $this->transitEntry($stock, $log, $actor, $witness, 'loss', $missing, $missing, '0.00', 'transit', $input['reconciliation_reason']);
            $loss = ControlledDrugLossReport::create([
                'client_id' => $log->client_id, 'client_medication_id' => $log->medication_id, 'medication_name' => $stock->medication->name,
                'quantity_lost' => $missing, 'unit' => $stock->unit, 'circumstances' => $input['reconciliation_reason'],
                'immediate_action_taken' => $input['immediate_action_taken'], 'register_entry_id' => $lossEntry->id,
                'discovered_by' => $actor->id, 'discovered_at' => now(), 'investigation_status' => 'reported',
            ]);
            app(MedicationIncidentIntegrationService::class)->handleControlledLossReport($loss, $actor->id);
        }
        $lots = $this->lots($stock)->keyBy('id');
        $operation = $input['request_uuid'] ?? (string) Str::uuid();
        foreach ($allocations as $lotId => $allocation) {
            $line = $submitted[$lotId];
            $lot = $lots[$lotId];
            if (Qty::greaterThan($line['missing'] ?? 0, 0)) {
                $this->linkedMovement($lot, $actor, (string) Str::uuid(), 'transit_loss', $line['missing'], $lot->quantity_remaining, $lot->quantity_remaining, $input['reconciliation_reason'],
                    ['transit_log_id' => $log->id, 'controlled_entry_id' => $lossEntry?->id, 'evidence' => ['outward_movement_id' => $allocation->outward_movement_id]]);
            }
            $before = $lot->quantity_remaining;
            $after = Qty::add($before, $line['quantity']);
            $this->settleLot($lot, $after);
            $this->linkedMovement($lot, $actor, $operation, 'transit_return', $line['quantity'], $before, $after, $input['reconciliation_reason'] ?? 'Exact outward packs returned.',
                ['transit_log_id' => $log->id, 'controlled_entry_id' => $entry?->id, 'return_of_id' => $allocation->outward_movement_id,
                    'evidence' => ['used' => $allocation->quantity_used, 'missing' => $line['missing'] ?? '0.00']]);
            DB::table('medication_stock_transit_allocations')->where('id', $allocation->id)->update([
                'quantity_returned' => $line['quantity'], 'quantity_missing' => $line['missing'] ?? '0.00', 'closed_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->stockChangedAfterCommit();
        if ($entry) {
            $this->assertPhysicalBalance($stock, $entry->on_hand_after);
            $stock->forceFill(['on_hand' => $entry->on_hand_after])->saveFromPackLedger();
        } else {
            $this->refreshBalance($stock);
        }
        $log->forceFill([
            'stock_reconciliation_status' => Qty::greaterThan($missing, 0) ? 'shortfall' : 'complete',
            'stock_reconciliation_reason' => $input['reconciliation_reason'] ?? null, 'stock_loss_report_id' => $loss?->id,
        ])->save();
        AuditLogger::logOrFail('medications.stock.transit_reconciled', $log, ['actor_id' => $actor->id, 'quantity_returned' => $total, 'quantity_missing' => $missing, 'loss_report_id' => $loss?->id]);
    }

    public function transitReconciled(FleetMedicationTransitLog $log): bool
    {
        return ! DB::table('medication_stock_transit_allocations')->where('transit_log_id', $log->id)
            ->where(fn ($q) => $q->where('quantity_missing', '>', 0)->orWhereRaw('quantity_out > quantity_used + quantity_returned + quantity_missing'))->exists();
    }

    /** Caller has already concealed the canonical custody/person/order record. */
    public function transitOptions(FleetMedicationTransitLog $log): array
    {
        $stock = ClientMedicationStock::where('client_medication_id', $log->medication_id)->with('lots')->first();
        if (! $stock || $stock->lots_started_at === null) {
            return ['stock_id' => $stock?->id, 'unit' => $stock?->unit, 'lots_started' => false, 'lots' => [], 'allocations' => []];
        }
        $lots = $stock->lots->keyBy('id');
        $rows = DB::table('medication_stock_transit_allocations')->where('transit_log_id', $log->id)->orderBy('medication_stock_lot_id')->get();

        return ['stock_id' => $stock->id, 'unit' => $stock->unit, 'lots_started' => true,
            'lots' => $lots->map(fn ($lot) => [...$lot->only(['id', 'batch_number', 'batch_not_printed', 'expiry_date', 'expiry_not_printed', 'state', 'quantity_remaining', 'revision']),
                'usable' => StockLotRules::usable([...$lot->toArray(), 'quantity_remaining' => '1.00'], $this->today())])->values()->all(),
            'allocations' => $rows->map(fn ($row) => [
                'lot_id' => $row->medication_stock_lot_id, 'outward_movement_id' => $row->outward_movement_id,
                'quantity_out' => $row->quantity_out, 'quantity_used' => $row->quantity_used,
                'quantity_returned' => $row->quantity_returned, 'quantity_missing' => $row->quantity_missing, 'closed_at' => $row->closed_at,
                'quantity_remaining_away' => Qty::subtract(Qty::subtract(Qty::subtract($row->quantity_out, $row->quantity_used), $row->quantity_returned), $row->quantity_missing),
            ])->values()->all()];
    }

    private function transitAllocations(ClientMedicationStock $stock, FleetMedicationTransitLog $log)
    {
        $this->requireLots($stock);

        return DB::table('medication_stock_transit_allocations')->where('transit_log_id', $log->id)->orderBy('medication_stock_lot_id')->lockForUpdate()->get();
    }

    private function assertTransitBinding(FleetMedicationTransitLog $log, ClientMedicationStock $stock): void
    {
        abort_unless((int) $log->medication_id === (int) $stock->client_medication_id && (int) $log->client_id === (int) $stock->medication->client_id
            && (int) $log->site_id === (int) $stock->medication->client->site_id, 404);
    }

    private function assertTransitWitness(User $actor, ?User $witness): void
    {
        abort_unless($actor->canDo('medications.controlled.record'), 403);
        if (! $witness || (int) $actor->id === (int) $witness->id) {
            $this->packError('Controlled journey stock needs its canonical authenticated second checker.');
        }
    }

    private function transitEntry(ClientMedicationStock $stock, FleetMedicationTransitLog $log, User $actor, User $witness, string $type, string $quantity, string $before, string $after, string $scope, string $reason, ?ClientMedicationAdministration $admin = null): ClientControlledDrugEntry
    {
        $entry = ClientControlledDrugEntry::create([
            'client_id' => $log->client_id, 'client_medication_id' => $log->medication_id, 'client_medication_administration_id' => $admin?->id,
            'shift_id' => $log->shift_id, 'service_context_id' => $stock->medication->client->service_context_id,
            'entry_type' => $type, 'quantity' => $quantity, 'unit' => $stock->unit, 'on_hand_before' => $before, 'on_hand_after' => $after,
            'recorded_by' => $actor->id, 'witnessed_by' => $witness->id, 'recorded_at' => $admin?->administered_at ?? now(),
            'reason' => $reason, 'stock_balance_scope' => $scope, 'transit_log_id' => $log->id, 'source_type' => 'journey_custody', 'source_id' => $log->id,
        ]);
        AuditLogger::logOrFail('medications.controlled.entry.record', $entry, ['actor_id' => $actor->id, 'transit_log_id' => $log->id, 'stock_balance_scope' => $scope]);

        return $entry;
    }
}
