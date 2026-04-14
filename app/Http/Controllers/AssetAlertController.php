<?php

namespace App\Http\Controllers;

use App\Models\AssetAlert;
use Illuminate\Http\Request;

/**
 * @deprecated ControlRoomAlert is the canonical operational alert surface.
 *             This controller now serves read-only archived asset_alert history
 *             for legacy Assets/Fleet views. Do not route new alert generation
 *             or alert lifecycle actions through this controller.
 *
 * @see \App\Http\Controllers\ControlRoom\ControlRoomAlertController
 */
class AssetAlertController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('assets.alerts.view'), 403);

        $alerts = AssetAlert::query()
            ->with(['asset:id,name,asset_tag', 'tracker:id,vendor,device_uid'])
            ->orderByDesc('triggered_at')
            ->paginate(25)
            ->withQueryString();

        return inertia('assets/alerts/index', [
            'alerts' => $alerts,
            'archive' => [
                'mode' => 'read_only',
                'replacement_url' => '/fleet-assets/alerts',
            ],
        ]);
    }
}
