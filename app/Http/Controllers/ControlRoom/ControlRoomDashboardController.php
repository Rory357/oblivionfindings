<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $alerts = ControlRoomAlert::query()
            ->latest('triggered_at')
            ->limit(100)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'source' => $a->source,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => optional($a->triggered_at)->toISOString(),
                'asset_id' => $a->asset_id,
            ])
            ->values();

        return Inertia::render('control-room/index', [
            'alerts' => $alerts,
        ]);
    }
}
