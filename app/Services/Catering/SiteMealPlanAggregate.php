<?php

namespace App\Services\Catering;

use App\Models\Client;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealWeekTemplate;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative relationship boundary for a Site meal plan.
 *
 * The route Site is the aggregate root. Resident placement, recipe sharing,
 * and clinical conflicts must all resolve through this service before a meal
 * plan relationship is disclosed or written.
 */
class SiteMealPlanAggregate
{
    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function __construct(
        private readonly DietaryConflictChecker $conflictChecker,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function residentQuery(Site $site, ?User $actor): Builder
    {
        $query = Client::query()
            ->where('site_id', $site->id)
            ->where('status', 'active');

        return $this->siteAccess->applyClientScope(
            $query,
            $actor,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    public function recipeQuery(Site $site): Builder
    {
        return MealRecipe::query()
            ->active()
            ->visibleToSite($site->id);
    }

    /**
     * @param  array<int, mixed>  $residentIds
     * @return array{recipe: MealRecipe|null, residents: Collection<int, Client>, resident_ids: array<int>, report: array<string, mixed>}
     */
    public function resolve(
        Site $site,
        ?int $recipeId,
        array $residentIds,
        CarbonInterface|string|null $planDate,
        ?User $actor,
        bool $lockForUpdate = false,
    ): array {
        $ids = $this->normaliseResidentIds($residentIds);

        $residentQuery = $this->residentQuery($site, $actor)
            ->whereIn('id', $ids)
            ->with('mealDislikes.product');
        if ($lockForUpdate) {
            $residentQuery->lockForUpdate();
        }

        $residentsById = $residentQuery->get()->keyBy('id');
        foreach ($ids as $index => $id) {
            if (! $residentsById->has($id)) {
                $this->unavailableResident($index);
            }
        }
        $residents = collect($ids)
            ->map(fn (int $id): Client => $residentsById->get($id))
            ->values();

        $recipe = null;
        if ($recipeId !== null) {
            $recipeQuery = $this->recipeQuery($site)
                ->whereKey($recipeId)
                ->with(['tags', 'ingredients.product.tags']);
            if ($lockForUpdate) {
                $recipeQuery->lockForUpdate();
            }
            $recipe = $recipeQuery->first();
            if (! $recipe) {
                throw ValidationException::withMessages([
                    'recipe_id' => ['The selected recipe is not available for this Site.'],
                ]);
            }
        }

        $report = $this->conflictChecker->checkMealAgainstResolvedClients(
            $recipe,
            $residents,
            $planDate,
        );

        return [
            'recipe' => $recipe,
            'residents' => $residents,
            'resident_ids' => $ids,
            'report' => $report,
        ];
    }

    /** @param array{report: array<string, mixed>} $resolved */
    public function assertClinicallySafe(array $resolved, string $field = 'client_ids'): void
    {
        if (! $resolved['report']['has_hard_blocks']) {
            return;
        }

        throw ValidationException::withMessages([
            $field => ['This meal is blocked by clinical meal restrictions. Resolve the authority, allergy, dietary or IDDSI conflict before saving.'],
        ])->status(422);
    }

    /**
     * Hide templates whose JSON payload contains a recipe unavailable to the
     * route Site. This prevents private recipe identifiers being serialized.
     *
     * @param  Collection<int, SiteMealWeekTemplate>  $templates
     * @return Collection<int, SiteMealWeekTemplate>
     */
    public function visibleTemplates(Site $site, Collection $templates): Collection
    {
        $recipeIds = $templates
            ->flatMap(fn (SiteMealWeekTemplate $template) => collect($template->meals ?? [])->pluck('recipe_id'))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $visibleIds = $recipeIds->isEmpty()
            ? collect()
            : $this->recipeQuery($site)->whereIn('id', $recipeIds)->pluck('id');
        $visible = $visibleIds->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);

        return $templates->filter(function (SiteMealWeekTemplate $template) use ($visible): bool {
            foreach (collect($template->meals ?? [])->pluck('recipe_id')->filter() as $recipeId) {
                if (! $visible->has((int) $recipeId)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /** @param array<int, mixed> $residentIds */
    private function normaliseResidentIds(array $residentIds): array
    {
        $ids = [];
        foreach (array_values($residentIds) as $index => $value) {
            $valid = filter_var($value, FILTER_VALIDATE_INT);
            if ($valid === false || (int) $valid <= 0 || in_array((int) $valid, $ids, true)) {
                $this->unavailableResident($index);
            }
            $ids[] = (int) $valid;
        }

        return $ids;
    }

    private function unavailableResident(int $index): never
    {
        throw ValidationException::withMessages([
            "client_ids.{$index}" => ['The selected resident is not available for this Site.'],
        ]);
    }
}
