<?php

use App\Jobs\SyncResourceCalendarsJob;
use App\Models\CalendarSyncMapping;
use App\Models\Site;
use App\Services\Sites\Calendar\CalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/** @return array{0: Site, 1: CalendarSyncMapping} */
function resourceCalendarJobFixture(): array
{
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'job@resource.calendar.google.com',
        'sync_direction' => 'two_way',
        'ical_feed_token' => str_repeat('j', 48),
        'is_active' => true,
    ]);

    return [$site, $mapping];
}

test('the cadence job excludes mappings for retired sites even when directly targeted', function (string $state) {
    [$site, $mapping] = resourceCalendarJobFixture();

    match ($state) {
        'inactive' => $site->update(['is_active' => false]),
        'archived' => $site->update(['archived' => true, 'archived_at' => now()]),
        'deleted' => $site->delete(),
    };

    $service = Mockery::mock(CalendarSyncService::class);
    $service->shouldNotReceive('syncMapping');
    Log::spy();

    (new SyncResourceCalendarsJob($mapping->id))->handle($service);

    Log::shouldNotHaveReceived('info');
})->with(['inactive', 'archived', 'deleted']);

test('the cadence job does not log a skipped service result as a successful sync', function () {
    [, $mapping] = resourceCalendarJobFixture();
    $service = Mockery::mock(CalendarSyncService::class);
    $service->shouldReceive('syncMapping')
        ->once()
        ->withArgs(fn (CalendarSyncMapping $candidate): bool => $candidate->is($mapping))
        ->andReturnNull();
    Log::spy();

    (new SyncResourceCalendarsJob($mapping->id))->handle($service);

    Log::shouldNotHaveReceived('info');
});

test('the cadence and direct jobs fail closed before syncing a site with several active mappings', function (bool $direct) {
    [$site, $mapping] = resourceCalendarJobFixture();
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'microsoft',
        'external_calendar_id' => 'duplicate@resource.calendar.test',
        'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('k', 48),
        'is_active' => true,
    ]);
    $service = Mockery::mock(CalendarSyncService::class);
    $service->shouldNotReceive('syncMapping');
    Log::spy();

    (new SyncResourceCalendarsJob($direct ? $mapping->id : null))->handle($service);

    Log::shouldNotHaveReceived('info');
    Log::shouldHaveReceived('warning')->once();
})->with(['cadence' => false, 'direct' => true]);

test('the cadence job does not log an incomplete result as a successful sync', function () {
    [, $mapping] = resourceCalendarJobFixture();
    $service = Mockery::mock(CalendarSyncService::class);
    $service->shouldReceive('syncMapping')
        ->once()
        ->withArgs(fn (CalendarSyncMapping $candidate): bool => $candidate->is($mapping))
        ->andReturn(['pushed' => 0, 'pulled' => 0, 'failed' => 1]);
    Log::spy();

    (new SyncResourceCalendarsJob($mapping->id))->handle($service);

    Log::shouldNotHaveReceived('info');
    Log::shouldHaveReceived('warning')->once();
});
