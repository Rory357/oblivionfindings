<?php

namespace App\Http\Controllers\Catering;

use App\Http\Controllers\Controller;
use App\Models\MealDietaryTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DietaryTagController extends Controller
{
    public function index()
    {
        $tags = MealDietaryTag::query()
            ->orderBy('kind')
            ->orderBy('label')
            ->get(['id', 'key', 'label', 'kind', 'severity', 'color', 'description']);

        return inertia('catering/tags/index', [
            'tags' => $tags,
            'kindOptions' => ['dietary', 'allergen'],
            'severityOptions' => ['info', 'warn', 'critical'],
            'canManage' => $this->canManage(),
        ]);
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

        $tenantId = auth()->user()?->tenant_id;
        $key = $data['key'] ?: Str::slug($data['label'], '_');

        MealDietaryTag::create([
            'tenant_id' => $tenantId,
            'key' => $key,
            'label' => $data['label'],
            'kind' => $data['kind'],
            'severity' => $data['severity'],
            'color' => $data['color'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

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

        return back()->with('status', 'Tag updated');
    }

    public function destroy(MealDietaryTag $tag)
    {
        abort_unless($this->canManage(), 403);
        $tag->delete();
        return back()->with('status', 'Tag deleted');
    }

    private function canManage(): bool
    {
        return auth()->user()?->canDo('catering.tags.manage') ?? false;
    }
}
