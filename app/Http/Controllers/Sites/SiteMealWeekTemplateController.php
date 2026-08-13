<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Services\Catering\DietaryConflictChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class SiteMealWeekTemplateController extends Controller
{
    use RespondsToInertiaOrJson;

    public function __construct(
        private readonly DietaryConflictChecker $conflictChecker,
    ) {}

    public function index(Site $site)
    {
        $this->authorize('view', $site);

        $templates = SiteMealWeekTemplate::query()
            ->where(fn ($query) => $query
                ->where('site_id', $site->id)
                ->orWhere('is_starter', true))
            ->orderBy('name')
            ->get()
            ->map(fn (SiteMealWeekTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'is_starter' => (bool) $t->is_starter,
                'meals' => $t->meals ?? [],
            ]);

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        $data = $this->validateInput($request, $site);

        SiteMealWeekTemplate::create([
            'site_id' => $site->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'meals' => $data['meals'] ?? [],
            'is_starter' => false,
            'created_by' => auth()->id(),
        ]);

        return $this->inertiaOrJson($request, 'Template saved');
    }

    public function update(Request $request, Site $site, SiteMealWeekTemplate $template)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless(! $template->is_starter && $template->site_id === $site->id, 404);
        $data = $this->validateInput($request, $site);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'meals' => $data['meals'] ?? [],
        ]);

        return $this->inertiaOrJson($request, 'Template updated');
    }

    public function destroy(Request $request, Site $site, SiteMealWeekTemplate $template)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless(! $template->is_starter && $template->site_id === $site->id, 404);
        $template->delete();

        return $this->inertiaOrJson($request, 'Template deleted');
    }

    public function apply(Request $request, Site $site, SiteMealWeekTemplate $template)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($template->is_starter || $template->site_id === $site->id, 404);
        $this->assertTemplateRecipesVisibleToSite($template, $site);

        $data = $request->validate([
            'week' => 'required|date',
            'replace' => 'nullable|boolean',
        ]);

        $start = CarbonImmutable::parse($data['week'])->startOfWeek();

        $residentIds = $site->type === 'house'
            ? Client::query()->where('site_id', $site->id)->pluck('id')->all()
            : [];

        $candidates = collect();
        foreach (($template->meals ?? []) as $meal) {
            $day = (int) ($meal['day'] ?? 0);
            if ($day < 0 || $day > 6 || empty($meal['recipe_id'])) {
                continue;
            }
            $planDate = $start->addDays($day)->toDateString();
            $recipe = MealRecipe::query()
                ->with(['tags', 'ingredients.product.tags'])
                ->findOrFail($meal['recipe_id']);
            $report = $this->conflictChecker->checkRecipeAgainstClients($recipe, $residentIds, $planDate);
            if ($report['has_hard_blocks']) {
                throw ValidationException::withMessages([
                    'template' => ['This template contains a meal blocked by current clinical restrictions. No meals were applied.'],
                ]);
            }

            $candidates->push([
                'plan_date' => $planDate,
                'meal_slot' => $meal['slot'] ?? 'lunch',
                'recipe_id' => $meal['recipe_id'],
                'servings' => (int) ($meal['servings'] ?? 1),
            ]);
        }

        $applied = DB::transaction(function () use ($data, $site, $start, $residentIds, $candidates): int {
            if (! empty($data['replace'])) {
                SiteMealPlanEntry::query()
                    ->where('site_id', $site->id)
                    ->whereBetween('plan_date', [$start->toDateString(), $start->addDays(6)->toDateString()])
                    ->delete();
            }

            foreach ($candidates as $candidate) {
                SiteMealPlanEntry::create([
                    'site_id' => $site->id,
                    'plan_date' => $candidate['plan_date'],
                    'meal_slot' => $candidate['meal_slot'],
                    'source_type' => 'recipe',
                    'recipe_id' => $candidate['recipe_id'],
                    'servings' => $candidate['servings'],
                    'client_ids' => $residentIds,
                    'created_by' => auth()->id(),
                ]);
            }

            return $candidates->count();
        }, 3);

        return $this->inertiaOrJson($request, "Applied “{$template->name}” · {$applied} meal".($applied === 1 ? '' : 's'));
    }

    private function validateInput(Request $request, Site $site): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'meals' => 'nullable|array',
            'meals.*.day' => 'required|integer|min:0|max:6',
            'meals.*.slot' => 'required|in:'.implode(',', SiteMealPlanEntry::MEAL_SLOTS),
            'meals.*.recipe_id' => [
                'required',
                'integer',
                $this->visibleRecipeRule($site),
            ],
            'meals.*.servings' => 'nullable|integer|min:1|max:500',
        ]);
    }

    private function assertTemplateRecipesVisibleToSite(SiteMealWeekTemplate $template, Site $site): void
    {
        $recipeIds = collect($template->meals ?? [])
            ->pluck('recipe_id')
            ->filter()
            ->map(fn ($recipeId) => (int) $recipeId)
            ->unique()
            ->values();

        if ($recipeIds->isEmpty()) {
            return;
        }

        $visibleCount = MealRecipe::query()
            ->active()
            ->visibleToSite($site->id)
            ->whereIn('id', $recipeIds)
            ->count();

        if ($visibleCount !== $recipeIds->count()) {
            throw ValidationException::withMessages([
                'template' => ['This template contains a recipe that is not available at this Site.'],
            ]);
        }
    }

    private function visibleRecipeRule(Site $site): Exists
    {
        return Rule::exists('meal_recipes', 'id')
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(fn ($scope) => $scope
                    ->where('scope', 'shared')
                    ->orWhere(fn ($local) => $local
                        ->where('scope', 'house')
                        ->where('site_id', $site->id))));
    }
}
