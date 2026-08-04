<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetDocument;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\HrAssetAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    /** Equipment whose lifecycle remains in HR. */
    private const HR_CATEGORIES = ['uniform', 'card', 'other'];

    /** Historic HR rows remain editable, but new technology lives in Security & Devices. */
    private const LEGACY_DEVICE_CATEGORIES = ['laptop', 'phone', 'tablet'];

    private const FLEET_CATEGORIES = ['vehicle', 'key'];

    private const DOC_CATEGORIES = ['manual', 'certificate', 'photo', 'invoice', 'handover'];

    /** Stored-XSS guard: only these document mimes may be uploaded. */
    private const DOC_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp', 'image/heic',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        protected AssetService $assetService,
        protected HrAssetAccessService $assetAccess,
    ) {}

    /* ================================================================== */
    /*  Hub */
    /* ================================================================== */

    /**
     * The Asset Management hub — a single tabbed surface (Overview · Inventory ·
     * Assignments · Maintenance & Docs) rendered from access-approved data.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);

        $canManage = $user->canDo('hr.assets.manage');
        $visibleAssets = $this->assetAccess->visibleAssets($user);

        $assets = (clone $visibleAssets)
            ->with([
                'currentAssignment.employeeProfile.user:id,name',
                'currentAssignment.employeeProfile.primarySite:id,name',
                'fleetAsset:id,name,asset_tag,registration_number,status',
                'openMaintenanceLog',
            ])
            ->orderBy('asset_tag')
            ->get();

        $inventory = $assets->map(fn (HrAsset $a) => $this->mapInventoryRow($a))->values();

        $activeAssignments = $this->assetAccess->visibleAssignments($user)
            ->whereNull('returned_at')
            ->whereHas('asset', fn ($q) => $q->where('status', 'assigned'))
            ->with([
                'asset:id,asset_tag,name,category,fleet_asset_id',
                'employeeProfile.user:id,name',
                'employeeProfile.primarySite:id,name',
            ])
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn (HrAssetAssignment $asg) => $this->mapAssignmentRow($asg))
            ->values();

        $maintenanceJobs = $this->assetAccess->visibleMaintenanceLogs($user)
            ->whereNull('completed_at')
            ->with('asset:id,asset_tag,name,category')
            ->orderByDesc('sent_at')
            ->get()
            ->map(fn (HrAssetMaintenanceLog $log) => [
                'id' => $log->id,
                'asset_id' => $log->asset_id,
                'asset_name' => $log->asset?->name,
                'asset_tag' => $log->asset?->asset_tag,
                'type' => $log->type,
                'vendor' => $log->vendor,
                'cost' => $log->cost !== null ? (float) $log->cost : null,
                'sent_at' => $log->sent_at?->toDateString(),
                'expected_back_at' => $log->expected_back_at?->toDateString(),
                'next_due_at' => $log->next_due_at?->toDateString(),
            ])
            ->values();

        $documents = $this->assetAccess->visibleDocuments($user)
            ->with(['asset:id,asset_tag,name', 'uploadedBy:id,name'])
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn (HrAssetDocument $doc) => [
                'id' => $doc->id,
                'asset_id' => $doc->asset_id,
                'asset_tag' => $doc->asset?->asset_tag,
                'title' => $doc->title,
                'category' => $doc->category,
                'effective_at' => $doc->effective_at?->toDateString(),
                'expiry_at' => $doc->expiry_at?->toDateString(),
                'uploaded_by' => $doc->uploadedBy?->name,
                'created_at' => $doc->created_at?->toDateString(),
            ])
            ->values();

        $hero = $this->assetService->aggregates(clone $visibleAssets);
        $hero['site_count'] = count($this->assetAccess->accessibleSiteIds($user));

        return Inertia::render('hr/assets/index', [
            'hero' => $hero,
            'inventory' => $inventory,
            'assignments' => $activeAssignments,
            'maintenance' => [
                'jobs' => $maintenanceJobs,
                'schedule' => $this->serviceSchedule($user),
                'documents' => $documents,
            ],
            'overview' => [
                'attention' => $this->attentionList($user),
                'activity' => $this->recentActivity($user),
            ],
            'staff' => $this->staffOptions($user),
            'categories' => $this->categoryOptions(),
            'filters' => [
                'tab' => $request->query('tab', 'overview'),
                'seg' => $request->query('seg', 'hr'),
                'search' => $request->query('search'),
            ],
            'can' => [
                'manage' => $canManage,
                'view_fleet' => (bool) ($user->canDo('assets.viewAny') || $user->canDo('fleet.viewAny')),
            ],
        ]);
    }

    /**
     * Asset detail — tabbed, with assignment history, maintenance, documents and a
     * unified activity timeline. All lifecycle actions run through hub modals.
     */
    public function show(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $asset->load([
            'currentAssignment.employeeProfile.user:id,name',
            'currentAssignment.employeeProfile.primarySite:id,name',
            'fleetAsset:id,name,asset_tag,registration_number,status',
            'assignments' => fn ($q) => $q->with([
                'employeeProfile.user:id,name',
                'assignedByUser:id,name',
            ])->orderByDesc('assigned_at'),
            'maintenanceLogs' => fn ($q) => $q->with('performedBy:id,name')->orderByDesc('sent_at'),
            'documents' => fn ($q) => $q->with('uploadedBy:id,name')->orderByDesc('created_at'),
        ]);

        // Fleet incident read routes are gated fleet.viewAny|assets.viewAny;
        // the fleet asset detail page itself is assets.viewAny|assets.viewAssigned.
        $canViewFleet = (bool) ($user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned'));
        $canViewFleetIncidents = (bool) ($user->canDo('fleet.viewAny') || $user->canDo('assets.viewAny'));

        $fleetIncidents = ($asset->isFleetLinked() && $canViewFleetIncidents && Schema::hasTable('fleet_incidents'))
            ? $asset->fleetIncidents()
                ->latest('occurred_at')
                ->limit(5)
                ->get(['id', 'reference_number', 'incident_type', 'description', 'severity', 'status', 'occurred_at'])
                ->map(fn (FleetIncident $i) => [
                    'id' => $i->id,
                    'reference' => $i->reference(),
                    'title' => ucfirst(str_replace('_', ' ', (string) $i->incident_type)),
                    'summary' => Str::limit((string) $i->description, 90),
                    'severity' => $i->severity,
                    'status' => $i->status,
                    'occurred_at' => $i->occurred_at?->toDateString(),
                ])
                ->values()
            : collect();

        return Inertia::render('hr/assets/show', [
            'asset' => $this->mapAssetDetail($asset),
            'staff' => $this->staffOptions($user),
            'categories' => $this->categoryOptions(),
            'fleetIncidents' => $fleetIncidents,
            'can' => [
                'manage' => $user->canDo('hr.assets.manage'),
                'view_fleet' => $canViewFleet,
                'view_fleet_incidents' => $canViewFleetIncidents,
            ],
        ]);
    }

    /* ================================================================== */
    /*  Asset CRUD */
    /* ================================================================== */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        abort_unless($this->assetAccess->canViewUnassigned($user), 403);

        $data = $this->validateAsset($request, $user, null);

        $asset = HrAsset::create([
            'status' => 'available',
            'qr_token' => (string) Str::uuid(),
            ...$data,
        ]);

        return redirect()->back()->with('success', "Asset {$asset->asset_tag} created.");
    }

    public function update(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $asset = DB::transaction(function () use ($request, $user, $asset): HrAsset {
            $lockedAsset = $this->assetAccess->visibleAsset($user, (int) $asset->id, true)
                ?? abort(404);
            $data = $this->validateAsset($request, $user, $lockedAsset->id);
            $lockedAsset->update($data);

            return $lockedAsset;
        });

        return redirect()->back()->with('success', "Asset {$asset->asset_tag} updated.");
    }

    /* ================================================================== */
    /*  Lifecycle */
    /* ================================================================== */

    public function assign(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer'],
            'assigned_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:assigned_at'],
            'condition_on_assign' => ['nullable', 'string', 'in:good,fair,poor'],
            'acknowledged' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($user, $asset, $data): void {
                $lockedAsset = $this->assetAccess->visibleAsset($user, (int) $asset->id, true)
                    ?? abort(404);
                $lockedProfile = $this->assetAccess->assignableProfile(
                    $user,
                    (int) $data['employee_profile_id'],
                    true,
                ) ?? abort(404);

                $this->assetService->assignAsset($lockedAsset, $lockedProfile, [
                    'assigned_by' => $user->id,
                    'assigned_at' => $data['assigned_at'],
                    'due_at' => $data['due_at'] ?? null,
                    'condition_on_assign' => $data['condition_on_assign'] ?? null,
                    'acknowledged_at' => ! empty($data['acknowledged']) ? now() : null,
                    'notes' => $data['notes'] ?? null,
                ]);
            });
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset assigned.');
    }

    public function returnAsset(Request $request, HrAssetAssignment $assignment)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $assignment = $this->assetAccess->visibleAssignment($user, (int) $assignment->id) ?? abort(404);

        $data = $request->validate([
            'returned_at' => ['required', 'date'],
            'condition_on_return' => ['nullable', 'string', 'in:good,fair,poor'],
            'damaged' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            // A damaged/lost return parks the asset in maintenance so the follow-up
            // repair or write-off has somewhere to land; otherwise it's available.
            DB::transaction(function () use ($user, $assignment, $data): void {
                $lockedAssignment = $this->assetAccess->visibleAssignment(
                    $user,
                    (int) $assignment->id,
                    true,
                ) ?? abort(404);

                $this->assetService->returnAsset($lockedAssignment, [
                    'returned_at' => $data['returned_at'],
                    'condition_on_return' => $data['condition_on_return'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'next_status' => ! empty($data['damaged']) ? 'maintenance' : 'available',
                ]);
            });
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset returned.');
    }

    public function logMaintenance(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:service,repair,cleaning,calibration'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'sent_at' => ['nullable', 'date'],
            'expected_back_at' => ['nullable', 'date'],
            'next_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($user, $asset, $data): void {
                $lockedAsset = $this->assetAccess->visibleAsset($user, (int) $asset->id, true)
                    ?? abort(404);
                $this->assetService->logMaintenance($lockedAsset, [
                    ...$data,
                    'performed_by' => $user->id,
                ]);
            });
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Repair logged.');
    }

    public function returnToService(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'in:repaired,replaced,no-fault'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'condition' => ['nullable', 'string', 'in:good,fair,poor'],
            'next_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($user, $asset, $data): void {
                $lockedAsset = $this->assetAccess->visibleAsset($user, (int) $asset->id, true)
                    ?? abort(404);
                $this->assetService->returnToService($lockedAsset, $data);
            });
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset returned to service.');
    }

    public function retire(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $data = $request->validate([
            'disposal_reason' => ['nullable', 'string', 'in:end-of-life,lost,stolen,sold,damaged'],
            'disposed_at' => ['nullable', 'date'],
            'disposal_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($user, $asset, $data): void {
                $lockedAsset = $this->assetAccess->visibleAsset($user, (int) $asset->id, true)
                    ?? abort(404);
                $this->assetService->retireAsset($lockedAsset, $data);
            });
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset retired.');
    }

    /* ================================================================== */
    /*  Documents */
    /* ================================================================== */

    public function storeDocument(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', self::DOC_CATEGORIES)],
            'effective_at' => ['nullable', 'date'],
            'expiry_at' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:20480', 'mimetypes:'.implode(',', self::DOC_MIMES)],
        ]);

        $file = $request->file('file');
        $path = $file->store("hr-assets/{$asset->id}", 'local');

        HrAssetDocument::create([
            'asset_id' => $asset->id,
            'title' => $data['title'],
            'category' => $data['category'],
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'effective_at' => $data['effective_at'] ?? null,
            'expiry_at' => $data['expiry_at'] ?? null,
            'uploaded_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function downloadDocument(Request $request, HrAssetDocument $document): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);
        $document = $this->assetAccess->visibleDocument($user, (int) $document->id) ?? abort(404);

        $disk = Storage::disk($document->storage_disk);
        abort_unless($disk->exists($document->storage_path), 404);

        // Force download (never inline) to neutralise stored-XSS in user uploads.
        return $disk->download(
            $document->storage_path,
            $document->original_name ?: $document->title,
            ['Content-Type' => 'application/octet-stream'],
        );
    }

    public function destroyDocument(Request $request, HrAssetDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);
        $document = $this->assetAccess->visibleDocument($user, (int) $document->id) ?? abort(404);

        Storage::disk($document->storage_disk)->delete($document->storage_path);
        $document->delete();

        return redirect()->back()->with('success', 'Document removed.');
    }

    /* ================================================================== */
    /*  QR */
    /* ================================================================== */

    /** Scan-to-open: resolve a token to its asset detail page. */
    public function qrRedirect(Request $request, string $token)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);

        $asset = $this->assetAccess->visibleAssets($user)
            ->where('qr_token', $token)
            ->firstOrFail();

        return redirect()->route('hr.assets.show', $asset);
    }

    /** Printable QR label (SVG) encoding the scan-to-open URL. */
    public function qrSvg(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);
        $asset = $this->assetAccess->visibleAsset($user, (int) $asset->id) ?? abort(404);

        $token = $this->assetService->ensureQrToken($asset);
        $url = route('hr.assets.qr.redirect', ['token' => $token]);

        $result = (new Builder(
            writer: new SvgWriter,
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 512,
            margin: 8,
        ))->build();

        return Response::make($result->getString(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /* ================================================================== */
    /*  Bulk + export + federation */
    /* ================================================================== */

    public function bulk(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:retire,set-category,label'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'category' => ['nullable', 'string', 'in:'.implode(',', self::HR_CATEGORIES)],
            'disposal_reason' => ['nullable', 'string', 'in:end-of-life,lost,stolen,sold,damaged'],
        ]);

        $count = DB::transaction(function () use ($user, $data): int {
            $ids = collect($data['ids'])->unique()->values();
            $assets = $this->assetAccess->visibleAssets($user)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();
            abort_unless($assets->count() === $ids->count(), 404);
            $updated = 0;

            foreach ($assets as $asset) {
                // Fleet-linked rows are read-through pointers — never bulk-mutated here.
                if ($asset->isFleetLinked()) {
                    continue;
                }

                if ($data['action'] === 'retire') {
                    if (in_array($asset->status, ['available', 'maintenance'], true)) {
                        $this->assetService->retireAsset($asset, [
                            'disposal_reason' => $data['disposal_reason'] ?? 'end-of-life',
                        ]);
                        $updated++;
                    }
                } elseif ($data['action'] === 'set-category' && ! empty($data['category'])) {
                    $asset->update(['category' => $data['category']]);
                    $updated++;
                } elseif ($data['action'] === 'label') {
                    $this->assetService->ensureQrToken($asset);
                    $updated++;
                }
            }

            return $updated;
        });

        return redirect()->back()->with('success', "{$count} assets updated.");
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);

        $assets = $this->assetAccess->visibleAssets($user)
            ->with('currentAssignment.employeeProfile.user:id,name')
            ->orderBy('asset_tag')
            ->get();

        $filename = 'hr-assets-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($assets) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Tag', 'Name', 'Category', 'Status', 'Make', 'Model', 'Serial',
                'Purchase cost (NZD)', 'Supplier', 'Warranty expiry', 'Assignee', 'Fleet-linked',
            ]);
            foreach ($assets as $a) {
                $this->putCsv($out, [
                    $a->asset_tag,
                    $a->name,
                    $a->category,
                    $a->status,
                    $a->make,
                    $a->model,
                    $a->serial_number,
                    $a->purchase_cost,
                    $a->supplier,
                    $a->warranty_expiry?->toDateString(),
                    $a->currentAssignment?->employeeProfile?->user?->name,
                    $a->fleet_asset_id ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Search the canonical Fleet register for a vehicle/key to link (federation). */
    public function fleetSearch(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $results = $this->assetService->searchFleetAssets(
            (string) $request->query('q', ''),
            $this->assetAccess->authorizedFleetAssetIds($user),
        );

        return response()->json(['data' => $results]);
    }

    /* ================================================================== */
    /*  Mapping helpers */
    /* ================================================================== */

    private function mapInventoryRow(HrAsset $a): array
    {
        $assignment = $a->currentAssignment;
        $profile = $assignment?->employeeProfile;
        $isLeaver = $profile !== null && $profile->is_active === false;
        $dueAt = $assignment?->due_at;
        $overdue = $dueAt !== null && $dueAt->isPast() && $assignment?->returned_at === null;

        return [
            'id' => $a->id,
            'tag' => $a->asset_tag,
            'name' => $a->name,
            'category' => $a->category,
            'status' => $a->status,
            'make' => $a->make,
            'model' => $a->model,
            'serial' => $a->serial_number,
            'cost' => $a->purchase_cost !== null ? (float) $a->purchase_cost : null,
            'warranty' => $a->warranty_expiry?->toDateString(),
            'supplier' => $a->supplier,
            'site' => $profile?->primarySite?->name,
            'fleet' => $a->fleet_asset_id !== null,
            'fleet_asset_id' => $a->fleet_asset_id,
            'assignment_id' => $assignment?->id,
            'assignee' => $profile?->user?->name,
            'role' => $profile?->position_title,
            'since' => $assignment?->assigned_at?->toDateString(),
            'due_by' => $dueAt?->toDateString(),
            'overdue' => $overdue,
            'leaver' => $isLeaver,
        ];
    }

    private function mapAssignmentRow(HrAssetAssignment $asg): array
    {
        $profile = $asg->employeeProfile;
        $isLeaver = $profile !== null && $profile->is_active === false;

        return [
            'assignment_id' => $asg->id,
            'asset_id' => $asg->asset_id,
            'tag' => $asg->asset?->asset_tag,
            'name' => $asg->asset?->name,
            'category' => $asg->asset?->category,
            'fleet' => $asg->asset?->fleet_asset_id !== null,
            'assignee' => $profile?->user?->name ?? '—',
            'role' => $profile?->position_title,
            'site' => $profile?->primarySite?->name,
            'since' => $asg->assigned_at?->toDateString(),
            'due_by' => $asg->due_at?->toDateString(),
            'overdue' => $asg->isOverdue(),
            'acknowledged' => $asg->acknowledged_at !== null,
            'leaver' => $isLeaver,
        ];
    }

    private function mapAssetDetail(HrAsset $a): array
    {
        return [
            'id' => $a->id,
            'tag' => $a->asset_tag,
            'name' => $a->name,
            'category' => $a->category,
            'status' => $a->status,
            'make' => $a->make,
            'model' => $a->model,
            'serial' => $a->serial_number,
            'cost' => $a->purchase_cost !== null ? (float) $a->purchase_cost : null,
            'supplier' => $a->supplier,
            'purchase_date' => $a->purchase_date?->toDateString(),
            'warranty' => $a->warranty_expiry?->toDateString(),
            'condition' => $a->condition,
            'depreciation_method' => $a->depreciation_method,
            'useful_life_years' => $a->useful_life_years,
            'qr_token' => $a->qr_token,
            'fleet' => $a->fleet_asset_id !== null,
            'fleet_asset' => $a->fleetAsset ? [
                'id' => $a->fleetAsset->id,
                'name' => $a->fleetAsset->name,
                'asset_tag' => $a->fleetAsset->asset_tag,
                'registration_number' => $a->fleetAsset->registration_number,
                'status' => $a->fleetAsset->status,
            ] : null,
            'notes' => $a->notes,
            'disposal_reason' => $a->disposal_reason,
            'disposed_at' => $a->disposed_at?->toDateString(),
            'disposal_value' => $a->disposal_value !== null ? (float) $a->disposal_value : null,
            'current_assignment' => $a->currentAssignment ? [
                'assignment_id' => $a->currentAssignment->id,
                'assignee' => $a->currentAssignment->employeeProfile?->user?->name,
                'role' => $a->currentAssignment->employeeProfile?->position_title,
                'since' => $a->currentAssignment->assigned_at?->toDateString(),
                'due_by' => $a->currentAssignment->due_at?->toDateString(),
            ] : null,
            'assignments' => $a->assignments->map(fn (HrAssetAssignment $asg) => [
                'id' => $asg->id,
                'assignee' => $asg->employeeProfile?->user?->name,
                'assigned_at' => $asg->assigned_at?->toDateString(),
                'returned_at' => $asg->returned_at?->toDateString(),
                'due_at' => $asg->due_at?->toDateString(),
                'condition_on_assign' => $asg->condition_on_assign,
                'condition_on_return' => $asg->condition_on_return,
                'assigned_by' => $asg->assignedByUser?->name,
            ])->values(),
            'maintenance_logs' => $a->maintenanceLogs->map(fn (HrAssetMaintenanceLog $log) => [
                'id' => $log->id,
                'type' => $log->type,
                'vendor' => $log->vendor,
                'cost' => $log->cost !== null ? (float) $log->cost : null,
                'sent_at' => $log->sent_at?->toDateString(),
                'expected_back_at' => $log->expected_back_at?->toDateString(),
                'completed_at' => $log->completed_at?->toDateString(),
                'outcome' => $log->outcome,
                'notes' => $log->notes,
            ])->values(),
            'documents' => $a->documents->map(fn (HrAssetDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'category' => $doc->category,
                'effective_at' => $doc->effective_at?->toDateString(),
                'expiry_at' => $doc->expiry_at?->toDateString(),
                'uploaded_by' => $doc->uploadedBy?->name,
                'created_at' => $doc->created_at?->toDateString(),
            ])->values(),
        ];
    }

    /**
     * Upcoming service obligations — warranties expiring and scheduled next-service
     * dates, soonest first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function serviceSchedule(User $viewer): array
    {
        $now = CarbonImmutable::now()->startOfDay();
        $items = [];

        $this->assetAccess->visibleAssets($viewer)
            ->whereNotNull('warranty_expiry')
            ->where('status', '!=', 'retired')
            ->orderBy('warranty_expiry')
            ->limit(12)
            ->get(['id', 'name', 'asset_tag', 'warranty_expiry'])
            ->each(function (HrAsset $a) use (&$items, $now) {
                $days = $now->diffInDays($a->warranty_expiry, false);
                $items[] = [
                    'asset_id' => $a->id,
                    'name' => $a->name,
                    'tag' => $a->asset_tag,
                    'label' => 'Warranty expires '.$a->warranty_expiry->format('d M Y'),
                    'date' => $a->warranty_expiry->toDateString(),
                    'tone' => $days < 0 ? 'crit' : ($days <= 30 ? 'warn' : 'ok'),
                ];
            });

        $this->assetAccess->visibleMaintenanceLogs($viewer)
            ->whereNotNull('next_due_at')
            ->with('asset:id,name,asset_tag')
            ->orderBy('next_due_at')
            ->limit(12)
            ->get()
            ->each(function (HrAssetMaintenanceLog $log) use (&$items, $now) {
                if (! $log->asset) {
                    return;
                }
                $days = $now->diffInDays($log->next_due_at, false);
                $items[] = [
                    'asset_id' => $log->asset_id,
                    'name' => $log->asset->name,
                    'tag' => $log->asset->asset_tag,
                    'label' => 'Next service '.$log->next_due_at->format('d M Y'),
                    'date' => $log->next_due_at->toDateString(),
                    'tone' => $days < 0 ? 'crit' : ($days <= 30 ? 'warn' : 'ok'),
                ];
            });

        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_slice($items, 0, 8);
    }

    /**
     * The hero "needs you" / overview attention feed: overdue returns, leaver-held,
     * warranties expiring and open repairs.
     *
     * @return array<int,array<string,mixed>>
     */
    private function attentionList(User $viewer): array
    {
        $now = CarbonImmutable::now()->startOfDay();
        $items = [];

        $this->assetAccess->visibleAssignments($viewer)
            ->whereNull('returned_at')
            ->where(function ($q) use ($now) {
                $q->where('due_at', '<', $now)
                    ->orWhereHas('employeeProfile', fn ($p) => $p->where('is_active', false));
            })
            ->with(['asset:id,name,asset_tag', 'employeeProfile.user:id,name', 'employeeProfile:id,user_id,is_active'])
            ->orderBy('due_at')
            ->limit(8)
            ->get()
            ->each(function (HrAssetAssignment $asg) use (&$items, $now) {
                $leaver = $asg->employeeProfile && $asg->employeeProfile->is_active === false;
                $overdueDays = $asg->due_at ? $now->diffInDays($asg->due_at, false) : null;
                $items[] = [
                    'tag' => $asg->asset?->asset_tag,
                    'asset_id' => $asg->asset_id,
                    'text' => $asg->asset?->name.' · '.($leaver
                        ? 'held by leaver — recover'
                        : 'return overdue '.abs((int) $overdueDays).' days'),
                    'who' => $asg->employeeProfile?->user?->name ?? '—',
                    'tone' => 'crit',
                    'target' => 'assignments',
                ];
            });

        $this->assetAccess->visibleAssets($viewer)
            ->whereNotNull('warranty_expiry')
            ->whereBetween('warranty_expiry', [$now, $now->addDays(30)])
            ->where('status', '!=', 'retired')
            ->orderBy('warranty_expiry')
            ->limit(6)
            ->get(['id', 'name', 'asset_tag', 'warranty_expiry'])
            ->each(function (HrAsset $a) use (&$items) {
                $items[] = [
                    'tag' => $a->asset_tag,
                    'asset_id' => $a->id,
                    'text' => $a->name.' · warranty expires '.$a->warranty_expiry->format('d M'),
                    'who' => 'Warranty',
                    'tone' => 'warn',
                    'target' => 'inventory',
                ];
            });

        return array_slice($items, 0, 8);
    }

    /**
     * Recent lifecycle events drawn from assignments, repairs and documents.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentActivity(User $viewer): array
    {
        $events = [];

        $this->assetAccess->visibleAssignments($viewer)
            ->with(['asset:id,name', 'employeeProfile.user:id,name'])
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->each(function (HrAssetAssignment $asg) use (&$events) {
                $who = $asg->employeeProfile?->user?->name ?? 'staff';
                if ($asg->returned_at) {
                    $events[] = ['icon' => 'rotate', 'tone' => 'info', 'text' => "{$asg->asset?->name} returned by {$who}", 'at' => $asg->returned_at];
                } else {
                    $events[] = ['icon' => 'assign', 'tone' => 'primary', 'text' => "{$asg->asset?->name} assigned to {$who}", 'at' => $asg->assigned_at];
                }
            });

        $this->assetAccess->visibleMaintenanceLogs($viewer)
            ->with('asset:id,name')
            ->latest('updated_at')
            ->limit(6)
            ->get()
            ->each(function (HrAssetMaintenanceLog $log) use (&$events) {
                $events[] = $log->completed_at
                    ? ['icon' => 'check', 'tone' => 'success', 'text' => "{$log->asset?->name} returned to service", 'at' => $log->completed_at]
                    : ['icon' => 'wrench', 'tone' => 'warn', 'text' => "{$log->asset?->name} sent to ".($log->vendor ?: 'repair'), 'at' => $log->sent_at];
            });

        $this->assetAccess->visibleDocuments($viewer)
            ->with('asset:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->each(function (HrAssetDocument $doc) use (&$events) {
                $events[] = ['icon' => 'doc', 'tone' => 'fleet', 'text' => "{$doc->title} added", 'at' => $doc->created_at];
            });

        $events = array_values(array_filter($events, fn ($e) => $e['at'] !== null));
        usort($events, fn ($a, $b) => $b['at'] <=> $a['at']);

        return array_map(fn ($e) => [
            'icon' => $e['icon'],
            'tone' => $e['tone'],
            'text' => $e['text'],
            'at' => CarbonImmutable::parse($e['at'])->diffForHumans(),
        ], array_slice($events, 0, 8));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function staffOptions(User $viewer): array
    {
        return $this->assetAccess->assignableProfiles($viewer)
            ->with(['user:id,name', 'primarySite:id,name'])
            ->orderBy('id')
            ->get(['id', 'user_id', 'position_title', 'primary_site_id', 'is_active'])
            ->map(fn (HrEmployeeProfile $p) => [
                'id' => $p->id,
                'name' => $p->user?->name ?? 'Unnamed',
                'role' => $p->position_title,
                'site' => $p->primarySite?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function categoryOptions(): array
    {
        $labels = [
            'laptop' => 'Laptop',
            'phone' => 'Phone',
            'tablet' => 'Tablet',
            'uniform' => 'Uniform',
            'card' => 'Access card',
            'other' => 'Other',
            'vehicle' => 'Vehicle',
            'key' => 'Key',
        ];

        return collect($labels)->map(fn ($label, $value) => [
            'value' => $value,
            'label' => $label,
            'fleet' => in_array($value, self::FLEET_CATEGORIES, true),
            'device' => in_array($value, self::LEGACY_DEVICE_CATEGORIES, true),
        ])->values()->all();
    }

    /**
     * Shared validation for store + update. Vehicles/keys may only be created by
     * linking a canonical Fleet asset (federation) — never hand-typed.
     *
     * @return array<string,mixed>
     */
    private function validateAsset(Request $request, User $viewer, ?int $ignoreId): array
    {
        $allCategories = array_merge(
            self::HR_CATEGORIES,
            self::LEGACY_DEVICE_CATEGORIES,
            self::FLEET_CATEGORIES,
        );

        $data = $request->validate([
            'asset_tag' => [
                'required', 'string', 'max:100',
                Rule::unique('hr_assets', 'asset_tag')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', $allCategories)],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'warranty_expiry' => ['nullable', 'date'],
            'condition' => ['nullable', 'string', 'in:new,good,refurb'],
            'depreciation_method' => ['nullable', 'string', 'in:straight,diminishing'],
            'useful_life_years' => ['nullable', 'integer', 'min:0', 'max:50'],
            'fleet_asset_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Federation guard: a fleet category must point at a canonical record.
        if (in_array($data['category'], self::FLEET_CATEGORIES, true) && empty($data['fleet_asset_id'])) {
            abort(422, 'Vehicles and keys must be linked to the Fleet register, not entered manually.');
        }

        if (in_array($data['category'], self::FLEET_CATEGORIES, true)) {
            $allowedFleetIds = $this->assetAccess->authorizedFleetAssetIds($viewer);
            abort_unless(
                Asset::query()
                    ->whereKey((int) $data['fleet_asset_id'])
                    ->whereKey($allowedFleetIds)
                    ->where('category', $data['category'])
                    ->exists(),
                404,
            );
        } else {
            $data['fleet_asset_id'] = null;
        }

        if (in_array($data['category'], self::LEGACY_DEVICE_CATEGORIES, true)) {
            $existingCategory = $ignoreId === null
                ? null
                : HrAsset::query()->whereKey($ignoreId)->value('category');

            abort_unless(
                $existingCategory === $data['category'],
                422,
                'Laptops, phones, and tablets must be registered and assigned in Security & Devices.',
            );
        }

        return $data;
    }
}
