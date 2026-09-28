<?php

use App\Services\Fleet\MaintenanceCostSummary;

function costSummaryBill(array $values = []): array
{
    return array_replace(['id' => 1, 'status' => 'approved', 'total' => '100.10', 'paid' => '20.05',
        'purchase_order' => ['id' => 7, 'status' => 'approved', 'total_amount' => '300.30'],
        'journal' => ['status' => 'posted']], $values);
}

it('deduplicates commitments and keeps invoice, posting and payment separate with decimal precision', function () {
    $summary = (new MaintenanceCostSummary)->totals([
        costSummaryBill(),
        costSummaryBill(['id' => 2, 'status' => 'draft', 'journal' => null, 'total' => '200.20', 'paid' => '0.00']),
    ], 0);
    expect($summary)->toBe(['committed' => '300.30', 'invoice' => '300.30', 'posted' => '100.10', 'paid' => '20.05', 'incomplete' => false]);
});

it('excludes cancelled invoices, unapproved orders and reversed postings', function () {
    $summary = (new MaintenanceCostSummary)->totals([
        costSummaryBill(['status' => 'cancelled', 'purchase_order' => null]),
        costSummaryBill(['purchase_order' => ['id' => 9, 'status' => 'draft', 'total_amount' => '999.00'], 'journal' => ['status' => 'reversed']]),
    ], 0);
    expect($summary)->toBe(['committed' => null, 'invoice' => '100.10', 'posted' => '0.00', 'paid' => '20.05', 'incomplete' => false]);
});

it('does not present partial accessible amounts as complete totals', function () {
    expect((new MaintenanceCostSummary)->totals([costSummaryBill()], 1))
        ->toBe(['committed' => null, 'invoice' => null, 'posted' => null, 'paid' => null, 'incomplete' => true]);
});

it('distinguishes absent records from zero amounts', function () {
    expect((new MaintenanceCostSummary)->totals([], 0))
        ->toBe(['committed' => null, 'invoice' => null, 'posted' => null, 'paid' => null, 'incomplete' => false]);
    expect((new MaintenanceCostSummary)->totals([costSummaryBill(['total' => '0.00', 'paid' => '0.00', 'purchase_order' => null])], 0)['invoice'])->toBe('0.00');
});
