<?php

namespace App\Http\Controllers\Sites;

use App\Domain\Clinical\Services\ClientMealRestrictionProjection;
use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\MealCostCalculator;
use App\Services\Catering\SiteMealPlanAggregate;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteMealPlanController extends Controller
{
    use ResolvesAllowedSiteTypes;
    use RespondsToInertiaOrJson;

    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    /** IDDSI food framework levels offered in the resident dietary editor. */
    public const IDDSI_LEVELS = [
        ['level' => 7, 'label' => 'Regular / Easy to chew'],
        ['level' => 6, 'label' => 'Soft & Bite-sized'],
        ['level' => 5, 'label' => 'Minced & Moist'],
        ['level' => 4, 'label' => 'Puréed'],
        ['level' => 3, 'label' => 'Liquidised'],
    ];

    public function __construct(
        private readonly SiteMealPlanAggregate $aggregate,
        private MealCostCalculator $costCalculator,
        private InventoryMovementRecorder $inventory,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ClientMealRestrictionProjection $restrictionProjection,
    ) {}

    public function bootstrap(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $user = auth()->user();

        $recipes = $this->aggregate->recipeQuery($site)
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

        $clients = $this->aggregate->residentQuery($site, $request->user())
            ->with(['mealDislikes.product:id,name'])
            ->orderBy('first_name')
            ->get()
            ->map(fn (Client $c) => $this->residentPayload($c));

        $templates = SiteMealWeekTemplate::query()
            ->where(fn ($query) => $query
                ->where('site_id', $site->id)
                ->orWhere('is_starter', true))
            ->orderBy('name')
            ->get();
        $templates = $this->aggregate
            ->visibleTemplates($site, $templates)
            ->map(fn (SiteMealWeekTemplate $t) => $this->templatePayload($t));

        // Slim list of houses & offices for the hero site switcher.
        $sites = Site::query()
            ->active()
            ->whereIn('type', $this->allowedSiteTypes($request))
            ->orderBy('name');
        $this->siteAccess->applySiteScope(
            $sites,
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );
        $sites = $sites
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
                'tags_manage' => (bool) $user?->canDo('catering.tags.manage'),
                'can_override' => false,
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
            'iddsi_food_level' => $r->iddsi_food_level,
            'prep_minutes' => $r->prep_minutes,
            'cook_minutes' => $r->cook_minutes,
            'category' => $r->category,
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
        $name = trim($c->first_name.' '.$c->last_name);
        $initials = strtoupper(mb_substr($c->first_name ?? '', 0, 1).mb_substr($c->last_name ?? '', 0, 1));
        $restriction = $this->restrictionProjection->forClient($c);

        return [
            'id' => $c->id,
            'name' => $name,
            'initials' => $initials ?: 'NA',
            'hue' => ($c->id * 47) % 360,
            'allergens' => $restriction['allergens'],
            'allergen_tag_ids' => $restriction['allergen_tag_ids'],
            'dietary' => $restriction['dietary'],
            'dietary_tag_ids' => $restriction['dietary_tag_ids'],
            'dislikes' => $c->mealDislikes->map(fn (ClientMealDislike $d) => $d->product?->name ?? $d->free_text_name)->filter()->values(),
            'dislike_product_ids' => $c->mealDislikes->pluck('product_id')->filter()->values(),
            'texture' => $restriction['texture'],
            'fluids' => $restriction['fluids'],
            'restriction_authority' => [
                'status' => $restriction['authority_status'],
                'restriction_id' => $restriction['restriction_id'],
                'version' => $restriction['version'],
                'effective_from' => $restriction['effective_from'],
                'effective_until' => $restriction['effective_until'],
                'review_due_at' => $restriction['review_due_at'],
                'approved_at' => $restriction['approved_at'],
                'approved_by' => $restriction['approved_by'],
                'open_discrepancies' => $restriction['open_discrepancies'],
            ],
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
        $this->authorize('view', $site);

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
        $entries = $entries->filter(function (SiteMealPlanEntry $entry) use ($request, $site): bool {
            try {
                $this->aggregate->resolve(
                    $site,
                    $entry->recipe_id ? (int) $entry->recipe_id : null,
                    $entry->client_ids ?? [],
                    $entry->plan_date,
                    $request->user(),
                );

                return true;
            } catch (ValidationException) {
                return false;
            }
        })->values();

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
        $this->authorize('view', $site);

        $data = $request->validate([
            'recipe_id' => ['nullable', 'integer'],
            'client_ids' => 'nullable|array',
            'client_ids.*' => ['integer', 'distinct'],
            'plan_date' => 'nullable|date',
        ]);

        $resolved = $this->aggregate->resolve(
            $site,
            isset($data['recipe_id']) ? (int) $data['recipe_id'] : null,
            $data['client_ids'] ?? [],
            $data['plan_date'] ?? now(),
            $request->user(),
        );

        return response()->json($resolved['report']);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);

        $data = $this->validateInput($request);

        DB::transaction(function () use ($data, $request, $site): void {
            $resolved = $this->aggregate->resolve(
                $site,
                $data['recipe_id'] ?? null,
                $data['client_ids'] ?? [],
                $data['plan_date'],
                $request->user(),
                true,
            );
            $this->aggregate->assertClinicallySafe($resolved);

            SiteMealPlanEntry::create([
                'site_id' => $site->id,
                'plan_date' => $data['plan_date'],
                'meal_slot' => $data['meal_slot'],
                'source_type' => $data['source_type'],
                'recipe_id' => $resolved['recipe']?->id,
                'ad_hoc_name' => $data['ad_hoc_name'] ?? null,
                'takeaway_vendor' => $data['takeaway_vendor'] ?? null,
                'takeaway_cost_cents' => $data['takeaway_cost_cents'] ?? null,
                'takeaway_reference' => $data['takeaway_reference'] ?? null,
                'servings' => $data['servings'] ?? 1,
                'notes' => $data['notes'] ?? null,
                'client_ids' => $resolved['resident_ids'],
                'created_by' => auth()->id(),
                'allergen_override_reason' => null,
                'allergen_override_by' => null,
                'allergen_override_at' => null,
            ]);
        }, 3);

        return $this->inertiaOrJson($request, 'Meal added');
    }

    public function update(Request $request, Site $site, SiteMealPlanEntry $entry)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);

        $data = $this->validateInput($request, true);

        DB::transaction(function () use ($data, $entry, $request, $site): void {
            $locked = SiteMealPlanEntry::query()
                ->whereKey($entry->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages([
                    'expected_version' => ['This meal has changed since it was opened. Refresh the meal plan and try again.'],
                ])->status(409);
            }

            $resolved = $this->aggregate->resolve(
                $site,
                $data['recipe_id'] ?? null,
                $data['client_ids'] ?? [],
                $data['plan_date'],
                $request->user(),
                true,
            );
            $this->aggregate->assertClinicallySafe($resolved);

            $locked->update([
                'plan_date' => $data['plan_date'],
                'meal_slot' => $data['meal_slot'],
                'source_type' => $data['source_type'],
                'recipe_id' => $resolved['recipe']?->id,
                'ad_hoc_name' => $data['ad_hoc_name'] ?? null,
                'takeaway_vendor' => $data['takeaway_vendor'] ?? null,
                'takeaway_cost_cents' => $data['takeaway_cost_cents'] ?? null,
                'takeaway_reference' => $data['takeaway_reference'] ?? null,
                'servings' => $data['servings'] ?? 1,
                'notes' => $data['notes'] ?? null,
                'client_ids' => $resolved['resident_ids'],
                'version' => $locked->version + 1,
            ]);
        }, 3);

        return $this->inertiaOrJson($request, 'Meal updated');
    }

    public function destroy(Request $request, Site $site, SiteMealPlanEntry $entry)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);
        $entry->delete();

        return $this->inertiaOrJson($request, 'Meal removed');
    }

    public function markServed(Request $request, Site $site, SiteMealPlanEntry $entry)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);

        return DB::transaction(function () use ($request, $site, $entry) {
            $locked = SiteMealPlanEntry::query()
                ->whereKey($entry->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->served_at) {
                return $this->inertiaOrJson($request, 'Already served');
            }

            $resolved = $this->aggregate->resolve(
                $site,
                $locked->recipe_id ? (int) $locked->recipe_id : null,
                $locked->client_ids ?? [],
                $locked->plan_date,
                $request->user(),
                true,
            );
            $this->aggregate->assertClinicallySafe($resolved);

            $locked->update([
                'served_at' => now(),
                'served_by' => auth()->id(),
                'version' => $locked->version + 1,
            ]);

            $count = $this->applyServeStock($site, $locked, $resolved['recipe'], -1);

            return $this->inertiaOrJson($request, $count > 0
                ? "Served · {$count} ingredient".($count === 1 ? '' : 's').' deducted from stock'
                : 'Marked as served');
        }, 3);
    }

    public function unserve(Request $request, Site $site, SiteMealPlanEntry $entry)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($entry->site_id === $site->id, 404);

        return DB::transaction(function () use ($request, $site, $entry) {
            $locked = SiteMealPlanEntry::query()
                ->whereKey($entry->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->served_at) {
                return $this->inertiaOrJson($request, 'Not served');
            }

            $resolved = $this->aggregate->resolve(
                $site,
                $locked->recipe_id ? (int) $locked->recipe_id : null,
                $locked->client_ids ?? [],
                $locked->plan_date,
                $request->user(),
                true,
            );
            $count = $this->applyServeStock($site, $locked, $resolved['recipe'], 1);

            $locked->update([
                'served_at' => null,
                'served_by' => null,
                'version' => $locked->version + 1,
            ]);

            return $this->inertiaOrJson($request, $count > 0 ? 'Un-served · stock restored' : 'Marked not served');
        }, 3);
    }

    /**
     * Adjust inventory for a recipe meal being served (sign -1) or
     * un-served (+1). Scales each tracked ingredient by servings ÷ default.
     * Returns the number of inventory movements written.
     */
    private function applyServeStock(
        Site $site,
        SiteMealPlanEntry $entry,
        ?MealRecipe $recipe,
        int $sign,
    ): int {
        if ($entry->source_type !== 'recipe' || ! $entry->recipe_id) {
            return 0;
        }
        if (! $recipe || (int) $recipe->id !== (int) $entry->recipe_id || $recipe->ingredients->isEmpty()) {
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
                note: ($sign < 0 ? 'Served: ' : 'Un-served: ').$entry->displayName(),
            );
            $touched++;
        }

        return $touched;
    }

    public function saveSettings(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.shopping.manage'), 403);
        $data = $request->validate([
            'weekly_food_budget_cents' => 'nullable|integer|min:0|max:100000000',
        ]);
        $site->update(['weekly_food_budget_cents' => $data['weekly_food_budget_cents'] ?? null]);

        return $this->inertiaOrJson($request, 'Meal planner settings saved');
    }

    /**
     * Operational meal planning may update preferences only. Clinically
     * governed allergy, diet, IDDSI and fluid fields are read-only here.
     */
    public function updateResident(Request $request, Site $site, Client $client)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless(
            $this->aggregate->residentQuery($site, $request->user())
                ->whereKey($client->id)
                ->exists(),
            404,
        );

        $data = $request->validate([
            'tag_ids' => 'prohibited',
            'iddsi_level' => 'prohibited',
            'iddsi_label' => 'prohibited',
            'fluids' => 'prohibited',
            'dislikes' => 'nullable|array',
            'dislikes.*' => 'string|max:255',
        ]);

        DB::transaction(function () use ($client, $data, $request, $site): void {
            $lockedClient = $this->aggregate->residentQuery($site, $request->user())
                ->whereKey($client->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Replace free-text dislikes (the editor sends the full set).
            $lockedClient->mealDislikes()->whereNull('product_id')->delete();
            foreach (array_unique($data['dislikes'] ?? []) as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                ClientMealDislike::create([
                    'client_id' => $lockedClient->id,
                    'free_text_name' => $name,
                    'created_by' => auth()->id(),
                ]);
            }
        }, 3);

        return $this->inertiaOrJson($request, 'Resident meal preferences updated');
    }

    public function clearWeek(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        $start = $this->resolveStart($request->string('week')->toString());
        $end = $start->addDays(6);
        SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->delete();

        return $this->inertiaOrJson($request, 'Week cleared');
    }

    public function copyWeek(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        $data = $request->validate([
            'from_week' => 'required|date',
            'to_week' => 'required|date',
            'replace' => 'nullable|boolean',
        ]);
        $from = CarbonImmutable::parse($data['from_week'])->startOfWeek();
        $to = CarbonImmutable::parse($data['to_week'])->startOfWeek();
        $fromEnd = $from->addDays(6);

        $copied = DB::transaction(function () use ($data, $from, $fromEnd, $request, $site, $to): int {
            $src = SiteMealPlanEntry::query()
                ->where('site_id', $site->id)
                ->whereBetween('plan_date', [$from->toDateString(), $fromEnd->toDateString()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $candidates = $src->map(function (SiteMealPlanEntry $entry) use ($from, $request, $site, $to): array {
                $offset = $from->diffInDays(CarbonImmutable::parse($entry->plan_date));
                $planDate = $to->addDays((int) $offset)->toDateString();
                $resolved = $this->aggregate->resolve(
                    $site,
                    $entry->recipe_id ? (int) $entry->recipe_id : null,
                    $entry->client_ids ?? [],
                    $planDate,
                    $request->user(),
                    true,
                );
                if ($resolved['report']['has_hard_blocks']) {
                    throw ValidationException::withMessages([
                        'to_week' => ['The copied week contains a meal blocked by current clinical restrictions. Review the destination week instead.'],
                    ]);
                }

                return [
                    'entry' => $entry,
                    'plan_date' => $planDate,
                    'recipe_id' => $resolved['recipe']?->id,
                    'resident_ids' => $resolved['resident_ids'],
                ];
            });

            SiteMealPlanEntry::query()
                ->where('site_id', $site->id)
                ->whereBetween('plan_date', [$to->toDateString(), $to->addDays(6)->toDateString()])
                ->lockForUpdate()
                ->get();
            if (! empty($data['replace'])) {
                SiteMealPlanEntry::query()
                    ->where('site_id', $site->id)
                    ->whereBetween('plan_date', [$to->toDateString(), $to->addDays(6)->toDateString()])
                    ->delete();
            }

            foreach ($candidates as $candidate) {
                /** @var SiteMealPlanEntry $entry */
                $entry = $candidate['entry'];
                SiteMealPlanEntry::create([
                    'site_id' => $site->id,
                    'plan_date' => $candidate['plan_date'],
                    'meal_slot' => $entry->meal_slot,
                    'source_type' => $entry->source_type,
                    'recipe_id' => $candidate['recipe_id'],
                    'ad_hoc_name' => $entry->ad_hoc_name,
                    'takeaway_vendor' => $entry->takeaway_vendor,
                    'takeaway_cost_cents' => $entry->takeaway_cost_cents,
                    'takeaway_reference' => $entry->takeaway_reference,
                    'servings' => $entry->servings,
                    'notes' => $entry->notes,
                    'client_ids' => $candidate['resident_ids'],
                    'created_by' => auth()->id(),
                ]);
            }

            return $candidates->count();
        }, 3);

        return $this->inertiaOrJson($request, "Copied {$copied} meal".($copied === 1 ? '' : 's'));
    }

    public function weekSummary(Request $request, Site $site)
    {
        $this->authorize('view', $site);

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
        $this->authorize('view', $site);

        $vendors = SiteMealPlanEntry::query()
            ->where('site_id', $site->id)
            ->whereNotNull('takeaway_vendor')
            ->distinct()
            ->orderBy('takeaway_vendor')
            ->pluck('takeaway_vendor')
            ->values();

        return response()->json(['vendors' => $vendors]);
    }

    private function validateInput(Request $request, bool $updating = false): array
    {
        $data = $request->validate([
            'plan_date' => 'required|date',
            'meal_slot' => 'required|in:'.implode(',', SiteMealPlanEntry::MEAL_SLOTS),
            'source_type' => 'nullable|in:'.implode(',', SiteMealPlanEntry::SOURCE_TYPES),
            'recipe_id' => ['nullable', 'integer'],
            'ad_hoc_name' => 'nullable|string|max:255',
            'takeaway_vendor' => 'nullable|string|max:255',
            // Accept either dollars (decimal) or cents (integer); normalised below.
            'takeaway_cost' => 'nullable|numeric|min:0|max:99999.99',
            'takeaway_cost_cents' => 'nullable|integer|min:0',
            'takeaway_reference' => 'nullable|string|max:255',
            'servings' => 'nullable|integer|min:1|max:500',
            'notes' => 'nullable|string|max:2000',
            'client_ids' => 'nullable|array',
            'client_ids.*' => ['integer', 'distinct'],
            'allergen_override_reason' => 'nullable|string|min:10|max:500',
            'expected_version' => [$updating ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);

        // Resolve source_type: explicit value wins; else infer from what's set.
        $sourceType = $data['source_type'] ?? null;
        if (! $sourceType) {
            if (! empty($data['takeaway_vendor']) || ! empty($data['takeaway_cost']) || ! empty($data['takeaway_cost_cents'])) {
                $sourceType = 'takeaway';
            } elseif (! empty($data['recipe_id'])) {
                $sourceType = 'recipe';
            } else {
                $sourceType = 'ad_hoc';
            }
        }
        $data['source_type'] = $sourceType;

        if ($sourceType === 'recipe' && empty($data['recipe_id'])) {
            throw ValidationException::withMessages([
                'recipe_id' => ['The selected recipe is not available for this Site.'],
            ]);
        }

        // Convert dollars → cents if dollars supplied
        if (array_key_exists('takeaway_cost', $data) && $data['takeaway_cost'] !== null) {
            $data['takeaway_cost_cents'] = (int) round(((float) $data['takeaway_cost']) * 100);
        }
        unset($data['takeaway_cost']);

        // Takeaway vendor required when source_type=takeaway
        if ($sourceType === 'takeaway' && empty($data['takeaway_vendor'])) {
            throw ValidationException::withMessages([
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
