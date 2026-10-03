<?php

use App\Models\Client;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Establish the calendar reader's exact capability, approved Site and person assignment. */
function medicationCalendarReader(Site $site, Client $client): User
{
    $reader = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $reader->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);
    foreach (['medications.view', 'clients.viewAssigned'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key]);
        $reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $client->supportWorkers()->attach($reader->id);

    return $reader;
}

function medicationCalendarOrder(Client $client): ClientMedication
{
    return ClientMedication::query()->create([
        'client_id' => $client->id,
        'name' => 'Warfarin',
        'dosage' => '3mg',
        'frequency' => 'Once daily',
        'controlled_drug' => false,
        'approval_status' => 'verified',
        'active' => true,
        'state' => 'active',
    ]);
}

/** Aggregate the medication source over a wide today-anchored window. */
function aggregateMedication(array $siteIds): \Illuminate\Support\Collection
{
    return collect(app(SiteCalendarAggregator::class)->itemsForRange(
        $siteIds,
        now()->subMonth(),
        now()->addMonths(4),
        ['sources' => ['medication']],
    ));
}

test('a scheduled medication review surfaces on the home calendar', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id, 'last_name' => 'Ngata']);
    $this->actingAs(medicationCalendarReader($site, $client));
    medicationCalendarOrder($client);

    $scheduled = MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'scheduled',
        'scheduled_date' => now()->addDays(10)->toDateString(),
    ]);

    // A completed review must not appear.
    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'completed',
        'scheduled_date' => now()->addDays(12)->toDateString(),
        'completed_date' => now()->toDateString(),
    ]);

    $items = aggregateMedication([$site->id]);

    expect($items)->toHaveCount(1);
    $review = $items->first();
    expect($review->source)->toBe('medication');
    expect($review->group)->toBe('auto');
    expect($review->allDay)->toBeTrue();
    expect($review->title)->toContain('Ngata');
    expect($review->title)->toContain('Medication review due');
    expect($review->link)->toBe('/emar/reviews?review='.$scheduled->id);
    expect($review->site['id'])->toBe($site->id);
});

test('an active medication stock expiry surfaces on the home calendar', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $this->actingAs(medicationCalendarReader($site, $client));

    $medication = medicationCalendarOrder($client);

    ClientMedicationStock::create([
        'client_medication_id' => $medication->id,
        'on_hand' => 30,
        'unit' => 'tablets',
        'expiry_date' => now()->addDays(20)->toDateString(),
    ]);

    $items = aggregateMedication([$site->id]);

    expect($items)->toHaveCount(1);
    $stock = $items->first();
    expect($stock->source)->toBe('medication');
    expect($stock->title)->toContain('Warfarin');
    expect($stock->title)->toContain('Stock expires');
    expect($stock->link)->toBe('/emar/stock');
});

test('medication obligations outside the window are excluded', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $this->actingAs(medicationCalendarReader($site, $client));
    medicationCalendarOrder($client);

    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'scheduled',
        'scheduled_date' => now()->addMonths(8)->toDateString(),
    ]);

    expect(aggregateMedication([$site->id]))->toBeEmpty();
});

test('calendar medication obligations conceal foreign Sites and same-Site unassigned people', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $foreignSite = Site::factory()->create(['type' => 'house']);
    $assigned = Client::factory()->create(['site_id' => $site->id]);
    $reader = medicationCalendarReader($site, $assigned);
    $this->actingAs($reader);

    foreach ([$assigned, Client::factory()->create(['site_id' => $site->id]), Client::factory()->create(['site_id' => $foreignSite->id])] as $client) {
        $order = medicationCalendarOrder($client);
        MedicationReview::create([
            'client_id' => $client->id,
            'review_type' => 'annual',
            'status' => 'scheduled',
            'scheduled_date' => today()->addDays(10),
        ]);
        ClientMedicationStock::create([
            'client_medication_id' => $order->id,
            'on_hand' => 30,
            'unit' => 'tablets',
            'expiry_date' => today()->addDays(20),
        ]);
    }

    $items = aggregateMedication([$site->id, $foreignSite->id]);
    expect($items)->toHaveCount(2)
        ->and($items->pluck('site.id')->unique()->all())->toBe([$site->id]);
    foreach ($items as $item) {
        expect($item->title)->toContain($assigned->last_name);
    }

    $view = Permission::query()->where('key', 'medications.view')->firstOrFail();
    $reader->permissionOverrides()->syncWithoutDetaching([$view->id => ['allowed' => false]]);
    $this->actingAs($reader->fresh());
    expect(aggregateMedication([$site->id, $foreignSite->id]))->toBeEmpty();
});
