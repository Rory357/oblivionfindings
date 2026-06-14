<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinVendor;
use App\Models\Permission;
use App\Models\User;

/**
 * The Edit-bill modal PUTs to finance.bills.update. A draft bill's fields + lines
 * are replaced, GST percentages are stored back as fractions, and editing is
 * locked to draft bills (an approved bill that already posted a GL journal can
 * never be silently mutated).
 */
function billUpdateUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

function draftBillForUpdate(): FinBill
{
    $account = FinAccount::factory()->create(['organization_id' => 1, 'code' => '6000', 'type' => 'expense']);
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'draft',
        'total_amount' => '115.00',
    ]);
    FinBillLine::create([
        'bill_id' => $bill->id, 'description' => 'Original', 'quantity' => 1,
        'unit_price' => '100.00', 'gst_rate' => '0.1500', 'gst_amount' => '15.00',
        'line_total' => '115.00', 'account_id' => $account->id,
    ]);

    return $bill->fresh();
}

it('updates a draft bill from the modal payload and stores GST as a fraction', function () {
    $account = FinAccount::factory()->create(['organization_id' => 1, 'code' => '6100', 'type' => 'expense']);
    $bill = draftBillForUpdate();

    $this->actingAs(billUpdateUser())
        ->put(route('finance.bills.update', $bill->id), [
            'vendor_id' => $bill->vendor_id,
            'vendor_reference' => 'REF-EDITED',
            'bill_date' => '2026-06-01',
            'due_date' => '2026-06-30',
            'notes' => 'edited',
            'lines' => [
                ['description' => 'Edited line', 'quantity' => 2, 'unit_price' => '50.00', 'gst_rate' => '15', 'account_id' => $account->id],
            ],
        ])
        ->assertRedirect();

    $bill->refresh()->load('lines');

    expect($bill->vendor_reference)->toBe('REF-EDITED')
        ->and((float) $bill->total_amount)->toBe(115.0)   // 2 × 50 + 15% GST
        ->and($bill->lines)->toHaveCount(1)
        ->and($bill->lines->first()->description)->toBe('Edited line')
        ->and((float) $bill->lines->first()->gst_rate)->toBe(0.15);  // percentage 15 stored as fraction
});

it('refuses to edit a non-draft bill (GL already posted)', function () {
    $bill = draftBillForUpdate();
    $bill->update(['status' => 'approved']);

    $this->actingAs(billUpdateUser())
        ->put(route('finance.bills.update', $bill->id), [
            'vendor_id' => $bill->vendor_id,
            'bill_date' => '2026-06-01',
            'due_date' => '2026-06-30',
            'lines' => [
                ['description' => 'Hack', 'quantity' => 1, 'unit_price' => '999.00', 'gst_rate' => '15', 'account_id' => FinAccount::where('code', '6000')->first()->id],
            ],
        ]);

    // Unchanged — the original line and total survive.
    $bill->refresh()->load('lines');
    expect($bill->lines)->toHaveCount(1)
        ->and($bill->lines->first()->description)->toBe('Original')
        ->and((float) $bill->total_amount)->toBe(115.0);
});
