<?php

namespace App\Services\Catering;

use App\Domain\Clinical\Services\ClientMealRestrictionProjection;
use App\Models\Client;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use Carbon\WeekDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The all-sites Meal Planner overview (/catering with no ?site=).
 *
 * One row per Site the person can open the planner for, carrying the numbers
 * the single-site planner shows in its own header for the same week, worked
 * out the same way but for every Site in a handful of batched reads:
 *
 *  - meals, served and plan fill count the entries the planner calendar
 *    shows: SiteMealPlanController::index drops an entry whose residents or
 *    recipe the Site can no longer resolve, and so does this
 *    (SiteMealPlanAggregate::wouldResolve);
 *  - allergen conflicts are the planner's "unresolved" count (conflictsFor()
 *    in the planner): a recipe meal whose allergen tags meet an assigned
 *    resident's authorised allergies, with no override logged. The resident
 *    allergies come from the same ClientMealRestrictionProjection the planner
 *    bootstrap reads, asked only for residents on such a meal this week;
 *  - low and out of stock follow the planner's inventory rules;
 *  - spend is SiteMealPlanController::weekSummary's total, against the Site's
 *    weekly food budget when one is set.
 */
final class MealPlannerOverview
{
    /** The same bypass the planner's site switcher and the aggregate use. */
    public const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    /** The three main meals the planner's "Plan filled" meter counts. */
    public const CORE_SLOTS = ['breakfast', 'lunch', 'dinner'];

    public function __construct(
        private readonly SiteMealPlanAggregate $aggregate,
        private readonly MealCostCalculator $costCalculator,
        private readonly ClientMealRestrictionProjection $restrictions,
        private readonly UserSiteAccessService $siteAccess,
        private readonly SiteTypeAccessService $siteTypes,
    ) {}

