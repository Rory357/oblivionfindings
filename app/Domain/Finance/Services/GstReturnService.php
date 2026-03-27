<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinGstReturnLine;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinTaxRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GstReturnService
{
    /**
     * Prepare a GST return by querying posted journal lines within the period
     * that have a non-null tax_rate_id, grouping by revenue vs expense/asset.
     */
    public function prepareReturn(?int $orgId, array $data): FinGstReturn
    {
        return DB::transaction(function () use ($orgId, $data) {
            $periodStart = Carbon::parse($data['period_start']);
            $periodEnd = Carbon::parse($data['period_end']);

            // Get all posted journal lines with tax rates in the period
            $journalLines = FinJournalLine::query()
                ->whereNotNull('fin_journal_lines.tax_rate_id')
                ->whereHas('journal', function ($q) use ($orgId, $periodStart, $periodEnd) {
                    $q->where('organization_id', $orgId)
                      ->where('status', 'posted')
                      ->whereBetween('journal_date', [$periodStart, $periodEnd]);
                })
                ->with(['account:id,type,code,name', 'taxRate:id,name,code,rate', 'journal:id,journal_number,journal_date'])
                ->get();

            $totalSales = '0';
            $totalGstCollected = '0';
            $totalPurchases = '0';
            $totalGstPaid = '0';

            $lineRecords = [];

            foreach ($journalLines as $line) {
                $accountType = $line->account->type ?? '';
                $isRevenue = $accountType === 'revenue';
                $isExpenseOrAsset = in_array($accountType, ['expense', 'asset']);

                // Net amount is credit - debit for revenue, debit - credit for expense/asset
                if ($isRevenue) {
                    $netAmount = bcsub((string) $line->credit, (string) $line->debit, 2);
                    $gstAmount = (string) ($line->tax_amount ?? '0');
                    $totalSales = bcadd($totalSales, $netAmount, 2);
                    $totalGstCollected = bcadd($totalGstCollected, $gstAmount, 2);
                } elseif ($isExpenseOrAsset) {
                    $netAmount = bcsub((string) $line->debit, (string) $line->credit, 2);
                    $gstAmount = (string) ($line->tax_amount ?? '0');
                    $totalPurchases = bcadd($totalPurchases, $netAmount, 2);
                    $totalGstPaid = bcadd($totalGstPaid, $gstAmount, 2);
                } else {
                    // Liability/equity lines with tax — treat as purchase-side
                    $netAmount = bcsub((string) $line->debit, (string) $line->credit, 2);
                    $gstAmount = (string) ($line->tax_amount ?? '0');
                    $totalPurchases = bcadd($totalPurchases, $netAmount, 2);
                    $totalGstPaid = bcadd($totalGstPaid, $gstAmount, 2);
                }

                $lineRecords[] = [
                    'journal_line_id' => $line->id,
                    'account_id' => $line->account_id,
                    'description' => $line->description ?? ($line->journal->journal_number ?? ''),
                    'net_amount' => $netAmount,
                    'gst_amount' => $gstAmount,
                    'tax_rate_id' => $line->tax_rate_id,
                ];
            }

            $gstPayable = bcsub($totalGstCollected, $totalGstPaid, 2);
            $irdPeriod = $this->calculateIrdPeriod($periodEnd->toDateString());

            $gstReturn = FinGstReturn::create([
                'organization_id' => $orgId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'filing_frequency' => $data['filing_frequency'],
                'basis' => $data['basis'],
                'total_sales' => $totalSales,
                'total_gst_collected' => $totalGstCollected,
                'total_purchases' => $totalPurchases,
                'total_gst_paid' => $totalGstPaid,
                'gst_payable' => $gstPayable,
                'adjustments' => 0,
                'status' => 'draft',
                'ird_period' => $irdPeriod,
                'created_by' => Auth::id(),
            ]);

            foreach ($lineRecords as $record) {
                $gstReturn->lines()->create($record);
            }

            return $gstReturn->load('lines');
        });
    }

    /**
     * Mark a GST return as filed.
     */
    public function fileReturn(FinGstReturn $return, int $userId): FinGstReturn
    {
        $return->update([
            'status' => 'filed',
            'filed_at' => now(),
            'filed_by' => $userId,
        ]);

        return $return->refresh();
    }

    /**
     * Get a summary of the GST return including breakdown by tax rate.
     */
    public function getReturnSummary(FinGstReturn $return): array
    {
        $return->loadMissing('lines.taxRate');

        $byTaxRate = [];
        foreach ($return->lines as $line) {
            $rateId = $line->tax_rate_id;
            $rateName = $line->taxRate->name ?? 'Unknown';
            $rateCode = $line->taxRate->code ?? '';
            $ratePercent = $line->taxRate->rate ?? '0';

            if (! isset($byTaxRate[$rateId])) {
                $byTaxRate[$rateId] = [
                    'tax_rate_id' => $rateId,
                    'name' => $rateName,
                    'code' => $rateCode,
                    'rate' => $ratePercent,
                    'net_amount' => '0',
                    'gst_amount' => '0',
                    'line_count' => 0,
                ];
            }

            $byTaxRate[$rateId]['net_amount'] = bcadd($byTaxRate[$rateId]['net_amount'], (string) $line->net_amount, 2);
            $byTaxRate[$rateId]['gst_amount'] = bcadd($byTaxRate[$rateId]['gst_amount'], (string) $line->gst_amount, 2);
            $byTaxRate[$rateId]['line_count']++;
        }

        return [
            'total_sales' => (float) $return->total_sales,
            'total_gst_collected' => (float) $return->total_gst_collected,
            'total_purchases' => (float) $return->total_purchases,
            'total_gst_paid' => (float) $return->total_gst_paid,
            'gst_payable' => (float) $return->gst_payable,
            'adjustments' => (float) $return->adjustments,
            'net_gst' => (float) $return->gst_payable,
            'is_refund' => bccomp((string) $return->gst_payable, '0', 2) < 0,
            'breakdown_by_tax_rate' => array_values($byTaxRate),
        ];
    }

    /**
     * Convert end date to IRD period format YYYYMM.
     */
    public function calculateIrdPeriod(string $periodEnd): string
    {
        $date = Carbon::parse($periodEnd);

        return $date->format('Ym');
    }
}
