<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\FinancialReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FinancialReportController extends Controller
{
    public function __construct(
        private FinancialReportService $reportService,
    ) {}

    public function trialBalance(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $asOfDate = $request->input('as_of_date', now()->toDateString());

        $data = $this->reportService->getTrialBalance($orgId, $asOfDate);

        return Inertia::render('finance/reports/TrialBalance', [
            'report' => $data,
            'filters' => [
                'as_of_date' => $asOfDate,
            ],
        ]);
    }

    public function profitAndLoss(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $data = $this->reportService->getProfitAndLoss($orgId, $startDate, $endDate);

        return Inertia::render('finance/reports/ProfitAndLoss', [
            'report' => $data,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $asOfDate = $request->input('as_of_date', now()->toDateString());

        $data = $this->reportService->getBalanceSheet($orgId, $asOfDate);

        return Inertia::render('finance/reports/BalanceSheet', [
            'report' => $data,
            'filters' => [
                'as_of_date' => $asOfDate,
            ],
        ]);
    }

    public function cashFlow(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $data = $this->reportService->getCashFlow($orgId, $startDate, $endDate);

        return Inertia::render('finance/reports/CashFlow', [
            'report' => $data,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function agedPayables(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $data = $this->reportService->getAgedPayables($orgId);

        return Inertia::render('finance/reports/AgedPayables', [
            'report' => $data,
        ]);
    }

    public function agedReceivables(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $data = $this->reportService->getAgedReceivables($orgId);

        return Inertia::render('finance/reports/AgedReceivables', [
            'report' => $data,
        ]);
    }

    public function fundingStreamSummary(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $report = $this->reportService->getFundingStreamSummary($orgId, $startDate, $endDate);
        $data = [
            'streams' => collect($report['rows'] ?? [])->map(fn (array $row) => [
                'name' => $row['funding_stream_name'] ?? 'Unknown funding stream',
                'revenue' => (float) ($row['revenue'] ?? 0),
                'expenses' => (float) ($row['expenses'] ?? 0),
                'net_margin' => (float) ($row['net_margin'] ?? 0),
                'margin_pct' => (float) ($row['margin_pct'] ?? 0),
            ])->values()->all(),
            'totals' => [
                'revenue' => (float) ($report['total_revenue'] ?? 0),
                'expenses' => (float) ($report['total_expenses'] ?? 0),
                'net_margin' => (float) ($report['total_net_margin'] ?? 0),
            ],
        ];

        return Inertia::render('finance/reports/FundingStreamSummary', [
            'report' => $report,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'data' => $data,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
