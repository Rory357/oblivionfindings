<?php

namespace App\Services\Catering;

use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use Illuminate\Support\Facades\DB;

class InventoryMovementRecorder
{
    public function __construct(private UnitConverter $units) {}

    /**
     * Single write-path for *all* inventory changes. Appends a movement
     * row + updates the materialised current_qty inside one transaction.
     *
     * $delta is signed and in $unit. Positive = added to stock,
     * negative = removed from stock.
     *
     * Returns the persisted movement.
     */
    public function record(
        Site $site,
        int $productId,
        float $delta,
        string $unit,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $performedBy = null,
        ?string $note = null,
    ): SiteMealInventoryMovement {
        return DB::transaction(function () use ($site, $productId, $delta, $unit, $reason, $referenceType, $referenceId, $performedBy, $note) {
            $item = SiteMealInventoryItem::firstOrCreate(
                ['site_id' => $site->id, 'product_id' => $productId],
                [
                    'tenant_id' => $site->tenant_id ?? auth()->user()?->organization_id,
                    'unit' => $unit,
                    'current_qty' => 0,
                ]
            );

            $deltaInItemUnit = $this->units->convert($delta, $unit, $item->unit);
            if ($deltaInItemUnit === null) {
                // Fall back to recording in the supplied unit and warning via note.
                $deltaInItemUnit = $delta;
                $note = trim(($note ? $note . ' ' : '') . "[unit conversion failed: {$unit} → {$item->unit}]");
            }

            $movement = SiteMealInventoryMovement::create([
                'tenant_id' => $item->tenant_id,
                'site_id' => $site->id,
                'product_id' => $productId,
                'delta' => $deltaInItemUnit,
                'unit' => $item->unit,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'performed_by' => $performedBy ?? auth()->id(),
                'performed_at' => now(),
            ]);

            $item->current_qty = (float) $item->current_qty + $deltaInItemUnit;
            if ($reason === 'stocktake') {
                $item->last_counted_at = now();
            }
            $item->save();

            return $movement;
        });
    }

    /**
     * Sets the absolute on-hand quantity (stocktake mode). Writes a
     * single movement representing the delta.
     */
    public function stocktake(
        Site $site,
        int $productId,
        float $newQty,
        string $unit,
        ?int $performedBy = null,
        ?string $note = null,
    ): SiteMealInventoryMovement {
        return DB::transaction(function () use ($site, $productId, $newQty, $unit, $performedBy, $note) {
            $item = SiteMealInventoryItem::firstOrCreate(
                ['site_id' => $site->id, 'product_id' => $productId],
                [
                    'tenant_id' => $site->tenant_id ?? auth()->user()?->organization_id,
                    'unit' => $unit,
                    'current_qty' => 0,
                ]
            );

            $targetInItemUnit = $this->units->convert($newQty, $unit, $item->unit);
            if ($targetInItemUnit === null) {
                $targetInItemUnit = $newQty;
            }
            $delta = $targetInItemUnit - (float) $item->current_qty;

            return $this->record(
                site: $site,
                productId: $productId,
                delta: $delta,
                unit: $item->unit,
                reason: 'stocktake',
                performedBy: $performedBy,
                note: $note,
            );
        });
    }
}
