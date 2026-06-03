<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Services\Catering\InventoryMovementRecorder;
use Illuminate\Http\Request;

class SiteMealInventoryController extends Controller
{
    use RespondsToInertiaOrJson;

    public function __construct(private InventoryMovementRecorder $recorder) {}

    public function index(Site $site)
    {
        $items = SiteMealInventoryItem::query()
            ->where('site_id', $site->id)
            ->with('product:id,name,category,default_unit,pack_size,pack_unit,cost_per_unit_cents,currency')
            ->orderBy('id', 'asc')
            ->get();

        $low = $items->filter(fn ($i) => $i->isLowStock())->count();

        return response()->json([
            'site_id' => $site->id,
            'items' => $items,
            'low_stock_count' => $low,
            'reasons' => SiteMealInventoryMovement::REASONS,
        ]);
    }

    public function storeItem(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.inventory.adjust'), 403);

        $data = $request->validate([
            'product_id' => 'required|integer|exists:meal_products,id',
            'unit' => 'required|string|in:each,kg,g,mg,L,l,ml,cl,pack,tin,bottle,box,tsp,tbsp,cup',
            'current_qty' => 'nullable|numeric|min:0',
            'par_level' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'location_label' => 'nullable|string|max:255',
        ]);

        $item = SiteMealInventoryItem::firstOrCreate(
            ['site_id' => $site->id, 'product_id' => $data['product_id']],
            [
                'tenant_id' => $site->tenant_id ?? auth()->user()?->tenant_id,
                'unit' => $data['unit'],
                'current_qty' => 0,
            ]
        );
        $item->update([
            'unit' => $data['unit'],
            'par_level' => $data['par_level'] ?? null,
            'reorder_level' => $data['reorder_level'] ?? null,
            'location_label' => $data['location_label'] ?? null,
        ]);

        if (isset($data['current_qty']) && (float) $data['current_qty'] !== (float) $item->current_qty) {
            $this->recorder->stocktake(
                site: $site,
                productId: $item->product_id,
                newQty: (float) $data['current_qty'],
                unit: $item->unit,
                note: 'Initial setup',
            );
        }

        return $this->inertiaOrJson($request, 'Inventory item saved');
    }

    public function updateItem(Request $request, Site $site, SiteMealInventoryItem $item)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.inventory.adjust'), 403);
        abort_unless($item->site_id === $site->id, 404);

        $data = $request->validate([
            'unit' => 'nullable|string|in:each,kg,g,mg,L,l,ml,cl,pack,tin,bottle,box,tsp,tbsp,cup',
            'par_level' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'location_label' => 'nullable|string|max:255',
        ]);

        $item->update(array_filter($data, fn ($v) => $v !== null));

        return $this->inertiaOrJson($request, 'Inventory item updated');
    }

    public function destroyItem(Request $request, Site $site, SiteMealInventoryItem $item)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.inventory.adjust'), 403);
        abort_unless($item->site_id === $site->id, 404);
        $item->delete();
        return $this->inertiaOrJson($request, 'Inventory item removed');
    }

    public function adjust(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.inventory.adjust'), 403);

        $data = $request->validate([
            'product_id' => 'required|integer|exists:meal_products,id',
            'delta' => 'required|numeric',
            'unit' => 'required|string|in:each,kg,g,mg,L,l,ml,cl,pack,tin,bottle,box,tsp,tbsp,cup',
            'reason' => 'required|in:' . implode(',', SiteMealInventoryMovement::REASONS),
            'note' => 'nullable|string|max:500',
        ]);

        $this->recorder->record(
            site: $site,
            productId: $data['product_id'],
            delta: (float) $data['delta'],
            unit: $data['unit'],
            reason: $data['reason'],
            note: $data['note'] ?? null,
        );

        return $this->inertiaOrJson($request, 'Adjustment recorded');
    }

    public function stocktake(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.inventory.adjust'), 403);

        $data = $request->validate([
            'counts' => 'required|array',
            'counts.*.product_id' => 'required|integer|exists:meal_products,id',
            'counts.*.qty' => 'required|numeric|min:0',
            'counts.*.unit' => 'required|string|in:each,kg,g,mg,L,l,ml,cl,pack,tin,bottle,box,tsp,tbsp,cup',
            'note' => 'nullable|string|max:500',
        ]);

        foreach ($data['counts'] as $row) {
            $this->recorder->stocktake(
                site: $site,
                productId: (int) $row['product_id'],
                newQty: (float) $row['qty'],
                unit: $row['unit'],
                note: $data['note'] ?? null,
            );
        }

        return $this->inertiaOrJson($request, 'Stocktake recorded for ' . count($data['counts']) . ' items');
    }

    public function movements(Request $request, Site $site)
    {
        $query = SiteMealInventoryMovement::query()
            ->where('site_id', $site->id)
            ->with(['product:id,name', 'performedBy:id,name'])
            ->orderByDesc('performed_at');

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }
        if ($reason = $request->string('reason')->toString()) {
            $query->where('reason', $reason);
        }

        return response()->json([
            'site_id' => $site->id,
            'movements' => $query->limit(200)->get(),
        ]);
    }
}
