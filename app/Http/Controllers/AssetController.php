<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Client;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned')), 403);

        $q = Asset::query()
            ->with([
                'site:id,name',
                'client:id,first_name,last_name,site_id',
            ])
            ->orderByDesc('updated_at');

        // Scope for assigned-only users
        if (!$user->canDo('assets.viewAny') && $user->canDo('assets.viewAssigned') && $user->hasRole('support_worker')) {
            $q->where(function ($w) use ($user) {
                $w->whereHas('client.supportWorkers', fn ($sq) => $sq->whereKey($user->id))
                  ->orWhereHas('site.clients.supportWorkers', fn ($sq) => $sq->whereKey($user->id));
            });
        }

        if ($request->filled('site_id')) {
            $q->where('site_id', (int) $request->input('site_id'));
        }

        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->input('client_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $q->where('status', $request->input('status'));
        }

        if ($request->filled('risk') && $request->input('risk') !== 'all') {
            $q->where('risk_level', $request->input('risk'));
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('asset_tag', 'like', "%{$s}%")
                  ->orWhere('serial_number', 'like', "%{$s}%")
                  ->orWhere('manufacturer', 'like', "%{$s}%")
                  ->orWhere('model', 'like', "%{$s}%");
            });
        }

        $assets = $q->paginate(25)->withQueryString();

        $sites = Site::query()->orderBy('name')->get(['id', 'name']);
        $clients = Client::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'site_id']);

        AuditLogger::log('assets.list', null, [
            'filters' => $request->only(['site_id', 'client_id', 'status', 'risk', 'search']),
        ]);

        return inertia('assets/index', [
            'assets' => $assets,
            'sites' => $sites,
            'clients' => $clients,
            'filters' => $request->only(['site_id', 'client_id', 'status', 'risk', 'search']),
            'can' => [
                'create' => $user->canDo('assets.create'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('assets.create'), 403);

        $sites = Site::query()->orderBy('name')->get(['id', 'name']);
        $clients = Client::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'site_id']);
        $categories = AssetCategory::query()->orderBy('name')->get(['id', 'name']);

        return inertia('assets/create', [
            'sites' => $sites,
            'clients' => $clients,
            'categories' => $categories,
            'prefill' => [
                'site_id' => $request->integer('site_id') ?: null,
                'client_id' => $request->integer('client_id') ?: null,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('assets.create'), 403);

        $data = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'asset_category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'description' => ['nullable', 'string'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'status' => ['required', 'in:active,out_of_service,retired'],
            'risk_level' => ['required', 'in:low,medium,high'],
            'location' => ['nullable', 'string', 'max:255'],
            'requires_inspection' => ['boolean'],
            'inspection_due_at' => ['nullable', 'date'],
            'requires_maintenance' => ['boolean'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        // If client_id is set, infer site_id from client unless explicitly set.
        if (!empty($data['client_id'])) {
            $client = Client::query()->select('id', 'site_id')->findOrFail($data['client_id']);
            $data['site_id'] = $client->site_id;
        }

        // Must belong to at least a site or client (site can come from client)
        if (empty($data['site_id']) && empty($data['client_id'])) {
            return back()->withErrors(['site_id' => 'Select a site or a client.']);
        }

        $data['created_by_user_id'] = $user->id;
        $data['updated_by_user_id'] = $user->id;

        $data['qr_token'] = $data['qr_token'] ?? Str::uuid()->toString();

        $asset = Asset::create($data);

        AuditLogger::log('assets.create', $asset, [
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return redirect()->route('assets.show', $asset);
    }

    public function show(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);

        $asset->load([
            'site:id,name',
            'client:id,first_name,last_name,site_id',
            'inspections.inspectedBy:id,name,email',
            'maintenanceLogs.performedBy:id,name,email',
            'documents.uploadedBy:id,name,email',
            'trackers',
            'alerts',
            'scanEvents',
            'geofences',
        ]);

        AuditLogger::log('assets.view', $asset, [
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        $user = $request->user();

        return inertia('assets/show', [
            'asset' => [
                'id' => $asset->id,
                'site' => $asset->site ? $asset->site->only(['id','name']) : null,
                'client' => $asset->client ? [
                    'id' => $asset->client->id,
                    'name' => trim($asset->client->first_name . ' ' . $asset->client->last_name),
                ] : null,
                'asset_tag' => $asset->asset_tag,
                'qr_token' => $asset->qr_token,
                'qr_png_url' => route('assets.qr.png', $asset),
                'qr_svg_url' => route('assets.qr.svg', $asset),
                'qr_download_url' => route('assets.qr.download', $asset),
                'name' => $asset->name,
                'category' => $asset->category,
                'description' => $asset->description,
                'manufacturer' => $asset->manufacturer,
                'model' => $asset->model,
                'serial_number' => $asset->serial_number,
                'purchase_date' => optional($asset->purchase_date)->toDateString(),
                'warranty_expires_at' => optional($asset->warranty_expires_at)->toDateString(),
                'status' => $asset->status,
                'risk_level' => $asset->risk_level,
                'location' => $asset->location,
                'requires_inspection' => (bool) $asset->requires_inspection,
                'inspection_due_at' => optional($asset->inspection_due_at)->toDateString(),
                'requires_maintenance' => (bool) $asset->requires_maintenance,
                'maintenance_due_at' => optional($asset->maintenance_due_at)->toDateString(),
                'notes' => $asset->notes,
                'created_at' => $asset->created_at?->toDateTimeString(),
                'updated_at' => $asset->updated_at?->toDateTimeString(),
            ],
            'inspections' => $asset->inspections->sortByDesc('inspected_at')->values()->map(fn($i)=>[
                'id'=>$i->id,
                'inspected_at'=>$i->inspected_at?->toDateTimeString(),
                'result'=>$i->result,
                'notes'=>$i->notes,
                'next_due_at'=>optional($i->next_due_at)->toDateString(),
                'inspected_by'=>$i->inspectedBy?->only(['id','name','email']),
            ]),
            'maintenance' => $asset->maintenanceLogs->sortByDesc('performed_at')->values()->map(fn($m)=>[
                'id'=>$m->id,
                'performed_at'=>$m->performed_at?->toDateTimeString(),
                'type'=>$m->type,
                'vendor'=>$m->vendor,
                'cost'=>$m->cost,
                'notes'=>$m->notes,
                'next_due_at'=>optional($m->next_due_at)->toDateString(),
                'performed_by'=>$m->performedBy?->only(['id','name','email']),
            ]),
            'documents' => $asset->documents->sortByDesc('created_at')->values()->map(fn($d)=>[
                'id'=>$d->id,
                'title'=>$d->title,
                'category'=>$d->category,
                'version'=>$d->version,
                'effective_date'=>optional($d->effective_date)->toDateString(),
                'expiry_date'=>optional($d->expiry_date)->toDateString(),
                'notes'=>$d->notes,
                'original_name'=>$d->original_name,
                'mime_type'=>$d->mime_type,
                'size_bytes'=>$d->size_bytes,
                'uploaded_by'=>$d->uploadedBy?->only(['id','name','email']),
                'download_url'=>route('assets.documents.download', [$asset, $d]),
            ]),
            'trackers' => $asset->trackers->sortByDesc('paired_at')->values()->map(fn($t) => [
                'id' => $t->id,
                'vendor' => $t->vendor,
                'device_uid' => $t->device_uid,
                'status' => $t->status,
                'paired_at' => $t->paired_at?->toDateTimeString(),
                'unpaired_at' => $t->unpaired_at?->toDateTimeString(),
                'last_seen_at' => $t->last_seen_at?->toDateTimeString(),
                'consent_id' => $t->consent_id,
            ]),
            'alerts' => $asset->alerts->sortByDesc('triggered_at')->values()->take(5)->map(fn($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => $a->triggered_at?->toDateTimeString(),
                'resolved_at' => $a->resolved_at?->toDateTimeString(),
            ]),
            'scan_events' => $asset->scanEvents->sortByDesc('scanned_at')->values()->take(5)->map(fn($s) => [
                'id' => $s->id,
                'qr_token' => $s->qr_token,
                'scanned_at' => $s->scanned_at?->toDateTimeString(),
            ]),
            'geofences' => $asset->geofences->values()->map(fn($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'breach_type' => $g->breach_type,
                'is_active' => (bool) $g->is_active,
            ]),
            'can' => [
                'update' => $user?->canDo('assets.update') ? ($user?->can('update', $asset) ?? false) : false,
                'delete' => $user?->canDo('assets.delete') ? ($user?->can('delete', $asset) ?? false) : false,
                'recordInspection' => $user?->can('recordInspection', $asset) ?? false,
                'recordMaintenance' => $user?->can('recordMaintenance', $asset) ?? false,
                'manageDocuments' => $user?->can('manageDocuments', $asset) ?? false,
                'downloadQr' => ($user?->canDo('assets.qr.download') ?? false) && ($user?->can('view', $asset) ?? false),
                'manageTrackers' => $user?->canDo('assets.trackers.manage') ?? false,
                'manageGeofences' => $user?->canDo('assets.geofences.manage') ?? false,
                'manageAlerts' => $user?->canDo('assets.alerts.manage') ?? false,
            ],
        ]);
    }

    public function edit(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $sites = Site::query()->orderBy('name')->get(['id', 'name']);
        $clients = Client::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'site_id']);
        $categories = AssetCategory::query()->orderBy('name')->get(['id', 'name']);

        return inertia('assets/edit', [
            'asset' => [
                'id'=>$asset->id,
                'site_id'=>$asset->site_id,
                'client_id'=>$asset->client_id,
                'asset_tag'=>$asset->asset_tag,
                'name'=>$asset->name,
                'category'=>$asset->category,
                'asset_category_id'=>$asset->asset_category_id,
                'description'=>$asset->description,
                'manufacturer'=>$asset->manufacturer,
                'model'=>$asset->model,
                'serial_number'=>$asset->serial_number,
                'purchase_date'=>optional($asset->purchase_date)->toDateString(),
                'warranty_expires_at'=>optional($asset->warranty_expires_at)->toDateString(),
                'status'=>$asset->status,
                'risk_level'=>$asset->risk_level,
                'location'=>$asset->location,
                'requires_inspection'=>(bool)$asset->requires_inspection,
                'inspection_due_at'=>optional($asset->inspection_due_at)->toDateString(),
                'requires_maintenance'=>(bool)$asset->requires_maintenance,
                'maintenance_due_at'=>optional($asset->maintenance_due_at)->toDateString(),
                'notes'=>$asset->notes,
            ],
            'sites'=>$sites,
            'clients'=>$clients,
            'categories'=>$categories,
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'asset_category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'description' => ['nullable', 'string'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'status' => ['required', 'in:active,out_of_service,retired'],
            'risk_level' => ['required', 'in:low,medium,high'],
            'location' => ['nullable', 'string', 'max:255'],
            'requires_inspection' => ['boolean'],
            'inspection_due_at' => ['nullable', 'date'],
            'requires_maintenance' => ['boolean'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (!empty($data['client_id'])) {
            $client = Client::query()->select('id', 'site_id')->findOrFail($data['client_id']);
            $data['site_id'] = $client->site_id;
        }

        if (empty($data['site_id']) && empty($data['client_id'])) {
            return back()->withErrors(['site_id' => 'Select a site or a client.']);
        }

        $data['updated_by_user_id'] = $request->user()?->id;

        $asset->update($data);

        AuditLogger::log('assets.update', $asset, [
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return redirect()->route('assets.show', $asset);
    }

    public function destroy(Request $request, Asset $asset)
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        AuditLogger::log('assets.delete', $asset, [
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return redirect()->route('assets.index');
    }
}
