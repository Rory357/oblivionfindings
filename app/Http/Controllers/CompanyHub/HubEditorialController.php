<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubEditorial;
use App\Domain\CompanyHub\Services\HubSectionLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HubEditorialController extends Controller
{
    public function navigation(Request $request, HubEditorial $editorial)
    {
        $data = $request->validate(['items' => 'required|array|min:1|max:100', 'items.*' => 'array:id,version,visible', 'items.*.id' => 'required|integer|distinct', 'items.*.version' => 'required|integer|min:1', 'items.*.visible' => 'required|boolean']);
        $editorial->navigation($request->user(), $data['items']);

        return back()->with('success', 'Navigation saved in the page drafts. Publish each changed page when ready.');
    }

    public function presets(Request $request, HubPage $page, HubSectionLibrary $library)
    {
        return response()->json($library->workspace($request->user(), $page));
    }

    public function savePreset(Request $request, HubPage $page, HubSectionLibrary $library)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'section' => 'required|array', 'scope' => ['sometimes', Rule::in(['personal', 'shared'])]]);
        $library->save($request->user(), $page, $data['name'], $data['section'], $data['scope'] ?? 'personal');

        return response()->json(['saved' => true]);
    }

    public function usePreset(Request $request, HubPage $page, int $preset, HubSectionLibrary $library)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return response()->json(['section' => $library->use($request->user(), $page, $preset, $data['version'])]);
    }

    public function removePreset(Request $request, HubPage $page, int $preset, HubSectionLibrary $library)
    {
        $data = $request->validate(['version' => 'sometimes|integer|min:1']);

        return response()->json(['version' => $library->removeOrRestore($request->user(), $page, $preset, $data['version'] ?? null, false)]);
    }

    public function restorePreset(Request $request, HubPage $page, int $preset, HubSectionLibrary $library)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return response()->json(['version' => $library->removeOrRestore($request->user(), $page, $preset, $data['version'], true)]);
    }
}
