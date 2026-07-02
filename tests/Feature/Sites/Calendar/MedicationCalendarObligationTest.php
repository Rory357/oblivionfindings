<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationReview;
use App\Models\Site;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

    MedicationReview::create([
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
    expect($review->link)->toBe('/emar/reviews');
    expect($review->site['id'])->toBe($site->id);
});

test('an active medication stock expiry surfaces on the home calendar', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);

    $medication = ClientMedication::create([
        'client_id' => $client->id,
        'name' => 'Warfarin',
        'dosage' => '3mg',
        'frequency' => 'Once daily',
        'active' => true,
        'state' => 'active',
    ]);

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

    MedicationReview::create([
        'client_id' => $client->id,
        'review_type' => 'annual',
        'status' => 'scheduled',
        'scheduled_date' => now()->addMonths(8)->toDateString(),
    ]);

    expect(aggregateMedication([$site->id]))->toBeEmpty();
});
