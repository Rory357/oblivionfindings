<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Models\Permission;
use App\Models\User;

/**
 * Finance list CSV export (C3d). Every list tab streams a sanitised CSV honouring
 * the current filters. This locks in the reference (invoices) endpoint: it streams
 * text/csv, includes a header row + a row per invoice, respects the ?status filter,
 * and neutralises CSV formula injection (SanitizesCsvOutput).
 */
function exportUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.ar.view'], ['description' => 'finance.ar.view']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

function streamed(\Illuminate\Testing\TestResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('streams invoices as CSV with a header and one row per invoice', function () {
    FinInvoice::factory()->count(3)->create(['organization_id' => 1, 'status' => 'sent']);

    $response = $this->actingAs(exportUser())->get(route('finance.invoices.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = streamed($response);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines[0])->toContain('Invoice #')      // header row
        ->and(count($lines))->toBe(4);              // header + 3 invoices
});

it('honours the status filter in the export', function () {
    FinInvoice::factory()->create(['organization_id' => 1, 'status' => 'paid', 'invoice_number' => 'INV-PAID-1']);
    FinInvoice::factory()->create(['organization_id' => 1, 'status' => 'draft', 'invoice_number' => 'INV-DRAFT-1']);

    $csv = streamed($this->actingAs(exportUser())->get(route('finance.invoices.export', ['status' => 'paid'])));

    expect($csv)->toContain('INV-PAID-1')
        ->and($csv)->not->toContain('INV-DRAFT-1');
});

it('neutralises CSV formula injection in exported cells', function () {
    FinInvoice::factory()->create(['organization_id' => 1, 'status' => 'sent', 'client_name' => '=cmd|calc']);

    $csv = streamed($this->actingAs(exportUser())->get(route('finance.invoices.export')));

    // The dangerous leading '=' is prefixed with an apostrophe, never emitted raw.
    expect($csv)->toContain("'=cmd|calc")
        ->and($csv)->not->toMatch('/(^|,)=cmd/m');
});

it('403s the export without finance.ar.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.invoices.export'))->assertForbidden();
});
