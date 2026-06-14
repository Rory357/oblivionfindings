<?php

use App\Domain\Finance\Models\FinVendor;
use App\Models\Permission;
use App\Models\User;

/**
 * The New Vendor modal posts the StoreVendorRequest shape (name + required
 * vendor_type enum + optional contact/terms/default account).
 */
function vendorManager(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('creates a vendor from the modal payload', function () {
    $this->actingAs(vendorManager())
        ->post(route('finance.vendors.store'), [
            'name' => 'Acme Supplies Ltd',
            'vendor_type' => 'supplier',
            'email' => 'ap@acme.test',
            'payment_terms_days' => 30,
        ])
        ->assertRedirect();

    $vendor = FinVendor::where('organization_id', 1)->where('name', 'Acme Supplies Ltd')->firstOrFail();

    expect($vendor->vendor_type)->toBe('supplier')
        ->and($vendor->email)->toBe('ap@acme.test')
        ->and((int) $vendor->payment_terms_days)->toBe(30)
        ->and((bool) $vendor->is_active)->toBeTrue();
});
