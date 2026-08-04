<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\PriceBook;
use App\Models\RecurringCharge;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function commercialFinanceUserForSite(?Site $site, array $permissionKeys): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    if ($site) {
        HrEmployeeProfile::query()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-COMMERCIAL-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Commercial Finance Officer',
            'position_role' => 'finance',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
    }

    return $user;
}

it('treats Price Books as application-global configuration and preserves nested item ownership', function () {
    $manager = commercialFinanceUserForSite(null, ['finance.ar.manage']);

    $this->actingAs($manager)
        ->post(route('finance.price_books.store'), [
            'name' => 'Application support rates',
            'is_active' => true,
        ])
        ->assertRedirect();

    $priceBook = PriceBook::query()->where('name', 'Application support rates')->firstOrFail();
    $this->actingAs($manager)
        ->post(route('finance.price_books.items.store', $priceBook), [
            'name' => 'Community support hour',
            'code' => 'CS-HOUR',
            'unit_price' => '82.50',
            'unit' => 'hour',
        ])
        ->assertRedirect();

    $item = $priceBook->items()->firstOrFail();
    expect($item->service_code)->toBe('CS-HOUR')
        ->and((string) $item->rate)->toBe('82.50');

    $otherPriceBook = PriceBook::query()->create(['name' => 'Other catalogue']);
    $this->actingAs($manager)
        ->put(route('finance.price_books.items.update', [$otherPriceBook, $item]), [
            'name' => 'Wrong parent update',
            'unit_price' => '99.00',
        ])
        ->assertNotFound();

    expect($item->fresh()->name)->toBe('Community support hour');
});

it('Site-scopes Billing entries and Client picker options', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = commercialFinanceUserForSite($assignedSite, ['finance.ar.view']);
    $staff = User::factory()->create();
    $assignedClient = Client::factory()->create(['site_id' => $assignedSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);

    foreach ([$assignedClient, $otherClient] as $client) {
        BillingEntry::query()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'staff_id' => $staff->id,
            'service_date' => now()->toDateString(),
            'hours' => 1,
            'rate' => 50,
            'amount' => 50,
            'rate_type' => 'weekday',
            'status' => 'pending',
        ]);
    }

    $this->actingAs($user)
        ->get(route('finance.billing.entries'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->has('clients', 1)
            ->where('clients.0.id', $assignedClient->id));
});

it('denies recurring-charge writes and direct records for an unassigned Client Site', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = commercialFinanceUserForSite($assignedSite, ['finance.ar.manage']);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $otherCharge = RecurringCharge::query()->create([
        'client_id' => $otherClient->id,
        'name' => 'Other Site charge',
        'description' => 'Other Site charge',
        'amount' => 50,
        'frequency' => 'monthly',
        'starts_at' => now()->toDateString(),
        'next_charge_at' => now()->toDateString(),
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('finance.recurring_charges.store'), [
            'client_id' => $otherClient->id,
            'description' => 'Blocked charge',
            'amount' => 50,
            'frequency' => 'monthly',
            'next_charge_date' => now()->toDateString(),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('finance.recurring_charges.update', $otherCharge), [
            'description' => 'Blocked update',
        ])
        ->assertNotFound();

    expect(RecurringCharge::query()->where('name', 'Blocked charge')->exists())->toBeFalse()
        ->and($otherCharge->fresh()->description)->toBe('Other Site charge');
});
