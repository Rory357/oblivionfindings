<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealShoppingList;
use App\Services\Catering\MealCostCalculator;
use Carbon\CarbonImmutable;

class DashboardController extends Controller
{
    public function __construct(private MealCostCalculator $costs) {}

    /**
     * Cheap JSON endpoint used by the shared CateringTabs nav so the
     * count badges (Recipes 15 · Products 41 · Tags 25) appear on every
     * catering page, not just the Overview dashboard.
     */
    public function libraryCounts()
    {
        return response()->json([
            'recipes' => MealRecipe::active()->count(),
            'products' => MealProduct::active()->count(),
            'tags' => MealDietaryTag::count(),
        ]);
    }

    /**
     * The dedicated interactive Meal Planner page (green hero + site
     * switcher). Defaults to the requested ?site=, else the first house,
     * else the first active site. The React app fetches the rest per-site.
     */
    public function mealPlanner(\Illuminate\Http\Request $request)
    {
        $requested = $request->integer('site');
        $default = $requested
            ? Site::query()->where('is_active', true)->whereKey($requested)->value('id')
            : null;
        $default ??= Site::query()->where('is_active', true)->where('type', 'house')->orderBy('name')->value('id')
            ?? Site::query()->where('is_active', true)->orderBy('name')->value('id');

        return inertia('catering/meal-planner', [
            'default_site_id' => $default,
        ]);
    }

    public function index()
    {
        $weekStart = CarbonImmutable::now()->startOfWeek();
        $weekEnd = $weekStart->addDays(6);

        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $entries = SiteMealPlanEntry::query()
            ->whereBetween('plan_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->with('recipe.ingredients.product')
            ->get()
            ->groupBy('site_id');

        $inventoryBySite = SiteMealInventoryItem::query()
            ->with('product:id,name,default_unit,cost_per_unit_cents,currency')
            ->get()
            ->groupBy('site_id');

        $listsBySite = SiteMealShoppingList::query()
            ->whereIn('status', ['draft', 'ordered'])
            ->get()
            ->groupBy('site_id');

        $siteCards = $sites->map(function (Site $site) use ($entries, $inventoryBySite, $listsBySite) {
            $siteEntries = $entries->get($site->id, collect());
            $items = $inventoryBySite->get($site->id, collect());
            $lists = $listsBySite->get($site->id, collect());

            $weekCostCents = 0;
            foreach ($siteEntries as $entry) {
                // Takeaway: actual cost paid wins over any recipe estimate.
                if ($entry->isTakeaway() && $entry->takeaway_cost_cents !== null) {
                    $weekCostCents += (int) $entry->takeaway_cost_cents;
                } elseif ($entry->recipe) {
                    $weekCostCents += $this->costs->forRecipe($entry->recipe, $entry->servings);
                }
            }

            $lowStock = $items->filter(fn (SiteMealInventoryItem $i) => $i->isLowStock());
            $outOfStock = $items->filter(fn (SiteMealInventoryItem $i) => (float) $i->current_qty <= 0);

            $totalValueCents = $items->reduce(function (int $carry, SiteMealInventoryItem $i) {
                if ($i->product?->cost_per_unit_cents === null) return $carry;
                return $carry + (int) round((float) $i->current_qty * (int) $i->product->cost_per_unit_cents);
            }, 0);

            return [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'meals_planned_this_week' => $siteEntries->count(),
                'meals_served_this_week' => $siteEntries->whereNotNull('served_at')->count(),
                'inventory_items' => $items->count(),
                'low_stock_count' => $lowStock->count(),
                'out_of_stock_count' => $outOfStock->count(),
                'top_low_stock' => $lowStock->take(3)->map(fn ($i) => [
                    'product_name' => $i->product?->name,
                    'current_qty' => (float) $i->current_qty,
                    'unit' => $i->unit,
                ])->values(),
                'inventory_value_cents' => $totalValueCents,
                'week_cost_cents' => $weekCostCents,
                'draft_list_id' => $lists->firstWhere('status', 'draft')?->id,
                'ordered_list_count' => $lists->where('status', 'ordered')->count(),
            ];
        });

        $totals = [
            'sites' => $sites->count(),
            'meals_this_week' => $siteCards->sum('meals_planned_this_week'),
            'meals_served' => $siteCards->sum('meals_served_this_week'),
            'low_stock' => $siteCards->sum('low_stock_count'),
            'out_of_stock' => $siteCards->sum('out_of_stock_count'),
            'inventory_value_cents' => $siteCards->sum('inventory_value_cents'),
            'week_cost_cents' => $siteCards->sum('week_cost_cents'),
            'draft_lists' => $siteCards->filter(fn ($c) => $c['draft_list_id'] !== null)->count(),
        ];

        $library = [
            'recipe_count' => MealRecipe::active()->count(),
            'recipe_total' => MealRecipe::count(),
            'product_count' => MealProduct::active()->count(),
            'tag_count' => MealDietaryTag::count(),
            'allergen_count' => MealDietaryTag::where('kind', 'allergen')->count(),
            'recent_recipes' => MealRecipe::active()->latest('updated_at')->limit(6)->get(['id', 'name', 'slug', 'serves_default']),
        ];

        return inertia('catering/dashboard', [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'sites' => $siteCards,
            'totals' => $totals,
            'library' => $library,
        ]);
    }
}
