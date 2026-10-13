<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubKnowledge;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HubChecklistController extends Controller
{
    public function update(Request $request, HubPage $page, HubKnowledge $knowledge)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1',
            'step_id' => 'required|string|max:60', 'step_key' => 'required|string|size:64', 'done' => 'required|boolean',
            'user_id' => 'prohibited',
        ]);

        return response()->json($knowledge->update($request->user(), $page, $data))->header('Cache-Control', 'private, no-store');
    }
}
