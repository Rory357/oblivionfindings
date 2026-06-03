<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealShoppingList;
use App\Models\SiteMealShoppingListItem;
use App\Services\Catering\DietaryConflictChecker;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\ShoppingListGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SiteMealShoppingListController extends Controller
{
    use RespondsToInertiaOrJson;

    public function __construct(
        private ShoppingListGenerator $generator,
        private InventoryMovementRecorder $recorder,
        private DietaryConflictChecker $conflictChecker,
    ) {}

    public function index(Site $site)
    {
        $lists = SiteMealShoppingList::query()
            ->where('site_id', $site->id)
            ->with(['items.product:id,name,default_unit,category,currency', 'generatedBy:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $conflictsByList = [];
        foreach ($lists as $list) {
            $conflictsByList[$list->id] = $this->summariseConflicts($site, $list);
        }

        return response()->json([
            'site_id' => $site->id,
            'lists' => $lists,
            'current_draft_id' => optional($lists->firstWhere('status', 'draft'))->id,
            'conflicts_by_list' => $conflictsByList,
        ]);
    }

    /**
     * For a given list's covered window, find every planned meal whose
     * recipe + assigned residents would trigger a hard allergen block.
     * Used to show the yellow banner at the top of the shopping panel.
     */
    private function summariseConflicts(Site $site, SiteMealShoppingList $list): array
    {
        $entries = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$list->covers_from->toDateString(), $list->covers_to->toDateString()])
            ->whereNotNull('recipe_id')
            ->with('recipe.tags', 'recipe.ingredients.product.tags')
            ->get();

        $affected = [];
        foreach ($entries as $entry) {
            $clientIds = (array) ($entry->client_ids ?? []);
            if (empty($clientIds)) continue;
            $report = $this->conflictChecker->checkRecipeAgainstClients($entry->recipe, $clientIds);
            if ($report['has_hard_blocks']) {
                foreach ($report['hard_blocks'] as $block) {
                    $affected[] = [
                        'plan_entry_id' => $entry->id,
                        'plan_date' => $entry->plan_date->toDateString(),
                        'meal_slot' => $entry->meal_slot,
                        'recipe_name' => $entry->recipe->name,
                        'client_name' => $block['client_name'],
                        'matches' => array_map(fn ($m) => $m['label'], $block['matches']),
                        'has_override' => $entry->allergen_override_at !== null,
                    ];
                }
            }
        }

        return [
            'count' => count($affected),
            'unresolved_count' => count(array_filter($affected, fn ($a) => !$a['has_override'])),
            'details' => $affected,
        ];
    }

    public function generate(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);

        $data = $request->validate([
            'covers_from' => 'required|date',
            'covers_to' => 'required|date|after_or_equal:covers_from',
            'include_restock_to_par' => 'sometimes|boolean',
        ]);

        $list = $this->generator->generate(
            site: $site,
            from: CarbonImmutable::parse($data['covers_from']),
            to: CarbonImmutable::parse($data['covers_to']),
            includeRestockToPar: $data['include_restock_to_par'] ?? true,
        );

        return $this->inertiaOrJson($request, 'Shopping list generated', ['list_id' => $list->id]);
    }

    public function update(Request $request, Site $site, SiteMealShoppingList $list)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        abort_unless($list->site_id === $site->id, 404);

        $data = $request->validate([
            'status' => 'nullable|in:' . implode(',', SiteMealShoppingList::STATUSES),
            'provider_key' => 'nullable|string|max:64',
            'provider_order_ref' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $payload = array_filter($data, fn ($v) => $v !== null);
        if (($data['status'] ?? null) === 'ordered' && !$list->ordered_at) {
            $payload['ordered_at'] = now();
        }
        $list->update($payload);

        return $this->inertiaOrJson($request, 'Shopping list updated');
    }

    public function addItem(Request $request, Site $site, SiteMealShoppingList $list)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        abort_unless($list->site_id === $site->id, 404);
        abort_if($list->isLocked(), 409, 'List is locked');

        $data = $request->validate([
            'product_id' => 'nullable|integer|exists:meal_products,id',
            'free_text_name' => 'nullable|string|max:255',
            'needed_qty' => 'required|numeric|min:0',
            'unit' => 'required|string|max:24',
            'notes' => 'nullable|string|max:500',
        ]);

        SiteMealShoppingListItem::create([
            'list_id' => $list->id,
            'product_id' => $data['product_id'] ?? null,
            'free_text_name' => $data['free_text_name'] ?? null,
            'needed_qty' => $data['needed_qty'],
            'unit' => $data['unit'],
            'source' => 'manual',
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->inertiaOrJson($request, 'Item added');
    }

    public function removeItem(Request $request, Site $site, SiteMealShoppingList $list, SiteMealShoppingListItem $item)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        abort_unless($list->site_id === $site->id, 404);
        abort_unless($item->list_id === $list->id, 404);
        abort_if($list->isLocked(), 409, 'List is locked');

        $item->delete();

        return $this->inertiaOrJson($request, 'Item removed');
    }

    public function markReceived(Request $request, Site $site, SiteMealShoppingList $list)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        abort_unless($list->site_id === $site->id, 404);

        $data = $request->validate([
            'items' => 'nullable|array',
            'items.*.id' => 'required|integer|exists:site_meal_shopping_list_items,id',
            'items.*.received_qty' => 'required|numeric|min:0',
        ]);

        $items = $list->items()->with('product')->get()->keyBy('id');
        foreach ($data['items'] ?? [] as $row) {
            $item = $items->get((int) $row['id']);
            if (!$item || $item->list_id !== $list->id) {
                continue;
            }
            $item->update(['received_qty' => $row['received_qty'], 'is_checked' => true]);

            if ($item->product_id && (float) $row['received_qty'] > 0) {
                $this->recorder->record(
                    site: $site,
                    productId: $item->product_id,
                    delta: (float) $row['received_qty'],
                    unit: $item->unit,
                    reason: 'delivery',
                    referenceType: SiteMealShoppingList::class,
                    referenceId: $list->id,
                    note: "Received from shopping list #{$list->id}",
                );
            }
        }

        $list->update(['status' => 'received', 'received_at' => now()]);

        return $this->inertiaOrJson($request, 'Shopping list received and inventory updated');
    }

    public function destroy(Request $request, Site $site, SiteMealShoppingList $list)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        abort_unless($list->site_id === $site->id, 404);
        abort_if($list->isLocked(), 409, 'Locked lists cannot be deleted');

        $list->delete();

        return $this->inertiaOrJson($request, 'Shopping list deleted');
    }
}
