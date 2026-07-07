<?php

use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinDonorFund;
use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * C3d command layer — the four laggard finance lists gain search + URL-synced filter
 * chips + pagination to match the invoices/bills golden template. This locks the
 * server side: donor-funds now paginates and filters (search / status / restricted),
 * and credit-notes gains search + a credit-date range on top of its type/status.
 * (Chart-of-accounts filtering is client-side over the loaded tree — covered by tsc
 * + the browser check, not here. GST returns already had status/year + pagination.)
 */
function cmdUser(string $permissionKey): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['description' => $permissionKey]);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

it('paginates donor funds and honours the search + status filters', function () {
    FinDonorFund::factory()->create(['organization_id' => 1, 'fund_name' => 'Alpha Trust', 'status' => 'active']);
    FinDonorFund::factory()->create(['organization_id' => 1, 'fund_name' => 'Beta Grant', 'status' => 'expired']);

    // Paginated shape (data + links), not a bare collection.
    $this->actingAs(cmdUser('finance.reports.view'))
        ->get(route('finance.donor-funds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('funds.data', 2)->has('funds.links')->has('filters'));

    // Search narrows to the matching fund.
    $this->actingAs(cmdUser('finance.reports.view'))
        ->get(route('finance.donor-funds.index', ['search' => 'Alpha']))
        ->assertInertia(fn (Assert $p) => $p->has('funds.data', 1)
            ->where('funds.data.0.fund_name', 'Alpha Trust'));

    // Status filter narrows to the matching fund.
    $this->actingAs(cmdUser('finance.reports.view'))
        ->get(route('finance.donor-funds.index', ['status' => 'expired']))
        ->assertInertia(fn (Assert $p) => $p->has('funds.data', 1)
            ->where('funds.data.0.status', 'expired'));
});

it('filters credit notes by search and credit-date range', function () {
    FinCreditNote::factory()->create(['organization_id' => 1, 'credit_note_number' => 'CN-AAA', 'credit_date' => '2026-01-10']);
    FinCreditNote::factory()->create(['organization_id' => 1, 'credit_note_number' => 'CN-BBB', 'credit_date' => '2026-06-20']);

    // Search on the CN number.
    $this->actingAs(cmdUser('finance.ap.view'))
        ->get(route('finance.credit-notes.index', ['search' => 'AAA']))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('creditNotes.data', 1)
            ->where('creditNotes.data.0.credit_note_number', 'CN-AAA'));

    // date_from excludes the earlier note.
    $this->actingAs(cmdUser('finance.ap.view'))
        ->get(route('finance.credit-notes.index', ['date_from' => '2026-06-01']))
        ->assertInertia(fn (Assert $p) => $p->has('creditNotes.data', 1)
            ->where('creditNotes.data.0.credit_note_number', 'CN-BBB'));
});
