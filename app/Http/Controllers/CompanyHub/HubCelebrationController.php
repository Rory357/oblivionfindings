<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubCelebrations;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class HubCelebrationController extends Controller
{
    public function index(Request $request, HubCelebrations $celebrations)
    {
        return response()->json($celebrations->read($request->user(), $request->query()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, User $person, HubCelebrations $celebrations)
    {
        $guarded = $request->expectsJson() && ! $request->header('X-Inertia');
        $receipt = $celebrations->save($request->user(), (int) $person->id, $request->all(), $guarded);
        if ($guarded) {
            return response()->json($receipt)->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', $receipt['person']['enabled'] ? 'Birthday celebration enabled.' : 'Birthday celebration hidden.');
    }
}
