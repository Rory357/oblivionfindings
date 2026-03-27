<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Services\ChartOfAccountsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChartOfAccountsController extends Controller
{
    public function __construct(
        private ChartOfAccountsService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', FinAccount::class);

        $orgId = $request->user()->organization_id;
        $tree = $this->service->getAccountTree($orgId);

        $accountTypes = [
            ['value' => 'asset', 'label' => 'Asset'],
            ['value' => 'liability', 'label' => 'Liability'],
            ['value' => 'equity', 'label' => 'Equity'],
            ['value' => 'revenue', 'label' => 'Revenue'],
            ['value' => 'expense', 'label' => 'Expense'],
        ];

        return Inertia::render('finance/accounts/Index', [
            'accountTree' => $tree,
            'accountTypes' => $accountTypes,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', FinAccount::class);

        $orgId = $request->user()->organization_id;

        $parentAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $fundingStreams = FinFundingStream::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/accounts/Create', [
            'parentAccounts' => $parentAccounts,
            'taxRates' => $taxRates,
            'fundingStreams' => $fundingStreams,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FinAccount::class);

        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'sub_type' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:fin_accounts,id',
            'is_active' => 'boolean',
            'gst_applicable' => 'boolean',
            'description' => 'nullable|string|max:1000',
            'default_tax_rate_id' => 'nullable|exists:fin_tax_rates,id',
            'funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
        ]);

        $validated['created_by'] = $request->user()->id;

        try {
            $this->service->createAccount($request->user()->organization_id, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('finance.accounts.index')
            ->with('success', 'Account created successfully.');
    }

    public function show(Request $request, FinAccount $account)
    {
        $this->authorize('view', $account);

        $startDate = $request->input('start_date', now()->subMonths(3)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $ledger = $this->service->getAccountLedger($account->id, $startDate, $endDate);

        return Inertia::render('finance/accounts/Show', [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'sub_type' => $account->sub_type,
                'is_system' => $account->is_system,
                'is_active' => $account->is_active,
                'gst_applicable' => $account->gst_applicable,
                'description' => $account->description,
                'balance' => $account->getBalance(),
            ],
            'ledger' => $ledger,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function edit(Request $request, FinAccount $account)
    {
        $this->authorize('update', $account);

        $orgId = $request->user()->organization_id;

        $parentAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->where('id', '!=', $account->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $fundingStreams = FinFundingStream::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/accounts/Edit', [
            'account' => $account->only([
                'id', 'code', 'name', 'type', 'sub_type', 'parent_id',
                'is_system', 'is_active', 'gst_applicable', 'description',
                'default_tax_rate_id', 'funding_stream_id',
            ]),
            'parentAccounts' => $parentAccounts,
            'taxRates' => $taxRates,
            'fundingStreams' => $fundingStreams,
            'hasJournalLines' => $account->journalLines()->exists(),
        ]);
    }

    public function update(Request $request, FinAccount $account)
    {
        $this->authorize('update', $account);

        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'sub_type' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:fin_accounts,id',
            'is_active' => 'boolean',
            'gst_applicable' => 'boolean',
            'description' => 'nullable|string|max:1000',
            'default_tax_rate_id' => 'nullable|exists:fin_tax_rates,id',
            'funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
        ]);

        try {
            $this->service->updateAccount($account, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('finance.accounts.index')
            ->with('success', 'Account updated successfully.');
    }

    public function destroy(Request $request, FinAccount $account)
    {
        $this->authorize('delete', $account);

        try {
            $this->service->deleteAccount($account);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        return redirect()->route('finance.accounts.index')
            ->with('success', 'Account deleted successfully.');
    }
}
