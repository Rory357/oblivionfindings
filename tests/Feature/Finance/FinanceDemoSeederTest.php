<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use Database\Seeders\FinanceDemoSeeder;
use Illuminate\Support\Carbon;

/**
 * FinanceDemoSeeder backs the "every hub renders populated on migrate:fresh
 * --seed" goal — without it the finance hubs and the obligation calendar render
 * empty on a fresh database.
 */
it('populates every transactional finance hub for organization 1', function () {
    $this->seed(FinanceDemoSeeder::class);

    expect(FinAccount::where('organization_id', 1)->count())->toBeGreaterThan(0)
        ->and(FinVendor::where('organization_id', 1)->count())->toBeGreaterThan(0)
        ->and(FinInvoice::where('organization_id', 1)->count())->toBeGreaterThan(0)
        ->and(FinBill::where('organization_id', 1)->count())->toBeGreaterThan(0)
        ->and(FinPaymentRun::where('organization_id', 1)->count())->toBeGreaterThan(0)
        ->and(FinGstReturn::where('organization_id', 1)->count())->toBeGreaterThan(0);
});

it('gives the finance calendar live events around today, including an overdue marker', function () {
    $this->seed(FinanceDemoSeeder::class);

    $items = app(FinanceCalendarAggregator::class)->itemsForRange(
        1,
        Carbon::now()->subMonths(2)->startOfMonth(),
        Carbon::now()->addMonths(2)->endOfMonth(),
    );

    expect($items)->not->toBeEmpty()
        ->and(collect($items)->pluck('status')->unique()->all())->toContain('overdue');
});

it('is idempotent — re-running does not duplicate the demo data', function () {
    $this->seed(FinanceDemoSeeder::class);
    $invoiceCount = FinInvoice::where('organization_id', 1)->count();

    $this->seed(FinanceDemoSeeder::class);

    expect(FinInvoice::where('organization_id', 1)->count())->toBe($invoiceCount);
});
