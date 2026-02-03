<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\FleetMapUsageLog;
use Illuminate\Http\Request;

class FleetMapUsageController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'context' => ['nullable', 'string', 'max:50'],
            'asset_id' => ['nullable', 'integer'],
        ]);

        FleetMapUsageLog::create([
            'user_id' => $request->user()?->id,
            'asset_id' => $data['asset_id'] ?? null,
            'context' => $data['context'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }
}
