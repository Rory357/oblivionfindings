<?php

use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Domain\Hr\Models\HrPayrollRun;
use Illuminate\Support\Carbon;

/**
 * Gap 5.3: the finance calendar aggregator now also surfaces payroll runs and
 * fiscal-period close dates alongside invoices/bills/payment-runs/GST.
 */
it('includes payroll and period-close obligations on the finance calendar', function () {
    HrPayrollRun::create([
        'tenant_id' => 1,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-14',
        'status' => 'locked',
        'total_gross' => 5000,
    ]);
    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'Jun 2026',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'status' => 'open',
    ]);

    $items = app(FinanceCalendarAggregator::class)->itemsForRange(
        1,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
    );
    $sources = collect($items)->map(fn ($i) => $i->source)->unique()->values();

    expect($sources)->toContain('payroll')
        ->and($sources)->toContain('period_close');

    $payroll = collect($items)->firstWhere('source', 'payroll');
    expect($payroll->amount)->toBe(5000.0)
        ->and($payroll->status)->toBe('scheduled');
});
