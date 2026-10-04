<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinVendor;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;

/**
 * The New Bill modal posts the StoreBillRequest shape (vendor_id + dates + lines
 * with a required expense account_id and a raw gst_rate percentage). createBill
 * computes line GST + totals with bcmath and stores a draft.
 */
function billManager(Site $site): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    ensureCanonicalHrStaffProfile($user, $site);

    return $user;
}

it('creates a draft bill with per-line GST and rolled-up totals', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $vendor = FinVendor::factory()->create(['organization_id' => 1, 'name' => 'Acme Supplies']);
    $expense = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '5000', 'name' => 'Supplies', 'type' => 'expense', 'is_active' => true,
    ]);

    $response = $this->actingAs(billManager($site))
        ->post(route('finance.bills.store'), [
            'site_id' => $site->id,
            'vendor_id' => $vendor->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [
                ['description' => 'Cleaning supplies', 'quantity' => '2', 'unit_price' => '50.00', 'gst_rate' => '15', 'account_id' => $expense->id],
                ['description' => 'Zero-rated item', 'quantity' => '1', 'unit_price' => '30.00', 'gst_rate' => '0', 'account_id' => $expense->id],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Bill created successfully.');

    $bill = FinBill::where('organization_id', 1)
        ->where('site_id', $site->id)
        ->where('vendor_id', $vendor->id)
        ->sole()->load('lines');
    $response->assertRedirect(route('finance.bills.show', $bill));

    expect($bill->status)->toBe('draft')
        ->and($bill->site_id)->toBe($site->id)
        ->and($bill->vendor_id)->toBe($vendor->id)
        ->and((float) $bill->subtotal)->toBe(130.0)        // 100 + 30
        ->and((float) $bill->gst_amount)->toBe(15.0)        // 100 * 0.15 + 0
        ->and((float) $bill->total_amount)->toBe(145.0)
        ->and($bill->lines)->toHaveCount(2)
        ->and((float) $bill->lines->firstWhere('description', 'Cleaning supplies')->line_total)->toBe(115.0);
});
