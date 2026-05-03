<?php

use App\Domain\Rostering\RosterPublishingService;
use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

it('publishes a roster period and stamps shifts without mass assigning publish fields', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $actor = User::factory()->create(['organization_id' => 1]);
    $weekStart = Carbon::parse('2026-05-04', 'Pacific/Auckland')->startOfDay();

    $period = RosterPeriod::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => $weekStart->toDateString(),
    ]);

    $shift = Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
        'status' => 'draft',
    ]);

    $published = app(RosterPublishingService::class)->publish($period, $actor);

    expect($published->status)->toBe(RosterPeriod::STATUS_PUBLISHED);
    expect($shift->fresh()->published_at)->not->toBeNull();
    expect($shift->fresh()->roster_period_id)->toBe($period->id);
});

it('publishes local Monday morning shifts that end on a UTC date boundary', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $actor = User::factory()->create(['organization_id' => 1]);
    $weekStart = Carbon::parse('2026-05-04', 'Pacific/Auckland')->startOfDay();

    $period = RosterPeriod::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => $weekStart->toDateString(),
    ]);

    $shift = Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(12, 0)->utc(),
        'status' => 'draft',
    ]);

    app(RosterPublishingService::class)->publish($period, $actor);

    expect($shift->fresh()->published_at)->not->toBeNull();
    expect($shift->fresh()->roster_period_id)->toBe($period->id);
});

it('marks a published roster period dirty when a roster-relevant shift field changes', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => '2026-05-04',
    ]);

    $shift = Shift::factory()->published($period)->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'roster_period_id' => $period->id,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
    ]);

    $shift->update([
        'starts_at' => Carbon::parse('2026-05-04 10:00:00', 'Pacific/Auckland')->utc(),
    ]);

    expect($shift->fresh()->publish_dirty_at)->not->toBeNull();
    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH);
});

it('republishes a changed period as a new version and archives the previous version', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $actor = User::factory()->create(['organization_id' => 1]);
    $weekStart = Carbon::parse('2026-05-04', 'Pacific/Auckland')->startOfDay();

    $period = RosterPeriod::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => $weekStart->toDateString(),
        'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
    ]);

    $shift = Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
        'status' => 'draft',
    ]);

    $published = app(RosterPublishingService::class)->publish($period, $actor);

    $shift->update([
        'starts_at' => $weekStart->copy()->setTime(10, 0)->utc(),
    ]);

    $diff = app(RosterPublishingService::class)->diff($published->fresh());

    expect($diff['summary']['changed'])->toBe(1);

    $republished = app(RosterPublishingService::class)->republish($published->fresh(), $actor);

    expect($republished->version)->toBe(2);
    expect($republished->status)->toBe(RosterPeriod::STATUS_PUBLISHED);
    expect($published->fresh()->status)->toBe(RosterPeriod::STATUS_ARCHIVED);
    expect($shift->fresh()->roster_period_id)->toBe($republished->id);
    expect($shift->fresh()->publish_dirty_at)->toBeNull();
});
