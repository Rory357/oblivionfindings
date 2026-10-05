<?php

namespace App\Services\Medication\Downtime;

use App\Models\ClientMedication;
use App\Models\MedicationStockLot;
use App\Services\MarScheduleService;
use App\Services\Medication\Recording\RecordingContract;
use App\Services\Medication\Recording\RecordingContractEnforcer;
use App\Support\Medication\MedicationStockQuantity as Qty;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Actual signed facts are separate from the later physical settlement decision. */
final class PaperDoseFacts
{
    public static function missing(array $clinical, array $physical): array
    {
        $labels = [
            'clinical_facts.quantity_given' => 'Actual clinical amount given', 'clinical_facts.amount_mode' => 'How the actual amount compares with the order',
            'stock_evidence.stock_id' => 'The medicine stock record', 'stock_evidence.unit' => 'The counted stock unit',
            'stock_evidence.quantity_removed' => 'Actual amount removed from stock', 'stock_evidence.quantity_wasted' => 'Actual amount wasted, including zero',
        ];
        $missing = [];
        foreach ($labels as $path => $label) {
            if (! filled(data_get(['clinical_facts' => $clinical, 'stock_evidence' => $physical], $path))) {
                $missing[$path] = $label;
            }
        }
        $lines = $physical['lines'] ?? [];
        if ($lines === []) {
            $missing['stock_evidence.lines'] = 'Each exact pack used';
        }
        foreach ($lines as $i => $line) {
            foreach (['lot_id' => 'Exact pack identity', 'quantity' => 'Actual amount removed from this pack', 'quantity_wasted' => 'Actual pack waste, including zero'] as $field => $label) {
                if (! filled($line[$field] ?? null)) {
                    $missing['stock_evidence.lines.'.$i.'.'.$field] = $label;
                }
            }
        }

        return $missing;
    }

    public static function complete(array $clinical, array $physical): bool
    {
        return self::missing($clinical, $physical) === [];
    }

    /** Collect unknown facts as unknown; an incomplete signed entry is not a dose posting. */
    public static function retain(ClientMedication $order, array $clinical, array $physical): array
    {
        abort_unless(array_diff(array_keys($clinical), ['quantity_given', 'amount_mode', 'amount_reason', 'late_reason', 'prn_reason', 'effect_check_due_at', 'more_severity', 'more_immediate_action']) === []
            && array_diff(array_keys($physical), ['stock_id', 'unit', 'quantity_removed', 'quantity_wasted', 'waste_reason', 'lines']) === [], 422);
        $rules = collect(RecordingContract::rules())->only(array_keys($clinical))
            ->mapWithKeys(fn ($rules, $key) => ['clinical_facts.'.$key => $rules])->all();
        Validator::make(['clinical_facts' => $clinical], $rules)->validate();
        if (filled($clinical['effect_check_due_at'] ?? null)) {
            try {
                $clinical['effect_check_due_at'] = app(MarScheduleService::class)->parseWorkerDateTime((string) $clinical['effect_check_due_at'])->utc()->toIso8601String();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['clinical_facts.effect_check_due_at' => 'Retain a valid New Zealand effect-check time from the paper.']);
            }
        }
        $stock = $order->stock()->first();
        if (filled($physical['stock_id'] ?? null)) {
            abort_unless($stock && (int) $physical['stock_id'] === (int) $stock->id, 404);
        }
        if (filled($physical['unit'] ?? null)) {
            abort_unless($stock && $physical['unit'] === $stock->unit, 422);
        }
        foreach ($physical['lines'] ?? [] as $line) {
            abort_unless(array_diff(array_keys($line), ['lot_id', 'quantity', 'quantity_wasted']) === [], 422);
            if (filled($line['lot_id'] ?? null)) {
                abort_unless($stock && MedicationStockLot::where('client_medication_stock_id', $stock->id)->whereKey($line['lot_id'])->exists(), 404);
            }
        }

