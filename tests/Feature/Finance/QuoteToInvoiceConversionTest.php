<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\Site;
use App\Models\User;

function arManager(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    foreach (['finance.ar.view', 'finance.ar.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-QUOTE-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Accounts Receivable Manager',
        'position_role' => 'manager',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->client = Client::factory()->create([
        'site_id' => $this->site->id,
        'first_name' => 'Ana',
        'last_name' => 'Smith',
    ]);

    $this->quote = Quote::create([
        'client_id' => $this->client->id,
        'quote_number' => 'Q-0001',
        'title' => 'Support quote',
        'status' => 'accepted',
        'client_name' => 'Ana Smith',
        'client_email' => 'ana@example.test',
        'subtotal' => '150.00',
        'tax_amount' => '22.50',
        'total_amount' => '172.50',
    ]);

    foreach ([['Line A', '100.00'], ['Line B', '50.00']] as [$desc, $amount]) {
        QuoteLineItem::create([
            'quote_id' => $this->quote->id,
            'description' => $desc,
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount, // ex-GST line total
        ]);
    }
});

it('converts an accepted quote into a draft FinInvoice with NZ GST', function () {
    $this->actingAs(arManager($this->site))
        ->post(route('finance.quotes.convert-to-invoice', $this->quote->id))
        ->assertRedirect();

    $invoice = FinInvoice::where('source_id', $this->quote->id)
        ->firstOrFail()
        ->load('lines');

    expect($invoice->status)->toBe('draft')
        ->and($invoice->client_id)->toBe($this->client->id)
        ->and((float) $invoice->subtotal)->toBe(150.0)
        ->and((float) $invoice->tax_amount)->toBe(22.5)   // 150 * 0.15
        ->and((float) $invoice->total_amount)->toBe(172.5)
        ->and($invoice->lines)->toHaveCount(2)
        ->and((float) $invoice->lines->firstWhere('description', 'Line A')->line_total)->toBe(115.0);

    $this->quote->refresh();
    expect($this->quote->status)->toBe('converted')
        ->and($this->quote->converted_to_invoice_id)->toBe($invoice->id);
});

it('is idempotent — re-converting returns the existing invoice, not a second one', function () {
    $user = arManager($this->site);
    $this->actingAs($user)->post(route('finance.quotes.convert-to-invoice', $this->quote->id));
    $this->actingAs($user)->post(route('finance.quotes.convert-to-invoice', $this->quote->id));

    expect(FinInvoice::where('source_id', $this->quote->id)->count())->toBe(1);
});

it('persists line amounts and rolls GST up onto the quote header on create', function () {
    $this->actingAs(arManager($this->site))
        ->post(route('finance.quotes.store'), [
            'client_id' => $this->client->id,
            'title' => 'New support quote',
            'line_items' => [
                ['description' => 'Daytime support', 'quantity' => 2, 'unit_price' => '40.00'],
                ['description' => 'Travel', 'quantity' => 1, 'unit_price' => '20.00'],
            ],
        ])
        ->assertRedirect();

    $quote = Quote::where('title', 'New support quote')->with('lineItems')->firstOrFail();

    // Lines store the computed ex-GST `amount` (the old `total` key was a no-op).
    expect($quote->lineItems)->toHaveCount(2)
        ->and((float) $quote->lineItems->firstWhere('description', 'Daytime support')->amount)->toBe(80.0)
        ->and((float) $quote->lineItems->firstWhere('description', 'Travel')->amount)->toBe(20.0)
        // Header rolls up: subtotal 100, GST 15, total 115.
        ->and((float) $quote->subtotal)->toBe(100.0)
        ->and((float) $quote->tax_amount)->toBe(15.0)
        ->and((float) $quote->total_amount)->toBe(115.0);
});

it('denies quote creation and direct access for an unassigned Client Site', function () {
    $otherSite = Site::factory()->create();
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $otherQuote = Quote::create([
        'client_id' => $otherClient->id,
        'quote_number' => 'Q-OTHER-SITE',
        'title' => 'Other Site quote',
        'status' => 'draft',
    ]);
    $actor = arManager($this->site);

    $this->actingAs($actor)
        ->post(route('finance.quotes.store'), [
            'client_id' => $otherClient->id,
            'title' => 'Blocked quote',
            'line_items' => [
                ['description' => 'Support', 'quantity' => 1, 'unit_price' => '50.00'],
            ],
        ])
        ->assertForbidden();

    $this->actingAs($actor)
        ->get(route('finance.quotes.show', $otherQuote->id))
        ->assertNotFound();

    expect(Quote::query()->where('title', 'Blocked quote')->exists())->toBeFalse();
});
