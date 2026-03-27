<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinEftposBatch;
use App\Domain\Finance\Models\FinEftposTerminal;
use App\Domain\Finance\Services\EftposReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EftposController extends Controller
{
    public function __construct(
        private EftposReconciliationService $reconciliationService,
    ) {}

    /**
     * List all EFTPOS terminals.
     */
    public function terminals(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $terminals = FinEftposTerminal::forOrganization($orgId)
            ->with('bankAccount:id,name', 'glAccount:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (FinEftposTerminal $terminal) => [
                'id' => $terminal->id,
                'terminal_id' => $terminal->terminal_id,
                'name' => $terminal->name,
                'location' => $terminal->location,
                'provider' => $terminal->provider,
                'bank_account_name' => $terminal->bankAccount?->name,
                'gl_account_name' => $terminal->glAccount ? $terminal->glAccount->code . ' - ' . $terminal->glAccount->name : null,
                'is_active' => $terminal->is_active,
                'batch_count' => $terminal->batches()->count(),
            ]);

        $bankAccounts = FinBankAccount::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'name']);
        $glAccounts = FinAccount::forOrganization($orgId)->active()->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('finance/eftpos/Terminals', [
            'terminals' => $terminals,
            'bankAccounts' => $bankAccounts,
            'glAccounts' => $glAccounts,
        ]);
    }

    /**
     * Store a new EFTPOS terminal.
     */
    public function storeTerminal(Request $request)
    {
        $validated = $request->validate([
            'terminal_id' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'provider' => 'required|in:paymark,worldline,eftpos_nz,windcave',
            'merchant_id' => 'required|string|max:255',
            'bank_account_id' => 'nullable|exists:fin_bank_accounts,id',
            'gl_account_id' => 'nullable|exists:fin_accounts,id',
        ]);

        $orgId = $request->user()->organization_id;

        FinEftposTerminal::create([
            ...$validated,
            'organization_id' => $orgId,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.eftpos.terminals')
            ->with('success', 'EFTPOS terminal added successfully.');
    }

    /**
     * Update an existing EFTPOS terminal.
     */
    public function updateTerminal(Request $request, FinEftposTerminal $terminal)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'provider' => 'required|in:paymark,worldline,eftpos_nz,windcave',
            'merchant_id' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:fin_bank_accounts,id',
            'gl_account_id' => 'nullable|exists:fin_accounts,id',
            'is_active' => 'boolean',
        ]);

        $terminal->update($validated);

        return redirect()->route('finance.eftpos.terminals')
            ->with('success', 'EFTPOS terminal updated successfully.');
    }

    /**
     * List EFTPOS batches with filtering.
     */
    public function batches(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinEftposBatch::forOrganization($orgId)
            ->with(['terminal:id,name,terminal_id', 'bankTransaction:id,amount,transaction_date', 'reconciledBy:id,name']);

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('batch_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('batch_date', '<=', $request->input('date_to'));
        }

        $batches = $query->orderByDesc('batch_date')
            ->paginate(25)
            ->through(fn (FinEftposBatch $batch) => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'batch_date' => $batch->batch_date->toDateString(),
                'terminal_name' => $batch->terminal?->name,
                'terminal_id_code' => $batch->terminal?->terminal_id,
                'total_transactions' => $batch->total_transactions,
                'total_amount' => (float) $batch->total_amount,
                'total_refunds' => (float) $batch->total_refunds,
                'net_amount' => (float) $batch->net_amount,
                'fees' => (float) $batch->fees,
                'settlement_amount' => (float) $batch->settlement_amount,
                'status' => $batch->status,
                'reconciled_at' => $batch->reconciled_at?->toDateTimeString(),
                'reconciled_by_name' => $batch->reconciledBy?->name,
                'discrepancy_amount' => (float) $batch->discrepancy_amount,
                'bank_transaction_amount' => $batch->bankTransaction ? (float) $batch->bankTransaction->amount : null,
            ]);

        $terminals = FinEftposTerminal::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'terminal_id']);

        // Get unmatched bank transactions for manual reconciliation
        $unmatchedBankTransactions = FinBankTransaction::forOrganization($orgId)
            ->unreconciled()
            ->where('amount', '>', 0)
            ->orderByDesc('transaction_date')
            ->limit(100)
            ->get(['id', 'transaction_date', 'amount', 'description', 'reference']);

        return Inertia::render('finance/eftpos/Batches', [
            'batches' => $batches,
            'terminals' => $terminals,
            'unmatchedBankTransactions' => $unmatchedBankTransactions,
            'filters' => $request->only(['status', 'terminal_id', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Import a batch of EFTPOS transactions.
     */
    public function importBatch(Request $request)
    {
        $validated = $request->validate([
            'terminal_id' => 'required|exists:fin_eftpos_terminals,id',
            'batch_number' => 'required|string|max:50',
            'batch_date' => 'required|date',
            'transactions' => 'required|array|min:1',
            'transactions.*.reference' => 'required|string',
            'transactions.*.date' => 'required|date',
            'transactions.*.amount' => 'required|numeric',
            'transactions.*.card_type' => 'nullable|in:visa,mastercard,eftpos,amex,other',
            'transactions.*.type' => 'nullable|in:purchase,refund,cash_out',
            'transactions.*.fee' => 'nullable|numeric|min:0',
            'transactions.*.auth_code' => 'nullable|string',
            'transactions.*.card_last_four' => 'nullable|string|size:4',
        ]);

        $orgId = $request->user()->organization_id;

        try {
            $batch = $this->reconciliationService->importBatch($orgId, $validated['terminal_id'], $validated);
        } catch (\Exception $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('finance.eftpos.batches.show', $batch)
            ->with('success', "Batch {$batch->batch_number} imported with {$batch->total_transactions} transactions.");
    }

    /**
     * Reconcile an EFTPOS batch.
     */
    public function reconcile(Request $request, FinEftposBatch $batch)
    {
        $validated = $request->validate([
            'bank_transaction_id' => 'nullable|exists:fin_bank_transactions,id',
            'discrepancy_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $batch = $this->reconciliationService->reconcileBatch(
                $batch,
                $validated['bank_transaction_id'] ?? null,
            );

            if (! empty($validated['discrepancy_notes'])) {
                $batch->update(['discrepancy_notes' => $validated['discrepancy_notes']]);
            }
        } catch (\Exception $e) {
            return back()->withErrors(['reconcile' => $e->getMessage()]);
        }

        $message = $batch->status === 'reconciled'
            ? 'Batch reconciled successfully.'
            : "Batch reconciled with discrepancy of \${$batch->discrepancy_amount}.";

        return redirect()->route('finance.eftpos.batches')
            ->with('success', $message);
    }

    /**
     * View batch detail with all transactions.
     */
    public function batchDetail(Request $request, FinEftposBatch $batch)
    {
        $batch->load([
            'terminal:id,name,terminal_id,provider',
            'bankTransaction:id,amount,transaction_date,description',
            'reconciledBy:id,name',
            'createdBy:id,name',
        ]);

        $transactions = $batch->transactions()
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($txn) => [
                'id' => $txn->id,
                'transaction_reference' => $txn->transaction_reference,
                'transaction_date' => $txn->transaction_date->toDateTimeString(),
                'card_type' => $txn->card_type,
                'transaction_type' => $txn->transaction_type,
                'amount' => (float) $txn->amount,
                'fee_amount' => (float) $txn->fee_amount,
                'auth_code' => $txn->auth_code,
                'card_last_four' => $txn->card_last_four,
                'status' => $txn->status,
            ]);

        return Inertia::render('finance/eftpos/BatchDetail', [
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'batch_date' => $batch->batch_date->toDateString(),
                'settlement_date' => $batch->settlement_date?->toDateString(),
                'terminal_name' => $batch->terminal?->name,
                'terminal_id_code' => $batch->terminal?->terminal_id,
                'provider' => $batch->terminal?->provider,
                'total_transactions' => $batch->total_transactions,
                'total_amount' => (float) $batch->total_amount,
                'total_refunds' => (float) $batch->total_refunds,
                'net_amount' => (float) $batch->net_amount,
                'fees' => (float) $batch->fees,
                'settlement_amount' => (float) $batch->settlement_amount,
                'status' => $batch->status,
                'reconciled_at' => $batch->reconciled_at?->toDateTimeString(),
                'reconciled_by_name' => $batch->reconciledBy?->name,
                'discrepancy_amount' => (float) $batch->discrepancy_amount,
                'discrepancy_notes' => $batch->discrepancy_notes,
                'bank_transaction' => $batch->bankTransaction ? [
                    'id' => $batch->bankTransaction->id,
                    'amount' => (float) $batch->bankTransaction->amount,
                    'transaction_date' => $batch->bankTransaction->transaction_date->toDateString(),
                    'description' => $batch->bankTransaction->description,
                ] : null,
                'created_by_name' => $batch->createdBy?->name,
            ],
            'transactions' => $transactions,
        ]);
    }
}
