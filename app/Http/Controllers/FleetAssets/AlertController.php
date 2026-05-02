<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\ControlRoomAlertController as CanonicalAlertController;
use App\Models\AssetAlert;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertController extends Controller
{
    /**
     * Acknowledge a control room alert from the fleet module.
     */
    public function acknowledge(Request $request, ControlRoomAlert $alert, CanonicalAlertController $canonical)
    {
        $this->assertFleetAlert($alert);

        return $canonical->acknowledge($request, $alert);
    }

    /**
     * Resolve a control room alert from the fleet module.
     */
    public function resolve(Request $request, ControlRoomAlert $alert, CanonicalAlertController $canonical)
    {
        $this->assertFleetAlert($alert);

        return $canonical->resolve($request, $alert);
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
                'manage' => (bool) $request->user()?->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    public function bulkAction(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:acknowledge,resolve'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'resolution_notes' => ['required_if:action,resolve', 'nullable', 'string', 'max:2000'],
        ]);

        $ids = collect($data['ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $alerts = ControlRoomAlert::query()
            ->with('sla')
            ->whereIn('source', $this->fleetAlertSources())
            ->whereIn('id', $ids)
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
            ->get();

        abort_if(
            $alerts->count() !== $ids->count(),
            403,
            'You are not authorized to update one or more selected alerts.',
        );

        $count = 0;
        $skipped = 0;

        foreach ($alerts as $alert) {
            if ($data['action'] === 'acknowledge') {
                if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_ACK)) {
                    $skipped++;

                    continue;
                }

                $alert->update([
                    'status' => ControlRoomAlert::STATUS_ACK,
                    'acknowledged_at' => now(),
                    'acknowledged_by_user_id' => $user->id,
                ]);

                $alert->sla?->recordAcknowledge();

                AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
                    'alert_id' => $alert->id,
                    'acknowledged_by' => $user->id,
                    'bulk' => true,
                    'source_bridge' => 'fleet-assets',
                ]);

                $count++;

                continue;
            }

            if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_RESOLVED)) {
                $skipped++;

                continue;
            }

            $alert->update([
                'status' => ControlRoomAlert::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $user->id,
                'notes' => $data['resolution_notes'],
            ]);

            $alert->sla?->recordResolution();

            AuditLogger::log('controlRoom.alert.resolve', $alert, [
                'alert_id' => $alert->id,
                'resolved_by' => $user->id,
                'bulk' => true,
                'source_bridge' => 'fleet-assets',
            ]);

            $count++;
        }

        $message = "{$count} alert(s) {$data['action']}d.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped because their current status cannot transition.";
        }

        return back()->with('success', $message);
    }

    protected function assertFleetAlert(ControlRoomAlert $alert): void
    {
        abort_unless(in_array($alert->source, $this->fleetAlertSources(), true), 404);
    }

    /**
     * @return array<int, string>
     */
    protected function fleetAlertSources(): array
    {
        return ['fleet', 'asset', 'tracker', 'geofence'];
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
