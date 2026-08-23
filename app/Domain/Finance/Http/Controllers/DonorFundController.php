<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinDonorFundReport;
use App\Domain\Finance\Models\FinDonorFundTransaction;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Services\DonorFundApplicationSiteScope;
use App\Domain\Finance\Services\DonorFundService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DonorFundController extends Controller
{
    public function __construct(
        private DonorFundService $donorFundService,
        private DonorFundApplicationSiteScope $billSiteScope,
    ) {}

    /**
     * List all donor funds with summary.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinDonorFund::forOrganization($orgId)
            ->with('glAccount:id,organization_id,code,name', 'fundingStream:id,organization_id,name')
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
            'gl_account_name' => $fund->glAccount
                && (int) $fund->glAccount->organization_id === (int) $fund->organization_id
                ? $fund->glAccount->code.' - '.$fund->glAccount->name
                : null,
            'funding_stream_name' => $fund->fundingStream
                && (int) $fund->fundingStream->organization_id === (int) $fund->organization_id
                ? $fund->fundingStream->name
                : null,
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
        $orgId = $request->user()->organization_id;

        $validated = $request->validate([
            'fund_code' => 'required|string|max:50',
            'fund_name' => 'required|string|max:255',
            'donor_name' => 'nullable|string|max:255',
            'donor_contact' => 'nullable|string|max:255',
            'fund_type' => 'required|in:grant,donation,bequest,trust,government,sponsorship',
            'gl_account_id' => [
                'nullable',
                Rule::exists('fin_accounts', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->whereIn('type', ['liability', 'equity'])
                        ->where('is_active', true),
                ),
            ],
            'funding_stream_id' => [
                'nullable',
                Rule::exists('fin_funding_streams', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->where('is_active', true),
                ),
            ],
            'budget_amount' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'restrictions' => 'nullable|string|max:2000',
            'reporting_requirements' => 'nullable|string|max:2000',
            'next_report_due' => 'nullable|date',
            'is_restricted' => 'boolean',
        ]);

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
        $this->assertFundOrganization($request, $fund);

        $fund->load(
            'glAccount:id,organization_id,code,name,type,is_active',
            'fundingStream:id,organization_id,name,default_revenue_account_id,is_active',
            'fundingStream.defaultRevenueAccount:id,organization_id,code,name,type,is_active',
            'createdBy:id,name',
        );

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

        $canManage = (bool) $request->user()->canDo('finance.admin');
        $expenseAccounts = $canManage
            ? FinAccount::forOrganization($orgId)
                ->active()
                ->where('type', 'expense')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
            : collect();
        $bankAccounts = $canManage
            ? FinBankAccount::forOrganization($orgId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();
        $eligibleBills = $canManage
            ? $this->billSiteScope->applyBillScope(
                FinBill::forOrganization($orgId),
                $request->user(),
            )
                ->whereIn('status', ['approved', 'partially_paid', 'paid'])
                ->whereNotNull('journal_id')
                ->whereHas('journal', fn ($query) => $query
                    ->where('status', 'posted')
                    ->where('source_type', FinBill::class)
                    ->whereColumn('fin_journals.organization_id', 'fin_bills.organization_id')
                    ->whereColumn('fin_journals.source_id', 'fin_bills.id')
                    ->whereNull('reversal_of_journal_id')
                    ->whereNull('reversed_by_journal_id'))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('fin_donor_fund_transactions')
                        ->whereColumn('fin_donor_fund_transactions.bill_id', 'fin_bills.id');
                })
                ->orderByDesc('bill_date')
                ->limit(100)
                ->get(['id', 'bill_number', 'total_amount'])
                ->map(fn (FinBill $bill) => [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'total_amount' => (float) $bill->total_amount,
                ])
            : collect();

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
                'gl_account_name' => $fund->glAccount
                    && (int) $fund->glAccount->organization_id === (int) $fund->organization_id
                    ? $fund->glAccount->code.' - '.$fund->glAccount->name
                    : null,
                // Structured GL account so the transaction modal can render the trust-journal preview.
                'gl_account' => $fund->glAccount
                    && (int) $fund->glAccount->organization_id === (int) $fund->organization_id
                    && $fund->glAccount->is_active
                    && in_array($fund->glAccount->type, ['liability', 'equity'], true)
                    ? ['code' => $fund->glAccount->code, 'name' => $fund->glAccount->name]
                    : null,
                'funding_stream_name' => $fund->fundingStream
                    && (int) $fund->fundingStream->organization_id === (int) $fund->organization_id
                    ? $fund->fundingStream->name
                    : null,
                'release_account' => $fund->fundingStream
                    && (int) $fund->fundingStream->organization_id === (int) $fund->organization_id
                    && $fund->fundingStream->is_active
                    && $fund->fundingStream->defaultRevenueAccount
                    && (int) $fund->fundingStream->defaultRevenueAccount->organization_id === (int) $fund->organization_id
                    && $fund->fundingStream->defaultRevenueAccount->is_active
                    && $fund->fundingStream->defaultRevenueAccount->type === 'revenue'
                    ? [
                        'code' => $fund->fundingStream->defaultRevenueAccount->code,
                        'name' => $fund->fundingStream->defaultRevenueAccount->name,
                    ]
                    : null,
                'created_by' => $fund->createdBy?->name,
            ],
            'transactions' => $transactions,
            'reports' => $reports,
            'expenseAccounts' => $expenseAccounts,
            'bankAccounts' => $bankAccounts,
            'eligibleBills' => $eligibleBills,
            // Receipts/expenditure post under finance.admin — gate the modals to match.
            'canManage' => $canManage,
        ]);
    }

    /**
     * Update fund details.
     */
    public function update(Request $request, FinDonorFund $fund)
    {
        $orgId = $request->user()->organization_id;
        $this->assertFundOrganization($request, $fund);

        $validated = $request->validate([
            'fund_name' => 'required|string|max:255',
            'donor_name' => 'nullable|string|max:255',
            'donor_contact' => 'nullable|string|max:255',
            'fund_type' => 'required|in:grant,donation,bequest,trust,government,sponsorship',
            'gl_account_id' => [
                'nullable',
                Rule::exists('fin_accounts', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->whereIn('type', ['liability', 'equity'])
                        ->where('is_active', true),
                ),
            ],
            'funding_stream_id' => [
                'nullable',
                Rule::exists('fin_funding_streams', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->where('is_active', true),
                ),
            ],
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
        $orgId = $request->user()->organization_id;
        $this->assertFundOrganization($request, $fund);

        $validated = $request->validate([
            'idempotency_key' => 'required|uuid',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'bank_account_id' => [
                'nullable',
                Rule::exists('fin_bank_accounts', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->where('is_active', true),
                ),
            ],
        ]);

        try {
            $this->donorFundService->recordReceipt($fund, $validated);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['receipt' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'receipt' => 'The receipt could not be recorded safely. No changes were saved.',
            ]);
        }

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Receipt recorded successfully.');
    }

    /**
     * Record an expenditure against a fund.
     */
    public function expenditure(Request $request, FinDonorFund $fund)
    {
        $orgId = $request->user()->organization_id;
        $this->assertFundOrganization($request, $fund);

        if ($request->filled('bill_id')) {
            $bill = FinBill::query()->find($request->integer('bill_id'));
            abort_unless($bill !== null, 404);
            $this->billSiteScope->assertCanAccessBill($request->user(), $bill);
        }

        $accessibleSiteIds = $this->billSiteScope->accessibleSiteIdsFor($request->user());

        $validated = $request->validate([
            'idempotency_key' => 'required|uuid',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'expense_account_id' => [
                'nullable',
                Rule::exists('fin_accounts', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->where('type', 'expense')
                        ->where('is_active', true),
                ),
            ],
            'bill_id' => [
                'required',
                Rule::exists('fin_bills', 'id')->where(
                    fn ($query) => $query
                        ->where('organization_id', $orgId)
                        ->whereIn('site_id', $accessibleSiteIds)
                        ->whereIn('status', ['approved', 'partially_paid', 'paid']),
                ),
            ],
        ]);

        try {
            $this->donorFundService->recordExpenditure($fund, $validated);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['expenditure' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'expenditure' => 'The expenditure could not be recorded safely. No changes were saved.',
            ]);
        }

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Expenditure recorded successfully.');
    }

    /**
     * Reverse one immutable donor-fund application through its canonical journal.
     */
    public function reverse(
        Request $request,
        FinDonorFund $fund,
        FinDonorFundTransaction $transaction,
    ) {
        $this->assertFundOrganization($request, $fund);
        abort_unless((int) $transaction->fund_id === (int) $fund->id, 404);

        $validated = $request->validate([
            'idempotency_key' => 'required|uuid',
            'transaction_date' => 'required|date',
            'reason' => 'required|string|max:500',
            'reference' => 'nullable|string|max:255',
        ]);

        try {
            $this->donorFundService->reverseTransaction($transaction, $validated);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'reversal' => 'The reversal could not be recorded safely. No changes were saved.',
            ]);
        }

        return redirect()->route('finance.donor-funds.show', $fund)
            ->with('success', 'Transaction reversed successfully.');
    }

    /**
     * Generate a fund report.
     */
    public function report(Request $request, FinDonorFund $fund)
    {
        $this->assertFundOrganization($request, $fund);

        $validated = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
        ]);

        try {
            $report = $this->donorFundService->generateReport($fund, $validated['period_from'], $validated['period_to']);
            $this->donorFundService->exportReportPdf($report);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'report' => 'The report could not be generated safely. Please try again.',
            ]);
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
        $this->assertFundOrganization($request, $fund);
        abort_unless((int) $report->fund_id === (int) $fund->id, 404);
        abort_unless($report->file_path && Storage::disk('local')->exists($report->file_path), 404);

        return Storage::disk('local')->download(
            $report->file_path,
            str($report->report_name)->slug()->append('.pdf')->toString()
        );
    }

    private function assertFundOrganization(Request $request, FinDonorFund $fund): void
    {
        abort_unless(
            (int) $fund->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
