<?php

use App\Domain\Finance\Services\AccountsReceivableService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AccountsReceivableService::createInvoice — the canonical AR counterpart to
 * createBill: a DRAFT invoice with bcmath totals, NZ 15% GST default (or a
 * per-line override), auto invoice number, and source_type/source_id capture.
 */
it('creates a draft invoice with 15% GST totals and lines', function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));

    $invoice = app(AccountsReceivableService::class)->createInvoice(1, [
        'funding_body' => 'Whaikaha',
        'source_type' => 'App\\Models\\RespiteBooking',
        'source_id' => 42,
        'lines' => [
            ['description' => 'Respite care — 3 nights', 'quantity' => 3, 'unit_price' => 200.00],
        ],
    ]);

    expect($invoice->status)->toBe('draft')
        ->and($invoice->organization_id)->toBe(1)
        ->and($invoice->funding_body)->toBe('Whaikaha')
        ->and($invoice->source_type)->toBe('App\\Models\\RespiteBooking')
        ->and((int) $invoice->source_id)->toBe(42)
        ->and((float) $invoice->subtotal)->toBe(600.0)
        ->and((float) $invoice->tax_amount)->toBe(90.0)   // 15% of 600
        ->and((float) $invoice->total_amount)->toBe(690.0)
        ->and($invoice->invoice_number)->toStartWith('INV-')
        ->and($invoice->lines)->toHaveCount(1);
});

it('honours a per-line gst_rate override (zero-rated)', function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));

    $invoice = app(AccountsReceivableService::class)->createInvoice(1, [
        'lines' => [
            ['description' => 'Zero-rated service', 'quantity' => 1, 'unit_price' => 500.00, 'gst_rate' => 0],
        ],
    ]);

    expect((float) $invoice->subtotal)->toBe(500.0)
        ->and((float) $invoice->tax_amount)->toBe(0.0)
        ->and((float) $invoice->total_amount)->toBe(500.0);
});

it('auto-generates sequential invoice numbers', function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));
    $svc = app(AccountsReceivableService::class);

    $a = $svc->createInvoice(1, ['lines' => [['description' => 'A', 'unit_price' => 100, 'gst_rate' => 0]]]);
    $b = $svc->createInvoice(1, ['lines' => [['description' => 'B', 'unit_price' => 100, 'gst_rate' => 0]]]);

    expect($a->invoice_number)->toStartWith('INV-')
        ->and($a->invoice_number)->not->toBe($b->invoice_number);
});
