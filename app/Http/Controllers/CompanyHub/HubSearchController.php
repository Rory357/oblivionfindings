<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubCommandSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HubSearchController extends Controller
{
    public function __invoke(Request $request, HubCommandSearch $search)
    {
        $data = $request->validate(['query' => 'required|string|min:2|max:150']);

        return response()->json([
            'actor_id' => $request->user()->id,
            'results' => $search->search($request->user(), $data['query']),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
