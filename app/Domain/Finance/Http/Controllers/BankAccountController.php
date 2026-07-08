<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Http\Requests\StoreBankAccountRequest;
use App\Domain\Finance\Http\Requests\UpdateBankAccountRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankAccountController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinBankAccount::class);

        $orgId = $request->user()->organization_id;
        $canManage = (bool) $request->user()->canDo('finance.bank.manage');

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->with('glAccount:id,code,name')
            ->withCount(['transactions as unreconciled_count' => function ($q) {
                $q->where('status', 'unreconciled');
            }])
            ->orderBy('name')
            ->get()
            ->map(fn($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                // Prefill for the edit modal — managers only (encrypted at rest).
                'account_number' => $canManage ? $account->account_number : null,
                'account_type' => $account->account_type,
                'gl_account_id' => $account->gl_account_id,
                'current_balance' => (float) $account->current_balance,
                'is_primary' => $account->is_primary,
                'is_active' => $account->is_active,
                'gl_account' => $account->glAccount ? [
                    'id' => $account->glAccount->id,
                    'code' => $account->glAccount->code,
                    'name' => $account->glAccount->name,
                ] : null,
                'unreconciled_count' => $account->unreconciled_count,
            ]);

        return Inertia::render('finance/bank-accounts/Index', [
            'bankAccounts' => $bankAccounts,
            'canManage' => $canManage,
            // Bank-type GL accounts for the add/edit modal.
            'glAccounts' => $canManage ? $this->bankGlAccounts($orgId) : [],
        ]);
    }

    public function store(StoreBankAccountRequest $request)
    {
        $validated = $request->validated();

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['current_balance'] = $validated['opening_balance'];
        $validated['created_by'] = $request->user()->id;

        FinBankAccount::create($validated);

        return redirect()->route('finance.bank-accounts.index')
            ->with('success', 'Bank account created successfully.');
    }

    public function show(Request $request, FinBankAccount $bankAccount)
    {
        $this->authorize('view', $bankAccount);

        $bankAccount->load('glAccount:id,code,name');

        $transactions = $bankAccount->transactions()
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn($txn) => [
                'id' => $txn->id,
                'transaction_date' => $txn->transaction_date->format('Y-m-d'),
                'amount' => (float) $txn->amount,
                'description' => $txn->description,
                'reference' => $txn->reference,
                'source' => $txn->source,
                'status' => $txn->status,
            ]);

        $reconciliations = $bankAccount->reconciliations()
            ->orderByDesc('statement_date')
            ->limit(10)
            ->get()
            ->map(fn($recon) => [
                'id' => $recon->id,
                'statement_date' => $recon->statement_date->format('Y-m-d'),
                'statement_balance' => (float) $recon->statement_balance,
                'calculated_balance' => $recon->calculated_balance ? (float) $recon->calculated_balance : null,
                'status' => $recon->status,
                'completed_at' => $recon->completed_at?->format('Y-m-d H:i'),
            ]);

        $balanceHistory = $bankAccount->transactions()
            ->orderBy('transaction_date')
            ->get()
            ->groupBy(fn($t) => $t->transaction_date->format('d M'))
            ->map(fn($group, $date) => [
                'date' => $date,
                'amount' => $group->sum('amount'),
            ])
            ->values()
            ->take(30)
            ->toArray();

        $canManage = (bool) $request->user()->canDo('finance.bank.manage');

        return Inertia::render('finance/bank-accounts/Show', [
            'bankAccount' => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
                'bank_name' => $bankAccount->bank_name,
                // Prefill for the edit modal — managers only (encrypted at rest).
                'account_number' => $canManage ? $bankAccount->account_number : null,
                'account_type' => $bankAccount->account_type,
                'gl_account_id' => $bankAccount->gl_account_id,
                'opening_balance' => (float) $bankAccount->opening_balance,
                'current_balance' => (float) $bankAccount->current_balance,
                'is_primary' => $bankAccount->is_primary,
                'is_active' => $bankAccount->is_active,
                'gl_account' => $bankAccount->glAccount ? [
                    'id' => $bankAccount->glAccount->id,
                    'code' => $bankAccount->glAccount->code,
                    'name' => $bankAccount->glAccount->name,
                ] : null,
            ],
            'transactions' => $transactions,
            'reconciliations' => $reconciliations,
            'balanceHistory' => $balanceHistory,
            'canManage' => $canManage,
            // Bank-type GL accounts for the edit modal.
            'glAccounts' => $canManage ? $this->bankGlAccounts($request->user()->organization_id) : [],
        ]);
    }

    /** Active `bank` sub-type GL accounts — options for the add/edit modal. */
    private function bankGlAccounts(?int $orgId)
    {
        return FinAccount::forOrganization($orgId)
            ->active()
            ->where('sub_type', 'bank')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function update(UpdateBankAccountRequest $request, FinBankAccount $bankAccount)
    {
        $validated = $request->validated();

        $bankAccount->update($validated);

        return redirect()->route('finance.bank-accounts.show', $bankAccount)
            ->with('success', 'Bank account updated successfully.');
    }
}
