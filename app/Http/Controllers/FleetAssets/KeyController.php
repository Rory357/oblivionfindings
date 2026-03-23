<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetKeyLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class KeyController extends Controller
{
    public function index(Request $request)
    {
        $tableExists = Schema::hasTable('fleet_key_logs');

        $currentHolders = Asset::vehicles()
            ->when($tableExists, fn ($q) => $q->with(['latestKeyLog' => fn ($q2) => $q2->with('user:id,name', 'transferredToUser:id,name')]))
            ->get()
            ->map(function ($vehicle) use ($tableExists) {
                $log = $tableExists ? $vehicle->latestKeyLog : null;
                $holder = null;
                $since = null;
                $location = null;
                $keyNumber = null;

                if ($log) {
                    if ($log->action === 'checked_out') {
                        $holder = $log->user;
                        $since = $log->created_at;
                    } elseif ($log->action === 'transferred') {
                        $holder = $log->transferredToUser;
                        $since = $log->created_at;
                    }
                    $location = $log->location;
                    $keyNumber = $log->key_number;
                }

                return [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag,
                    'holder_id' => $holder?->id,
                    'holder_name' => $holder?->name,
                    'since' => $since?->toISOString(),
                    'location' => $location,
                    'key_number' => $keyNumber,
                    'status' => $log?->action ?? 'unknown',
                ];
            })
            ->values();

        $recentLogs = collect();
        if ($tableExists) {
            $vehicleIds = Asset::vehicles()->pluck('id');
            $recentLogs = FleetKeyLog::query()
                ->whereIn('asset_id', $vehicleIds)
                ->with(['asset:id,name', 'user:id,name', 'transferredToUser:id,name'])
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'vehicle' => $log->asset?->name,
                    'action' => $log->action,
                    'user' => $log->user?->name,
                    'transferred_to' => $log->transferredToUser?->name,
                    'key_number' => $log->key_number,
                    'location' => $log->location,
                    'notes' => $log->notes,
                    'created_at' => $log->created_at?->toISOString(),
                ])
                ->values();
        }

        // Users for dropdowns
        $users = User::query()
            ->whereNotNull('name')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        // Vehicles for dropdowns
        $vehicles = Asset::vehicles()->get(['id', 'name', 'asset_tag']);

        return Inertia::render('fleet-assets/keys/index', [
            'current_holders' => $currentHolders,
            'recent_logs' => $recentLogs,
            'users' => $users,
            'vehicles' => $vehicles,
        ]);
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'user_id' => 'required|exists:users,id',
            'key_number' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $log = FleetKeyLog::create([
            'organisation_id' => $request->user()->organisation_id ?? $request->user()->organization_id ?? 1,
            'asset_id' => $request->input('asset_id'),
            'user_id' => $request->input('user_id'),
            'action' => 'checked_out',
            'key_number' => $request->input('key_number'),
            'location' => $request->input('location', 'with_driver'),
            'notes' => $request->input('notes'),
        ]);

        AuditLogger::log('fleet-assets.keys.checkout', $log, [
            'asset_id' => $log->asset_id,
            'user_id' => $log->user_id,
        ]);

        return back()->with('success', 'Key checked out successfully.');
    }

    public function returnKey(Request $request)
    {
        $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'key_number' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $log = FleetKeyLog::create([
            'organisation_id' => $request->user()->organisation_id ?? $request->user()->organization_id ?? 1,
            'asset_id' => $request->input('asset_id'),
            'user_id' => $request->user()->id,
            'action' => 'returned',
            'key_number' => $request->input('key_number'),
            'location' => $request->input('location', 'key_safe'),
            'notes' => $request->input('notes'),
        ]);

        AuditLogger::log('fleet-assets.keys.return', $log, [
            'asset_id' => $log->asset_id,
        ]);

        return back()->with('success', 'Key returned successfully.');
    }

    public function transfer(Request $request)
    {
        $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'transferred_to_user_id' => 'required|exists:users,id',
            'key_number' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $log = FleetKeyLog::create([
            'organisation_id' => $request->user()->organisation_id ?? $request->user()->organization_id ?? 1,
            'asset_id' => $request->input('asset_id'),
            'user_id' => $request->user()->id,
            'action' => 'transferred',
            'transferred_to_user_id' => $request->input('transferred_to_user_id'),
            'key_number' => $request->input('key_number'),
            'location' => $request->input('location', 'with_driver'),
            'notes' => $request->input('notes'),
        ]);

        AuditLogger::log('fleet-assets.keys.transfer', $log, [
            'asset_id' => $log->asset_id,
            'transferred_to' => $log->transferred_to_user_id,
        ]);

        return back()->with('success', 'Key transferred successfully.');
    }
}
