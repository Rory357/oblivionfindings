<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Services\PettyCashService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class PettyCashController extends Controller
{
    public function __construct(
        private PettyCashService $pettyCashService,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $funds = FinPettyCashFund::forOrganization($orgId)
            ->with('custodian:id,name', 'glAccount:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (FinPettyCashFund $fund) => [
                'id' => $fund->id,
                'name' => $fund->name,
                'float_amount' => (float) $fund->float_amount,
                'current_balance' => (float) $fund->current_balance,
                'custodian_name' => $fund->custodian->name ?? null,
                'gl_account_name' => $fund->glAccount ? $fund->glAccount->code . ' - ' . $fund->glAccount->name : null,
                'is_active' => $fund->is_active,
            ]);

        $canManage = (bool) $request->user()->canDo('finance.petty_cash.manage');

        return Inertia::render('finance/petty-cash/Index', [
            'funds' => $funds,
            'canManage' => $canManage,
            // Reference data for the New Fund modal (asset GL accounts + custodians).
            'accounts' => $canManage
                ? FinAccount::forOrganization($orgId)
                    ->active()
                    ->whereIn('type', ['asset'])
                    ->orderBy('code')
                    ->get(['id', 'code', 'name'])
                : [],
            'users' => $canManage
                ? User::query()
                    ->when(
                        $orgId && Schema::hasColumn('users', 'organization_id'),
                        fn ($query) => $query->where('organization_id', $orgId),
                    )
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'float_amount' => 'required|numeric|min:0.01',
            'gl_account_id' => 'required|exists:fin_accounts,id',
            'custodian_user_id' => 'nullable|exists:users,id',
        ]);

        $fund = $this->pettyCashService->createFund(
            $request->user()->organization_id,
            $validated,
        );

        return redirect()->route('finance.petty-cash.show', $fund)
            ->with('success', 'Petty cash fund created successfully.');
    }

    public function show(Request $request, FinPettyCashFund $fund)
    {
        $orgId = $request->user()->organization_id;

        $summary = $this->pettyCashService->getFundSummary($fund);

        $expenseAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->ofType('expense')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/petty-cash/Show', [
            'summary' => $summary,
            'expenseAccounts' => $expenseAccounts,
        ]);
    }

    public function storeTransaction(Request $request, FinPettyCashFund $fund)
    {
        $validated = $request->validate([
            'transaction_date' => 'required|date',
            'type' => 'required|in:top_up,expense,adjustment',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'account_id' => 'nullable|exists:fin_accounts,id',
            'receipt_path' => 'nullable|string|max:500',
        ]);

        try {
            $this->pettyCashService->addTransaction($fund, $validated);
        } catch (\Exception $e) {
            return back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->route('finance.petty-cash.show', $fund)
            ->with('success', 'Transaction recorded successfully.');
    }
}
