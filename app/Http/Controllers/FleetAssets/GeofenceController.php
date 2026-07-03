<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetSignal;
use App\Models\Site;
use App\Rules\GeofenceShape;
use App\Services\AuditLogger;
use App\Services\Fleet\GeofenceStateCleanupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class GeofenceController extends Controller
{
    public function index(Request $request)
    {
        $query = AssetGeofence::query()
            ->with(['asset:id,name,asset_tag', 'site:id,name']);

        // Filters
        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('site_id')) {
            $query->where('site_id', $request->input('site_id'));
        }

        $geofences = $query->orderBy('name')
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'scope' => $g->scope ?? 'vehicle',
                'breach_type' => $g->breach_type,
                'is_active' => $g->is_active,
                'shape' => $g->shape,
                'time_rules' => $g->time_rules,
                'alert_config' => $g->alert_config,
                'asset' => $g->asset ? [
                    'id' => $g->asset->id,
                    'name' => $g->asset->name,
                    'asset_tag' => $g->asset->asset_tag,
                ] : null,
                'site' => $g->site ? [
                    'id' => $g->site->id,
                    'name' => $g->site->name,
                ] : null,
            ])->values();

        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Hero — whole-register counts (independent of filters). Coverage counts
        // distinct vehicles reached by an active geofence (direct asset_id plus
        // the assignment pivot the evaluator also consults). Breaches come from
        // the geofence.breach signals the evaluator emits.
        $activeGeofenceIds = AssetGeofence::query()->where('is_active', true)->pluck('id');
        $coveredAssetIds = AssetGeofence::query()
            ->where('is_active', true)
            ->whereNotNull('asset_id')
            ->pluck('asset_id');
        if ($activeGeofenceIds->isNotEmpty() && Schema::hasTable('asset_geofence_assignments')) {
            $coveredAssetIds = $coveredAssetIds->merge(
                DB::table('asset_geofence_assignments')
                    ->whereIn('asset_geofence_id', $activeGeofenceIds)
                    ->pluck('asset_id'),
            );
        }
        $hero = [
            'active' => $activeGeofenceIds->count(),
            'vehicles_covered' => $coveredAssetIds->unique()->count(),
            'breaches_7d' => Schema::hasTable('fleet_signals')
                ? FleetSignal::query()
                    ->where('signal_type', 'geofence.breach')
                    ->where('occurred_at', '>=', now()->subDays(7))
                    ->count()
                : 0,
        ];

        return Inertia::render('fleet-assets/geofences/index', [
            'hero' => $hero,
            'geofences' => $geofences,
            'sites' => $sites,
            'filters' => [
                'status' => $request->input('status', ''),
                'type' => $request->input('type', ''),
                'site_id' => $request->input('site_id', ''),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $assets = Asset::query()
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag', 'category']);

        $sites = Site::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->orderBy('name')
            ->get(['id', 'name', 'latitude', 'longitude']);

        return Inertia::render('fleet-assets/geofences/create', [
            'assets' => $assets,
            'sites' => $sites,
            'prefillSiteId' => $request->query('site_id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:circle,polygon'],
            'scope' => ['required', 'string', 'in:vehicle,resident'],
            'shape' => ['required', 'array', new GeofenceShape],
            'breach_type' => ['required', 'string', 'in:enter,exit,both'],
            'alert_config' => ['nullable', 'array'],
            'time_rules' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $geofence = AssetGeofence::create($data);

        AuditLogger::log('fleet.geofence.create', $geofence, [
            'asset_id' => $data['asset_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'name' => $data['name'],
        ]);

        return redirect()->route('fleet-assets.geofences.index')
            ->with('success', 'Geofence created successfully.');
    }

    public function edit(Request $request, AssetGeofence $geofence)
    {
        $geofence->load(['asset:id,name,asset_tag', 'site:id,name']);

        $assets = Asset::query()
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag', 'category']);

        $sites = Site::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->orderBy('name')
            ->get(['id', 'name', 'latitude', 'longitude']);

        return Inertia::render('fleet-assets/geofences/edit', [
            'geofence' => [
                'id' => $geofence->id,
                'name' => $geofence->name,
                'type' => $geofence->type,
                'scope' => $geofence->scope ?? 'vehicle',
                'breach_type' => $geofence->breach_type,
                'is_active' => $geofence->is_active,
                'shape' => $geofence->shape,
                'time_rules' => $geofence->time_rules,
                'alert_config' => $geofence->alert_config,
                'asset_id' => $geofence->asset_id,
                'site_id' => $geofence->site_id,
            ],
            'assets' => $assets,
            'sites' => $sites,
        ]);
    }

    public function update(Request $request, AssetGeofence $geofence)
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:circle,polygon'],
            'scope' => ['required', 'string', 'in:vehicle,resident'],
            'shape' => ['required', 'array', new GeofenceShape],
            'breach_type' => ['required', 'string', 'in:enter,exit,both'],
            'alert_config' => ['nullable', 'array'],
            'time_rules' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $geofence->update($data);

        AuditLogger::log('fleet.geofence.update', $geofence, [
            'geofence_id' => $geofence->id,
        ]);

        return redirect()->route('fleet-assets.geofences.index')
            ->with('success', 'Geofence updated successfully.');
    }

    public function toggleActive(Request $request, AssetGeofence $geofence)
    {
        // If deactivating, clean up state for vehicles currently inside
        if ($geofence->is_active) {
            $this->cleanupGeofenceStates($geofence);
        }

        $geofence->update(['is_active' => !$geofence->is_active]);

        AuditLogger::log('fleet.geofence.toggle', $geofence, [
            'geofence_id' => $geofence->id,
            'is_active' => $geofence->is_active,
        ]);

        return back()->with('success', 'Geofence status updated.');
    }

    public function destroy(Request $request, AssetGeofence $geofence)
    {
        // Emit exit signals for vehicles currently inside and clean up state
        $this->cleanupGeofenceStates($geofence);

        AuditLogger::log('fleet.geofence.delete', $geofence, [
            'geofence_id' => $geofence->id,
            'asset_id' => $geofence->asset_id,
        ]);

        $geofence->delete();

        return redirect()->route('fleet-assets.geofences.index')
            ->with('success', 'Geofence deleted.');
    }

    private function cleanupGeofenceStates(AssetGeofence $geofence): void
    {
        app(GeofenceStateCleanupService::class)->cleanup($geofence);
    }
}
