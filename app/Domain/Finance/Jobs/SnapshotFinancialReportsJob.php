<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinReportSnapshot;
use App\Domain\Finance\Services\FinancialReportService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SnapshotFinancialReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FinancialReportService $reportService): void
    {
        $orgIds = User::whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id');

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        foreach ($orgIds as $orgId) {
            try {
                // Snapshot Profit & Loss for current month
                $pnl = $reportService->getProfitAndLoss($orgId, $monthStart, $monthEnd);

                FinReportSnapshot::create([
                    'organization_id' => $orgId,
                    'report_type' => 'profit_and_loss',
                    'period_start' => $monthStart,
                    'period_end' => $monthEnd,
                    'data' => $pnl,
                    'generated_at' => now(),
                ]);

                // Snapshot Balance Sheet as of today
                $balanceSheet = $reportService->getBalanceSheet($orgId, $today);

                FinReportSnapshot::create([
                    'organization_id' => $orgId,
                    'report_type' => 'balance_sheet',
                    'period_start' => $today,
                    'period_end' => $today,
                    'data' => $balanceSheet,
                    'generated_at' => now(),
                ]);

                Log::info("SnapshotFinancialReportsJob: snapshots created for org {$orgId}.");
            } catch (\Throwable $e) {
                Log::error("SnapshotFinancialReportsJob: failed for org {$orgId}: {$e->getMessage()}");
            }
        }
    }
}
