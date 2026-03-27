<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinGstReturn;
use Carbon\Carbon;

class NzComplianceService
{
    /**
     * Generate data structured for IRD GST101A form.
     * This is informational — actual filing is manual via myIR.
     */
    public function generateGst101AData(FinGstReturn $return): array
    {
        $return->loadMissing('lines.taxRate', 'lines.account');

        $totalSales = (float) $return->total_sales;
        $totalGstCollected = (float) $return->total_gst_collected;
        $totalPurchases = (float) $return->total_purchases;
        $totalGstPaid = (float) $return->total_gst_paid;

        // Calculate zero-rated supplies (lines where tax rate is 0%)
        $zeroRated = 0.0;
        $exempt = 0.0;

        foreach ($return->lines as $line) {
            $accountType = $line->account->type ?? '';
            if ($accountType !== 'revenue') {
                continue;
            }

            $rate = (float) ($line->taxRate->rate ?? 0);
            $netAmount = (float) $line->net_amount;

            if ($rate == 0 && $line->tax_rate_id !== null) {
                // Zero-rated supply (has a tax rate but it's 0%)
                $zeroRated += $netAmount;
            }
        }

        // Box 5: Total sales and income for the period (including GST)
        $box5 = $totalSales + $totalGstCollected;

        // Box 6: Zero-rated supplies included in Box 5
        $box6 = $zeroRated;

        // Box 7: Exempt supplies (not subject to GST)
        $box7 = $exempt;

        // Box 8: Total sales and income subject to GST (Box 5 - Box 6 - Box 7)
        $box8 = $box5 - $box6 - $box7;

        // Box 9: Output tax (GST on sales)
        $box9 = $totalGstCollected;

        // Box 11: Total purchases and expenses (including GST)
        $box11 = $totalPurchases + $totalGstPaid;

        // Box 12: Input tax (GST on purchases)
        $box12 = $totalGstPaid;

        // Box 13: Net GST to pay (or refund if negative)
        $box13 = $box9 - $box12;

        return [
            'period_start' => $return->period_start->toDateString(),
            'period_end' => $return->period_end->toDateString(),
            'ird_period' => $return->ird_period,
            'filing_frequency' => $return->filing_frequency,
            'basis' => $return->basis,
            'box_5' => round($box5, 2),
            'box_5_label' => 'Total sales and income for the period',
            'box_6' => round($box6, 2),
            'box_6_label' => 'Zero-rated supplies',
            'box_7' => round($box7, 2),
            'box_7_label' => 'Exempt supplies',
            'box_8' => round($box8, 2),
            'box_8_label' => 'Total sales subject to GST',
            'box_9' => round($box9, 2),
            'box_9_label' => 'Output tax on sales',
            'box_11' => round($box11, 2),
            'box_11_label' => 'Total purchases and expenses',
            'box_12' => round($box12, 2),
            'box_12_label' => 'Input tax on purchases',
            'box_13' => round($box13, 2),
            'box_13_label' => $box13 >= 0 ? 'GST to pay to IRD' : 'GST refund due from IRD',
        ];
    }

    /**
     * Return filing period dates for a given frequency and year.
     * Monthly = every month, due 28th of following month.
     * Two-monthly = Jan-Feb, Mar-Apr, etc., due 28th of month after period.
     * Six-monthly = Jan-Jun, Jul-Dec.
     */
    public function getGstFilingDates(string $frequency, int $year): array
    {
        $periods = [];

        switch ($frequency) {
            case 'monthly':
                for ($m = 1; $m <= 12; $m++) {
                    $start = Carbon::create($year, $m, 1);
                    $end = $start->copy()->endOfMonth();
                    $due = $end->copy()->addMonth()->day(28);

                    $periods[] = [
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'due_date' => $due->toDateString(),
                        'ird_period' => $end->format('Ym'),
                    ];
                }
                break;

            case 'two_monthly':
                for ($m = 1; $m <= 11; $m += 2) {
                    $start = Carbon::create($year, $m, 1);
                    $end = $start->copy()->addMonth()->endOfMonth();
                    $due = $end->copy()->addMonth()->day(28);

                    $periods[] = [
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'due_date' => $due->toDateString(),
                        'ird_period' => $end->format('Ym'),
                    ];
                }
                break;

            case 'six_monthly':
                // Jan-Jun
                $start1 = Carbon::create($year, 1, 1);
                $end1 = Carbon::create($year, 6, 30);
                $due1 = Carbon::create($year, 7, 28);

                $periods[] = [
                    'period_start' => $start1->toDateString(),
                    'period_end' => $end1->toDateString(),
                    'due_date' => $due1->toDateString(),
                    'ird_period' => $end1->format('Ym'),
                ];

                // Jul-Dec
                $start2 = Carbon::create($year, 7, 1);
                $end2 = Carbon::create($year, 12, 31);
                $due2 = Carbon::create($year + 1, 1, 28);

                $periods[] = [
                    'period_start' => $start2->toDateString(),
                    'period_end' => $end2->toDateString(),
                    'due_date' => $due2->toDateString(),
                    'ird_period' => $end2->format('Ym'),
                ];
                break;
        }

        return $periods;
    }
}
