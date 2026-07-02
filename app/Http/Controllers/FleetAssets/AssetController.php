<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Client;
use App\Models\ClientEmergencyContact;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AssetController extends Controller
{
    private function mapAssetAssignment(AssetAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'assignee' => [
                'id' => $assignment->assignee_id,
                'name' => $this->resolveAssignmentAssigneeName($assignment),
            ],
            'assigned_at' => optional($assignment->assigned_at)->toISOString(),
            'returned_at' => optional($assignment->released_at)->toISOString(),
            'purpose' => $assignment->purpose,
        ];
    }

    private function resolveAssignmentAssigneeName(AssetAssignment $assignment): string
    {
        return match ($assignment->assignee_type) {
            'staff' => User::query()->whereKey($assignment->assignee_id)->value('name') ?? "Staff #{$assignment->assignee_id}",
            'client' => Client::query()
                ->whereKey($assignment->assignee_id)
                ->get(['first_name', 'last_name'])
                ->map(fn ($client) => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')))
                ->first() ?: "Client #{$assignment->assignee_id}",
            'whanau' => ClientEmergencyContact::query()->whereKey($assignment->assignee_id)->value('name') ?? "Whanau #{$assignment->assignee_id}",
            default => ucfirst((string) $assignment->assignee_type) . " #{$assignment->assignee_id}",
        };
    }

    private function hasFleetFields(): bool
    {
        return Schema::hasColumn('assets', 'home_site_id');
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
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
                $this->putCsv($handle, ['Name', 'Asset Tag', 'Category', 'Status', 'Site', 'Manufacturer', 'Model', 'Serial Number']);
                foreach ($allAssets as $a) {
                    $this->putCsv($handle, [
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

        // Hero stats — whole-register counts (independent of filters/pagination).
        $heroTotal = Asset::query()->count();
        $heroActive = Asset::query()->where('status', 'active')->count();
        $heroMaintenance = Asset::query()->whereIn('status', ['maintenance', 'out_of_service'])->count();
        $heroInspectionsDue = Schema::hasColumn('assets', 'inspection_due_at')
            ? Asset::query()
                ->whereNotNull('inspection_due_at')
                ->where('inspection_due_at', '<=', now()->addDays(30))
                ->count()
            : 0;

        return Inertia::render('fleet-assets/assets/index', [
            'hero' => [
                'total' => $heroTotal,
                'active' => $heroActive,
                'maintenance' => $heroMaintenance,
                'inspections_due' => $heroInspectionsDue,
            ],
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
        $hasDeviceAssetLinks = $this->hasTable('device_asset_links');
        $hasFleetVehicleStateSnapshots = $this->hasTable('fleet_vehicle_state_snapshots');
        $hasFleetVehicleBookings = $this->hasTable('fleet_vehicle_bookings');
        $hasFleetWorkOrders = $this->hasTable('fleet_work_orders');
        $hasFleetChecklistRuns = $this->hasTable('fleet_checklist_runs');
        $hasFleetChecklistTemplates = $this->hasTable('fleet_checklist_templates');
        $hasFleetServiceSchedules = $this->hasTable('fleet_service_schedules');

        $eagerLoads = [
            'site:id,name',
            'client:id,first_name,last_name',
            'inspections' => fn ($q) => $q->latest()->limit(20)->with('inspectedBy:id,name'),
            'maintenanceLogs' => fn ($q) => $q->latest()->limit(20),
            'documents',
            'assignments' => fn ($q) => $q->latest()->limit(20),
            'ownerships' => fn ($q) => $q->latest(),
            'alerts' => fn ($q) => $q->latest()->limit(20),
            'geofences',
            'scanEvents' => fn ($q) => $q->latest()->limit(20),
            'categoryRef:id,name,slug',
        ];

        if ($hasFleetVehicleStateSnapshots) {
            $eagerLoads[] = 'fleetState';
        }

        if ($hasFleetWorkOrders) {
            $eagerLoads['workOrders'] = fn ($q) => $q->latest()->limit(20)->with('assignedTo:id,name');
        }

        if ($hasFleetChecklistRuns) {
            $eagerLoads['checklistRuns'] = fn ($q) => $q->latest()->limit(20);

            if ($hasFleetChecklistTemplates) {
                $eagerLoads[] = 'checklistRuns.template';
            }
        }

        if ($hasFleetServiceSchedules) {
            $eagerLoads[] = 'serviceSchedules';
        }

        if ($hasFleetVehicleBookings) {
            $eagerLoads['bookings'] = fn ($q) => $q->latest()->limit(20);
        }

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

        if ($hasFleetWorkOrders) {
            foreach ($asset->workOrders as $item) {
                $timeline->push([
                    'type' => 'work_order',
                    'date' => optional($item->created_at)->toISOString(),
                    'summary' => "Work order: {$item->title}",
                    'id' => $item->id,
                ]);
            }
        }

        foreach ($asset->alerts as $item) {
            $timeline->push([
                'type' => 'alert',
                'date' => optional($item->triggered_at)->toISOString(),
                'summary' => "Legacy asset alert: {$item->alert_type} ({$item->severity})",
                'id' => $item->id,
            ]);
        }

        $timeline = $timeline->sortByDesc('date')->values()->take(50);
        $currentAssignment = $asset->assignments->first(fn ($assignment) => $assignment->released_at === null);

        // Federation: the HR-register wrapper (if any) so the fleet page can
        // point back at /hr/assets/{id} — link only for hr.assets.view holders.
        $hrAsset = $this->hasTable('hr_assets')
            ? $asset->hrAsset()->with('currentAssignment.employeeProfile.user:id,name')->first()
            : null;

        $safeAsset = [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'category' => $asset->category,
            'status' => $asset->status,
            'risk_level' => $asset->risk_level,
            'description' => $asset->description,
            'manufacturer' => $asset->manufacturer,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'location' => $asset->location,
            'site_id' => $asset->site_id,
            'site' => $asset->site ? ['id' => $asset->site->id, 'name' => $asset->site->name] : null,
            'client' => $asset->client ? ['id' => $asset->client->id, 'name' => trim(($asset->client->first_name ?? '') . ' ' . ($asset->client->last_name ?? ''))] : null,
            'category_ref' => $asset->categoryRef ? ['id' => $asset->categoryRef->id, 'name' => $asset->categoryRef->name, 'slug' => $asset->categoryRef->slug] : null,
            'registration_number' => $asset->registration_number ?? null,
            'fuel_type' => $asset->fuel_type ?? null,
            'odometer_km' => $asset->odometer_km ?? null,
            'home_site_id' => $asset->home_site_id ?? null,
            'home_site' => $asset->homeSite ? ['id' => $asset->homeSite->id, 'name' => $asset->homeSite->name] : null,
            'primary_driver' => $asset->primaryDriver ? ['id' => $asset->primaryDriver->id, 'name' => $asset->primaryDriver->name] : null,
            'purchase_date' => optional($asset->purchase_date)->toDateString(),
            'warranty_expires_at' => optional($asset->warranty_expires_at)->toDateString(),
            'requires_inspection' => (bool) $asset->requires_inspection,
            'inspection_due_at' => optional($asset->inspection_due_at)->toDateString(),
            'wof_expires_at' => optional($asset->wof_expires_at)->toDateString(),
            'registration_expires_at' => optional($asset->registration_expires_at)->toDateString(),
            'cof_expires_at' => optional($asset->cof_expires_at)->toDateString(),
            'insurance_expires_at' => optional($asset->insurance_expires_at)->toDateString(),
            'requires_maintenance' => (bool) $asset->requires_maintenance,
            'maintenance_due_at' => optional($asset->maintenance_due_at)->toDateString(),
            'notes' => $asset->notes,
            'trackers' => $hasDeviceAssetLinks
                ? \App\Domain\SecurityDevices\Models\DeviceAssetLink::query()
                    ->active()
                    ->forAsset($asset->id)
                    ->with('device:id,device_uid,name,status,health_status,provider,last_seen_at,battery_level,imei,serial_number')
                    ->get()
                    ->map(fn ($link) => [
                        'id' => $link->device?->id,
                        'device_uid' => $link->device?->device_uid,
                        'name' => $link->device?->name,
                        'vendor' => $link->device?->provider,
                        'status' => $link->device?->status?->value,
                        'health_status' => $link->device?->health_status?->value,
                        'last_seen_at' => $link->device?->last_seen_at?->toISOString(),
                        'battery_level' => $link->device?->battery_level,
                        'imei' => $link->device?->imei,
                        'serial_number' => $link->device?->serial_number,
                        'link_type' => $link->link_type?->value,
                        'linked_at' => $link->linked_at?->toISOString(),
                        'detail_url' => $link->device ? "/security-devices/devices/{$link->device->id}" : null,
                    ])
                    ->filter(fn ($t) => $t['id'] !== null)
                    ->values()
                : collect(),
            'fleet_state' => $hasFleetVehicleStateSnapshots && $asset->fleetState ? [
                'status' => $asset->fleetState->status,
                'latitude' => $asset->fleetState->latitude,
                'longitude' => $asset->fleetState->longitude,
                'speed_kph' => $asset->fleetState->speed_kph,
                'last_seen_at' => optional($asset->fleetState->last_seen_at)->toISOString(),
                'consent_blocked' => (bool) $asset->fleetState->consent_blocked,
            ] : null,
            'documents' => $asset->documents->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->title ?: ($d->original_name ?: 'Document'),
                'type' => $d->category ?: ($d->mime_type ?: 'document'),
                'uploaded_at' => optional($d->created_at)->toISOString(),
                'url' => "/assets/{$asset->id}/documents/{$d->id}/download",
            ])->values(),
            'inspections' => $asset->inspections->map(fn ($i) => [
                'id' => $i->id,
                'type' => 'Inspection',
                'result' => $i->result,
                'inspected_at' => optional($i->inspected_at ?? $i->created_at)->toISOString(),
                'inspector' => $i->inspectedBy?->name,
                'notes' => $i->notes,
            ])->values(),
            'work_orders' => $hasFleetWorkOrders
                ? $asset->workOrders->map(fn ($w) => [
                    'id' => $w->id,
                    'title' => $w->title,
                    'priority' => $w->priority,
                    'status' => $w->status,
                    'category' => $w->category,
                    'due_at' => optional($w->due_at)->toISOString(),
                    'assigned_to' => $w->assignedTo?->name,
                    'created_at' => optional($w->created_at)->toISOString(),
                ])->values()
                : collect(),
            'archived_alerts' => $asset->alerts->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => optional($a->triggered_at)->toISOString(),
                'resolved_at' => optional($a->resolved_at)->toISOString(),
            ])->values(),
            'geofences' => $asset->geofences->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'is_active' => $g->is_active,
            ])->values(),
            'current_assignment' => $currentAssignment ? $this->mapAssetAssignment($currentAssignment) : null,
            'assignments' => $asset->assignments->map(fn ($a) => $this->mapAssetAssignment($a))->values(),
            'ownerships' => $asset->ownerships->map(fn ($o) => [
                'id' => $o->id,
                'owner_type' => $o->owner_type ?? null,
                'started_at' => optional($o->started_at)->toISOString(),
            ])->values(),
            'service_schedules' => $hasFleetServiceSchedules
                ? $asset->serviceSchedules->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name ?? $s->service_type ?? null,
                    'interval_km' => $s->interval_km,
                    'interval_days' => $s->interval_days,
                    'last_completed_at' => optional($s->last_completed_at)->toISOString(),
                    'next_due_at' => optional($s->next_due_at)->toDateString(),
                ])->values()
                : collect(),
            'bookings' => $hasFleetVehicleBookings
                ? $asset->bookings->map(fn ($b) => [
                    'id' => $b->id,
                    'purpose' => $b->purpose,
                    'status' => $b->status,
                    'starts_at' => optional($b->starts_at)->toISOString(),
                    'ends_at' => optional($b->ends_at)->toISOString(),
                ])->values()
                : collect(),
            'checklist_runs' => $hasFleetChecklistRuns
                ? $asset->checklistRuns->map(fn ($c) => [
                    'id' => $c->id,
                    'passed' => $c->passed,
                    'template' => $hasFleetChecklistTemplates && $c->template ? ['id' => $c->template->id, 'name' => $c->template->name] : null,
                    'completed_at' => optional($c->completed_at)->toISOString(),
                ])->values()
                : collect(),
            'created_at' => optional($asset->created_at)->toISOString(),
            'updated_at' => optional($asset->updated_at)->toISOString(),
        ];

        return Inertia::render('fleet-assets/assets/show', [
            'asset' => $safeAsset,
            'timeline' => $timeline,
            'hr_asset' => $hrAsset ? [
                'id' => $hrAsset->id,
                'asset_tag' => $hrAsset->asset_tag,
                'status' => $hrAsset->status,
                'current_holder_name' => $hrAsset->currentAssignment?->employeeProfile?->user?->name,
            ] : null,
            'can_view_hr_assets' => (bool) $request->user()?->canDo('hr.assets.view'),
        ]);
    }

    public function create(Request $request)
    {
        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = AssetCategory::orderBy('name')->get(['id', 'name', 'slug']);
        $clients = Client::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'site_id']);

        return Inertia::render('fleet-assets/assets/create', [
            'sites' => $sites,
            'categories' => $categories,
            'clients' => $clients,
            'prefill' => [
                'site_id' => $request->integer('site_id') ?: null,
                'client_id' => $request->integer('client_id') ?: null,
                'category' => $request->string('category')->toString() ?: null,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
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
            'status' => ['required', 'in:active,out_of_service,retired'],
            'risk_level' => ['required', 'in:low,medium,high'],
            'location' => ['nullable', 'string', 'max:255'],
            'requires_inspection' => ['boolean'],
            'inspection_due_at' => ['nullable', 'date'],
            'requires_maintenance' => ['boolean'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // If a client was picked, derive the owning site from the client.
        if (!empty($data['client_id'])) {
            $client = Client::query()->select('id', 'site_id')->findOrFail($data['client_id']);
            $data['site_id'] = $client->site_id;
        }

        // Must belong to at least a site or client.
        if (empty($data['site_id']) && empty($data['client_id'])) {
            return back()->withErrors(['site_id' => 'Select a site or a client.'])->withInput();
        }

        $fleetFields = ['home_site_id', 'registration_number', 'registration_expires_at', 'wof_expires_at', 'cof_expires_at', 'fuel_type', 'odometer_km'];
        if (!$this->hasFleetFields()) {
            $data = collect($data)->except($fleetFields)->toArray();
        }

        $userId = $request->user()->id;
        $data['created_by_user_id'] = $userId;
        $data['updated_by_user_id'] = $userId;
        $data['status'] = $data['status'] ?? 'active';

        $asset = Asset::create($data);

        AuditLogger::log('assets.create', $asset, [
            'asset_id' => $asset->id,
            'name' => $asset->name,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return redirect()->route('fleet-assets.assets.show', $asset)
            ->with('success', 'Asset created successfully.');
    }

    public function edit(Request $request, Asset $asset)
    {
        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = AssetCategory::orderBy('name')->get(['id', 'name', 'slug']);

        $editableAsset = [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'category' => $asset->category,
            'asset_category_id' => $asset->asset_category_id,
            'status' => $asset->status,
            'description' => $asset->description,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
            'registration_number' => $asset->registration_number ?? null,
            'fuel_type' => $asset->fuel_type ?? null,
            'odometer_km' => $asset->odometer_km ?? null,
            'home_site_id' => $asset->home_site_id ?? null,
            'primary_driver_user_id' => $asset->primary_driver_user_id ?? null,
            'purchase_date' => optional($asset->purchase_date)->toDateString(),
            'purchase_price' => $asset->purchase_price,
            'warranty_expires_at' => optional($asset->warranty_expires_at)->toDateString(),
            'wof_expires_at' => optional($asset->wof_expires_at)->toDateString(),
            'registration_expires_at' => optional($asset->registration_expires_at)->toDateString(),
            'cof_expires_at' => optional($asset->cof_expires_at)->toDateString(),
            'insurance_expires_at' => optional($asset->insurance_expires_at)->toDateString(),
            'notes' => $asset->notes,
            'requires_maintenance' => $asset->requires_maintenance ?? false,
            'maintenance_due_at' => optional($asset->maintenance_due_at)->toDateString(),
        ];

        return Inertia::render('fleet-assets/assets/edit', [
            'asset' => $editableAsset,
            'sites' => $sites,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
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
            'status' => ['required', 'in:active,out_of_service,retired'],
            'risk_level' => ['required', 'in:low,medium,high'],
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
