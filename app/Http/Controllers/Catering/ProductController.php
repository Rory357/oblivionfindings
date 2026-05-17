<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = MealProduct::query()->with('tags:id,key,label,kind,severity,color');

        if ($search = $request->string('q')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($category = $request->string('category')->toString()) {
            $query->where('category', $category);
        }
        if ($request->boolean('inactive')) {
            $query->withTrashed();
        }

        $products = $query->orderBy('category')->orderBy('name')->paginate(50)->withQueryString();
        $categories = MealProduct::query()->whereNotNull('category')->distinct()->pluck('category')->sort()->values();

        return inertia('catering/products/index', [
            'products' => $products,
            'categories' => $categories,
            'tags' => MealDietaryTag::orderBy('label')->get(['id', 'key', 'label', 'kind', 'severity', 'color']),
            'filters' => [
                'q' => $request->string('q')->toString(),
                'category' => $request->string('category')->toString(),
                'inactive' => $request->boolean('inactive'),
            ],
            'canManage' => $this->canManage(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validateInput($request);
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        $data['tenant_id'] = auth()->user()?->tenant_id;
        $product = MealProduct::create($data);
        if (!empty($tagIds)) {
            $product->tags()->sync($tagIds);
        }

        if ($request->wantsJson()) {
            return response()->json($product->load('tags'));
        }

        return back()->with('status', 'Product created');
    }

    public function update(Request $request, MealProduct $product)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validateInput($request);
        $tagIds = $data['tag_ids'] ?? null;
        unset($data['tag_ids']);

        $product->update($data);
        if ($tagIds !== null) {
            $product->tags()->sync($tagIds);
        }

        return back()->with('status', 'Product updated');
    }

    public function destroy(MealProduct $product)
    {
        abort_unless($this->canManage(), 403);
        $product->delete();
        return back()->with('status', 'Product archived');
    }

    private function canManage(): bool
    {
        return auth()->user()?->canDo('catering.products.manage') ?? false;
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:64',
            'default_unit' => 'required|string|max:24',
            'pack_size' => 'nullable|numeric|min:0',
            'pack_unit' => 'nullable|string|max:24',
            'cost_per_unit_cents' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'barcode' => 'nullable|string|max:64',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:2000',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:meal_dietary_tags,id',
        ]);
    }
}
