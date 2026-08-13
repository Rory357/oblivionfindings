<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Services\Catering\SiteMealPlanAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteMealWeekTemplateController extends Controller
{
    use RespondsToInertiaOrJson;

    public function __construct(
        private readonly SiteMealPlanAggregate $aggregate,
    ) {}

    public function index(Site $site)
    {
        $this->authorize('view', $site);

        $templates = SiteMealWeekTemplate::query()
            ->where(fn ($query) => $query
                ->where('site_id', $site->id)
                ->orWhere('is_starter', true))
            ->orderBy('name')
            ->get();
        $templates = $this->aggregate
            ->visibleTemplates($site, $templates)
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
        $data = $this->validateInput($request);

        DB::transaction(function () use ($data, $request, $site): void {
            $meals = $this->resolveTemplateMeals($site, $data['meals'] ?? [], $request, true);
            SiteMealWeekTemplate::create([
                'site_id' => $site->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'meals' => $meals,
                'is_starter' => false,
                'created_by' => auth()->id(),
            ]);
        }, 3);

        return $this->inertiaOrJson($request, 'Template saved');
    }

    public function update(Request $request, Site $site, SiteMealWeekTemplate $template)
    {
        $this->authorize('view', $site);
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless(! $template->is_starter && $template->site_id === $site->id, 404);
        $data = $this->validateInput($request);

        DB::transaction(function () use ($data, $request, $site, $template): void {
            $locked = SiteMealWeekTemplate::query()
                ->whereKey($template->id)
                ->where('is_starter', false)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();
            $meals = $this->resolveTemplateMeals($site, $data['meals'] ?? [], $request, true);
            $locked->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'meals' => $meals,
            ]);
        }, 3);

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

        $data = $request->validate([
            'week' => 'required|date',
            'replace' => 'nullable|boolean',
        ]);

        $start = CarbonImmutable::parse($data['week'])->startOfWeek();

        $applied = DB::transaction(function () use ($data, $request, $site, $start, $template): int {
            $lockedTemplate = SiteMealWeekTemplate::query()
                ->whereKey($template->id)
                ->where(fn ($query) => $query
                    ->where('is_starter', true)
                    ->orWhere('site_id', $site->id))
                ->lockForUpdate()
                ->firstOrFail();
            $residentIds = $site->type === 'house'
                ? $this->aggregate->residentQuery($site, $request->user())->pluck('id')->all()
                : [];
            $candidates = collect();
            foreach (($lockedTemplate->meals ?? []) as $meal) {
                $day = (int) ($meal['day'] ?? 0);
                if ($day < 0 || $day > 6 || empty($meal['recipe_id'])) {
                    continue;
                }
                $planDate = $start->addDays($day)->toDateString();
                try {
                    $resolved = $this->aggregate->resolve(
                        $site,
                        (int) $meal['recipe_id'],
                        $residentIds,
                        $planDate,
                        $request->user(),
                        true,
                    );
                } catch (ValidationException) {
                    throw ValidationException::withMessages([
                        'template' => ['This template contains a recipe that is not available at this Site.'],
                    ]);
                }
                if ($resolved['report']['has_hard_blocks']) {
                    throw ValidationException::withMessages([
                        'template' => ['This template contains a meal blocked by current clinical restrictions. No meals were applied.'],
                    ]);
                }

                $candidates->push([
                    'plan_date' => $planDate,
                    'meal_slot' => $meal['slot'] ?? 'lunch',
                    'recipe_id' => $resolved['recipe']?->id,
                    'servings' => (int) ($meal['servings'] ?? 1),
                    'resident_ids' => $resolved['resident_ids'],
                ]);
            }

            SiteMealPlanEntry::query()
                ->where('site_id', $site->id)
                ->whereBetween('plan_date', [$start->toDateString(), $start->addDays(6)->toDateString()])
                ->lockForUpdate()
                ->get();
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
                    'client_ids' => $candidate['resident_ids'],
                    'created_by' => auth()->id(),
                ]);
            }

            return $candidates->count();
        }, 3);

        return $this->inertiaOrJson($request, "Applied “{$template->name}” · {$applied} meal".($applied === 1 ? '' : 's'));
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'meals' => 'nullable|array',
            'meals.*.day' => 'required|integer|min:0|max:6',
            'meals.*.slot' => 'required|in:'.implode(',', SiteMealPlanEntry::MEAL_SLOTS),
            'meals.*.recipe_id' => ['required', 'integer'],
            'meals.*.servings' => 'nullable|integer|min:1|max:500',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $meals
     * @return array<int, array<string, mixed>>
     */
    private function resolveTemplateMeals(Site $site, array $meals, Request $request, bool $lockForUpdate): array
    {
        foreach ($meals as $index => $meal) {
            try {
                $resolved = $this->aggregate->resolve(
                    $site,
                    (int) $meal['recipe_id'],
                    [],
                    null,
                    $request->user(),
                    $lockForUpdate,
                );
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    "meals.{$index}.recipe_id" => ['The selected recipe is not available for this Site.'],
                ]);
            }
            $meals[$index]['recipe_id'] = $resolved['recipe']?->id;
        }

        return array_values($meals);
    }
}
