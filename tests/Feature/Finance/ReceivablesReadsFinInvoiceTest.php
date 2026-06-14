<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Domain\Finance\Services\FinancialReportService;
use App\Models\Client;

/**
 * Regression for the AR data-blindness P0: the receivables index, aged-AR report
 * and statements used to read the orphaned legacy App\Models\Invoice table (which
 * nothing writes to any more), so every AR surface rendered empty. They now read
 * the live FinInvoice table and net partial payments.
 */
beforeEach(function () {
    $this->client = Client::factory()->create([
        'organization_id' => 1,
        'first_name' => 'Ana',
        'last_name' => 'Smith',
    ]);

    // A sent invoice for $100, partially paid $30 → $70 outstanding.
    $this->invoice = FinInvoice::factory()->create([
        'organization_id' => 1,
        'client_id' => $this->client->id,
        'status' => 'sent',
        'total_amount' => '100.00',
        'invoice_date' => now()->subDays(10),
        'due_date' => now()->addDays(20),
    ]);

    FinPaymentAllocation::create([
        'organization_id' => 1,
        'type' => 'receivable',
        'payment_date' => now()->subDays(2),
        'amount' => '30.00',
        'allocatable_type' => FinInvoice::class,
        'allocatable_id' => $this->invoice->id,
    ]);
});

it('aged receivables reads FinInvoice and nets partial payments', function () {
    $aged = app(AccountsReceivableService::class)->getAgedReceivables(1);

    expect($aged['clients'])->toHaveCount(1)
        ->and($aged['clients'][0]['client_name'])->toBe('Ana Smith')
        ->and($aged['clients'][0]['total'])->toBe(70.0)
        ->and($aged['totals']['total'])->toBe(70.0);
});

it('outstanding invoices read FinInvoice with netted amount_due', function () {
    $invoices = app(AccountsReceivableService::class)->getOutstandingInvoices(1);

    expect($invoices)->toHaveCount(1)
        ->and((float) $invoices->first()->amount_due)->toBe(70.0)
        ->and((float) $invoices->first()->amount_paid)->toBe(30.0);
});

it('client statement reads FinInvoice with netted amount_due', function () {
    $stmt = app(AccountsReceivableService::class)
        ->generateStatement(1, $this->client->id, now()->toDateString());

    expect($stmt['invoices'])->toHaveCount(1)
        ->and($stmt['invoices'][0]['amount_due'])->toBe(70.0)
        ->and($stmt['total_outstanding'])->toBe(70.0);
});

it('financial-report aged receivables nets partial payments from FinInvoice', function () {
    $report = app(FinancialReportService::class)->getAgedReceivables(1);

    expect($report['rows'])->toHaveCount(1)
        ->and($report['grand_total']['total'])->toBe(70.0);
});

it('a fully-paid invoice drops off the outstanding/aged surfaces', function () {
    FinPaymentAllocation::create([
        'organization_id' => 1,
        'type' => 'receivable',
        'payment_date' => now(),
        'amount' => '70.00',
        'allocatable_type' => FinInvoice::class,
        'allocatable_id' => $this->invoice->id,
    ]);

    expect(app(AccountsReceivableService::class)->getOutstandingInvoices(1))->toHaveCount(0)
        ->and(app(AccountsReceivableService::class)->getAgedReceivables(1)['totals']['total'])->toBe(0.0);
});
