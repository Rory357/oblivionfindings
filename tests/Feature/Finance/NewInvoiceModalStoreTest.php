<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;

/**
 * The New Invoice modal posts the StoreInvoiceRequest shape (client_id OR
 * client_name+funding_body; lines with tax_rate_id 'default'→null→NZ GST 15%).
 * The controller computes line tax + totals with bcmath and stores a draft.
 */
function invoiceManager(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    foreach (['finance.ar.view', 'finance.ar.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-INVOICE-STORE-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Accounts Receivable Officer',
        'position_role' => 'finance',
        'employment_type' => 'full_time',
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}

it('creates a draft client invoice with NZ GST applied per line', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Ana', 'last_name' => 'Smith']);

    $this->actingAs(invoiceManager($site))
        ->post(route('finance.invoices.store'), [
            'client_id' => $client->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'notes' => 'Week ending 14 Jun',
            'lines' => [
                ['description' => 'Supported living', 'quantity' => '2', 'unit_price' => '50.00', 'tax_rate_id' => 'default'],
            ],
        ])
        ->assertRedirect();

    $invoice = FinInvoice::query()->latest('id')->firstOrFail()->load('lines');

    expect($invoice->status)->toBe('draft')
        ->and($invoice->client_id)->toBe($client->id)
        ->and((float) $invoice->subtotal)->toBe(100.0)
        ->and((float) $invoice->tax_amount)->toBe(15.0)   // 100 * 0.15
        ->and((float) $invoice->total_amount)->toBe(115.0)
        ->and($invoice->lines)->toHaveCount(1)
        ->and((float) $invoice->lines->first()->line_total)->toBe(115.0);
});

it('creates a draft funder invoice from client_name without a client_id', function () {
    $site = Site::factory()->create();

    $this->actingAs(invoiceManager($site))
        ->post(route('finance.invoices.store'), [
            'client_name' => 'Te Whatu Ora',
            'funding_body' => 'NASC',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'lines' => [
                ['description' => 'Respite block', 'quantity' => '1', 'unit_price' => '300.00', 'tax_rate_id' => 'default'],
            ],
        ])
        ->assertRedirect();

    $invoice = FinInvoice::query()->latest('id')->firstOrFail();

    expect($invoice->client_id)->toBeNull()
        ->and($invoice->client_name)->toBe('Te Whatu Ora')
        ->and((float) $invoice->total_amount)->toBe(345.0); // 300 + 45 GST
});
