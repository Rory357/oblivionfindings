<?php

use App\Domain\Finance\Models\FinBill;
use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The bills index summary filtered on a non-existent 'partial' status, so
 * partially-paid bills (stored as 'partially_paid') were silently excluded from
 * total_unpaid / total_overdue / due_this_week — understating payables.
 */
function apViewer(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.ap.view'], ['description' => 'finance.ap.view']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

it('counts partially_paid bills in the payables summary', function () {
    // Due in 3 days, half paid → 600 outstanding (unpaid + due-this-week, not overdue).
    FinBill::factory()->create([
        'organization_id' => 1, 'status' => 'partially_paid',
        'total_amount' => '1000.00', 'amount_paid' => '400.00',
        'due_date' => now()->addDays(3)->toDateString(),
    ]);
    // Overdue (due yesterday), part paid → 400 outstanding (unpaid + overdue).
    FinBill::factory()->create([
        'organization_id' => 1, 'status' => 'partially_paid',
        'total_amount' => '500.00', 'amount_paid' => '100.00',
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs(apViewer())
        ->get(route('finance.bills.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/bills/Index')
            ->where('summary.total_unpaid', fn ($v) => (float) $v === 1000.0)   // 600 + 400
            ->where('summary.total_overdue', fn ($v) => (float) $v === 400.0)   // the overdue one
            ->where('summary.due_this_week', fn ($v) => (float) $v === 600.0)   // the +3d one
        );
});
