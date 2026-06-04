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
        'tenant_id' => $site->tenant_id,
        'plan_date' => now()->addDays(2)->toDateString(),
        'meal_slot' => 'dinner',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'House Roast',
        'servings' => 4,
    ]);

    CalendarSyncMapping::create([
        'tenant_id' => 0,
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
