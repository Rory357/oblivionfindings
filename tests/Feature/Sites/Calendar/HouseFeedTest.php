<?php

use App\Models\CalendarSyncMapping;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the per-house resource feed returns a VCALENDAR for a valid token', function () {
    $site = Site::factory()->create(['type' => 'house']);

    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => now()->addDays(2)->toDateString(),
        'meal_slot' => 'dinner',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'House Roast',
        'servings' => 4,
    ]);

    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'house-a@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'sources' => null, // all sources
        'ical_feed_token' => 'housetoken123',
        'is_active' => true,
    ]);

    $response = $this->get("/calendar/site/{$site->id}/feed/housetoken123.ics");

    $response->assertOk();
    expect($response->getContent())->toContain('BEGIN:VCALENDAR')
        ->toContain('House Roast')
        ->toContain('END:VCALENDAR');
});

test('the per-house feed 404s for an unknown token', function () {
    $site = Site::factory()->create(['type' => 'house']);

    $this->get("/calendar/site/{$site->id}/feed/unknowntoken.ics")->assertNotFound();
});

test('the per-house feed conceals an inactive mapping', function () {
    $site = Site::factory()->create(['type' => 'house']);
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'inactive@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'ical_feed_token' => 'inactivemappingtoken',
        'is_active' => false,
    ]);

    $this->get("/calendar/site/{$site->id}/feed/inactivemappingtoken.ics")->assertNotFound();
});

test('the per-house feed conceals retired sites', function (string $state) {
    $site = Site::factory()->create(['type' => 'house']);
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'retired-'.$state.'@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'ical_feed_token' => 'retired'.$state.'token',
        'is_active' => true,
    ]);

    match ($state) {
        'inactive' => $site->update(['is_active' => false]),
        'archived' => $site->update(['archived' => true, 'archived_at' => now()]),
        'deleted' => $site->delete(),
    };

    $this->get("/calendar/site/{$site->id}/feed/retired{$state}token.ics")->assertNotFound();
})->with(['inactive', 'archived', 'deleted']);

test('the per-house feed conceals a missing site', function () {
    $this->get('/calendar/site/999999999/feed/missingsitetoken.ics')->assertNotFound();
});

test('the per-house feed fails closed when a site has several active mappings', function () {
    $site = Site::factory()->create(['type' => 'house']);
    foreach (['google', 'microsoft'] as $provider) {
        CalendarSyncMapping::create([
            'site_id' => $site->id,
            'provider' => $provider,
            'external_calendar_id' => $provider.'@resource.calendar.test',
            'sync_direction' => 'one_way',
            'ical_feed_token' => $provider.'duplicatemappingtoken',
            'is_active' => true,
        ]);
    }

    $this->get("/calendar/site/{$site->id}/feed/googleduplicatemappingtoken.ics")
        ->assertNotFound();
    $this->get("/calendar/site/{$site->id}/feed/microsoftduplicatemappingtoken.ics")
        ->assertNotFound();
});
