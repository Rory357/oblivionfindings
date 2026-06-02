<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = MealRecipe::query()->with('tags:id,key,label,kind,severity,color');

        if ($search = $request->string('q')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->boolean('inactive')) {
            $query->withTrashed();
        }

        $recipes = $query->orderBy('name')->paginate(30)->withQueryString();

        return inertia('catering/recipes/index', [
            'recipes' => $recipes,
            'tags' => MealDietaryTag::orderBy('label')->get(['id', 'key', 'label', 'kind', 'severity', 'color']),
            'filters' => [
                'q' => $request->string('q')->toString(),
                'inactive' => $request->boolean('inactive'),
            ],
            'canManage' => $this->canManage(),
        ]);
    }

    public function show(\Illuminate\Http\Request $request, MealRecipe $recipe)
    {
        $recipe->load(['ingredients.product', 'tags', 'creator:id,name']);

        $impact = null;
        $siteId = $request->integer('site');
        if ($siteId) {
            $site = \App\Models\Site::find($siteId);
            if ($site) {
                $clientIds = \App\Models\Client::where('site_id', $site->id)->pluck('id')->all();
                $report = app(\App\Services\Catering\DietaryConflictChecker::class)
                    ->checkRecipeAgainstClients($recipe, $clientIds);
                $impact = [
                    'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
                    'report' => $report,
                ];
            }
        }

        return inertia('catering/recipes/show', [
            'recipe' => $recipe,
            'canManage' => $this->canManage(),
            'impact' => $impact,
        ]);
    }

    public function create()
    {
        abort_unless($this->canManage(), 403);

        return inertia('catering/recipes/edit', [
            'recipe' => null,
            'tags' => MealDietaryTag::orderBy('label')->get(['id', 'key', 'label', 'kind', 'severity', 'color']),
            'products' => MealProduct::active()->orderBy('name')->get(['id', 'name', 'default_unit']),
        ]);
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

        return inertia('catering/recipes/edit', [
            'recipe' => $recipe,
            'tags' => MealDietaryTag::orderBy('label')->get(['id', 'key', 'label', 'kind', 'severity', 'color']),
            'products' => MealProduct::active()->orderBy('name')->get(['id', 'name', 'default_unit']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validateInput($request);
        $recipe = DB::transaction(function () use ($data) {
            $recipe = MealRecipe::create([
                'tenant_id' => auth()->user()?->tenant_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'serves_default' => $data['serves_default'] ?? 1,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);
            $recipe->tags()->sync($data['tag_ids'] ?? []);
            $this->syncIngredients($recipe, $data['ingredients'] ?? []);
            return $recipe;
        });

        if ($request->wantsJson()) {
            return response()->json(['recipe' => $recipe->load(['ingredients', 'tags:id'])]);
        }

        return redirect()->route('catering.recipes.show', $recipe)->with('status', 'Recipe created');
    }

    public function update(Request $request, MealRecipe $recipe)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validateInput($request);
        DB::transaction(function () use ($recipe, $data) {
            $recipe->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'serves_default' => $data['serves_default'] ?? 1,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $recipe->tags()->sync($data['tag_ids'] ?? []);
            $this->syncIngredients($recipe, $data['ingredients'] ?? []);
        });

        if ($request->wantsJson()) {
            return response()->json(['recipe' => $recipe->fresh(['ingredients', 'tags:id'])]);
        }

        return redirect()->route('catering.recipes.show', $recipe)->with('status', 'Recipe updated');
    }

    public function destroy(MealRecipe $recipe)
    {
        abort_unless($this->canManage(), 403);
        $recipe->delete();
        return redirect()->route('catering.recipes.index')->with('status', 'Recipe archived');
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
            'serves_default' => 'nullable|integer|min:1|max:500',
            'prep_minutes' => 'nullable|integer|min:0|max:1440',
            'cook_minutes' => 'nullable|integer|min:0|max:1440',
            'instructions' => 'nullable|string|max:20000',
            'is_active' => 'sometimes|boolean',
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
