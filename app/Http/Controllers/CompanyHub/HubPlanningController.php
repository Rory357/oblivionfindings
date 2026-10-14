<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubEditorialPlan;
use App\Domain\CompanyHub\Services\HubInsights;
use App\Domain\CompanyHub\Services\HubPlanning;
use App\Domain\CompanyHub\Services\HubTemplates;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HubPlanningController extends Controller
{
    public function index(Request $request, HubPlanning $planning)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);

        return response()->json($planning->workspace($request->user(), $data['month']));
    }

    public function save(Request $request, HubPlanning $planning, ?HubEditorialPlan $plan = null)
    {
        $data = $request->validate([
            'title' => 'required|string|min:4|max:160', 'target_date' => 'required|date_format:Y-m-d',
            'owner_id' => 'required|integer', 'template' => ['required', Rule::in(HubTemplates::KEYS)], 'brief' => 'nullable|string|max:2000',
            'audience' => 'required|array:type,ids', 'audience.type' => ['required', Rule::in(['all', 'sites', 'departments'])],
            'audience.ids' => 'present|array|max:100', 'audience.ids.*' => 'integer|distinct', 'version' => $plan ? 'required|integer|min:1' : 'nullable',
        ]);
        $data['owner_id'] = (int) $data['owner_id'];
        $data['version'] = (int) ($data['version'] ?? 1);
        $data['audience']['ids'] = array_map('intval', $data['audience']['ids']);

        return response()->json($planning->save($request->user(), $data, $plan));
    }

    public function start(Request $request, HubEditorialPlan $plan, HubPlanning $planning)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $page = $planning->start($request->user(), $plan, (int) $data['version']);

        return response()->json(['url' => '/company-hub/manage/'.$page->id.'/edit', 'page_id' => $page->id]);
    }

    public function insights(Request $request, HubInsights $insights)
    {
        $data = $request->validate(['days' => ['required', 'integer', Rule::in([7, 28, 90])]]);

        return response()->json($insights->forUser($request->user(), (int) $data['days']), 200, ['Cache-Control' => 'no-store, private']);
    }
}
