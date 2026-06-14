<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankReconciliation;
use App\Domain\Finance\Models\FinBankReconciliationLine;
use App\Domain\Finance\Services\BankReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankReconciliationController extends Controller
{
    public function __construct(
        private BankReconciliationService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', FinBankReconciliation::class);

        $orgId = $request->user()->organization_id;

        $reconciliations = FinBankReconciliation::forOrganization($orgId)
            ->with('bankAccount:id,name')
            ->with('completedBy:id,name')
            ->when($request->bank_account_id, function ($q, $bankAccountId) {
                $q->where('bank_account_id', $bankAccountId);
            })
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->orderByDesc('statement_date')
            ->paginate(20)
            ->withQueryString();

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('finance/bank-reconciliation/Index', [
            'reconciliations' => $reconciliations,
            'bankAccounts' => $bankAccounts,
            'filters' => [
                'bank_account_id' => $request->bank_account_id ?? '',
                'status' => $request->status ?? '',
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', FinBankReconciliation::class);

        $orgId = $request->user()->organization_id;

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'current_balance']);

        return Inertia::render('finance/bank-reconciliation/Create', [
            'bankAccounts' => $bankAccounts,
            'preselectedBankAccountId' => $request->bank_account_id ? (int) $request->bank_account_id : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FinBankReconciliation::class);

        $validated = $request->validate([
            'bank_account_id' => ['required', 'exists:fin_bank_accounts,id'],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
        ]);

        $orgId = $request->user()->organization_id;

        $reconciliation = $this->service->startReconciliation($orgId, $validated['bank_account_id'], [
            'statement_date' => $validated['statement_date'],
            'statement_balance' => $validated['statement_balance'],
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.bank-reconciliation.show', $reconciliation)
            ->with('success', 'Reconciliation started.');
    }

    public function show(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('view', $reconciliation);

        $reconciliation->load([
            'bankAccount:id,name,current_balance,opening_balance',
            'completedBy:id,name',
            'lines.bankTransaction',
            'lines.journalLine.journal:id,journal_number,journal_date,description',
        ]);

        $unreconciledItems = $this->service->getUnreconciledItems($reconciliation->bank_account_id);
        $suggestedMatches = [];

        if ($reconciliation->status === 'in_progress') {
            $suggestedMatches = $this->service->suggestMatches($reconciliation->id);
        }

        $matchedLines = $reconciliation->lines->map(fn ($line) => [
            'id' => $line->id,
            'bank_transaction' => $line->bankTransaction ? [
                'id' => $line->bankTransaction->id,
                'transaction_date' => $line->bankTransaction->transaction_date->format('Y-m-d'),
                'amount' => (float) $line->bankTransaction->amount,
                'description' => $line->bankTransaction->description,
                'reference' => $line->bankTransaction->reference,
            ] : null,
            'journal_line' => $line->journalLine ? [
                'id' => $line->journalLine->id,
                'debit' => (float) $line->journalLine->debit,
                'credit' => (float) $line->journalLine->credit,
                'description' => $line->journalLine->description,
                'journal_number' => $line->journalLine->journal?->journal_number,
                'journal_date' => $line->journalLine->journal?->journal_date?->format('Y-m-d'),
            ] : null,
        ]);

        $transactions = $unreconciledItems['transactions']->map(fn ($txn) => [
            'id' => $txn->id,
            'transaction_date' => $txn->transaction_date->format('Y-m-d'),
            'amount' => (float) $txn->amount,
            'description' => $txn->description,
            'reference' => $txn->reference,
            'source' => $txn->source,
        ]);

        $journalLines = $unreconciledItems['journal_lines']->map(fn ($line) => [
            'id' => $line->id,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
            'description' => $line->description,
            'journal_number' => $line->journal?->journal_number,
            'journal_date' => $line->journal?->journal_date?->format('Y-m-d'),
            'journal_description' => $line->journal?->description,
        ]);

        // Calculate the starting balance for this reconciliation
        $previousRecon = FinBankReconciliation::where('bank_account_id', $reconciliation->bank_account_id)
            ->where('status', 'completed')
            ->orderByDesc('statement_date')
            ->first();

        $bankAccount = FinBankAccount::find($reconciliation->bank_account_id);
        $startingBalance = $previousRecon
            ? (float) $previousRecon->statement_balance
            : (float) $bankAccount->opening_balance;

        return Inertia::render('finance/bank-reconciliation/Reconcile', [
            'reconciliation' => [
                'id' => $reconciliation->id,
                'bank_account_id' => $reconciliation->bank_account_id,
                'bank_account_name' => $reconciliation->bankAccount->name,
                'statement_date' => $reconciliation->statement_date->format('Y-m-d'),
                'statement_balance' => (float) $reconciliation->statement_balance,
                'calculated_balance' => $reconciliation->calculated_balance ? (float) $reconciliation->calculated_balance : null,
                'status' => $reconciliation->status,
                'completed_at' => $reconciliation->completed_at?->format('Y-m-d H:i'),
                'completed_by_name' => $reconciliation->completedBy?->name,
                'starting_balance' => $startingBalance,
            ],
            'matchedLines' => $matchedLines,
            'unreconciledTransactions' => $transactions,
            'unmatchedJournalLines' => $journalLines,
            'suggestedMatches' => $suggestedMatches,
            // Income/expense accounts for matching a statement line as an adjustment
            // (bank fees → expense, interest → income).
            'adjustmentAccounts' => FinAccount::forOrganization($bankAccount->organization_id)
                ->active()
                ->whereIn('type', ['expense', 'income', 'revenue'])
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function match(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('complete', $reconciliation);

        $validated = $request->validate([
            'bank_transaction_id' => ['required', 'exists:fin_bank_transactions,id'],
            'journal_line_id' => ['nullable', 'exists:fin_journal_lines,id'],
            'adjustment_account_id' => ['nullable', 'exists:fin_accounts,id'],
        ]);

        $this->service->matchTransaction(
            $reconciliation->id,
            $validated['bank_transaction_id'],
            $validated['journal_line_id'] ?? null,
            $validated['adjustment_account_id'] ?? null,
        );

        return redirect()->back()
            ->with('success', 'Transaction matched.');
    }

    public function unmatch(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('complete', $reconciliation);

        $request->validate([
            'line_id' => ['required', 'exists:fin_bank_reconciliation_lines,id'],
        ]);

        $line = FinBankReconciliationLine::findOrFail($request->line_id);

        if ($line->reconciliation_id !== $reconciliation->id) {
            abort(403, 'Line does not belong to this reconciliation.');
        }

        $this->service->unmatchTransaction($line);

        return redirect()->back()
            ->with('success', 'Transaction unmatched.');
    }

    public function complete(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('complete', $reconciliation);

        try {
            $this->service->completeReconciliation($reconciliation, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return redirect()->route('finance.bank-reconciliation.show', $reconciliation)
            ->with('success', 'Reconciliation completed successfully.');
    }
}
