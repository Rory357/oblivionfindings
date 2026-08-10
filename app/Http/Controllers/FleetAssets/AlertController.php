<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\AssetAlert;
use App\Models\ControlRoomAlert;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertController extends Controller
{
    /**
     * Acknowledge a control room alert from the fleet module.
     */
    public function acknowledge(ControlRoomAlert $alert)
    {
        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        return back();
    }

    /**
     * Resolve a control room alert from the fleet module.
     */
    public function resolve(ControlRoomAlert $alert)
    {
        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return back();
    }

    public function index(Request $request)
    {
        // Canonical operational alerts from fleet/asset sources.
        $crQuery = ControlRoomAlert::query()
            ->with(['asset:id,name,asset_tag', 'assignedTo:id,name'])
            ->whereIn('source', ['fleet', 'asset', 'tracker', 'geofence']);

        if ($request->filled('status')) {
            $crQuery->where('status', $request->input('status'));
        } else {
            // Default to unresolved
            $crQuery->whereNotIn('status', ['closed', 'resolved']);
        }

        if ($request->filled('severity')) {
            $crQuery->where('severity', $request->input('severity'));
        }

        if ($request->filled('asset_id')) {
            $crQuery->where('asset_id', (int) $request->input('asset_id'));
        }

        // Sorting
        $allowedSorts = ['triggered_at', 'severity', 'status'];
        $sort = $request->input('sort', 'triggered_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'triggered_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';

        $controlRoomAlerts = $crQuery->orderBy($sort, $direction)
            ->paginate(25, ['*'], 'cr_page')
            ->withQueryString();

        // Archived legacy asset_alerts history.
        $archivedAssetAlertQuery = AssetAlert::query()
            ->with(['asset:id,name,asset_tag', 'tracker:id,vendor,device_uid']);

        if ($request->filled('status')) {
            $archivedAssetAlertQuery->where('status', $request->input('status'));
        }

        if ($request->filled('severity')) {
            $archivedAssetAlertQuery->where('severity', $request->input('severity'));
        }

        if ($request->filled('asset_id')) {
            $archivedAssetAlertQuery->where('asset_id', (int) $request->input('asset_id'));
        }

        $archivedAssetAlerts = $archivedAssetAlertQuery->latest('triggered_at')
            ->limit(25)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => optional($a->triggered_at)->toISOString(),
                'acknowledged_at' => optional($a->acknowledged_at)->toISOString(),
                'resolved_at' => optional($a->resolved_at)->toISOString(),
                'context' => $a->context,
                'asset' => $a->asset ? ['id' => $a->asset->id, 'name' => $a->asset->name, 'asset_tag' => $a->asset->asset_tag] : null,
                'tracker' => $a->tracker ? ['id' => $a->tracker->id, 'vendor' => $a->tracker->vendor, 'device_uid' => $a->tracker->device_uid] : null,
            ])->values();

        return Inertia::render('fleet-assets/alerts/index', [
            'control_room_alerts' => [
                'data' => $controlRoomAlerts->getCollection()->map(fn ($a) => [
                    'id' => $a->id,
                    'source' => 'control_room',
                    'alert_type' => $a->alert_type,
                    'severity' => $a->severity,
                    'status' => $a->status,
                    'triggered_at' => optional($a->triggered_at)->toISOString(),
                    'acknowledged_at' => optional($a->acknowledged_at)->toISOString(),
                    'resolved_at' => optional($a->resolved_at)->toISOString(),
                    'context' => $a->context,
                    'notes' => $a->notes,
                    'asset' => $a->asset ? ['id' => $a->asset->id, 'name' => $a->asset->name, 'asset_tag' => $a->asset->asset_tag] : null,
                    'assigned_to' => $a->assignedTo ? ['id' => $a->assignedTo->id, 'name' => $a->assignedTo->name] : null,
                ])->values(),
                'links' => $controlRoomAlerts->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $controlRoomAlerts->currentPage(),
                    'last_page' => $controlRoomAlerts->lastPage(),
                    'total' => $controlRoomAlerts->total(),
                ],
            ],
            'archived_asset_alerts' => $archivedAssetAlerts,
            'filters' => $request->only(['status', 'severity', 'asset_id']),
            'can' => [
                'manage' => (bool) ($request->user()?->canDo('fleet.manage') || $request->user()?->canDo('assets.alerts.manage')),
            ],
        ]);
    }

    public function bulkAction(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:acknowledge,resolve'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $updateData = [];
        switch ($data['action']) {
            case 'acknowledge':
                $updateData = ['status' => 'acknowledged', 'acknowledged_at' => now()];
                break;
            case 'resolve':
                $updateData = ['status' => 'resolved', 'resolved_at' => now()];
                break;
        }

        if (!empty($updateData)) {
            ControlRoomAlert::whereIn('id', $data['ids'])->update($updateData);
        }

        return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' alert(s).');
    }
}
