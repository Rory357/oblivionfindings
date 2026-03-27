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

        $assets = $query->orderBy('asset_name')
            ->paginate(25)
            ->withQueryString();

        // Summary totals
        $allAssets = FinFixedAsset::forOrganization($orgId)->get();
        $summary = [
            'total_count' => $allAssets->count(),
            'total_cost' => (float) $allAssets->sum('purchase_cost'),
            'total_depreciation' => (float) $allAssets->sum('accumulated_depreciation'),
            'net_book_value' => (float) ($allAssets->sum('purchase_cost') - $allAssets->sum('accumulated_depreciation')),
            'active_count' => $allAssets->where('status', 'active')->count(),
        ];

        return Inertia::render('finance/fixed-assets/Index', [
            'assets' => $assets,
            'summary' => $summary,
            'filters' => $request->only(['category', 'status', 'search']),
        ]);
    }

    /**
     * Show the create asset form.
     */
    public function create(Request $request)
    {
        $this->authorize('create', FinFixedAsset::class);

        $orgId = $request->user()->organization_id;

        return Inertia::render('finance/fixed-assets/Create', [
            'assetAccounts' => FinAccount::forOrganization($orgId)
                ->active()
                ->ofType('asset')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'expenseAccounts' => FinAccount::forOrganization($orgId)
                ->active()
                ->ofType('expense')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
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
        ]);

        $schedule = $this->assetService->getDepreciationSchedule($fixedAsset);

        return Inertia::render('finance/fixed-assets/Show', [
            'asset' => $fixedAsset,
            'depreciationSchedule' => $schedule,
        ]);
    }

    /**
     * Show the edit form for a fixed asset.
     */
    public function edit(Request $request, FinFixedAsset $fixedAsset)
    {
        $this->authorize('update', $fixedAsset);

        $orgId = $request->user()->organization_id;

        $fixedAsset->load('depreciations');
        $hasDepreciations = $fixedAsset->depreciations->isNotEmpty();

        return Inertia::render('finance/fixed-assets/Edit', [
            'asset' => $fixedAsset,
            'hasDepreciations' => $hasDepreciations,
            'assetAccounts' => FinAccount::forOrganization($orgId)
                ->active()
                ->ofType('asset')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'expenseAccounts' => FinAccount::forOrganization($orgId)
                ->active()
                ->ofType('expense')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
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
