<?php

use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Models\Site;
use App\Services\Operations\RecurringChargeService;
use Illuminate\Support\Carbon;

/**
 * The recurring-charge engine queried non-existent columns (active / next_charge_date
 * instead of is_active / next_charge_at), so processDueCharges matched zero rows and
 * never generated anything. It now generates a BillingEntry per due charge and advances
 * the schedule.
 */
function recurringCharge(array $overrides = []): RecurringCharge
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);

    return RecurringCharge::create(array_merge([
        'client_id' => $client->id,
        'name' => 'Monthly support fee',
        'description' => 'Monthly support fee',
        'amount' => '50.00',
        'frequency' => 'monthly',
        'starts_at' => now()->subMonths(2)->toDateString(),
        'next_charge_at' => now()->subDay()->toDateString(),
        'is_active' => true,
    ], $overrides));
}

it('generates a billing entry for a due charge and advances the schedule', function () {
    $charge = recurringCharge();
    $chargeDate = $charge->next_charge_at->toDateString();

    $count = app(RecurringChargeService::class)->processDueCharges();

    expect($count)->toBe(1);

    $entry = BillingEntry::where('client_id', $charge->client_id)->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->amount)->toBe(50.0)
        ->and($entry->rate_type)->toBe('recurring')
        ->and($entry->status)->toBe('pending')
        ->and($entry->site_id)->toBe($charge->client->site_id)
        ->and($entry->service_date->toDateString())->toBe($chargeDate);

    $charge->refresh();
    expect($charge->last_charged_at->toDateString())->toBe($chargeDate)
        ->and($charge->next_charge_at->toDateString())->toBe(Carbon::parse($chargeDate)->addMonth()->toDateString());
});

it('does not process charges that are not yet due or inactive', function () {
    recurringCharge(['next_charge_at' => now()->addWeek()->toDateString()]); // future
    recurringCharge(['is_active' => false]);                                  // inactive

    expect(app(RecurringChargeService::class)->processDueCharges())->toBe(0)
        ->and(BillingEntry::count())->toBe(0);
});

it('processes every valid due charge in the single application', function () {
    recurringCharge();
    recurringCharge();

    expect(app(RecurringChargeService::class)->processDueCharges())->toBe(2)
        ->and(BillingEntry::count())->toBe(2);
});

it('fails closed for a recurring charge whose Client has no canonical Site', function () {
    $client = Client::factory()->create(['site_id' => null]);
    RecurringCharge::create([
        'client_id' => $client->id,
        'name' => 'Orphan monthly fee',
        'description' => 'Orphan monthly fee',
        'amount' => '50.00',
        'frequency' => 'monthly',
        'starts_at' => now()->subMonth()->toDateString(),
        'next_charge_at' => now()->subDay()->toDateString(),
        'is_active' => true,
    ]);

    expect(app(RecurringChargeService::class)->processDueCharges())->toBe(0)
        ->and(BillingEntry::count())->toBe(0);
});

it('deactivates a charge once its next run would pass the end date', function () {
    $charge = recurringCharge([
        'next_charge_at' => now()->subDay()->toDateString(),
        'ends_at' => now()->addDays(3)->toDateString(), // next monthly run is well past this
    ]);

    app(RecurringChargeService::class)->processDueCharges();

    expect($charge->fresh()->is_active)->toBeFalse();
});
