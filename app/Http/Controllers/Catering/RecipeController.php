<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index()
    {
        // Recipes are managed inside the Meal Planner (/catering); the standalone
        // library page has been folded in. Bounce direct/bookmarked hits back to
        // the planner so there is a single home. The in-planner dialogs talk to
        // the JSON store/update/destroy endpoints below — not this page.
        return redirect()->route('catering.meal-planner');
    }

    public function show(MealRecipe $recipe)
    {
        // Folded into the Meal Planner — see index().
        return redirect()->route('catering.meal-planner');
    }

    public function create()
    {
        // Folded into the Meal Planner — recipes are added via the in-page dialog.
        return redirect()->route('catering.meal-planner');
    }

    public function edit(Request $request, MealRecipe $recipe)
    {
        abort_unless($this->canManage(), 403);
        $recipe->load(['ingredients', 'tags:id']);

        // The in-planner recipe editor (folded into /catering) fetches the
        // editable payload as JSON rather than navigating to this page.
        if ($request->wantsJson()) {
            return response()->json(['recipe' => $recipe]);
        }

        // The standalone editor page has been folded into the Meal Planner.
        return redirect()->route('catering.meal-planner');
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validateInput($request);
        $recipe = DB::transaction(function () use ($data) {
            $scope = $data['scope'] ?? 'shared';
            $recipe = MealRecipe::create([
                'tenant_id' => auth()->user()?->tenant_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'serves_default' => $data['serves_default'] ?? 1,
                'iddsi_food_level' => $data['iddsi_food_level'] ?? null,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'scope' => $scope,
                'site_id' => $scope === 'house' ? ($data['site_id'] ?? null) : null,
                'created_by' => auth()->id(),
            ]);
            $recipe->tags()->sync($data['tag_ids'] ?? []);
            $this->syncIngredients($recipe, $data['ingredients'] ?? []);

            return $recipe;
        });

        if ($request->wantsJson()) {
            return response()->json(['recipe' => $recipe->load(['ingredients', 'tags:id'])]);
        }

        return redirect()->route('catering.meal-planner')->with('status', 'Recipe created');
    }

    public function update(Request $request, MealRecipe $recipe)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validateInput($request);
        DB::transaction(function () use ($request, $recipe, $data) {
            $update = [
                'name' => $data['name'],
                'serves_default' => $data['serves_default'] ?? 1,
                'iddsi_food_level' => $data['iddsi_food_level'] ?? null,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ];

            // Only overwrite these when the submitting form actually sent them,
            // so the in-planner dialog (category/scope/site_id) and the legacy
            // library page (description) don't clobber each other's fields.
            if ($request->has('description')) {
                $update['description'] = $data['description'] ?? null;
            }
            if ($request->has('category')) {
                $update['category'] = $data['category'] ?? null;
            }
            if ($request->has('scope')) {
                $scope = $data['scope'] ?? 'shared';
                $update['scope'] = $scope;
                $update['site_id'] = $scope === 'house' ? ($data['site_id'] ?? null) : null;
            }

            $recipe->update($update);
            $recipe->tags()->sync($data['tag_ids'] ?? []);
            $this->syncIngredients($recipe, $data['ingredients'] ?? []);
        });

        if ($request->wantsJson()) {
            return response()->json(['recipe' => $recipe->fresh(['ingredients', 'tags:id'])]);
        }

        return redirect()->route('catering.meal-planner')->with('status', 'Recipe updated');
    }

    public function destroy(Request $request, MealRecipe $recipe)
    {
        abort_unless($this->canManage(), 403);
        $recipe->delete();

        // The in-planner recipe editor deletes via axios and expects JSON.
        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('catering.meal-planner')->with('status', 'Recipe archived');
    }

    private function syncIngredients(MealRecipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();
        foreach ($ingredients as $order => $ing) {
            MealRecipeIngredient::create([
                'recipe_id' => $recipe->id,
                'product_id' => $ing['product_id'] ?? null,
                'free_text_name' => $ing['free_text_name'] ?? null,
                'quantity' => $ing['quantity'],
                'unit' => $ing['unit'],
                'notes' => $ing['notes'] ?? null,
                'sort_order' => $order,
            ]);
        }
    }

    private function canManage(): bool
    {
        return auth()->user()?->canDo('catering.recipes.manage') ?? false;
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category' => 'nullable|string|max:80',
            'serves_default' => 'nullable|integer|min:1|max:500',
            'iddsi_food_level' => 'nullable|integer|in:3,4,5,6,7',
            'prep_minutes' => 'nullable|integer|min:0|max:1440',
            'cook_minutes' => 'nullable|integer|min:0|max:1440',
            'instructions' => 'nullable|string|max:20000',
            'is_active' => 'sometimes|boolean',
            'scope' => 'nullable|in:house,shared',
            'site_id' => 'nullable|integer|exists:sites,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:meal_dietary_tags,id',
            'ingredients' => 'nullable|array',
            'ingredients.*.product_id' => 'nullable|integer|exists:meal_products,id',
            'ingredients.*.free_text_name' => 'nullable|string|max:255',
            'ingredients.*.quantity' => 'required|numeric|min:0',
            'ingredients.*.unit' => 'required|string|max:24',
            'ingredients.*.notes' => 'nullable|string|max:255',
        ]);
    }
}