        return ['clinical' => $clinical, 'physical' => $physical];
    }

    public static function normalize(ClientMedication $order, array $clinical, array $physical, CarbonInterface $at): array
    {
        if (! self::complete($clinical, $physical)) {
            throw ValidationException::withMessages(['stock_evidence' => 'Complete actual facts are required for normalization. Unknown waste is never assumed to be zero.']);
        }
        $retained = self::retain($order, $clinical, $physical);
        $clinical = $retained['clinical'];
        $physical = $retained['physical'];
        abort_unless(array_diff(array_keys($clinical), ['quantity_given', 'amount_mode', 'amount_reason', 'late_reason', 'prn_reason', 'effect_check_due_at', 'more_severity', 'more_immediate_action']) === []
            && array_diff(array_keys($physical), ['stock_id', 'unit', 'quantity_removed', 'quantity_wasted', 'waste_reason', 'lines']) === [], 422);
        $rules = collect(RecordingContract::rules())->only(array_keys($clinical))
            ->mapWithKeys(fn ($rules, $key) => ['clinical_facts.'.$key => $rules])->all();
        Validator::make(['clinical_facts' => $clinical], $rules)->validate();
        if (blank($order->dose_unit)) {
            throw ValidationException::withMessages(['clinical_facts.quantity_given' => 'The order has no structured clinical unit. Keep the signed paper for clinical review; do not assume its unit from stock.']);
        }
        try {
            $clinical['quantity_given'] = Qty::normalizeMovement($clinical['quantity_given'] ?? 0);
            $removed = Qty::normalizeMovement($physical['quantity_removed'] ?? 0);
            $wasted = Qty::normalizeMovement($physical['quantity_wasted'] ?? 0);
            if (! Qty::greaterThan($clinical['quantity_given'], 0) || ! Qty::greaterThan($removed, $wasted)
                || Qty::greaterThan(0, $wasted) || ! in_array($clinical['amount_mode'] ?? null, RecordingContract::AMOUNT_MODES, true)) {
                throw new \InvalidArgumentException;
            }
            $stock = $order->stock()->first();
            abort_unless($stock && (int) ($physical['stock_id'] ?? 0) === (int) $stock->id, 404);
            if ($stock->lots_started_at === null || blank($stock->unit) || ($physical['unit'] ?? null) !== $stock->unit
                || (Qty::greaterThan($wasted, 0) && blank($physical['waste_reason'] ?? null))) {
                throw new \InvalidArgumentException;
            }
            if (($clinical['amount_mode'] ?? null) === RecordingContract::AMOUNT_AS_ORDERED
                && ($order->dose_amount === null || ! app(RecordingContractEnforcer::class)->matchesOrderedAmount($clinical['quantity_given'], $order))) {
                throw ValidationException::withMessages(['clinical_facts.quantity_given' => 'The actual amount must match the structured order for As ordered. Choose Less or More for a different amount; an unstructured order needs clinical review.']);
            }
            if (app(RecordingContractEnforcer::class)->sameStockUnit($order->dose_unit, $stock->unit)
                && ! Qty::equals($clinical['quantity_given'], Qty::subtract($removed, $wasted))) {
                throw ValidationException::withMessages(['stock_evidence.quantity_removed' => 'The physical amount given must equal the signed clinical amount in the same unit.']);
            }
            $lines = $physical['lines'] ?? [];
            if ($lines === [] || count(array_unique(array_column($lines, 'lot_id'))) !== count($lines)) {
                throw new \InvalidArgumentException;
            }
            $sum = $wasteSum = '0.00';
            foreach ($lines as &$line) {
                abort_unless(MedicationStockLot::query()->where('client_medication_stock_id', $stock->id)->whereKey($line['lot_id'] ?? 0)->exists(), 404);
                $quantity = Qty::normalizeMovement($line['quantity'] ?? 0);
                $waste = Qty::normalizeMovement($line['quantity_wasted'] ?? 0);
                if (! Qty::greaterThan($quantity, 0) || Qty::greaterThan(0, $waste) || Qty::greaterThan($waste, $quantity)) {
                    throw new \InvalidArgumentException;
                }
                $line = ['lot_id' => (int) $line['lot_id'], 'quantity' => $quantity, 'quantity_wasted' => $waste];
                $sum = Qty::add($sum, $quantity);
                $wasteSum = Qty::add($wasteSum, $waste);
            }
            unset($line);
            if (! Qty::equals($sum, $removed) || ! Qty::equals($wasteSum, $wasted)) {
                throw new \InvalidArgumentException;
            }
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['stock_evidence' => 'Retain positive actual quantities, the counted stock unit and each exact pack removal/waste. Pack totals must match; no dose or stock amount is assumed.']);
        }

        return ['clinical' => $clinical, 'physical' => ['stock_id' => (int) $stock->id, 'unit' => $stock->unit,
            'quantity_removed' => $removed, 'quantity_wasted' => $wasted, 'waste_reason' => $physical['waste_reason'] ?? null, 'lines' => $lines]];
    }
}
