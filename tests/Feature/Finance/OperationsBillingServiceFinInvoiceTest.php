<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\BillingService;

it('creates FinInvoice records from operational billing entries without writing legacy invoices', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Ari',
        'last_name' => 'Mason',
    ]);
    $staff = User::factory()->create();

    $pendingEntry = BillingEntry::create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'staff_id' => $staff->id,
        'service_date' => '2026-05-01',
        'hours' => 2,
        'rate' => 80,
        'amount' => 160,
        'rate_type' => 'weekday',
        'status' => 'pending',
    ]);
    $approvedEntry = BillingEntry::create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'staff_id' => $staff->id,
        'service_date' => '2026-05-02',
        'hours' => 1.5,
        'rate' => 90,
        'amount' => 135,
        'rate_type' => 'weekend',
        'status' => 'approved',
    ]);

    $invoice = app(BillingService::class)->generateInvoice(
        [$pendingEntry->id, $approvedEntry->id],
        $staff->id,
    );

    expect($invoice)->toBeInstanceOf(FinInvoice::class)
        ->and($invoice->source)->toBe('operations')
        ->and($invoice->client_id)->toBe($client->id)
        ->and((string) $invoice->subtotal)->toBe('295.00')
        ->and((string) $invoice->tax_amount)->toBe('44.25')
        ->and((string) $invoice->total_amount)->toBe('339.25')
        ->and($invoice->lines()->count())->toBe(2)
        ->and(Invoice::count())->toBe(0)
        ->and($pendingEntry->refresh()->status)->toBe('invoiced')
        ->and($approvedEntry->refresh()->status)->toBe('invoiced');
});
