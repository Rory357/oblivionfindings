<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Services\FixedAssetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FixedAssetController extends Controller
{
    public function __construct(
        protected FixedAssetService $assetService,
    ) {}

    /**
     * List fixed assets with filters and pagination.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinFixedAsset::class);

        $orgId = $request->user()->organization_id;

        $query = FinFixedAsset::forOrganization($orgId);

        if ($request->filled('category')) {
            $query->ofCategory($request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('asset_name', 'like', "%{$search}%")
                  ->orWhere('asset_tag', 'like', "%{$search}%");
            });
        }

        $assets = $query->withCount('depreciations')
            ->orderBy('asset_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FinFixedAsset $asset) => [
                'id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'asset_tag' => $asset->asset_tag,
                'category' => $asset->category,
                'purchase_date' => $asset->purchase_date?->toDateString(),
                'purchase_cost' => (string) $asset->purchase_cost,
                'accumulated_depreciation' => (string) $asset->accumulated_depreciation,
                'residual_value' => (string) $asset->residual_value,
                'useful_life_months' => $asset->useful_life_months,
                'depreciation_method' => $asset->depreciation_method,
                'status' => $asset->status,
                // Raw GL ids + depreciation flag so an active row can prefill the edit modal.
                'gl_asset_account_id' => $asset->gl_asset_account_id,
                'gl_depreciation_account_id' => $asset->gl_depreciation_account_id,
                'gl_expense_account_id' => $asset->gl_expense_account_id,
                'notes' => $asset->notes,
                'has_depreciations' => $asset->depreciations_count > 0,
            ]);

        // Summary totals
        $allAssets = FinFixedAsset::forOrganization($orgId)->get();
        $summary = [
            'total_count' => $allAssets->count(),
            'total_cost' => (float) $allAssets->sum('purchase_cost'),
            'total_depreciation' => (float) $allAssets->sum('accumulated_depreciation'),
            'net_book_value' => (float) ($allAssets->sum('purchase_cost') - $allAssets->sum('accumulated_depreciation')),
            'active_count' => $allAssets->where('status', 'active')->count(),
        ];

        $canManage = (bool) $request->user()->can('create', FinFixedAsset::class);

        return Inertia::render('finance/fixed-assets/Index', [
            'assets' => $assets,
            'summary' => $summary,
            'filters' => $request->only(['category', 'status', 'search']),
            'canManage' => $canManage,
            'assetAccounts' => $canManage ? $this->glAccounts($orgId, 'asset') : [],
            'expenseAccounts' => $canManage ? $this->glAccounts($orgId, 'expense') : [],
        ]);
    }

    /**
     * Stream the (filtered) fixed-asset list as a sanitised CSV. Honours the same
     * category/status/search filters as the index. Book value uses the model's
     * getBookValue() accessor (purchase cost − accumulated depreciation).
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', FinFixedAsset::class);

        $orgId = $request->user()->organization_id;

        $query = FinFixedAsset::forOrganization($orgId);

        if ($request->filled('category')) {
            $query->ofCategory($request->input('category'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('asset_name', 'like', "%{$search}%")
                ->orWhere('asset_tag', 'like', "%{$search}%"));
        }

        $rows = $query->orderBy('asset_name')
            ->get()
            ->map(fn (FinFixedAsset $asset) => [
                $asset->asset_tag,
                $asset->asset_name,
                $asset->category,
                optional($asset->purchase_date)->format('Y-m-d'),
                number_format((float) $asset->purchase_cost, 2, '.', ''),
                number_format((float) $asset->accumulated_depreciation, 2, '.', ''),
                number_format($asset->getBookValue(), 2, '.', ''),
                $asset->status,
            ]);

        return $this->streamSanitizedCsv(
            'fixed-assets-'.now()->format('Y-m-d').'.csv',
            ['Asset Tag', 'Name', 'Category', 'Purchase Date', 'Purchase Cost', 'Accumulated Depreciation', 'Book Value', 'Status'],
            $rows,
        );
    }

    /** Active GL accounts of a type for the fixed-asset modal pickers. */
    private function glAccounts(?int $orgId, string $type)
    {
        return FinAccount::forOrganization($orgId)
            ->active()
            ->ofType($type)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Store a new fixed asset.
     */
    public function store(Request $request)
    {
        $this->authorize('create', FinFixedAsset::class);

        $validated = $request->validate([
            'asset_name' => ['required', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'category' => ['required', 'in:vehicle,equipment,building,furniture,it_equipment,land'],
            'purchase_date' => ['required', 'date'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'depreciation_method' => ['required', 'in:straight_line,diminishing_value'],
            'gl_asset_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'gl_depreciation_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'gl_expense_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'linked_asset_id' => ['nullable', 'exists:assets,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $orgId = $request->user()->organization_id;

        try {
            $asset = $this->assetService->createAsset($orgId, $validated);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('finance.fixed-assets.show', $asset)
            ->with('success', "Fixed asset \"{$asset->asset_name}\" has been created.");
    }

    /**
     * Show a single fixed asset with depreciation history and projected schedule.
     * Includes device health data when the fixed asset is linked to an operational
     * asset that has canonical device links.
     */
    public function show(Request $request, FinFixedAsset $fixedAsset)
    {
        $this->authorize('view', $fixedAsset);

        $fixedAsset->load([
            'depreciations' => function ($q) {
                $q->orderBy('depreciation_date');
            },
            'depreciations.journal:id,journal_number',
            'glAssetAccount:id,code,name',
            'glDepreciationAccount:id,code,name',
            'glExpenseAccount:id,code,name',
            'createdBy:id,name',
            'linkedAsset:id,name,asset_tag,category,status',
        ]);

        $schedule = $this->assetService->getDepreciationSchedule($fixedAsset);

        // Device health: follow FinFixedAsset → Asset → DeviceAssetLink → Device.
        $linkedDevices = [];
        if ($fixedAsset->linked_asset_id) {
            $linkedDevices = \App\Domain\SecurityDevices\Models\DeviceAssetLink::query()
                ->active()
                ->forAsset($fixedAsset->linked_asset_id)
                ->with('device:id,device_uid,name,domain,category,status,health_status,provider,last_seen_at,battery_level')
                ->get()
                ->map(fn ($link) => [
                    'id' => $link->device?->id,
                    'device_uid' => $link->device?->device_uid,
                    'name' => $link->device?->name,
                    'domain' => $link->device?->domain,
                    'category' => $link->device?->category,
                    'status' => $link->device?->status?->value,
                    'health_status' => $link->device?->health_status?->value,
                    'provider' => $link->device?->provider,
                    'last_seen_at' => $link->device?->last_seen_at?->toISOString(),
                    'battery_level' => $link->device?->battery_level,
                    'link_type' => $link->link_type?->value,
                    'detail_url' => $link->device ? "/security-devices/devices/{$link->device->id}" : null,
                ])
                ->filter(fn ($d) => $d['id'] !== null)
                ->values()
                ->all();
        }

        $orgId = $fixedAsset->organization_id;
        $canManage = (bool) $request->user()->can('update', $fixedAsset);

        return Inertia::render('finance/fixed-assets/Show', [
            'asset' => $fixedAsset,
            'depreciationSchedule' => $schedule,
            'hasDepreciations' => $fixedAsset->depreciations->isNotEmpty(),
            'canManage' => $canManage,
            // Reference data for the edit modal (only when the user can manage assets).
            'assetAccounts' => $canManage ? $this->glAccounts($orgId, 'asset') : [],
            'expenseAccounts' => $canManage ? $this->glAccounts($orgId, 'expense') : [],
            'linkedAsset' => $fixedAsset->linkedAsset ? [
                'id' => $fixedAsset->linkedAsset->id,
                'name' => $fixedAsset->linkedAsset->name,
                'asset_tag' => $fixedAsset->linkedAsset->asset_tag,
                'category' => $fixedAsset->linkedAsset->category,
                'status' => $fixedAsset->linkedAsset->status,
            ] : null,
            'linkedDevices' => $linkedDevices,
        ]);
    }

    /**
     * Update an existing fixed asset.
     */
    public function update(Request $request, FinFixedAsset $fixedAsset)
    {
        $this->authorize('update', $fixedAsset);

        $validated = $request->validate([
            'asset_name' => ['required', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'category' => ['required', 'in:vehicle,equipment,building,furniture,it_equipment,land'],
            'purchase_date' => ['required', 'date'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'depreciation_method' => ['required', 'in:straight_line,diminishing_value'],
            'gl_asset_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'gl_depreciation_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'gl_expense_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'linked_asset_id' => ['nullable', 'exists:assets,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $asset = $this->assetService->updateAsset($fixedAsset, $validated);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('finance.fixed-assets.show', $asset)
            ->with('success', "Fixed asset \"{$asset->asset_name}\" has been updated.");
    }

    /**
     * Post the acquisition journal for an asset registered without GL accounts
     * (capture-at-source). Idempotent; errors are surfaced, never silent.
     */
    public function capitalise(Request $request, FinFixedAsset $fixedAsset)
    {
        $this->authorize('update', $fixedAsset);

        try {
            $asset = $this->assetService->capitaliseAsset($fixedAsset);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('finance.fixed-assets.show', $asset)
            ->with('success', "Acquisition journal posted for \"{$asset->asset_name}\".");
    }

    /**
     * Dispose of a fixed asset.
     */
    public function dispose(Request $request, FinFixedAsset $fixedAsset)
    {
        $this->authorize('dispose', $fixedAsset);

        $validated = $request->validate([
            'disposed_date' => ['required', 'date'],
            'disposal_proceeds' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $asset = $this->assetService->disposeAsset($fixedAsset, $validated);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('finance.fixed-assets.show', $asset)
            ->with('success', "Fixed asset \"{$asset->asset_name}\" has been disposed.");
    }

    /**
     * Run depreciation for the organisation.
     */
    public function runDepreciation(Request $request)
    {
        $this->authorize('create', FinFixedAsset::class);

        $validated = $request->validate([
            'depreciation_date' => ['required', 'date'],
        ]);

        $orgId = $request->user()->organization_id;

        try {
            $results = $this->assetService->runDepreciation($orgId, $validated['depreciation_date']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['general' => $e->getMessage()]);
        }

        $count = count($results);

        return redirect()->route('finance.fixed-assets.index')
            ->with('success', "Depreciation run complete. {$count} asset(s) processed.");
    }
}
