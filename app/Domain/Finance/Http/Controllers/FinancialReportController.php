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

        $data = $this->reportService->getFundingStreamSummary($orgId, $startDate, $endDate);

        return Inertia::render('finance/reports/FundingStreamSummary', [
            'report' => $data,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
