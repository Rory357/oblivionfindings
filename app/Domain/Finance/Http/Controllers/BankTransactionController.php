<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Services\BankReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankTransactionController extends Controller
{
    public function __construct(
        private BankReconciliationService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', FinBankTransaction::class);

        $orgId = $request->user()->organization_id;

        $transactions = FinBankTransaction::forOrganization($orgId)
            ->with('bankAccount:id,name')
            ->when($request->bank_account_id, function ($q, $bankAccountId) {
                $q->where('bank_account_id', $bankAccountId);
            })
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->start_date, function ($q, $start) {
                $q->where('transaction_date', '>=', $start);
            })
            ->when($request->end_date, function ($q, $end) {
                $q->where('transaction_date', '<=', $end);
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn (FinBankTransaction $transaction) => [
                'id' => $transaction->id,
                'transaction_date' => $transaction->transaction_date?->toDateString(),
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'payee' => $transaction->payee,
                'source' => $transaction->source,
                'status' => $transaction->status,
                'is_from_feed' => (bool) $transaction->is_from_feed,
                'bank_account' => $transaction->bankAccount ? [
                    'id' => $transaction->bankAccount->id,
                    'name' => $transaction->bankAccount->name,
                ] : null,
            ])
            ->withQueryString();

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('finance/bank-transactions/Index', [
            'transactions' => $transactions,
            'bankAccounts' => $bankAccounts,
            'filters' => [
                'bank_account_id' => $request->bank_account_id ?? '',
                'status' => $request->status ?? '',
                'start_date' => $request->start_date ?? '',
                'end_date' => $request->end_date ?? '',
            ],
        ]);
    }

    /**
     * Stream the (filtered) bank-transaction list as a sanitised CSV. Honours the
     * same account/status/date filters as the index so "Export" respects the view.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', FinBankTransaction::class);

        $orgId = $request->user()->organization_id;

        $rows = FinBankTransaction::forOrganization($orgId)
            ->when($request->bank_account_id, fn ($q, $bankAccountId) => $q->where('bank_account_id', $bankAccountId))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->start_date, fn ($q, $start) => $q->where('transaction_date', '>=', $start))
            ->when($request->end_date, fn ($q, $end) => $q->where('transaction_date', '<=', $end))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FinBankTransaction $t) => [
                optional($t->transaction_date)->format('Y-m-d'),
                $t->description,
                $t->reference,
                number_format((float) $t->amount, 2, '.', ''),
                (float) $t->amount >= 0 ? 'credit' : 'debit',
                $t->status,
            ]);

        return $this->streamSanitizedCsv(
            'bank-transactions-'.now()->format('Y-m-d').'.csv',
            ['Date', 'Description', 'Reference', 'Amount', 'Type', 'Status'],
            $rows,
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', FinBankTransaction::class);

        $validated = $request->validate([
            'bank_account_id' => ['required', 'exists:fin_bank_accounts,id'],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'numeric'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['source'] = 'manual';
        $validated['status'] = 'unreconciled';

        FinBankTransaction::create($validated);

        return redirect()->back()
            ->with('success', 'Transaction added successfully.');
    }

    public function import(Request $request)
    {
        $this->authorize('create', FinBankTransaction::class);

        $request->validate([
            'bank_account_id' => ['required', 'exists:fin_bank_accounts,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $orgId = $request->user()->organization_id;
        $bankAccountId = $request->bank_account_id;
        $filePath = $request->file('file')->getRealPath();

        try {
            $result = $this->service->importTransactions($orgId, $bankAccountId, $filePath);
        } catch (\Throwable $e) {
            return redirect()->back()
                ->withErrors(['file' => 'Failed to import transactions: ' . $e->getMessage()]);
        }

        return redirect()->back()
            ->with('success', "Imported {$result['imported']} transactions. {$result['skipped']} rows skipped.");
    }
}
