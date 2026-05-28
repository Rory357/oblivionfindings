<?php

namespace App\Services\Catering;

use App\Models\MealProduct;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealShoppingList;
use App\Models\SiteMealShoppingListItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShoppingListGenerator
{
    public function __construct(private UnitConverter $units) {}

    /**
     * Build (or refresh) a draft shopping list for a site/date window.
     * Manual items (source=manual) on an existing draft are preserved.
     * Locked lists (ordered/received) are never mutated — a new draft
     * is created instead.
     */
    public function generate(
        Site $site,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $includeRestockToPar = true,
        ?int $userId = null,
    ): SiteMealShoppingList {
        return DB::transaction(function () use ($site, $from, $to, $includeRestockToPar, $userId) {
            $list = SiteMealShoppingList::query()
                ->where('site_id', $site->id)
                ->where('status', 'draft')
                ->first();

            if (!$list) {
                $list = SiteMealShoppingList::create([
                    'tenant_id' => $site->tenant_id ?? auth()->user()?->organization_id,
                    'site_id' => $site->id,
                    'status' => 'draft',
                    'covers_from' => $from->toDateString(),
                    'covers_to' => $to->toDateString(),
                    'generated_at' => now(),
                    'generated_by' => $userId ?? auth()->id(),
                    'provider_key' => 'manual',
                ]);
            } else {
                $list->update([
                    'covers_from' => $from->toDateString(),
                    'covers_to' => $to->toDateString(),
                    'generated_at' => now(),
                    'generated_by' => $userId ?? auth()->id(),
                ]);
            }

            // Preserve manual items, wipe machine-generated ones.
            $list->items()->whereIn('source', ['meal_plan', 'restock_to_par'])->delete();

            $aggregates = $this->aggregateFromPlan($site, $from, $to);

            // Subtract on-hand inventory.
            $aggregates = $this->subtractInventory($site, $aggregates);

            if ($includeRestockToPar) {
                $this->addRestockToPar($site, $aggregates);
            }

            foreach ($aggregates as $key => $row) {
                if (($row['qty'] ?? 0) <= 0) {
                    continue;
                }
                SiteMealShoppingListItem::create([
                    'list_id' => $list->id,
                    'product_id' => $row['product_id'] ?? null,
                    'free_text_name' => $row['name'],
                    'needed_qty' => $row['qty'],
                    'unit' => $row['unit'],
                    'source' => $row['source'],
                    'source_meta' => $row['meta'] ?? null,
                    'estimated_cost_cents' => $row['cost_cents'] ?? null,
                ]);
            }

            return $list->fresh('items');
        });
    }

    /** @return array<string, array{product_id:?int, name:string, qty:float, unit:string, source:string, meta?:array, cost_cents?:int}> */
    private function aggregateFromPlan(Site $site, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $entries = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$from->toDateString(), $to->toDateString()])
            ->with('recipe.ingredients.product')
            ->get();

        $bucket = [];
        foreach ($entries as $entry) {
            if (!$entry->recipe) {
                continue;
            }
            $scale = max(1, $entry->servings) / max(1, $entry->recipe->serves_default);
            foreach ($entry->recipe->ingredients as $ing) {
                $product = $ing->product;
                $name = $product?->name ?? ($ing->free_text_name ?? 'Ingredient');
                $key = $product
                    ? "p:{$product->id}:{$ing->unit}"
                    : "t:" . strtolower($name) . ":{$ing->unit}";

                $qty = (float) $ing->quantity * $scale;

                if (!isset($bucket[$key])) {
                    $bucket[$key] = [
                        'product_id' => $product?->id,
                        'name' => $name,
                        'qty' => 0,
                        'unit' => $ing->unit,
                        'source' => 'meal_plan',
                        'meta' => ['plan_entry_ids' => []],
                    ];
                }
                $bucket[$key]['qty'] += $qty;
                $bucket[$key]['meta']['plan_entry_ids'][] = $entry->id;

                if ($product?->cost_per_unit_cents !== null) {
                    $inProductUnit = $this->units->convert(
                        $qty,
                        $ing->unit,
                        $product->default_unit,
                        $product->pack_size !== null ? (float) $product->pack_size : null,
                        $product->pack_unit,
                    );
                    if ($inProductUnit !== null) {
                        $bucket[$key]['cost_cents'] = ($bucket[$key]['cost_cents'] ?? 0) + (int) round($inProductUnit * $product->cost_per_unit_cents);
                    }
                }
            }
        }

        return $bucket;
    }

    private function subtractInventory(Site $site, array $aggregates): array
    {
        $productIds = collect($aggregates)->pluck('product_id')->filter()->unique()->all();
        if (empty($productIds)) {
            return $aggregates;
        }

        $items = SiteMealInventoryItem::query()
            ->where('site_id', $site->id)
            ->whereIn('product_id', $productIds)
            ->with('product:id,default_unit,pack_size,pack_unit')
            ->get()
            ->keyBy('product_id');

        foreach ($aggregates as $key => &$row) {
            if (!$row['product_id'] || !isset($items[$row['product_id']])) {
                continue;
            }
            $item = $items[$row['product_id']];
            $onHandInRowUnit = $this->units->convert(
                (float) $item->current_qty,
                $item->unit,
                $row['unit'],
                $item->product?->pack_size !== null ? (float) $item->product->pack_size : null,
                $item->product?->pack_unit,
            );
            if ($onHandInRowUnit === null) {
                continue;
            }
            $row['qty'] = max(0, $row['qty'] - $onHandInRowUnit);
        }
        return $aggregates;
    }

    private function addRestockToPar(Site $site, array &$aggregates): void
    {
        $items = SiteMealInventoryItem::query()
            ->where('site_id', $site->id)
            ->whereNotNull('par_level')
            ->with('product:id,name,default_unit,cost_per_unit_cents,currency')
            ->get();

        foreach ($items as $item) {
            $shortage = (float) $item->par_level - (float) $item->current_qty;
            if ($shortage <= 0) {
                continue;
            }

            $key = "p:{$item->product_id}:{$item->unit}";
            if (isset($aggregates[$key])) {
                $aggregates[$key]['qty'] += $shortage;
                $aggregates[$key]['source'] = 'restock_to_par';
                continue;
            }

            $aggregates[$key] = [
                'product_id' => $item->product_id,
                'name' => $item->product?->name ?? 'Item',
                'qty' => $shortage,
                'unit' => $item->unit,
                'source' => 'restock_to_par',
                'meta' => ['par_level' => (float) $item->par_level, 'current_qty' => (float) $item->current_qty],
                'cost_cents' => $item->product?->cost_per_unit_cents !== null
                    ? (int) round($shortage * $item->product->cost_per_unit_cents)
                    : null,
            ];
        }
    }
}
