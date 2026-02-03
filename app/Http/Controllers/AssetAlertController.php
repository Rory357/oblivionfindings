<?php

namespace App\Http\Controllers;

use App\Models\AssetAlert;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

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
            'can' => [
                'manage' => $request->user()?->canDo('assets.alerts.manage') ?? false,
            ],
        ]);
    }

    public function acknowledge(Request $request, AssetAlert $alert)
    {
        abort_unless($request->user()?->canDo('assets.alerts.manage'), 403);

        if ($alert->status !== 'open') {
            return back()->with('error', 'Alert is not open.');
        }

        $alert->update([
            'status' => 'ack',
            'acknowledged_by_user_id' => $request->user()?->id,
            'acknowledged_at' => now(),
        ]);

        AuditLogger::log('assets.alert.acknowledged', $alert->asset, [
            'alert_id' => $alert->id,
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    public function resolve(Request $request, AssetAlert $alert)
    {
        abort_unless($request->user()?->canDo('assets.alerts.manage'), 403);

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        AuditLogger::log('assets.alert.resolved', $alert->asset, [
            'alert_id' => $alert->id,
        ]);

        return back()->with('success', 'Alert resolved.');
    }
}
