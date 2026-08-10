<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DietaryTagController extends Controller
{
    public function index(Request $request)
    {
        // The standalone library page has been folded into the Meal Planner.
        if (! $request->wantsJson()) {
            return redirect()->route('catering.meal-planner');
        }

        // The in-planner Dietary & allergen tags manager fetches as JSON.
        $tags = MealDietaryTag::query()
            ->orderBy('kind')
            ->orderBy('label')
            ->get(['id', 'key', 'label', 'kind', 'severity', 'color', 'description']);

        return response()->json(['tags' => $tags]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);

        $data = $request->validate([
            'key' => 'nullable|string|max:64',
            'label' => 'required|string|max:255',
            'kind' => 'required|in:dietary,allergen',
            'severity' => 'required|in:info,warn,critical',
            'color' => 'nullable|string|max:16',
            'description' => 'nullable|string|max:500',
        ]);

        $key = ($data['key'] ?? null) ?: Str::slug($data['label'], '_');

        $tag = MealDietaryTag::create([
            'key' => $key,
            'label' => $data['label'],
            'kind' => $data['kind'],
            'severity' => $data['severity'],
            'color' => $data['color'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json($tag);
        }

        return back()->with('status', 'Tag created');
    }

    public function update(Request $request, MealDietaryTag $tag)
    {
        abort_unless($this->canManage(), 403);

        $data = $request->validate([
            'label' => 'required|string|max:255',
            'kind' => 'required|in:dietary,allergen',
            'severity' => 'required|in:info,warn,critical',
            'color' => 'nullable|string|max:16',
            'description' => 'nullable|string|max:500',
        ]);

        $tag->update($data);

        if ($request->wantsJson()) {
            return response()->json($tag->fresh());
        }

        return back()->with('status', 'Tag updated');
    }

    public function destroy(Request $request, MealDietaryTag $tag)
    {
        abort_unless($this->canManage(), 403);
        $tag->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('status', 'Tag deleted');
    }

    private function canManage(): bool
    {
        return auth()->user()?->canDo('catering.tags.manage') ?? false;
    }
}
