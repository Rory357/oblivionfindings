<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AccountsReceivableService::captureOperationalInvoice — the AR capture-at-source
 * helper (C7c respite → funder invoice builds on it). Draft invoice, idempotent
 * on source_type/source_id, revenue account resolved by code, zero-amount no-op.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));
    FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '4000', 'name' => 'Funding Revenue', 'type' => 'revenue', 'is_active' => true,
    ]);
});

it('captures an operational event as a draft invoice billed to the funder', function () {
    $invoice = app(AccountsReceivableService::class)->captureOperationalInvoice(1, [
        'source_type' => 'App\\Models\\RespiteBooking',
        'source_id' => 7,
        'funding_body' => 'Whaikaha',
        'description' => 'Respite care — 3 night(s)',
        'quantity' => 3,
        'unit_price' => 200.00,
        'gst_rate' => 0,
        'revenue_account_code' => '4000',
    ]);

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->funding_body)->toBe('Whaikaha')
        ->and($invoice->client_name)->toBe('Whaikaha') // falls back to the funder
        ->and($invoice->source_type)->toBe('App\\Models\\RespiteBooking')
        ->and((int) $invoice->source_id)->toBe(7)
        ->and((float) $invoice->total_amount)->toBe(600.0) // 3 × 200, zero-rated
        ->and($invoice->lines->first()->account_id)->toBe(FinAccount::where('code', '4000')->value('id'));
});

it('is idempotent on the source — a second capture returns the same invoice', function () {
    $svc = app(AccountsReceivableService::class);
    $data = [
        'source_type' => 'App\\Models\\RespiteBooking', 'source_id' => 8, 'funding_body' => 'ACC',
        'description' => 'Respite', 'quantity' => 2, 'unit_price' => 150, 'gst_rate' => 0, 'revenue_account_code' => '4000',
    ];

    $a = $svc->captureOperationalInvoice(1, $data);
    $b = $svc->captureOperationalInvoice(1, $data);

    expect($a->id)->toBe($b->id)
        ->and(FinInvoice::where('source_type', 'App\\Models\\RespiteBooking')->where('source_id', 8)->count())->toBe(1);
});

it('is a no-op when the amount is zero', function () {
    $invoice = app(AccountsReceivableService::class)->captureOperationalInvoice(1, [
        'source_type' => 'App\\Models\\RespiteBooking', 'source_id' => 9, 'funding_body' => 'X',
        'description' => 'Zero', 'quantity' => 1, 'unit_price' => 0, 'gst_rate' => 0, 'revenue_account_code' => '4000',
    ]);

    expect($invoice)->toBeNull()
        ->and(FinInvoice::where('source_id', 9)->exists())->toBeFalse();
});
