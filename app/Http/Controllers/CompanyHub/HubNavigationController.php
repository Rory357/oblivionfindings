<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubNavigation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HubNavigationController extends Controller
{
    public function show(Request $request, HubNavigation $navigation)
    {
        return response()->json($navigation->workspace($request->user()));
    }

    public function update(Request $request, HubNavigation $navigation)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:save,publish,discard', 'items' => 'required_if:action,save|array']);

        return response()->json($navigation->change($request->user(), $data['action'], $data['version'], $data['items'] ?? null));
    }
}
