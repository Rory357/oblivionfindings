<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Site;

class DashboardController extends Controller
{
    /**
     * Cheap JSON endpoint used by the shared CateringTabs nav so the
     * count badges (Recipes 15 · Products 41 · Tags 25) appear on every
     * catering page.
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
     * The dedicated interactive Meal Planner page (brand hero + site
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
}
