<?php

use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the subscribe feed returns a VCALENDAR for a valid token', function () {
    $site = Site::factory()->create(['type' => 'house']);

    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'plan_date' => now()->addDays(3)->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Feed Roast',
        'servings' => 2,
    ]);

    $user = User::factory()->create();
    $user->calendar_feed_token = 'feedtoken123';
    $user->save();

    $response = $this->get('/calendar/feed/feedtoken123.ics');

    $response->assertOk();
    expect($response->getContent())->toContain('BEGIN:VCALENDAR');
    expect($response->getContent())->toContain('Feed Roast');
    expect($response->getContent())->toContain('END:VCALENDAR');
});

test('the subscribe feed 404s for an unknown token', function () {
    $this->get('/calendar/feed/unknowntoken.ics')->assertNotFound();
});
