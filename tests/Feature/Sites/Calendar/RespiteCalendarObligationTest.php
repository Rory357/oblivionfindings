<?php

use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\Site;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Aggregate the respite source over a wide today-anchored window for the given sites. */
function aggregateRespite(array $siteIds): \Illuminate\Support\Collection
{
    return collect(app(SiteCalendarAggregator::class)->itemsForRange(
        $siteIds,
        now()->subMonth(),
        now()->addMonths(4),
        ['sources' => ['respite']],
    ));
}

test('respite bookings surface on the home calendar, resolving the site from the client when the booking has no location', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id, 'last_name' => 'Pohatu']);

    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'status' => 'confirmed',
        'location_id' => null,
        'start_at' => now()->addDays(3),
        'end_at' => now()->addDays(10),
    ]);

    // A cancelled booking for the same client must NOT appear on the calendar.
    RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'status' => 'cancelled',
        'location_id' => null,
        'start_at' => now()->addDays(3),
        'end_at' => now()->addDays(10),
    ]);

    $items = aggregateRespite([$site->id]);

    expect($items)->toHaveCount(1);
    $resp = $items->first();
    expect($resp->source)->toBe('respite');
    expect($resp->group)->toBe('auto');
    expect($resp->editable)->toBeFalse();
    expect($resp->allDay)->toBeTrue();
    expect($resp->title)->toContain('Pohatu');
    expect($resp->status)->toBe('approved');             // confirmed -> approved
    expect($resp->site['id'])->toBe($site->id);
    expect($resp->link)->toBe("/respite/bookings/{$booking->id}");
});

test('a respite booking with an explicit location surfaces under that home, not the client home', function () {
    $homeA = Site::factory()->create(['type' => 'house']);
    $homeB = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $homeA->id]);

    RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'status' => 'confirmed',
        'location_id' => $homeB->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(6),
    ]);

    // Shows under the booking's own location...
    expect(aggregateRespite([$homeB->id]))->toHaveCount(1);
    // ...and not under the client's home, because the booking has its own location.
    expect(aggregateRespite([$homeA->id]))->toBeEmpty();
});

test('respite bookings outside the requested window are excluded', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id]);

    RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'status' => 'confirmed',
        'location_id' => null,
        'start_at' => now()->addMonths(8),
        'end_at' => now()->addMonths(8)->addDays(5),
    ]);

    expect(aggregateRespite([$site->id]))->toBeEmpty();
});
