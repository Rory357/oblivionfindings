<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Services\AuditLogger;
use App\Services\Catering\DietaryConflictChecker;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\MealCostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SiteMealPlanController extends Controller
{
    /** IDDSI food framework levels offered in the resident dietary editor. */
    public const IDDSI_LEVELS = [
        ['level' => 7, 'label' => 'Regular / Easy to chew'],
        ['level' => 6, 'label' => 'Soft & Bite-sized'],
        ['level' => 5, 'label' => 'Minced & Moist'],
        ['level' => 4, 'label' => 'Puréed'],
        ['level' => 3, 'label' => 'Liquidised'],
    ];

    public function __construct(
        private DietaryConflictChecker $conflictChecker,
        private MealCostCalculator $costCalculator,
        private InventoryMovementRecorder $inventory,
    ) {}

    public function bootstrap(Site $site)
    {
        $user = auth()->user();

        $recipes = MealRecipe::active()
            ->visibleToSite($site->id)
            ->with([
                'tags:id,label,kind,severity',
                'ingredients.product:id,name,default_unit,category',
                'ingredients.product.tags:id,kind',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (MealRecipe $r) => $this->recipePayload($r));

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
            ->with(['mealDietaryTags:id,label,kind', 'mealDislikes.product:id,name'])
            ->orderBy('first_name')
            ->get()
            ->map(fn (Client $c) => $this->residentPayload($c));

        $templates = SiteMealWeekTemplate::query()
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get()
            ->map(fn (SiteMealWeekTemplate $t) => $this->templatePayload($t));

        // Slim list of houses & offices for the hero site switcher.
        $sites = Site::query()
            ->where('tenant_id', $site->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'suburb', 'region'])
            ->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'suburb' => $s->suburb,
                'region' => $s->resolved_region ?? $s->region,
                'beds' => $s->clients()->count(),
            ]);

        return response()->json([
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'suburb' => $site->suburb,
                'region' => $site->resolved_region ?? $site->region,
                'weekly_food_budget_cents' => $site->weekly_food_budget_cents,
            ],
            'recipes' => $recipes,
            'products' => $products,
            'product_categories' => $productCategories,
            'clients' => $clients,
            'templates' => $templates,
            'sites' => $sites,
            'iddsi_levels' => self::IDDSI_LEVELS,
            'dietary_tags' => MealDietaryTag::orderBy('label')->get(['id', 'label', 'kind']),
            'permissions' => [
                'plan' => (bool) $user?->canDo('sites.meals.plan'),
                'inventory_adjust' => (bool) $user?->canDo('sites.meals.inventory.adjust'),
                'shopping_manage' => (bool) $user?->canDo('sites.meals.shopping.manage'),
                'products_manage' => (bool) $user?->canDo('catering.products.manage'),
                'recipes_manage' => (bool) $user?->canDo('catering.recipes.manage'),
                'can_override' => (bool) $user?->canDo('sites.meals.allergen.override'),
            ],
        ]);
    }

    private function recipePayload(MealRecipe $r): array
    {
        $allergenTagIds = $r->tags->where('kind', 'allergen')->pluck('id')
            ->merge($r->ingredients->flatMap(fn ($i) => $i->product?->tags?->where('kind', 'allergen')->pluck('id') ?? collect()))
            ->unique()->values();

        return [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'serves_default' => $r->serves_default,
            'prep_minutes' => $r->prep_minutes,
            'cook_minutes' => $r->cook_minutes,
            'scope' => $r->scope ?? 'shared',
            'site_id' => $r->site_id,
            'instructions' => $r->instructions,
            'tags' => $r->tags->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'kind' => $t->kind, 'severity' => $t->severity])->values(),
            'tag_ids' => $r->tags->pluck('id')->values(),
            'allergen_tag_ids' => $allergenTagIds,
            'cost_cents' => $this->costCalculator->forRecipe($r, $r->serves_default),
            'ingredients' => $r->ingredients->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->product?->name ?? $i->free_text_name,
                'qty' => (float) $i->quantity,
                'unit' => $i->unit,
                'category' => $i->product?->category,
            ])->values(),
        ];
    }

    private function residentPayload(Client $c): array
    {
        $name = trim($c->first_name . ' ' . $c->last_name);
        $initials = strtoupper(mb_substr($c->first_name ?? '', 0, 1) . mb_substr($c->last_name ?? '', 0, 1));
        $allergens = $c->mealDietaryTags->where('kind', 'allergen');
        $dietary = $c->mealDietaryTags->where('kind', 'dietary');

        return [
            'id' => $c->id,
            'name' => $name,
            'initials' => $initials ?: 'NA',
            'hue' => ($c->id * 47) % 360,
            'allergens' => $allergens->pluck('label')->values(),
            'allergen_tag_ids' => $allergens->pluck('id')->values(),
            'dietary' => $dietary->pluck('label')->values(),
            'dietary_tag_ids' => $dietary->pluck('id')->values(),
            'dislikes' => $c->mealDislikes->map(fn (ClientMealDislike $d) => $d->product?->name ?? $d->free_text_name)->filter()->values(),
            'dislike_product_ids' => $c->mealDislikes->pluck('product_id')->filter()->values(),
            'texture' => $c->meal_iddsi_level
                ? ['level' => (int) $c->meal_iddsi_level, 'label' => $c->meal_iddsi_label ?? '']
                : null,
            'fluids' => $c->meal_fluids_label,
        ];
    }

    private function templatePayload(SiteMealWeekTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'is_starter' => (bool) $t->is_starter,
            'meals' => $t->meals ?? [],
        ];
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

        if ($entry->served_at) {
            return back()->with('status', 'Already served');
        }

        $entry->update([
            'served_at' => now(),
            'served_by' => auth()->id(),
        ]);

        // Closed loop: deduct the recipe's tracked ingredients from inventory.
        $count = $this->applyServeStock($site, $entry, -1);

        return back()->with('status', $count > 0
            ? "Served · {$count} ingredient" . ($count === 1 ? '' : 's') . ' deducted from stock'
            : 'Marked as served');
    }

    public function unserve(Site $site, SiteMealPlanEntry $entry)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);

        if (! $entry->served_at) {
            return back()->with('status', 'Not served');
        }

        // Restore stock that was deducted on serve, then clear the served flag.
        $count = $this->applyServeStock($site, $entry, 1);

        $entry->update([
            'served_at' => null,
            'served_by' => null,
        ]);

        return back()->with('status', $count > 0 ? 'Un-served · stock restored' : 'Marked not served');
    }

    /**
     * Adjust inventory for a recipe meal being served (sign -1) or
     * un-served (+1). Scales each tracked ingredient by servings ÷ default.
     * Returns the number of inventory movements written.
     */
    private function applyServeStock(Site $site, SiteMealPlanEntry $entry, int $sign): int
    {
        if ($entry->source_type !== 'recipe' || ! $entry->recipe_id) {
            return 0;
        }
        $recipe = MealRecipe::with('ingredients')->find($entry->recipe_id);
        if (! $recipe || $recipe->ingredients->isEmpty()) {
            return 0;
        }
        $base = max(1, (int) $recipe->serves_default);
        $scale = ((int) ($entry->servings ?: $base)) / $base;
        $touched = 0;
        foreach ($recipe->ingredients as $ing) {
            if (! $ing->product_id) {
                continue;
            }
            $delta = $sign * ((float) $ing->quantity) * $scale;
            if ($delta === 0.0) {
                continue;
            }
            $this->inventory->record(
                site: $site,
                productId: $ing->product_id,
                delta: $delta,
                unit: $ing->unit,
                reason: 'plan_consumption',
                referenceType: SiteMealPlanEntry::class,
                referenceId: $entry->id,
                performedBy: auth()->id(),
                note: ($sign < 0 ? 'Served: ' : 'Un-served: ') . $entry->displayName(),
            );
            $touched++;
        }
        return $touched;
    }

    public function saveSettings(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        $data = $request->validate([
            'weekly_food_budget_cents' => 'nullable|integer|min:0|max:100000000',
        ]);
        $site->update(['weekly_food_budget_cents' => $data['weekly_food_budget_cents'] ?? null]);
        return back()->with('status', 'Meal planner settings saved');
    }

    /**
     * Update a resident's dietary profile from the calendar's resident
     * editor — allergen/dietary tags, dislikes, IDDSI texture and fluids.
     */
    public function updateResident(Request $request, Site $site, Client $client)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($client->site_id === $site->id, 404);

        $data = $request->validate([
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:meal_dietary_tags,id',
            'dislikes' => 'nullable|array',
            'dislikes.*' => 'string|max:255',
            'iddsi_level' => 'nullable|integer|min:1|max:7',
            'iddsi_label' => 'nullable|string|max:120',
            'fluids' => 'nullable|string|max:120',
        ]);

        $client->mealDietaryTags()->sync($data['tag_ids'] ?? []);

        // Replace free-text dislikes (the editor sends the full set).
        $client->mealDislikes()->whereNull('product_id')->delete();
        foreach (array_unique($data['dislikes'] ?? []) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            ClientMealDislike::create([
                'client_id' => $client->id,
                'free_text_name' => $name,
                'created_by' => auth()->id(),
            ]);
        }

        $client->update([
            'meal_iddsi_level' => $data['iddsi_level'] ?? null,
            'meal_iddsi_label' => $data['iddsi_label'] ?? null,
            'meal_fluids_label' => $data['fluids'] ?? null,
        ]);

        return back()->with('status', 'Resident dietary profile updated');
    }

    public function clearWeek(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        $start = $this->resolveStart($request->string('week')->toString());
        $end = $start->addDays(6);
        SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->delete();
        return back()->with('status', 'Week cleared');
    }

    public function copyWeek(Request $request, Site $site)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        $data = $request->validate([
            'from_week' => 'required|date',
            'to_week' => 'required|date',
            'replace' => 'nullable|boolean',
        ]);
        $from = CarbonImmutable::parse($data['from_week'])->startOfWeek();
        $to = CarbonImmutable::parse($data['to_week'])->startOfWeek();
        $fromEnd = $from->addDays(6);

        if (! empty($data['replace'])) {
            SiteMealPlanEntry::query()
                ->where('site_id', $site->id)
                ->whereBetween('plan_date', [$to->toDateString(), $to->addDays(6)->toDateString()])
                ->delete();
        }

        $src = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$from->toDateString(), $fromEnd->toDateString()])
            ->get();

        $copied = 0;
        foreach ($src as $e) {
            $offset = $from->diffInDays(CarbonImmutable::parse($e->plan_date));
            SiteMealPlanEntry::create([
                'tenant_id' => $e->tenant_id,
                'site_id' => $site->id,
                'plan_date' => $to->addDays((int) $offset)->toDateString(),
                'meal_slot' => $e->meal_slot,
                'source_type' => $e->source_type,
                'recipe_id' => $e->recipe_id,
                'ad_hoc_name' => $e->ad_hoc_name,
                'takeaway_vendor' => $e->takeaway_vendor,
                'takeaway_cost_cents' => $e->takeaway_cost_cents,
                'takeaway_reference' => $e->takeaway_reference,
                'servings' => $e->servings,
                'notes' => $e->notes,
                'client_ids' => $e->client_ids,
                'created_by' => auth()->id(),
            ]);
            $copied++;
        }

        return back()->with('status', "Copied {$copied} meal" . ($copied === 1 ? '' : 's'));
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
        // Only Service Managers / Registered Nurses (or admins) may override a
        // hard allergen conflict — Support Workers are blocked outright.
        if (! auth()->user()?->canDo('sites.meals.allergen.override')) {
            throw ValidationException::withMessages([
                'allergen_override_reason' => ['This meal conflicts with a resident allergen and your role cannot override it. Ask a Service Manager or Registered Nurse.'],
            ])->status(422);
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
