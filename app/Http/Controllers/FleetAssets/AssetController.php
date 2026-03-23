<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AssetController extends Controller
{
    private function hasFleetFields(): bool
    {
        return Schema::hasColumn('assets', 'home_site_id');
    }

    public function index(Request $request)
    {
        $hasFleetFields = $this->hasFleetFields();

        $eagerLoads = ['site:id,name', 'categoryRef:id,name,slug', 'trackers' => fn ($q) => $q->where('status', 'paired')];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
        }

        $query = Asset::query()
            ->with($eagerLoads);

        // CSV export
        if ($request->input('export') === 'csv') {
            $allAssets = (clone $query)->orderBy('name')->limit(5000)->get();
            return response()->streamDownload(function () use ($allAssets) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Name', 'Asset Tag', 'Category', 'Status', 'Site', 'Manufacturer', 'Model', 'Serial Number']);
                foreach ($allAssets as $a) {
                    fputcsv($handle, [
                        $a->name, $a->asset_tag, $a->category, $a->status,
                        $a->site?->name ?? '', $a->manufacturer, $a->model, $a->serial_number,
                    ]);
                }
                fclose($handle);
            }, 'assets-export.csv');
        }

        // Category tab filter
        if ($request->filled('category') && $request->input('category') !== 'all') {
            $category = $request->input('category');
            $query->where(function ($q) use ($category) {
                $q->where('category', $category)
                    ->orWhereHas('categoryRef', fn ($sub) => $sub->where('slug', $category));
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Site filter
        if ($request->filled('site_id')) {
            $query->where('site_id', (int) $request->input('site_id'));
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        // Sorting
        $allowedSorts = ['name', 'asset_tag', 'status', 'category'];
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');
        if (!in_array($sort, $allowedSorts)) $sort = 'name';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';
        $query->orderBy($sort, $direction);

        $assets = $query->paginate(25)->withQueryString();

        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('fleet-assets/assets/index', [
            'assets' => [
                'data' => $assets->getCollection()->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'asset_tag' => $a->asset_tag,
                    'category' => $a->category,
                    'category_ref' => $a->categoryRef ? [
                        'id' => $a->categoryRef->id,
                        'name' => $a->categoryRef->name,
                        'slug' => $a->categoryRef->slug,
                    ] : null,
                    'status' => $a->status,
                    'site' => $a->site ? ['id' => $a->site->id, 'name' => $a->site->name] : null,
                    'home_site' => $hasFleetFields && $a->homeSite ? [
                        'id' => $a->homeSite->id,
                        'name' => $a->homeSite->name,
                    ] : null,
                    'manufacturer' => $a->manufacturer,
                    'model' => $a->model,
                    'serial_number' => $a->serial_number,
                    'tracker_count' => $a->trackers->count(),
                ])->values(),
                'links' => $assets->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $assets->currentPage(),
                    'last_page' => $assets->lastPage(),
                    'total' => $assets->total(),
                ],
            ],
            'sites' => $sites,
            'filters' => $request->only(['category', 'status', 'search', 'site_id']),
        ]);
    }

    public function show(Request $request, Asset $asset)
    {
        $hasFleetFields = $this->hasFleetFields();

        $eagerLoads = [
            'site:id,name',
            'client:id,first_name,last_name',
            'trackers',
            'inspections' => fn ($q) => $q->latest()->limit(20),
            'maintenanceLogs' => fn ($q) => $q->latest()->limit(20),
            'documents',
            'assignments' => fn ($q) => $q->latest()->limit(20),
            'ownerships' => fn ($q) => $q->latest(),
            'alerts' => fn ($q) => $q->latest()->limit(20),
            'geofences',
            'scanEvents' => fn ($q) => $q->latest()->limit(20),
            'fleetState',
            'workOrders' => fn ($q) => $q->latest()->limit(20),
            'checklistRuns' => fn ($q) => $q->latest()->limit(20),
            'checklistRuns.template',
            'serviceSchedules',
            'bookings' => fn ($q) => $q->latest()->limit(20),
            'categoryRef:id,name,slug',
        ];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
            $eagerLoads[] = 'primaryDriver';
        }

        $asset->load($eagerLoads);

        // Build lifecycle timeline from all events
        $timeline = collect();

        foreach ($asset->inspections as $item) {
            $timeline->push([
                'type' => 'inspection',
                'date' => optional($item->created_at)->toISOString(),
                'summary' => "Inspection #{$item->id}",
                'id' => $item->id,
            ]);
        }

        foreach ($asset->maintenanceLogs as $item) {
            $timeline->push([
                'type' => 'maintenance',
                'date' => optional($item->created_at)->toISOString(),
                'summary' => "Maintenance log #{$item->id}",
                'id' => $item->id,
            ]);
        }

        foreach ($asset->scanEvents as $item) {
            $timeline->push([
                'type' => 'scan',
                'date' => optional($item->created_at)->toISOString(),
                'summary' => "QR scan by user #{$item->user_id}",
                'id' => $item->id,
            ]);
        }

        foreach ($asset->workOrders as $item) {
            $timeline->push([
                'type' => 'work_order',
                'date' => optional($item->created_at)->toISOString(),
                'summary' => "Work order: {$item->title}",
                'id' => $item->id,
            ]);
        }

        foreach ($asset->alerts as $item) {
            $timeline->push([
                'type' => 'alert',
                'date' => optional($item->triggered_at)->toISOString(),
                'summary' => "{$item->alert_type} ({$item->severity})",
                'id' => $item->id,
            ]);
        }

        $timeline = $timeline->sortByDesc('date')->values()->take(50);

        return Inertia::render('fleet-assets/assets/show', [
            'asset' => $asset,
            'timeline' => $timeline,
        ]);
    }

    public function create(Request $request)
    {
        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = AssetCategory::orderBy('name')->get(['id', 'name', 'slug']);

        return Inertia::render('fleet-assets/assets/create', [
            'sites' => $sites,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'asset_category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'home_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'registration_expires_at' => ['nullable', 'date'],
            'wof_expires_at' => ['nullable', 'date'],
            'cof_expires_at' => ['nullable', 'date'],
            'fuel_type' => ['nullable', 'string', 'in:petrol,diesel,electric,hybrid,lpg'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'risk_level' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'requires_inspection' => ['boolean'],
            'inspection_due_at' => ['nullable', 'date'],
            'requires_maintenance' => ['boolean'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $fleetFields = ['home_site_id', 'registration_number', 'registration_expires_at', 'wof_expires_at', 'cof_expires_at', 'fuel_type', 'odometer_km'];
        if (!$this->hasFleetFields()) {
            $data = collect($data)->except($fleetFields)->toArray();
        }

        $data['created_by_user_id'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'active';

        $asset = Asset::create($data);

        AuditLogger::log('assets.create', $asset, [
            'asset_id' => $asset->id,
            'name' => $asset->name,
        ]);

        return redirect()->route('fleet-assets.assets.show', $asset)
            ->with('success', 'Asset created successfully.');
    }

    public function edit(Request $request, Asset $asset)
    {
        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = AssetCategory::orderBy('name')->get(['id', 'name', 'slug']);

        return Inertia::render('fleet-assets/assets/edit', [
            'asset' => $asset,
            'sites' => $sites,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'asset_category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'home_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'registration_expires_at' => ['nullable', 'date'],
            'wof_expires_at' => ['nullable', 'date'],
            'cof_expires_at' => ['nullable', 'date'],
            'fuel_type' => ['nullable', 'string', 'in:petrol,diesel,electric,hybrid,lpg'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'risk_level' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'requires_inspection' => ['boolean'],
            'inspection_due_at' => ['nullable', 'date'],
            'requires_maintenance' => ['boolean'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $fleetFields = ['home_site_id', 'registration_number', 'registration_expires_at', 'wof_expires_at', 'cof_expires_at', 'fuel_type', 'odometer_km'];
        if (!$this->hasFleetFields()) {
            $data = collect($data)->except($fleetFields)->toArray();
        }

        $data['updated_by_user_id'] = $request->user()->id;

        $asset->update($data);

        AuditLogger::log('assets.update', $asset, [
            'asset_id' => $asset->id,
        ]);

        return redirect()->route('fleet-assets.assets.show', $asset)
            ->with('success', 'Asset updated successfully.');
    }
}
