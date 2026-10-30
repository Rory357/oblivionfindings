<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\Fleet\FleetMapSettings;
use App\Services\Fleet\FleetNotificationPreferences;
use App\Services\Fleet\VehicleTelemetryPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceSettingsController extends Controller
{
    public function index(Request $request, FleetNotificationPreferences $notifications, FleetMapSettings $maps)
    {
        $actor = $request->user();

        return inertia('fleet-assets/settings/index', [
            'notifications' => $notifications->snapshot($actor),
            'maps' => $maps->snapshot(),
            'permissions' => [
                'manageMaps' => $actor->canDo('fleet.settings.manage'),
                'roleDefaults' => $actor->canDo('settings.access.manage'),
                'devices' => $actor->canDo('fleet.viewAny'),
            ],
            'policies' => [
                ['key' => 'contact', 'title' => 'Connection freshness', 'value' => config('fleet.signals.offline_after_minutes').' minutes', 'detail' => 'Time since contact before the Fleet offline signal. A heartbeat does not prove a usable position.', 'owner' => 'Fleet signals'],
                ['key' => 'position', 'title' => 'Usable position freshness', 'value' => VehicleTelemetryPresenter::FRESH_MINUTES.' minutes', 'detail' => 'Older permitted fixes are last known. Missing or withheld positions remain unknown.', 'owner' => 'Vehicle telemetry'],
                ['key' => 'coverage', 'title' => 'Trip coverage gap', 'value' => config('fleet.trip.coverage_gap_seconds').' seconds', 'detail' => 'A larger gap leaves part of the recorded trip uncovered; it does not mean the tracker was disconnected.', 'owner' => 'Trip history'],
                ['key' => 'mileage', 'title' => 'Mileage evidence', 'value' => config('fleet.mileage_feed.fresh_minutes').' minutes / '.config('fleet.mileage_feed.default_tolerance_km').' km', 'detail' => 'Freshness and distance tolerance for mileage evidence; not a live tracking threshold.', 'owner' => 'Mileage'],
                ['key' => 'retention', 'title' => 'Fleet telemetry retention', 'value' => config('fleet.retention.telemetry_days').' days', 'detail' => 'Configured Fleet cleanup window. Raw device messages, personal assignment data and audit evidence have separate owners.', 'owner' => 'Fleet telemetry'],
            ],
        ]);
    }

    public function maps(Request $request, FleetMapSettings $maps)
    {
        return response()->json($maps->snapshot())->header('Cache-Control', 'private, no-store');
    }

    public function updateMaps(Request $request, FleetMapSettings $maps)
    {
        abort_unless($request->user()->canDo('fleet.settings.manage'), 403);
        $data = $request->validate([
            'revision' => 'required|string|size:64',
            'values' => 'required|array:google,project,display,places,geocoding,routes,restrictions_reviewed,terms_reviewed',
            'values.google' => 'required|boolean', 'values.project' => 'present|nullable|string|max:100|regex:/^[a-zA-Z0-9 ._-]*$/',
            'values.display' => 'required|boolean', 'values.places' => 'required|boolean',
            'values.geocoding' => 'required|boolean', 'values.routes' => 'required|boolean',
            'values.restrictions_reviewed' => 'required|boolean', 'values.terms_reviewed' => 'required|boolean',
        ]);

        return DB::transaction(function () use ($request, $maps, $data) {
            // insertOrIgnore + the unique key serialises the first save by different administrators.
            AppSetting::query()->insertOrIgnore(['key' => FleetMapSettings::KEY, 'value' => json_encode($maps->values()), 'created_at' => now(), 'updated_at' => now()]);
            $row = AppSetting::where('key', FleetMapSettings::KEY)->lockForUpdate()->firstOrFail();
            $before = $maps->snapshot();
            if (! hash_equals($before['revision'], $data['revision'])) {
                return response()->json(['message' => 'Map settings changed. Review the current configuration before saving.', 'latest' => $before], 409);
            }
            $values = $data['values'];
            $values['project'] = trim($values['project'] ?? '');
            foreach (array_diff(array_keys($values), ['project']) as $key) {
                $values[$key] = (bool) $values[$key];
            }
            if ($errors = $maps->validationErrors($values)) {
                throw ValidationException::withMessages($errors);
            }
            $row->update(['value' => $values]);
            AuditLogger::logOrFail('fleet.settings.maps.updated', $row, ['before' => $before['values'], 'after' => $values], $request);

            return response()->json($maps->snapshot())->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function devices(Request $request, SecurityDevicesAccessService $access)
    {
        abort_unless($request->user()->canDo('fleet.viewAny'), 403);
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        // Canonical visibility before search/pagination. No personal positions or assignment identities.
        return $access->visibleDevices($request->user())->where('domain', 'tracking')
            ->when($data['q'] ?? null, fn ($query, $term) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('model', 'like', '%'.$term.'%')))
            ->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name', 'manufacturer', 'model', 'firmware_version', 'status', 'last_seen_at'])
            ->through(fn ($device) => [
                'id' => $device->id, 'name' => $device->name ?: 'Unnamed tracker', 'model' => $device->model,
                'firmware' => $device->firmware_version, 'lastContact' => $device->last_seen_at?->toISOString(),
                'href' => '/fleet-assets/devices?device='.$device->id,
            ]);
    }

    public function history(Request $request)
    {
        return AuditLog::where(function ($query) use ($request) {
            $query->where(fn ($own) => $own->where('user_id', $request->user()->id)->where('action', 'fleet.settings.notifications.updated'));
            if ($request->user()->canDo('fleet.settings.manage')) {
                $query->orWhere('action', 'fleet.settings.maps.updated');
            }
        })->latest('id')->paginate(20, ['id', 'action', 'created_at'])->through(fn ($row) => [
            'id' => $row->id, 'title' => $row->action === 'fleet.settings.maps.updated' ? 'Map provider configuration saved' : 'Your notification preferences saved',
            'time' => $row->created_at->toISOString(),
        ]);
    }
}
