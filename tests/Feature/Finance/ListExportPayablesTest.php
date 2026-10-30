<?php

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Payables list CSV exports (C3d). Each Accounts-Payable list tab streams a
 * sanitised CSV honouring its current filters. This locks in every payables
 * endpoint: bills, purchase orders, payment runs, vendors and credit notes each
 * stream text/csv with a header row + a row per record, and 403 without
 * finance.ap.view. Formula-injection neutralisation is covered by
 * ListExportTest (the shared SanitizesCsvOutput helper is identical here).
 */
function apExportUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.ap.view'], ['description' => 'finance.ap.view']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

function apStreamed(TestResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

/**
 * @return array{0: string, 1: int} the header CSV line and total line count
 */
function apCsvLines(string $csv): array
{
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    return [$lines[0], count($lines)];
}

// ── Bills ────────────────────────────────────────────────────────────────
it('streams bills as CSV with a header and one row per bill', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $user = apExportUser();
    ensureCanonicalHrStaffProfile($user, $site);
    $bills = FinBill::factory()->count(3)->create([
        'organization_id' => 1, 'site_id' => $site->id, 'status' => 'approved',
    ]);
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $foreignBill = FinBill::factory()->create([
        'site_id' => $foreignSite->id, 'status' => 'approved', 'bill_number' => 'BILL-FOREIGN-SITE',
    ]);

    $response = $this->actingAs($user)->get(route('finance.bills.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = apStreamed($response);
    [$header, $count] = apCsvLines($csv);
    expect($header)->toContain('Bill #')
        ->and($count)->toBe(4) // header + 3 bills
        ->and($csv)->not->toContain($foreignBill->bill_number);
    foreach ($bills as $bill) {
        expect($csv)->toContain($bill->bill_number);
    }
});

it('honours the status filter in the bills export', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $user = apExportUser();
    ensureCanonicalHrStaffProfile($user, $site);
    FinBill::factory()->create(['organization_id' => 1, 'site_id' => $site->id, 'status' => 'paid', 'bill_number' => 'BILL-PAID-1']);
    FinBill::factory()->create(['organization_id' => 1, 'site_id' => $site->id, 'status' => 'draft', 'bill_number' => 'BILL-DRAFT-1']);
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    FinBill::factory()->create(['site_id' => $foreignSite->id, 'status' => 'paid', 'bill_number' => 'BILL-FOREIGN-PAID']);

    $response = $this->actingAs($user)->get(route('finance.bills.export', ['status' => 'paid']))->assertOk();
    $csv = apStreamed($response);
    [$header, $count] = apCsvLines($csv);

    expect($csv)->toContain('BILL-PAID-1')
        ->and($csv)->not->toContain('BILL-DRAFT-1')
        ->and($csv)->not->toContain('BILL-FOREIGN-PAID')
        ->and($header)->toContain('Bill #')
        ->and($count)->toBe(2); // header + the scoped paid bill
});

it('403s the bills export without finance.ap.view', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($user, $site);

    $this->actingAs($user)->get(route('finance.bills.export'))->assertForbidden();
});

// ── Purchase Orders ──────────────────────────────────────────────────────
it('streams purchase orders as CSV with a header and one row per PO', function () {
    FinPurchaseOrder::factory()->count(3)->create(['organization_id' => 1, 'status' => 'draft']);

    $response = $this->actingAs(apExportUser())->get(route('finance.purchase-orders.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    [$header, $count] = apCsvLines(apStreamed($response));
    expect($header)->toContain('PO #')
        ->and($count)->toBe(4);
});

it('honours the status filter in the purchase orders export', function () {
    FinPurchaseOrder::factory()->create(['organization_id' => 1, 'status' => 'received', 'po_number' => 'PO-RECV-1']);
    FinPurchaseOrder::factory()->create(['organization_id' => 1, 'status' => 'draft', 'po_number' => 'PO-DRAFT-1']);

    $csv = apStreamed($this->actingAs(apExportUser())->get(route('finance.purchase-orders.export', ['status' => 'received'])));

    expect($csv)->toContain('PO-RECV-1')
        ->and($csv)->not->toContain('PO-DRAFT-1');
});

it('403s the purchase orders export without finance.ap.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.purchase-orders.export'))->assertForbidden();
});

