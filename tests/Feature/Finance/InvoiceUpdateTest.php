<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinInvoiceLine;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;

/**
 * The Edit-invoice modal PUTs the SAME payload the New-invoice modal posts
 * (client_id / funding_body + 'default' tax sentinel). The invoice update contract
 * was aligned with create so that payload validates and persists on edit too:
 * a client-billed invoice derives its name from the client, a funder-billed one
 * from the funding body, and the 'default' tax sentinel maps to null (15% GST).
 * Editing is locked to draft invoices (a sent invoice has posted its GL journal).
 */
function invoiceUpdateUser(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    foreach (['finance.ar.view', 'finance.ar.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-INVOICE-UPDATE-'.$user->id,
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

function draftInvoiceForUpdate(array $overrides = []): FinInvoice
{
    $invoice = FinInvoice::factory()->create(array_merge([
        'status' => 'draft',
        'client_name' => 'Original Name',
        'total_amount' => '115.00',
    ], $overrides));
    FinInvoiceLine::create([
        'invoice_id' => $invoice->id, 'description' => 'Original', 'quantity' => 1,
        'unit_price' => '100.00', 'tax_amount' => '15.00', 'line_total' => '115.00', 'sort_order' => 0,
    ]);

    return $invoice->fresh();
}

it('edits a client-billed draft invoice, deriving the name from the client and mapping the default tax sentinel', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $invoice = draftInvoiceForUpdate(['client_id' => null]);

    $this->actingAs(invoiceUpdateUser($site))
        ->put(route('finance.invoices.update', $invoice->id), [
            'client_id' => $client->id,
            'client_name' => null,
            'funding_body' => null,
            'invoice_date' => '2026-06-01',
            'due_date' => '2026-06-30',
            'lines' => [
                ['description' => 'Edited line', 'quantity' => 2, 'unit_price' => '50.00', 'tax_rate_id' => 'default'],
            ],
        ])
        ->assertRedirect();

    $invoice->refresh()->load('lines');

    expect($invoice->client_id)->toBe($client->id)
        ->and($invoice->client_name)->toBe($client->full_name)   // derived from the client
        ->and((float) $invoice->total_amount)->toBe(115.0)        // 2 × 50 + 15% GST
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->description)->toBe('Edited line')
        ->and($invoice->lines->first()->tax_rate_id)->toBeNull(); // 'default' → null
});

it('edits a funder-billed draft invoice, persisting the funding body', function () {
    $site = Site::factory()->create();
    $invoice = draftInvoiceForUpdate(['client_id' => null]);

    $this->actingAs(invoiceUpdateUser($site))
        ->put(route('finance.invoices.update', $invoice->id), [
            'client_id' => null,
            'client_name' => null,
            'funding_body' => 'Ministry of Health',
            'invoice_date' => '2026-06-01',
            'due_date' => '2026-06-30',
            'lines' => [
                ['description' => 'Funded support', 'quantity' => 1, 'unit_price' => '200.00', 'tax_rate_id' => 'default'],
            ],
        ])
        ->assertRedirect();

    $invoice->refresh();

    expect($invoice->client_id)->toBeNull()
        ->and($invoice->funding_body)->toBe('Ministry of Health')
        ->and($invoice->client_name)->toBe('Ministry of Health'); // derived from funding body
});

it('refuses to edit a non-draft invoice (GL already posted on send)', function () {
    $site = Site::factory()->create();
    $invoice = draftInvoiceForUpdate(['client_id' => null]);
    $invoice->update(['status' => 'sent']);

    $this->actingAs(invoiceUpdateUser($site))
        ->put(route('finance.invoices.update', $invoice->id), [
            'funding_body' => 'Hacker',
            'invoice_date' => '2026-06-01',
            'due_date' => '2026-06-30',
            'lines' => [
                ['description' => 'Hack', 'quantity' => 1, 'unit_price' => '999.00', 'tax_rate_id' => 'default'],
            ],
        ]);

    $invoice->refresh()->load('lines');
    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->description)->toBe('Original')
        ->and((float) $invoice->total_amount)->toBe(115.0);
});
