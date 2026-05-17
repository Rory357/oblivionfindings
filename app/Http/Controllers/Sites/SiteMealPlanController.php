<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Services\AuditLogger;
use App\Services\Catering\DietaryConflictChecker;
use App\Services\Catering\MealCostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SiteMealPlanController extends Controller
{
    public function __construct(
        private DietaryConflictChecker $conflictChecker,
        private MealCostCalculator $costCalculator,
    ) {}

    public function bootstrap(Site $site)
    {
        $user = auth()->user();
        $recipes = MealRecipe::active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'serves_default'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'serves_default' => $r->serves_default,
            ]);

        $products = MealProduct::active()
            ->orderBy('name')
            ->get(['id', 'name', 'default_unit']);

        $productCategories = MealProduct::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();

        $clients = Client::query()
            ->where('site_id', $site->id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name)]);

        return response()->json([
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'recipes' => $recipes,
            'products' => $products,
            'product_categories' => $productCategories,
            'clients' => $clients,
            'permissions' => [
                'plan' => (bool) $user?->canDo('sites.meals.plan'),
                'inventory_adjust' => (bool) $user?->canDo('sites.meals.inventory.adjust'),
                'shopping_manage' => (bool) $user?->canDo('sites.meals.shopping.manage'),
                'products_manage' => (bool) $user?->canDo('catering.products.manage'),
            ],
        ]);
    }

    public function index(Request $request, Site $site)
    {
        $start = $this->resolveStart($request->string('week')->toString());
        $end = $start->addDays(6);

        $entries = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->with([
                'recipe:id,name,slug,serves_default',
                'createdBy:id,name',
                'servedBy:id,name',
                'allergenOverrideBy:id,name',
            ])
            ->orderBy('plan_date')
            ->get();

        return response()->json([
            'site_id' => $site->id,
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'entries' => $entries,
        ]);
    }

    /**
     * Live dietary-conflict preview used by PlanEntryDialog. Returns the
     * full report without mutating anything.
     */
    public function checkConflicts(Request $request, Site $site)
    {
        $data = $request->validate([
            'recipe_id' => 'nullable|integer|exists:meal_recipes,id',
            'client_ids' => 'nullable|array',
            'client_ids.*' => 'integer|exists:clients,id',
        ]);

        if (empty($data['recipe_id']) || empty($data['client_ids'])) {
            return response()->json([
                'has_hard_blocks' => false,
                'has_soft_warnings' => false,
                'hard_blocks' => [],
                'soft_warnings' => [],
                'recipe_tag_ids' => [],
            ]);
        }

        $recipe = MealRecipe::with(['tags', 'ingredients.product.tags'])->find($data['recipe_id']);
        $report = $this->conflictChecker->checkRecipeAgainstClients($recipe, $data['client_ids']);

        return response()->json($report);
    }

    public function store(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);

        $data = $this->validateInput($request);
        $this->enforceOverrideGate($data);

        $entry = SiteMealPlanEntry::create([
            'tenant_id' => $site->tenant_id ?? auth()->user()?->tenant_id,
            'site_id' => $site->id,
            'plan_date' => $data['plan_date'],
            'meal_slot' => $data['meal_slot'],
            'source_type' => $data['source_type'],
            'recipe_id' => $data['recipe_id'] ?? null,
            'ad_hoc_name' => $data['ad_hoc_name'] ?? null,
            'takeaway_vendor' => $data['takeaway_vendor'] ?? null,
            'takeaway_cost_cents' => $data['takeaway_cost_cents'] ?? null,
            'takeaway_reference' => $data['takeaway_reference'] ?? null,
            'servings' => $data['servings'] ?? 1,
            'notes' => $data['notes'] ?? null,
            'client_ids' => $data['client_ids'] ?? [],
            'created_by' => auth()->id(),
            'allergen_override_reason' => $data['allergen_override_reason'] ?? null,
            'allergen_override_by' => !empty($data['allergen_override_reason']) ? auth()->id() : null,
            'allergen_override_at' => !empty($data['allergen_override_reason']) ? now() : null,
        ]);

        if (!empty($data['allergen_override_reason'])) {
            AuditLogger::log('meal.allergen_override', $entry, [
                'reason' => $data['allergen_override_reason'],
                'recipe_id' => $entry->recipe_id,
                'client_ids' => $entry->client_ids,
            ]);
        }

        return back()->with('status', 'Meal added');
    }

    public function update(Request $request, Site $site, SiteMealPlanEntry $entry)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);

        $data = $this->validateInput($request);
        $this->enforceOverrideGate($data);

        $payload = [
            'plan_date' => $data['plan_date'],
            'meal_slot' => $data['meal_slot'],
            'source_type' => $data['source_type'],
            'recipe_id' => $data['recipe_id'] ?? null,
            'ad_hoc_name' => $data['ad_hoc_name'] ?? null,
            'takeaway_vendor' => $data['takeaway_vendor'] ?? null,
            'takeaway_cost_cents' => $data['takeaway_cost_cents'] ?? null,
            'takeaway_reference' => $data['takeaway_reference'] ?? null,
            'servings' => $data['servings'] ?? 1,
            'notes' => $data['notes'] ?? null,
            'client_ids' => $data['client_ids'] ?? [],
        ];

        if (!empty($data['allergen_override_reason'])) {
            // Only update override fields when a (new) reason was supplied —
            // editing an already-overridden meal without changing the reason
            // leaves the original audit trail intact.
            $payload['allergen_override_reason'] = $data['allergen_override_reason'];
            $payload['allergen_override_by'] = auth()->id();
            $payload['allergen_override_at'] = now();
        }

        $entry->update($payload);

        if (!empty($data['allergen_override_reason'])) {
            AuditLogger::log('meal.allergen_override', $entry, [
                'reason' => $data['allergen_override_reason'],
                'recipe_id' => $entry->recipe_id,
                'client_ids' => $entry->client_ids,
            ]);
        }

        return back()->with('status', 'Meal updated');
    }

    public function destroy(Site $site, SiteMealPlanEntry $entry)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);
        $entry->delete();
        return back()->with('status', 'Meal removed');
    }

    public function markServed(Site $site, SiteMealPlanEntry $entry)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);

        $entry->update([
            'served_at' => now(),
            'served_by' => auth()->id(),
        ]);

        return back()->with('status', 'Marked as served');
    }

    public function weekSummary(Request $request, Site $site)
    {
        $start = $this->resolveStart($request->string('week')->toString());
        $end = $start->addDays(6);

        $entries = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->with('recipe.ingredients.product')
            ->get();

        $totalCostCents = 0;
        $cookCostCents = 0;
        $takeawayCostCents = 0;
        $byDay = [];
        foreach ($entries as $entry) {
            $cost = 0;
            // Takeaway: prefer actual cost paid over any recipe estimate.
            if ($entry->isTakeaway() && $entry->takeaway_cost_cents !== null) {
                $cost = (int) $entry->takeaway_cost_cents;
                $takeawayCostCents += $cost;
            } elseif ($entry->recipe) {
                $cost = $this->costCalculator->forRecipe($entry->recipe, $entry->servings);
                $cookCostCents += $cost;
            }
            $totalCostCents += $cost;
            $byDay[$entry->plan_date->toDateString()] ??= ['count' => 0, 'cost_cents' => 0];
            $byDay[$entry->plan_date->toDateString()]['count']++;
            $byDay[$entry->plan_date->toDateString()]['cost_cents'] += $cost;
        }

        return response()->json([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'total_cost_cents' => $totalCostCents,
            'cook_cost_cents' => $cookCostCents,
            'takeaway_cost_cents' => $takeawayCostCents,
            'currency' => 'NZD',
            'by_day' => $byDay,
        ]);
    }

    /**
     * Past takeaway vendors used at this site — drives the
     * autocomplete suggestions in the planner dialog.
     */
    public function takeawayVendors(Site $site)
    {
        $vendors = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereNotNull('takeaway_vendor')
            ->distinct()
            ->orderBy('takeaway_vendor')
            ->pluck('takeaway_vendor')
            ->values();

        return response()->json(['vendors' => $vendors]);
    }

    /**
     * If the payload would create an allergen conflict and no override
     * reason of sufficient length is supplied, fail validation with the
     * conflict report attached so the client can render the warning.
     */
    private function enforceOverrideGate(array $data): void
    {
        if (empty($data['recipe_id']) || empty($data['client_ids'])) {
            return;
        }
        $recipe = MealRecipe::with(['tags', 'ingredients.product.tags'])->find($data['recipe_id']);
        if (!$recipe) {
            return;
        }
        $report = $this->conflictChecker->checkRecipeAgainstClients($recipe, $data['client_ids']);
        if (!$report['has_hard_blocks']) {
            return;
        }
        $reason = trim((string) ($data['allergen_override_reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'allergen_override_reason' => ['An allergen override reason (at least 10 characters) is required to save this meal.'],
            ])->status(422);
        }
    }

    private function validateInput(Request $request): array
    {
        $data = $request->validate([
            'plan_date' => 'required|date',
            'meal_slot' => 'required|in:' . implode(',', SiteMealPlanEntry::MEAL_SLOTS),
            'source_type' => 'nullable|in:' . implode(',', SiteMealPlanEntry::SOURCE_TYPES),
            'recipe_id' => 'nullable|integer|exists:meal_recipes,id',
            'ad_hoc_name' => 'nullable|string|max:255',
            'takeaway_vendor' => 'nullable|string|max:255',
            // Accept either dollars (decimal) or cents (integer); normalised below.
            'takeaway_cost' => 'nullable|numeric|min:0|max:99999.99',
            'takeaway_cost_cents' => 'nullable|integer|min:0',
            'takeaway_reference' => 'nullable|string|max:255',
            'servings' => 'nullable|integer|min:1|max:500',
            'notes' => 'nullable|string|max:2000',
            'client_ids' => 'nullable|array',
            'client_ids.*' => 'integer|exists:clients,id',
            'allergen_override_reason' => 'nullable|string|min:10|max:500',
        ]);

        // Resolve source_type: explicit value wins; else infer from what's set.
        $sourceType = $data['source_type'] ?? null;
        if (!$sourceType) {
            if (!empty($data['takeaway_vendor']) || !empty($data['takeaway_cost']) || !empty($data['takeaway_cost_cents'])) {
                $sourceType = 'takeaway';
            } elseif (!empty($data['recipe_id'])) {
                $sourceType = 'recipe';
            } else {
                $sourceType = 'ad_hoc';
            }
        }
        $data['source_type'] = $sourceType;

        // Convert dollars → cents if dollars supplied
        if (array_key_exists('takeaway_cost', $data) && $data['takeaway_cost'] !== null) {
            $data['takeaway_cost_cents'] = (int) round(((float) $data['takeaway_cost']) * 100);
        }
        unset($data['takeaway_cost']);

        // Takeaway vendor required when source_type=takeaway
        if ($sourceType === 'takeaway' && empty($data['takeaway_vendor'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'takeaway_vendor' => ['Vendor is required for takeaway meals.'],
            ]);
        }

        // Clear irrelevant fields based on source type
        if ($sourceType !== 'recipe') {
            $data['recipe_id'] = null;
        }
        if ($sourceType !== 'ad_hoc') {
            $data['ad_hoc_name'] = $sourceType === 'takeaway' ? null : ($data['ad_hoc_name'] ?? null);
        }
        if ($sourceType !== 'takeaway') {
            $data['takeaway_vendor'] = null;
            $data['takeaway_cost_cents'] = null;
            $data['takeaway_reference'] = null;
        }

        return $data;
    }

    private function resolveStart(string $week): CarbonImmutable
    {
        $base = $week !== '' ? CarbonImmutable::parse($week) : CarbonImmutable::now();
        return $base->startOfWeek();
    }
}