/**
 * A payment run the export can return. PaymentSettlementSiteScope hides any run
 * that has no items, or whose items sit outside the actor's Site scope — a run
 * is only visible when every line is one the actor may settle — so a bare
 * factory run is correctly invisible and would leave the CSV empty.
 */
function apVisiblePaymentRun(Site $site, array $attributes = []): FinPaymentRun
{
    $run = FinPaymentRun::factory()->create(array_merge([
        'organization_id' => 1,
        'status' => 'completed',
    ], $attributes));

    FinPaymentRunItem::create([
        'payment_run_id' => $run->id,
        'site_id' => $site->id,
        'vendor_id' => FinVendor::factory()->create(['organization_id' => 1])->id,
        'amount' => '100.00',
        'status' => 'pending',
    ]);

    return $run;
}

// ── Payment Runs ─────────────────────────────────────────────────────────
it('streams payment runs as CSV with a header and one row per run', function () {
    $site = Site::factory()->create();
    $user = apExportUser();
    ensureCanonicalHrStaffProfile($user, $site);
    foreach (range(1, 3) as $ignored) {
        apVisiblePaymentRun($site);
    }

    $response = $this->actingAs($user)->get(route('finance.payment-runs.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    [$header, $count] = apCsvLines(apStreamed($response));
    expect($header)->toContain('Run #')
        ->and($count)->toBe(4);
});

it('honours the status filter in the payment runs export', function () {
    $site = Site::factory()->create();
    $user = apExportUser();
    ensureCanonicalHrStaffProfile($user, $site);
    apVisiblePaymentRun($site, ['status' => 'completed', 'run_number' => 'PAY-DONE-1']);
    apVisiblePaymentRun($site, ['status' => 'draft', 'run_number' => 'PAY-DRAFT-1']);

    $csv = apStreamed($this->actingAs($user)->get(route('finance.payment-runs.export', ['status' => 'completed'])));

    expect($csv)->toContain('PAY-DONE-1')
        ->and($csv)->not->toContain('PAY-DRAFT-1');
});

it('403s the payment runs export without finance.ap.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.payment-runs.export'))->assertForbidden();
});

// ── Vendors ──────────────────────────────────────────────────────────────
it('streams vendors as CSV with a header and one row per vendor', function () {
    FinVendor::factory()->count(3)->create(['organization_id' => 1]);

    $response = $this->actingAs(apExportUser())->get(route('finance.vendors.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    [$header, $count] = apCsvLines(apStreamed($response));
    expect($header)->toContain('Name')
        ->and($count)->toBe(4);
});

it('honours the type filter in the vendors export', function () {
    FinVendor::factory()->create(['organization_id' => 1, 'vendor_type' => 'utility', 'name' => 'Utility Co']);
    FinVendor::factory()->create(['organization_id' => 1, 'vendor_type' => 'contractor', 'name' => 'Contractor Co']);

    $csv = apStreamed($this->actingAs(apExportUser())->get(route('finance.vendors.export', ['vendor_type' => 'utility'])));

    expect($csv)->toContain('Utility Co')
        ->and($csv)->not->toContain('Contractor Co');
});

it('403s the vendors export without finance.ap.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.vendors.export'))->assertForbidden();
});

// ── Credit Notes ─────────────────────────────────────────────────────────
it('streams credit notes as CSV with a header and one row per credit note', function () {
    FinCreditNote::factory()->count(3)->create(['organization_id' => 1, 'type' => 'payable']);

    $response = $this->actingAs(apExportUser())->get(route('finance.credit-notes.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    [$header, $count] = apCsvLines(apStreamed($response));
    expect($header)->toContain('Credit Note #')
        ->and($count)->toBe(4);
});

it('honours the type filter in the credit notes export', function () {
    FinCreditNote::factory()->create(['organization_id' => 1, 'type' => 'payable', 'credit_note_number' => 'CN-AP-1']);
    FinCreditNote::factory()->create(['organization_id' => 1, 'type' => 'receivable', 'credit_note_number' => 'CN-AR-1']);

    $csv = apStreamed($this->actingAs(apExportUser())->get(route('finance.credit-notes.export', ['type' => 'payable'])));

    expect($csv)->toContain('CN-AP-1')
        ->and($csv)->not->toContain('CN-AR-1');
});

it('403s the credit notes export without finance.ap.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get(route('finance.credit-notes.export'))->assertForbidden();
});
