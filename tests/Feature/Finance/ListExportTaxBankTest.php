<?php

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Models\Permission;
use App\Models\User;

/**
 * Finance list CSV export (C3d) — Tax + Banking tabs. Each list tab streams a
 * sanitised CSV honouring the current filters. This mirrors ListExportTest.php
 * (the invoices reference) for the GST returns, IRD filings, donor funds, bank
 * transactions and petty-cash-funds endpoints: each streams text/csv with a
 * header row + a row per record, and 403s without the list's view permission.
 */
function tbExportUserWith(string $permissionKey): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['description' => $permissionKey]);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

function streamedCsv(\Illuminate\Testing\TestResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

/** @return array<int, string> Non-empty CSV lines (BOM/whitespace trimmed). */
function tbCsvLines(string $csv): array
{
    return array_values(array_filter(explode("\n", trim($csv))));
}

// ── GST returns ─────────────────────────────────────────────────────────────

it('streams GST returns as CSV with a header and one row per return', function () {
    FinGstReturn::factory()->count(3)->create(['organization_id' => 1, 'status' => 'filed']);

    $response = $this->actingAs(tbExportUserWith('finance.tax.view'))->get(route('finance.gst-returns.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = tbCsvLines(streamedCsv($response));
    expect($lines[0])->toContain('Period')
        ->and($lines[0])->toContain('GST Payable')
        ->and(count($lines))->toBe(4); // header + 3 returns
});

it('403s the GST-returns export without finance.tax.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.gst-returns.export'))->assertForbidden();
});

// ── IRD filings (no factory — seed via create) ──────────────────────────────

it('streams IRD filings as CSV with a header and one row per filing', function () {
    // FinIrdFiling has no factory; seed the minimum NOT-NULL columns directly.
    foreach (range(1, 3) as $i) {
        FinIrdFiling::create([
            'organization_id' => 1,
            'ird_number' => '12-345-67'.$i,
            'filing_type' => 'gst',
            'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'filing_data' => ['form' => 'GST101A'],
            'total_amount' => 1000 + $i,
            'status' => 'draft',
        ]);
    }

    $response = $this->actingAs(tbExportUserWith('finance.tax.manage'))->get(route('finance.ird-filings.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = tbCsvLines(streamedCsv($response));
    expect($lines[0])->toContain('Filing Type')
        ->and($lines[0])->toContain('IRD Reference')
        ->and(count($lines))->toBe(4); // header + 3 filings
});

it('403s the IRD-filings export without finance.tax.manage', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.ird-filings.export'))->assertForbidden();
});

// ── Donor funds ─────────────────────────────────────────────────────────────

it('streams donor funds as CSV with a header and one row per fund', function () {
    FinDonorFund::factory()->count(3)->create(['organization_id' => 1]);

    $response = $this->actingAs(tbExportUserWith('finance.reports.view'))->get(route('finance.donor-funds.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = tbCsvLines(streamedCsv($response));
    expect($lines[0])->toContain('Fund Code')
        ->and($lines[0])->toContain('Balance')
        ->and(count($lines))->toBe(4); // header + 3 funds
});

it('403s the donor-funds export without finance.reports.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.donor-funds.export'))->assertForbidden();
});

// ── Bank transactions (no factory — seed via create) ────────────────────────

it('streams bank transactions as CSV with a header and one row per transaction', function () {
    $account = FinBankAccount::factory()->create(['organization_id' => 1]);

    // FinBankTransaction has no factory; seed the minimum NOT-NULL columns directly.
    foreach (range(1, 3) as $i) {
        FinBankTransaction::create([
            'organization_id' => 1,
            'bank_account_id' => $account->id,
            'transaction_date' => now()->subDays($i)->toDateString(),
            'amount' => $i % 2 === 0 ? -($i * 10) : $i * 10,
            'description' => "Transaction {$i}",
            'reference' => "REF-{$i}",
            'status' => 'unreconciled',
        ]);
    }

    $response = $this->actingAs(tbExportUserWith('finance.bank.view'))->get(route('finance.bank-transactions.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = tbCsvLines(streamedCsv($response));
    expect($lines[0])->toContain('Date')
        ->and($lines[0])->toContain('Type')
        ->and(count($lines))->toBe(4); // header + 3 transactions
});

it('403s the bank-transactions export without finance.bank.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.bank-transactions.export'))->assertForbidden();
});

// ── Petty cash funds ────────────────────────────────────────────────────────

it('streams petty-cash funds as CSV with a header and one row per fund', function () {
    FinPettyCashFund::factory()->count(3)->create(['organization_id' => 1]);

    $response = $this->actingAs(tbExportUserWith('finance.petty_cash.view'))->get(route('finance.petty-cash.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = tbCsvLines(streamedCsv($response));
    expect($lines[0])->toContain('Fund Name')
        ->and($lines[0])->toContain('Current Balance')
        ->and(count($lines))->toBe(4); // header + 3 funds
});

it('403s the petty-cash export without finance.petty_cash.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.petty-cash.export'))->assertForbidden();
});
