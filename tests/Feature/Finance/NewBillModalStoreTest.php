<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinVendor;
use App\Models\Permission;
use App\Models\User;

/**
 * The New Bill modal posts the StoreBillRequest shape (vendor_id + dates + lines
 * with a required expense account_id and a raw gst_rate percentage). createBill
 * computes line GST + totals with bcmath and stores a draft.
 */
function billManager(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('creates a draft bill with per-line GST and rolled-up totals', function () {
    $vendor = FinVendor::factory()->create(['organization_id' => 1, 'name' => 'Acme Supplies']);
    $expense = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '5000', 'name' => 'Supplies', 'type' => 'expense', 'is_active' => true,
    ]);

    $this->actingAs(billManager())
        ->post(route('finance.bills.store'), [
            'vendor_id' => $vendor->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [
                ['description' => 'Cleaning supplies', 'quantity' => '2', 'unit_price' => '50.00', 'gst_rate' => '15', 'account_id' => $expense->id],
                ['description' => 'Zero-rated item', 'quantity' => '1', 'unit_price' => '30.00', 'gst_rate' => '0', 'account_id' => $expense->id],
            ],
        ])
        ->assertRedirect();

    $bill = FinBill::where('organization_id', 1)->latest('id')->firstOrFail()->load('lines');

    expect($bill->status)->toBe('draft')
        ->and($bill->vendor_id)->toBe($vendor->id)
        ->and((float) $bill->subtotal)->toBe(130.0)        // 100 + 30
        ->and((float) $bill->gst_amount)->toBe(15.0)        // 100 * 0.15 + 0
        ->and((float) $bill->total_amount)->toBe(145.0)
        ->and($bill->lines)->toHaveCount(2)
        ->and((float) $bill->lines->firstWhere('description', 'Cleaning supplies')->line_total)->toBe(115.0);
});
