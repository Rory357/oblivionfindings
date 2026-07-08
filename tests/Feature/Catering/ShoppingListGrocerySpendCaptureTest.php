<?php

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Models\HouseLedgerEntry;
use App\Models\MealProduct;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealShoppingList;
use App\Models\SiteMealShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * C7 capture-at-source (b): marking a shopping list received posts the actual
 * grocery spend to the site's house ledger exactly once; the existing
 * HouseLedgerEntryObserver bridges it to the GL (DR 6431 Groceries / CR 1000).
 * Helpers prefixed `gcap_` to stay unique in the global Pest function space.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->seed(\Database\Seeders\CateringPermissionsSeeder::class);

    $adminRole = Role::where('name', 'admin')->first();
    $perm = Permission::where('key', 'sites.meals.shopping.manage')->first();
    if ($adminRole && $perm && ! $adminRole->permissions()->where('permissions.id', $perm->id)->exists()) {
        $adminRole->permissions()->attach($perm->id);
    }

    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach($adminRole);
    $this->site = Site::factory()->create(['type' => 'house']);
});

/** @return array{0: SiteMealShoppingList, 1: SiteMealShoppingListItem} */
function gcap_groceryList(int $siteId, ?int $costPerUnitCents = 500, ?int $estimatedCents = null): array
{
    $product = MealProduct::create([
        'name' => 'Milk ' . uniqid(),
        'default_unit' => 'each',
        'is_active' => true,
        'cost_per_unit_cents' => $costPerUnitCents,
    ]);
    $list = SiteMealShoppingList::create([
        'site_id' => $siteId,
        'status' => 'draft',
        'covers_from' => '2026-05-18',
        'covers_to' => '2026-05-24',
    ]);
    $item = SiteMealShoppingListItem::create([
        'list_id' => $list->id,
        'product_id' => $product->id,
        'needed_qty' => 4,
        'unit' => 'each',
        'source' => 'manual',
        'estimated_cost_cents' => $estimatedCents,
    ]);

    return [$list, $item];
}

it('posts received grocery spend to the house ledger and bridges it to the GL', function () {
    Bus::fake();
    [$list, $item] = gcap_groceryList($this->site->id, 500); // $5.00/unit

    $this->actingAs($this->admin)
        ->post("/sites/{$this->site->id}/meal-shopping-lists/{$list->id}/receive", [
            'items' => [['id' => $item->id, 'received_qty' => 3]], // 3 × $5 = $15
        ])
        ->assertOk();

    $entry = HouseLedgerEntry::where('reference', "shopping-list:{$list->id}")->first();
    expect($entry)->not->toBeNull()
        ->and($entry->entry_type)->toBe('expense')
        ->and($entry->category)->toBe('groceries')
        ->and((float) $entry->amount)->toBe(15.0)
        ->and((float) $entry->ledger->current_balance)->toBe(-15.0);

    Bus::assertDispatched(ProcessFinancialEventJob::class, fn ($job) => $job->eventData['event_type'] === 'house_ledger_expense'
        && $job->eventData['debit_account_code'] === '6431'
        && (float) $job->eventData['amount'] === 15.0);
});

it('falls back to the line estimate when the product has no unit cost', function () {
    Bus::fake();
    [$list, $item] = gcap_groceryList($this->site->id, null, 1200); // no unit cost, $12 estimate

    $this->actingAs($this->admin)
        ->post("/sites/{$this->site->id}/meal-shopping-lists/{$list->id}/receive", [
            'items' => [['id' => $item->id, 'received_qty' => 2]],
        ])
        ->assertOk();

    $entry = HouseLedgerEntry::where('reference', "shopping-list:{$list->id}")->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->amount)->toBe(12.0);
});

it('does not double-post grocery spend when receive is submitted twice', function () {
    Bus::fake();
    [$list, $item] = gcap_groceryList($this->site->id, 500);
    $payload = ['items' => [['id' => $item->id, 'received_qty' => 3]]];
    $url = "/sites/{$this->site->id}/meal-shopping-lists/{$list->id}/receive";

    $this->actingAs($this->admin)->post($url, $payload)->assertOk();
    $this->actingAs($this->admin)->post($url, $payload)->assertOk();

    expect(HouseLedgerEntry::where('reference', "shopping-list:{$list->id}")->count())->toBe(1);
});

it('skips the ledger capture when there is no grocery cost', function () {
    Bus::fake();
    [$list, $item] = gcap_groceryList($this->site->id, null, null); // no cost anywhere

    $this->actingAs($this->admin)
        ->post("/sites/{$this->site->id}/meal-shopping-lists/{$list->id}/receive", [
            'items' => [['id' => $item->id, 'received_qty' => 2]],
        ])
        ->assertOk();

    expect(HouseLedgerEntry::where('reference', "shopping-list:{$list->id}")->count())->toBe(0);
    Bus::assertNotDispatched(ProcessFinancialEventJob::class);
});
