<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankAccountController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinBankAccount::class);

        $orgId = $request->user()->organization_id;

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
                'account_type' => $account->account_type,
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
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', FinBankAccount::class);

        $orgId = $request->user()->organization_id;

        $glAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->where('sub_type', 'bank')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/bank-accounts/Create', [
            'glAccounts' => $glAccounts,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FinBankAccount::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_type' => ['required', 'in:cheque,savings,term_deposit,credit_card'],
            'gl_account_id' => ['required', 'exists:fin_accounts,id'],
            'opening_balance' => ['required', 'numeric'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

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

        return Inertia::render('finance/bank-accounts/Show', [
            'bankAccount' => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
                'bank_name' => $bankAccount->bank_name,
                'account_type' => $bankAccount->account_type,
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
        ]);
    }

    public function edit(Request $request, FinBankAccount $bankAccount)
    {
        $this->authorize('update', $bankAccount);

        $orgId = $request->user()->organization_id;

        $glAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->where('sub_type', 'bank')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/bank-accounts/Edit', [
            'bankAccount' => $bankAccount->only([
                'id', 'name', 'bank_name', 'account_number', 'account_type',
                'gl_account_id', 'opening_balance', 'is_primary', 'is_active',
            ]),
            'glAccounts' => $glAccounts,
        ]);
    }

    public function update(Request $request, FinBankAccount $bankAccount)
    {
        $this->authorize('update', $bankAccount);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_type' => ['required', 'in:cheque,savings,term_deposit,credit_card'],
            'gl_account_id' => ['required', 'exists:fin_accounts,id'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $bankAccount->update($validated);

        return redirect()->route('finance.bank-accounts.show', $bankAccount)
            ->with('success', 'Bank account updated successfully.');
    }
}
