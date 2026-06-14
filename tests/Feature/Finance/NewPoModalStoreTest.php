<?php

use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Models\Permission;
use App\Models\User;

/**
 * The New PO modal posts the StorePurchaseOrderRequest shape (vendor_id +
 * order_date + lines with optional account_id and a gst_rate percentage). The
 * controller computes line GST + totals and stores a draft PO.
 */
function poManager(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('creates a draft purchase order with per-line GST and rolled-up totals', function () {
    $vendor = FinVendor::factory()->create(['organization_id' => 1, 'name' => 'Acme Supplies']);

    $this->actingAs(poManager())
        ->post(route('finance.purchase-orders.store'), [
            'vendor_id' => $vendor->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Office chairs', 'quantity' => '2', 'unit_price' => '50.00', 'gst_rate' => '15', 'account_id' => null],
            ],
        ])
        ->assertRedirect();

    $po = FinPurchaseOrder::where('organization_id', 1)->latest('id')->firstOrFail();

    expect($po->status)->toBe('draft')
        ->and($po->vendor_id)->toBe($vendor->id)
        ->and((float) $po->subtotal)->toBe(100.0)
        ->and((float) $po->gst_amount)->toBe(15.0)
        ->and((float) $po->total_amount)->toBe(115.0)
        ->and($po->po_number)->toMatch('/^PO-\d{6}-\d{3}$/');
});
