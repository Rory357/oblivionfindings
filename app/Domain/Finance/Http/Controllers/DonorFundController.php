<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinDonorFundReport;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Services\DonorFundService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DonorFundController extends Controller
{
    public function __construct(
        private DonorFundService $donorFundService,
    ) {}

    /**
     * List all donor funds with summary.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinDonorFund::forOrganization($orgId)
            ->with('glAccount:id,code,name', 'fundingStream:id,name')
            ->orderBy('fund_name');

        $this->applyFundFilters($query, $request);

        $funds = $query->paginate(20)->withQueryString()->through(fn (FinDonorFund $fund) => [
                'id' => $fund->id,
                'fund_code' => $fund->fund_code,
                'fund_name' => $fund->fund_name,
                'donor_name' => $fund->donor_name,
                'fund_type' => $fund->fund_type,
                'total_received' => (float) $fund->total_received,
                'total_spent' => (float) $fund->total_spent,
                'available_balance' => (float) $fund->available_balance,
                'budget_amount' => $fund->budget_amount ? (float) $fund->budget_amount : null,
                'status' => $fund->status,
                'is_restricted' => $fund->is_restricted,
                'start_date' => $fund->start_date?->toDateString(),
                'end_date' => $fund->end_date?->toDateString(),
                'next_report_due' => $fund->next_report_due?->toDateString(),
                'gl_account_name' => $fund->glAccount ? $fund->glAccount->code.' - '.$fund->glAccount->name : null,
                'funding_stream_name' => $fund->fundingStream?->name,
            ]);

        $summary = $this->donorFundService->getFundsSummary($orgId);

        // The store route is gated by finance.admin — mirror that so the create
        // modal (and its reference data) only appears for users who can post.
        $canManage = (bool) $request->user()->canDo('finance.admin');

        return Inertia::render('finance/donor-funds/Index', [
            'funds' => $funds,
            'filters' => $request->only(['search', 'status', 'restricted']),
            'summary' => $summary,
            'canManage' => $canManage,
            'glAccounts' => $canManage ? $this->fundGlAccounts($orgId) : [],
            'fundingStreams' => $canManage ? $this->fundFundingStreams($orgId) : [],
        ]);
    }

    /**
     * Stream the donor-fund list as a sanitised CSV. Honours the same
     * search / status / restricted filters as the index so "Export" respects the
     * current view.
     */
    public function export(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinDonorFund::forOrganization($orgId)->orderBy('fund_name');
        $this->applyFundFilters($query, $request);

        $rows = $query->get()->map(fn (FinDonorFund $fund) => [
                $fund->fund_code,
                $fund->fund_name,
                $fund->donor_name,
                number_format((float) $fund->total_received, 2, '.', ''),
                number_format((float) $fund->total_spent, 2, '.', ''),
                number_format((float) $fund->available_balance, 2, '.', ''),
                $fund->status,
            ]);

        return $this->streamSanitizedCsv(
            'donor-funds-'.now()->format('Y-m-d').'.csv',
            ['Fund Code', 'Name', 'Donor', 'Total Received', 'Total Spent', 'Balance', 'Status'],
            $rows,
        );
    }

    /**
     * Apply the shared donor-fund list filters (search / status / restricted) so the
     * index list and the CSV export always show the same rows for a given query string.
     *
     * @param  Builder<FinDonorFund>  $query
     */
    private function applyFundFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('fund_name', 'like', "%{$search}%")
                    ->orWhere('fund_code', 'like', "%{$search}%")
                    ->orWhere('donor_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('restricted')) {
            $query->where('is_restricted', $request->input('restricted') === 'restricted');
        }
    }

    /** Active liability/equity GL accounts for the donor-fund modal. */
    private function fundGlAccounts(?int $orgId)
    {
        return FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['liability', 'equity'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /** Active funding streams for the donor-fund modal. */
    private function fundFundingStreams(?int $orgId)
    {
        return FinFundingStream::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Store a new donor fund.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fund_code' => 'required|string|max:50',
            'fund_name' => 'required|string|max:255',
            'donor_name' => 'nullable|string|max:255',
            'donor_contact' => 'nullable|string|max:255',
            'fund_type' => 'required|in:grant,donation,bequest,trust,government,sponsorship',
            'gl_account_id' => 'nullable|exists:fin_accounts,id',
            'funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
            'budget_amount' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'restrictions' => 'nullable|string|max:2000',
            'reporting_requirements' => 'nullable|string|max:2000',
            'next_report_due' => 'nullable|date',
            'is_restricted' => 'boolean',
        ]);

        $orgId = $request->user()->organization_id;

        $fund = FinDonorFund::create([
            ...$validated,
            'organization_id' => $orgId,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Donor fund created successfully.');
    }

    /**
     * Show fund detail with transactions.
     */
    public function show(Request $request, FinDonorFund $fund)
    {
        $orgId = $request->user()->organization_id;

        $fund->load('glAccount:id,code,name', 'fundingStream:id,name', 'createdBy:id,name');

        $transactions = $fund->transactions()
            ->with('createdBy:id,name', 'journal:id,journal_number')
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($txn) => [
                'id' => $txn->id,
                'transaction_date' => $txn->transaction_date->toDateString(),
                'type' => $txn->type,
                'description' => $txn->description,
                'amount' => (float) $txn->amount,
                'reference' => $txn->reference,
                'journal_number' => $txn->journal?->journal_number,
                'created_by' => $txn->createdBy?->name,
            ]);

        $reports = $fund->reports()
            ->orderByDesc('period_to')
            ->limit(20)
            ->get()
            ->map(fn ($report) => [
                'id' => $report->id,
                'report_name' => $report->report_name,
                'period_from' => $report->period_from->toDateString(),
                'period_to' => $report->period_to->toDateString(),
                'opening_balance' => (float) $report->opening_balance,
                'total_receipts' => (float) $report->total_receipts,
                'total_expenditure' => (float) $report->total_expenditure,
                'closing_balance' => (float) $report->closing_balance,
                'status' => $report->status,
                'download_url' => $report->file_path
                    ? route('finance.donor-funds.reports.download', [$fund, $report])
                    : null,
            ]);

        $expenseAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->where('type', 'expense')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('finance/donor-funds/Show', [
            'fund' => [
                'id' => $fund->id,
                'fund_code' => $fund->fund_code,
                'fund_name' => $fund->fund_name,
                'donor_name' => $fund->donor_name,
                'donor_contact' => $fund->donor_contact,
                'fund_type' => $fund->fund_type,
                'total_received' => (float) $fund->total_received,
                'total_spent' => (float) $fund->total_spent,
                'total_committed' => (float) $fund->total_committed,
                'available_balance' => (float) $fund->available_balance,
                'budget_amount' => $fund->budget_amount ? (float) $fund->budget_amount : null,
                'start_date' => $fund->start_date?->toDateString(),
                'end_date' => $fund->end_date?->toDateString(),
                'restrictions' => $fund->restrictions,
                'reporting_requirements' => $fund->reporting_requirements,
                'next_report_due' => $fund->next_report_due?->toDateString(),
                'status' => $fund->status,
                'is_restricted' => $fund->is_restricted,
                'gl_account_name' => $fund->glAccount ? $fund->glAccount->code.' - '.$fund->glAccount->name : null,
                // Structured GL account so the transaction modal can render the trust-journal preview.
                'gl_account' => $fund->glAccount ? ['code' => $fund->glAccount->code, 'name' => $fund->glAccount->name] : null,
                'funding_stream_name' => $fund->fundingStream?->name,
                'created_by' => $fund->createdBy?->name,
            ],
            'transactions' => $transactions,
            'reports' => $reports,
            'expenseAccounts' => $expenseAccounts,
            'bankAccounts' => $bankAccounts,
            // Receipts/expenditure post under finance.admin — gate the modals to match.
            'canManage' => (bool) $request->user()->canDo('finance.admin'),
        ]);
    }

    /**
     * Update fund details.
     */
    public function update(Request $request, FinDonorFund $fund)
    {
        $validated = $request->validate([
            'fund_name' => 'required|string|max:255',
            'donor_name' => 'nullable|string|max:255',
            'donor_contact' => 'nullable|string|max:255',
            'fund_type' => 'required|in:grant,donation,bequest,trust,government,sponsorship',
            'gl_account_id' => 'nullable|exists:fin_accounts,id',
            'funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
            'budget_amount' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'restrictions' => 'nullable|string|max:2000',
            'reporting_requirements' => 'nullable|string|max:2000',
            'next_report_due' => 'nullable|date',
            'is_restricted' => 'boolean',
            'status' => 'nullable|in:active,fully_spent,expired,returned',
        ]);

        $fund->update($validated);

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Fund updated successfully.');
    }

    /**
     * Record a receipt against a fund.
     */
    public function receipt(Request $request, FinDonorFund $fund)
    {
        $validated = $request->validate([
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:fin_bank_accounts,id',
        ]);

        try {
            $this->donorFundService->recordReceipt($fund, $validated);
        } catch (\Exception $e) {
            return back()->withErrors(['receipt' => $e->getMessage()]);
        }

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Receipt recorded successfully.');
    }

    /**
     * Record an expenditure against a fund.
     */
    public function expenditure(Request $request, FinDonorFund $fund)
    {
        $validated = $request->validate([
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'expense_account_id' => 'nullable|exists:fin_accounts,id',
            'bill_id' => 'nullable|exists:fin_bills,id',
        ]);

        try {
            $this->donorFundService->recordExpenditure($fund, $validated);
        } catch (\Exception $e) {
            return back()->withErrors(['expenditure' => $e->getMessage()]);
        }

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Expenditure recorded successfully.');
    }

    /**
     * Generate a fund report.
     */
    public function report(Request $request, FinDonorFund $fund)
    {
        $validated = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
        ]);

        try {
            $report = $this->donorFundService->generateReport($fund, $validated['period_from'], $validated['period_to']);
            $this->donorFundService->exportReportPdf($report);
        } catch (\Exception $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Report generated successfully.');
    }

    /**
     * List reports for a fund.
     */
    public function reports(Request $request, FinDonorFund $fund)
    {
        return $this->show($request, $fund);
    }

    public function downloadReport(Request $request, FinDonorFund $fund, FinDonorFundReport $report)
    {
        abort_unless($fund->organization_id === $request->user()->organization_id, 403);
        abort_unless($report->fund_id === $fund->id, 404);
        abort_unless($report->file_path && Storage::disk('local')->exists($report->file_path), 404);

        return Storage::disk('local')->download(
            $report->file_path,
            str($report->report_name)->slug()->append('.pdf')->toString()
        );
    }
}
