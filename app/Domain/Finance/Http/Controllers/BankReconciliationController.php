<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankReconciliation;
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
            'bank_account_id' => ['required', 'integer', 'min:1'],
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

        $unreconciledItems = $this->service->getUnreconciledItems($reconciliation->bank_account_id, $reconciliation->id);
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

        $bankAccount = FinBankAccount::find($reconciliation->bank_account_id);

        return Inertia::render('finance/bank-reconciliation/Reconcile', [
            'reconciliation' => [
                'id' => $reconciliation->id,
                'bank_account_id' => $reconciliation->bank_account_id,
                'bank_account_name' => $reconciliation->bankAccount->name,
                'statement_date' => $reconciliation->statement_date->format('Y-m-d'),
                'statement_balance' => (float) $reconciliation->statement_balance,
                'calculated_balance' => $reconciliation->calculated_balance ? (float) $reconciliation->calculated_balance : null,
                'status' => $reconciliation->status,
                'version' => $reconciliation->version,
                'integrity_state' => $reconciliation->integrity_state,
                'recovery_message' => $reconciliation->recovery_message,
                'completed_at' => $reconciliation->completed_at?->format('Y-m-d H:i'),
                'completed_by_name' => $reconciliation->completedBy?->name,
                'starting_balance' => (float) $reconciliation->starting_balance,
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
            'bank_transaction_id' => ['required', 'integer', 'min:1'],
            'journal_line_id' => ['nullable', 'integer', 'min:1'],
            'adjustment_account_id' => ['nullable', 'integer', 'min:1'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->matchTransaction(
                $reconciliation->id,
                $validated['bank_transaction_id'],
                $validated['journal_line_id'] ?? null,
                $validated['adjustment_account_id'] ?? null,
                $request->user()->id,
                $validated['expected_version'] ?? null,
                $validated['idempotency_key'] ?? null,
            );
        } catch (BankReconciliationConflict $exception) {
            return redirect()->back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return redirect()->back()
            ->with('success', 'Transaction matched.');
    }

    public function unmatch(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('complete', $reconciliation);

        $request->validate([
            'line_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->unmatchTransaction(
                $reconciliation->id,
                (int) $request->line_id,
                $request->user()->id,
                $request->integer('expected_version') ?: null,
                $request->input('idempotency_key'),
            );
        } catch (BankReconciliationConflict $exception) {
            return redirect()->back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return redirect()->back()
            ->with('success', 'Transaction unmatched.');
    }

    public function complete(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('complete', $reconciliation);

        try {
            $validated = $request->validate([
                'expected_version' => ['nullable', 'integer', 'min:1'],
                'idempotency_key' => ['nullable', 'string', 'max:255'],
            ]);
            $this->service->completeReconciliation(
                $reconciliation,
                $request->user()->id,
                $validated['expected_version'] ?? null,
                $validated['idempotency_key'] ?? null,
            );
        } catch (BankReconciliationConflict $e) {
            return redirect()->back()
                ->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return redirect()->route('finance.bank-reconciliation.show', $reconciliation)
            ->with('success', 'Reconciliation completed successfully.');
    }

    public function amend(Request $request, FinBankReconciliation $reconciliation)
    {
        $this->authorize('complete', $reconciliation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:255'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $amendment = $this->service->createAmendment(
                $reconciliation,
                $request->user()->id,
                $validated['reason'],
                $validated['evidence_reference'],
                $validated['expected_version'],
                $validated['idempotency_key'] ?? null,
            );
        } catch (BankReconciliationConflict $exception) {
            return redirect()->back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return redirect()->route('finance.bank-reconciliation.show', $amendment)
            ->with('success', 'Evidence-backed reconciliation correction started.');
    }
}
