<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetFuelLog;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetFuelController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.viewAny'), 403);

        $query = FleetFuelLog::query()
            ->with(['asset:id,name,asset_tag', 'user:id,name'])
            ->orderByDesc('logged_at');

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('logged_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('logged_at', '<=', $request->input('date_to'));
        }

        $logs = $query->paginate(25)->withQueryString();

        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orWhereHas('categoryRef', fn($q) => $q->where('slug', 'vehicle'))
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag']);

        // Calculate summary stats
        $stats = FleetFuelLog::query()
            ->when($request->filled('asset_id'), fn($q) => $q->where('asset_id', $request->input('asset_id')))
            ->selectRaw('
                COUNT(*) as total_logs,
                SUM(quantity_litres) as total_litres,
                SUM(total_cost) as total_cost,
                AVG(cost_per_litre) as avg_cost_per_litre
            ')
            ->first();

        return Inertia::render('fleet-management/fuel', [
            'logs' => [
                'data' => $logs->getCollection()->map(fn($log) => [
                    'id' => $log->id,
                    'asset' => $log->asset ? [
                        'id' => $log->asset->id,
                        'name' => $log->asset->name,
                        'asset_tag' => $log->asset->asset_tag,
                    ] : null,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                    'logged_at' => optional($log->logged_at)->toISOString(),
                    'fuel_type' => $log->fuel_type,
                    'quantity_litres' => $log->quantity_litres,
                    'cost_per_litre' => $log->cost_per_litre,
                    'total_cost' => $log->total_cost,
                    'odometer_km' => $log->odometer_km,
                    'full_tank' => $log->full_tank,
                    'station_name' => $log->station_name,
                ])->values(),
                'links' => $logs->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                ],
            ],
            'vehicles' => $vehicles,
            'stats' => [
                'total_logs' => (int) $stats->total_logs,
                'total_litres' => round((float) $stats->total_litres, 1),
                'total_cost' => round((float) $stats->total_cost, 2),
                'avg_cost_per_litre' => round((float) $stats->avg_cost_per_litre, 3),
            ],
            'filters' => $request->only(['asset_id', 'date_from', 'date_to']),
            'can' => [
                'manage' => $user->canDo('fleet.fuel.manage'),
            ],
        ]);
    }

    public function store(Request $request, Asset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.fuel.manage'), 403);

        $data = $request->validate([
            'logged_at' => ['required', 'date'],
            'fuel_type' => ['required', 'string', 'in:petrol,diesel,electric,hybrid,lpg'],
            'quantity_litres' => ['required', 'numeric', 'min:0.1', 'max:999'],
            'cost_per_litre' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'total_cost' => ['required', 'numeric', 'min:0', 'max:9999'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'full_tank' => ['boolean'],
            'station_name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['asset_id'] = $asset->id;
        $data['user_id'] = $user->id;

        // Calculate cost per litre if not provided
        if (empty($data['cost_per_litre']) && $data['quantity_litres'] > 0) {
            $data['cost_per_litre'] = round($data['total_cost'] / $data['quantity_litres'], 3);
        }

        $log = FleetFuelLog::create($data);

        AuditLogger::log('fleet.fuel.create', $log, [
            'asset_id' => $asset->id,
            'quantity_litres' => $data['quantity_litres'],
            'total_cost' => $data['total_cost'],
        ]);

        return back()->with('success', 'Fuel log recorded.');
    }

    public function update(Request $request, FleetFuelLog $fuelLog)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.fuel.manage'), 403);

        $data = $request->validate([
            'logged_at' => ['required', 'date'],
            'fuel_type' => ['required', 'string', 'in:petrol,diesel,electric,hybrid,lpg'],
            'quantity_litres' => ['required', 'numeric', 'min:0.1', 'max:999'],
            'cost_per_litre' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'total_cost' => ['required', 'numeric', 'min:0', 'max:9999'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'full_tank' => ['boolean'],
            'station_name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (empty($data['cost_per_litre']) && $data['quantity_litres'] > 0) {
            $data['cost_per_litre'] = round($data['total_cost'] / $data['quantity_litres'], 3);
        }

        $fuelLog->update($data);

        AuditLogger::log('fleet.fuel.update', $fuelLog, [
            'fuel_log_id' => $fuelLog->id,
        ]);

        return back()->with('success', 'Fuel log updated.');
    }

    public function destroy(Request $request, FleetFuelLog $fuelLog)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.fuel.manage'), 403);

        AuditLogger::log('fleet.fuel.delete', $fuelLog, [
            'fuel_log_id' => $fuelLog->id,
            'asset_id' => $fuelLog->asset_id,
        ]);

        $fuelLog->delete();

        return back()->with('success', 'Fuel log deleted.');
    }
}