    /**
     * Sites the person can open the planner for: the planner's site switcher
     * list (active, a type they may see, inside their Site scope) that the
     * Site view policy also allows, since every planner read authorises it.
     *
     * @return Collection<int, Site>
     */
    public function accessibleSites(User $user): Collection
    {
        // The policy asks canDo() a few times per Site; with the permissions
        // loaded it answers from memory instead of three reads per question.
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
        $gate = Gate::forUser($user);

        return $this->accessibleSiteQuery($user)
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $site): bool => $gate->allows('view', $site))
            ->values();
    }

    /** One Site from accessibleSites(), or null when the person can't open it (or it doesn't exist). */
    public function accessibleSite(User $user, int $siteId): ?Site
    {
        if ($siteId <= 0) {
            return null;
        }

        $site = $this->accessibleSiteQuery($user)->whereKey($siteId)->first();

        return $site && Gate::forUser($user)->allows('view', $site) ? $site : null;
    }

    /** Monday of the requested week (Y-m-d), or of this week at the NZ wall clock. */
    public function weekStart(?string $week): CarbonImmutable
    {
        if (is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $week, WorkerClock::timezone());
                if ($date instanceof CarbonImmutable && $date->format('Y-m-d') === $week) {
                    return $date->startOfWeek(WeekDay::Monday);
                }
            } catch (Throwable) {
                // An unreadable week falls back to this week.
            }
        }

        return WorkerClock::today()->startOfWeek(WeekDay::Monday);
    }

    /**
     * @return array{start: string, end: string, number: int, is_current: bool, previous: string, next: string, days: array<int, string>}
     */
    public function weekPayload(CarbonImmutable $weekStart): array
    {
        $current = WorkerClock::today()->startOfWeek(WeekDay::Monday);

        return [
            'start' => $weekStart->toDateString(),
            'end' => $weekStart->addDays(6)->toDateString(),
            'number' => (int) $weekStart->isoWeek(),
            'is_current' => $weekStart->toDateString() === $current->toDateString(),
            'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->addWeek()->toDateString(),
            'days' => $this->days($weekStart),
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites  from accessibleSites()
     * @return array{week_start: string, sites: array<int, array<string, mixed>>}
     */
    public function build(User $user, Collection $sites, CarbonImmutable $weekStart): array
    {
        $days = $this->days($weekStart);
        $siteIds = $sites->map(fn (Site $site): int => (int) $site->id)->values()->all();

        if ($siteIds === []) {
            return ['week_start' => $days[0], 'sites' => []];
        }

        /** @var Collection<int, Collection<int, Client>> $residentsBySite */
        $residentsBySite = $this->aggregate
            ->residentQueryForSites($siteIds, $user)
            ->get()
            ->groupBy('site_id');

        $recipes = $this->aggregate
            ->recipeQueryForSites($siteIds)
            ->get(['id', 'scope', 'site_id']);

        // The calendar entries and the week-summary costs both read this week's
        // entries; recipe tags and ingredients are loaded once for both.
        $entriesBySite = SiteMealPlanEntry::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('plan_date', [$days[0], $days[6]])
            ->with(['recipe.tags', 'recipe.ingredients.product.tags'])
            ->orderBy('plan_date')
            ->get()
            ->groupBy('site_id');

        $stockBySite = SiteMealInventoryItem::query()
            ->whereIn('site_id', $siteIds)
            ->get(['id', 'site_id', 'current_qty', 'reorder_level'])
            ->groupBy('site_id');

        /** @var array<int, array<int, int>> $allergiesByResident */
        $allergiesByResident = [];

        $rows = [];
        foreach ($sites as $site) {
            $siteId = (int) $site->id;
            /** @var Collection<int, Client> $residents */
            $residents = ($residentsBySite->get($siteId) ?? collect())->keyBy('id');
            $visibleResidentIds = $residents->keys()
                ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
                ->all();
            $visibleRecipeIds = $recipes
                ->filter(fn (MealRecipe $recipe): bool => $this->aggregate->recipeVisibleToSite($recipe, $siteId))
                ->mapWithKeys(fn (MealRecipe $recipe): array => [(int) $recipe->id => true])
                ->all();

            /** @var Collection<int, SiteMealPlanEntry> $entries */
            $entries = $entriesBySite->get($siteId) ?? collect();
            $shown = $entries->filter(fn (SiteMealPlanEntry $entry): bool => $this->aggregate->wouldResolve(
                is_array($entry->client_ids) ? $entry->client_ids : [],
                $entry->recipe_id ? (int) $entry->recipe_id : null,
                $visibleResidentIds,
                $visibleRecipeIds,
            ))->values();

            $rows[] = [
                'id' => $siteId,
                'name' => $site->name,
                'type' => $site->type,
                'is_house' => $site->type === 'house',
                'suburb' => $site->suburb,
                'region' => $site->resolved_region ?? $site->region,
                'residents' => $residents->count(),
                'week' => $this->weekFill($shown, $days),
                'allergens' => [
                    'unresolved' => $this->unresolvedAllergenConflicts($shown, $residents, $allergiesByResident),
                    'overrides' => $shown->filter(fn (SiteMealPlanEntry $entry): bool => $entry->hasAllergenOverride())->count(),
                ],
                'stock' => $this->stock($stockBySite->get($siteId) ?? collect()),
                'spend' => [
                    'total_cents' => $this->spendCents($entries),
                    'budget_cents' => $site->weekly_food_budget_cents,
                ],
            ];
        }

        return ['week_start' => $days[0], 'sites' => $rows];
    }

    private function accessibleSiteQuery(User $user): Builder
    {
        $query = Site::query()
            ->active()
            ->whereIn('type', $this->siteTypes->allowedTypes($user));

        return $this->siteAccess->applySiteScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    /** @return array<int, string> Monday to Sunday as Y-m-d */
    private function days(CarbonImmutable $weekStart): array
    {
        $monday = $weekStart->startOfWeek(WeekDay::Monday);

        return array_map(fn (int $offset): string => $monday->addDays($offset)->toDateString(), range(0, 6));
    }

    /**
     * Meals per day and how full the week is, as the planner header counts it:
     * a main-meal slot is filled when at least one meal is planned in it.
     *
     * @param  Collection<int, SiteMealPlanEntry>  $entries
     * @param  array<int, string>  $days
     * @return array<string, mixed>
     */
    private function weekFill(Collection $entries, array $days): array
    {
        $byDay = $entries->groupBy(fn (SiteMealPlanEntry $entry): string => $entry->plan_date->toDateString());

        $dayRows = [];
        $filled = 0;
        foreach ($days as $date) {
            /** @var Collection<int, SiteMealPlanEntry> $dayEntries */
            $dayEntries = $byDay->get($date) ?? collect();
            $slots = $dayEntries
                ->pluck('meal_slot')
                ->filter(fn (mixed $slot): bool => in_array($slot, self::CORE_SLOTS, true))
                ->unique()
                ->count();
            $filled += $slots;
            $dayRows[] = ['date' => $date, 'meals' => $dayEntries->count(), 'slots' => $slots];
        }

        $total = count($days) * count(self::CORE_SLOTS);

        return [
            'days' => $dayRows,
            'meals' => $entries->count(),
            'served' => $entries->filter(fn (SiteMealPlanEntry $entry): bool => $entry->served_at !== null)->count(),
            'slots_filled' => $filled,
            'slots_total' => $total,
            'fill_percent' => (int) round($filled / $total * 100),
        ];
    }

    /**
     * The planner's "unresolved" allergen conflicts: recipe meals where an
     * assigned resident's authorised allergies meet the recipe's allergen tags
     * (its own and its ingredients'), with no override logged.
     *
     * @param  Collection<int, SiteMealPlanEntry>  $entries  entries the calendar shows
     * @param  Collection<int, Client>  $residents  visible residents keyed by id
     * @param  array<int, array<int, int>>  $allergiesByResident  read-through cache
     */
    private function unresolvedAllergenConflicts(Collection $entries, Collection $residents, array &$allergiesByResident): int
    {
        $unresolved = 0;
        foreach ($entries as $entry) {
            if ($entry->source_type !== 'recipe' || ! $entry->recipe_id || ! $entry->recipe || $entry->hasAllergenOverride()) {
                continue;
            }

            $recipeAllergens = $this->recipeAllergenTagIds($entry->recipe);
            if ($recipeAllergens === []) {
                continue;
            }

            foreach (is_array($entry->client_ids) ? $entry->client_ids : [] as $clientId) {
                $resident = $residents->get((int) $clientId);
                if (! $resident instanceof Client) {
                    continue;
                }

                $allergiesByResident[$resident->id] ??= array_map(
                    'intval',
                    $this->restrictions->forClient($resident)['allergen_tag_ids'] ?? [],
                );

                if (array_intersect($allergiesByResident[$resident->id], $recipeAllergens) !== []) {
                    $unresolved++;

                    break;
                }
            }
        }

        return $unresolved;
    }

    /**
     * The recipe's allergen tag ids, as the planner bootstrap sends them
     * (SiteMealPlanController::recipePayload `allergen_tag_ids`).
     *
     * @return array<int, int>
     */
    private function recipeAllergenTagIds(MealRecipe $recipe): array
    {
        return $recipe->tags->where('kind', 'allergen')->pluck('id')
            ->merge($recipe->ingredients->flatMap(fn ($ingredient) => $ingredient->product?->tags?->where('kind', 'allergen')->pluck('id') ?? collect()))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Low and out of stock as the planner header counts them.
     *
     * @param  Collection<int, SiteMealInventoryItem>  $items
     * @return array{tracked: int, low: int, out: int, at_par_percent: int|null}
     */
    private function stock(Collection $items): array
    {
        $low = 0;
        $out = 0;
        $atPar = 0;
        foreach ($items as $item) {
            $current = (float) $item->current_qty;
            $reorder = $item->reorder_level === null ? null : (float) $item->reorder_level;
            if ($current <= 0) {
                $out++;
            } elseif ($reorder !== null && $current <= $reorder) {
                $low++;
            } else {
                $atPar++;
            }
        }

        $tracked = $items->count();

        return [
            'tracked' => $tracked,
            'low' => $low,
            'out' => $out,
            'at_par_percent' => $tracked > 0 ? (int) round($atPar / $tracked * 100) : null,
        ];
    }

    /**
     * SiteMealPlanController::weekSummary's total: the takeaway cost paid when
     * one is recorded, otherwise the recipe's ingredient cost for its servings.
     *
     * @param  Collection<int, SiteMealPlanEntry>  $entries  every entry of the week
     */
    private function spendCents(Collection $entries): int
    {
        $total = 0;
        foreach ($entries as $entry) {
            if ($entry->isTakeaway() && $entry->takeaway_cost_cents !== null) {
                $total += (int) $entry->takeaway_cost_cents;
            } elseif ($entry->recipe) {
                $total += $this->costCalculator->forRecipe($entry->recipe, (int) $entry->servings);
            }
        }

        return $total;
    }
}
