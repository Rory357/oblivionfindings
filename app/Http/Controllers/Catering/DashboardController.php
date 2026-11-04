<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Site;
use App\Services\Catering\MealPlannerOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
     * The Meal Planner. With no ?site= (the side menu) it opens on every Site
     * the person can plan for: each Site's week, allergen conflicts, low stock
     * and spend, loaded in the background. ?site= opens that Site's planner
     * exactly as before. A ?site= the person can't open (or that doesn't
     * exist — the two read the same) goes back to the all-sites view with a
     * notice, so a stale or shared link never opens a Site they can't see.
     */
    public function mealPlanner(Request $request, MealPlannerOverview $overview)
    {
        $user = $request->user();

        if ($request->has('site')) {
            $site = $overview->accessibleSite($user, $request->integer('site'));

            if (! $site) {
                return redirect()
                    ->route('catering.meal-planner')
                    ->with('warning', "You can't open that site's meal planner, so here are the sites you can plan for.");
            }

            $week = $request->string('week')->toString();

            return inertia('catering/meal-planner', [
                'default_site_id' => $site->id,
                'default_site_name' => $site->name,
                'default_week' => $week !== '' ? $overview->weekStart($week)->toDateString() : null,
            ]);
        }

        $weekStart = $overview->weekStart($request->string('week')->toString());

        return inertia('catering/meal-planner-overview', [
            'week' => fn () => $overview->weekPayload($weekStart),
            'can_create_sites' => fn () => (bool) $user?->can('create', Site::class),
            'overview' => Inertia::defer(
                fn () => $overview->build($user, $overview->accessibleSites($user), $weekStart),
                'overview',
            ),
        ]);
    }
}
