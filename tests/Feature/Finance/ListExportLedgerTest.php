<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\Site;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Finance list CSV export (C3d) — AR-remainder (quotes) + Ledger tabs
 * (journals, chart of accounts, fixed assets). Mirrors ListExportTest.php:
 * each endpoint streams text/csv with a header row + one row per record,
 * honours the current filters, and 403s without the tab's view permission.
 */
function exportUserWith(string $permission, ?Site $site = null): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $perm = Permission::firstOrCreate(['key' => $permission], ['description' => $permission]);
    $user->permissionOverrides()->syncWithoutDetaching([$perm->id => ['allowed' => true]]);

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    return $user;
}

function streamedLedger(TestResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

/** Non-blank CSV lines (drops the trailing newline's empty tail). */
function csvLines(string $csv): array
{
    return array_values(array_filter(explode("\n", trim($csv))));
}

// ── Quotes ───────────────────────────────────────────────────────────────
it('streams quotes as CSV with a header and one row per quote', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    Quote::factory()->count(3)->create(['client_id' => $client->id, 'status' => 'sent']);

    $response = $this->actingAs(exportUserWith('finance.ar.view', $site))->get(route('finance.quotes.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = csvLines(streamedLedger($response));
    expect($lines[0])->toContain('Quote #')
        ->and(count($lines))->toBe(4); // header + 3 quotes
});

it('honours the status filter in the quotes export', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    Quote::factory()->create(['client_id' => $client->id, 'status' => 'accepted', 'quote_number' => 'QTE-ACC-1']);
    Quote::factory()->create(['client_id' => $client->id, 'status' => 'draft', 'quote_number' => 'QTE-DRAFT-1']);

    $csv = streamedLedger($this->actingAs(exportUserWith('finance.ar.view', $site))
        ->get(route('finance.quotes.export', ['status' => 'accepted'])));

    expect($csv)->toContain('QTE-ACC-1')
        ->and($csv)->not->toContain('QTE-DRAFT-1');
});

it('403s the quotes export without finance.ar.view', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.quotes.export'))->assertForbidden();
});

// ── Journals ─────────────────────────────────────────────────────────────
it('streams journals as CSV with a header and one row per journal', function () {
    FinJournal::factory()->count(3)->create(['status' => 'posted']);

    $response = $this->actingAs(exportUserWith('finance.ledger.view'))->get(route('finance.journals.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = csvLines(streamedLedger($response));
    expect($lines[0])->toContain('Journal #')
        ->and(count($lines))->toBe(4); // header + 3 journals
});

it('sums journal line debits/credits in the export totals', function () {
    $account = FinAccount::factory()->create();
    $journal = FinJournal::factory()->create([
        'status' => 'posted',
        'journal_number' => 'JNL-SUM-1',
    ]);
    FinJournalLine::create(['journal_id' => $journal->id, 'account_id' => $account->id, 'debit' => 125.50, 'credit' => 0]);
    FinJournalLine::create(['journal_id' => $journal->id, 'account_id' => $account->id, 'debit' => 0, 'credit' => 125.50]);

    $csv = streamedLedger($this->actingAs(exportUserWith('finance.ledger.view'))
        ->get(route('finance.journals.export')));

    // The Debit Total + Credit Total columns are summed from the two lines.
    expect($csv)->toContain('JNL-SUM-1')
        ->and($csv)->toContain('125.50');
});

it('honours the status filter in the journals export', function () {
    FinJournal::factory()->create(['status' => 'posted', 'journal_number' => 'JNL-POSTED-1']);
    FinJournal::factory()->create(['status' => 'draft', 'journal_number' => 'JNL-DRAFT-1']);

    $csv = streamedLedger($this->actingAs(exportUserWith('finance.ledger.view'))
        ->get(route('finance.journals.export', ['status' => 'posted'])));

    expect($csv)->toContain('JNL-POSTED-1')
        ->and($csv)->not->toContain('JNL-DRAFT-1');
});

it('403s the journals export without finance.ledger.view', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.journals.export'))->assertForbidden();
});

// ── Chart of Accounts ────────────────────────────────────────────────────
it('streams accounts as a flat CSV with a header and one row per account', function () {
    FinAccount::factory()->count(3)->create();

    $response = $this->actingAs(exportUserWith('finance.ledger.view'))->get(route('finance.accounts.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = csvLines(streamedLedger($response));
    expect($lines[0])->toContain('Code')
        ->and(count($lines))->toBe(4); // header + 3 accounts
});

it('403s the accounts export without finance.ledger.view', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.accounts.export'))->assertForbidden();
});

// ── Fixed Assets ─────────────────────────────────────────────────────────
it('streams fixed assets as CSV with a header and one row per asset', function () {
    FinFixedAsset::factory()->count(3)->create(['status' => 'active']);

    $response = $this->actingAs(exportUserWith('finance.assets.view'))->get(route('finance.fixed-assets.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = csvLines(streamedLedger($response));
    expect($lines[0])->toContain('Asset Tag')
        ->and(count($lines))->toBe(4); // header + 3 assets
});

it('honours the category filter in the fixed assets export', function () {
    FinFixedAsset::factory()->create(['category' => 'vehicle', 'asset_tag' => 'FA-VEHICLE-1']);
    FinFixedAsset::factory()->create(['category' => 'building', 'asset_tag' => 'FA-BUILDING-1']);

    $csv = streamedLedger($this->actingAs(exportUserWith('finance.assets.view'))
        ->get(route('finance.fixed-assets.export', ['category' => 'vehicle'])));

    expect($csv)->toContain('FA-VEHICLE-1')
        ->and($csv)->not->toContain('FA-BUILDING-1');
});

it('403s the fixed assets export without finance.assets.view', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.fixed-assets.export'))->assertForbidden();
});
