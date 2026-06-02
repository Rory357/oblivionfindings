<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SiteMealWeekTemplateController extends Controller
{
    public function index(Site $site)
    {
        $templates = SiteMealWeekTemplate::query()
            ->where('site_id', $site->id)
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
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        $data = $this->validateInput($request);

        SiteMealWeekTemplate::create([
            'tenant_id' => $site->tenant_id ?? auth()->user()?->tenant_id,
            'site_id' => $site->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'meals' => $data['meals'] ?? [],
            'is_starter' => false,
            'created_by' => auth()->id(),
        ]);

        return back()->with('status', 'Template saved');
    }

    public function update(Request $request, Site $site, SiteMealWeekTemplate $template)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($template->site_id === $site->id, 404);
        $data = $this->validateInput($request);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'meals' => $data['meals'] ?? [],
        ]);

        return back()->with('status', 'Template updated');
    }

    public function destroy(Site $site, SiteMealWeekTemplate $template)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($template->site_id === $site->id, 404);
        $template->delete();
        return back()->with('status', 'Template deleted');
    }

    public function apply(Request $request, Site $site, SiteMealWeekTemplate $template)
    {
        abort_unless(auth()->user()?->canDo('sites.meals.plan'), 403);
        abort_unless($template->site_id === $site->id, 404);

        $data = $request->validate([
            'week' => 'required|date',
            'replace' => 'nullable|boolean',
        ]);

        $start = CarbonImmutable::parse($data['week'])->startOfWeek();

        if (! empty($data['replace'])) {
            SiteMealPlanEntry::query()
                ->where('site_id', $site->id)
                ->whereBetween('plan_date', [$start->toDateString(), $start->addDays(6)->toDateString()])
                ->delete();
        }

        $residentIds = $site->type === 'house'
            ? Client::query()->where('site_id', $site->id)->pluck('id')->all()
            : [];

        $applied = 0;
        foreach (($template->meals ?? []) as $meal) {
            $day = (int) ($meal['day'] ?? 0);
            if ($day < 0 || $day > 6 || empty($meal['recipe_id'])) {
                continue;
            }
            SiteMealPlanEntry::create([
                'tenant_id' => $site->tenant_id ?? auth()->user()?->tenant_id,
                'site_id' => $site->id,
                'plan_date' => $start->addDays($day)->toDateString(),
                'meal_slot' => $meal['slot'] ?? 'lunch',
                'source_type' => 'recipe',
                'recipe_id' => $meal['recipe_id'],
                'servings' => (int) ($meal['servings'] ?? 1),
                'client_ids' => $residentIds,
                'created_by' => auth()->id(),
            ]);
            $applied++;
        }

        return back()->with('status', "Applied “{$template->name}” · {$applied} meal" . ($applied === 1 ? '' : 's'));
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'meals' => 'nullable|array',
            'meals.*.day' => 'required|integer|min:0|max:6',
            'meals.*.slot' => 'required|in:' . implode(',', SiteMealPlanEntry::MEAL_SLOTS),
            'meals.*.recipe_id' => 'required|integer|exists:meal_recipes,id',
            'meals.*.servings' => 'nullable|integer|min:1|max:500',
        ]);
    }
}
