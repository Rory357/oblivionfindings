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
use App\Services\Sites\HouseLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SiteMealShoppingListController extends Controller
{
    use RespondsToInertiaOrJson;

    public function __construct(
        private ShoppingListGenerator $generator,
        private InventoryMovementRecorder $recorder,
        private DietaryConflictChecker $conflictChecker,
        private HouseLedgerService $houseLedger,
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
            if (empty($clientIds)) {
                continue;
            }
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
            'unresolved_count' => count(array_filter($affected, fn ($a) => ! $a['has_override'])),
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
            'status' => 'nullable|in:'.implode(',', SiteMealShoppingList::STATUSES),
            'provider_key' => 'nullable|string|max:64',
            'provider_order_ref' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $payload = array_filter($data, fn ($v) => $v !== null);
        if (($data['status'] ?? null) === 'ordered' && ! $list->ordered_at) {
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
        $receivedCents = 0;
        foreach ($data['items'] ?? [] as $row) {
            $item = $items->get((int) $row['id']);
            if (! $item || $item->list_id !== $list->id) {
                continue;
            }
            $qty = (float) $row['received_qty'];
            $item->update(['received_qty' => $row['received_qty'], 'is_checked' => true]);

            if ($item->product_id && $qty > 0) {
                $this->recorder->record(
                    site: $site,
                    productId: $item->product_id,
                    delta: $qty,
                    unit: $item->unit,
                    reason: 'delivery',
                    referenceType: SiteMealShoppingList::class,
                    referenceId: $list->id,
                    note: "Received from shopping list #{$list->id}",
                );
            }

            // Actual grocery cost = product unit cost × received qty, falling back
            // to the line's estimate when the product has no unit cost.
            if ($qty > 0) {
                $receivedCents += $item->product?->cost_per_unit_cents
                    ? (int) round((float) $item->product->cost_per_unit_cents * $qty)
                    : (int) ($item->estimated_cost_cents ?? 0);
            }
        }

        $list->update(['status' => 'received', 'received_at' => now()]);

        $this->captureGrocerySpend($site, $list, $receivedCents);

        return $this->inertiaOrJson($request, 'Shopping list received and inventory updated');
    }

    /**
     * Capture-at-source: post the received grocery spend to the site's house
     * ledger exactly once. The HouseLedgerEntryObserver bridges the entry into
     * the GL (DR 6431 House Groceries / CR 1000 Bank). A stable reference makes
     * this idempotent — repeated markReceived submits never double-post. Failure
     * is logged, never allowed to break the operational receipt.
     */
    private function captureGrocerySpend(Site $site, SiteMealShoppingList $list, int $receivedCents): void
    {
        if ($receivedCents <= 0) {
            return;
        }

        $reference = "shopping-list:{$list->id}";
        try {
            $ledger = $this->houseLedger->getOrCreateLedger($site);
            if ($ledger->entries()->where('reference', $reference)->exists()) {
                return;
            }

            $this->houseLedger->addEntry($site, [
                'entry_type' => 'expense',
                'category' => 'groceries',
                'description' => "Groceries received — shopping list #{$list->id}",
                'reference' => $reference,
                'amount' => $receivedCents / 100,
                'entry_date' => now()->toDateString(),
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ], (int) auth()->id());
        } catch (\Throwable $e) {
            Log::error("Grocery house-ledger capture failed for shopping list #{$list->id}: {$e->getMessage()}");
        }
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
