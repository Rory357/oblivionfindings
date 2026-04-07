<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use Illuminate\Http\Request;

class FleetDriverSessionController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        abort_unless($asset->category === 'vehicle', 404, 'Asset is not a vehicle.');

        $request->validate([
            'started_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'in:manual,auto,device'],
        ]);

        $session = FleetDriverSession::create([
            'asset_id' => $asset->id,
            'user_id' => $request->user()?->id,
            'started_at' => $request->input('started_at') ?? now(),
            'source' => $request->input('source', 'manual'),
            'status' => 'open',
        ]);

        return back()->with('success', 'Driver session started.');
    }

    public function end(Request $request, FleetDriverSession $session)
    {
        if ($session->status !== 'open') {
            return back()->with('error', 'Driver session is not open.');
        }

        $session->update([
            'ended_at' => $request->input('ended_at') ?? now(),
            'status' => 'closed',
        ]);

        return back()->with('success', 'Driver session ended.');
    }
}
