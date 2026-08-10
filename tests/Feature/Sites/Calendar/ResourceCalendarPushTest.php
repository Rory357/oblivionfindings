<?php

use App\Models\CalendarSyncConnection;
use App\Models\CalendarSyncEventLink;
use App\Models\CalendarSyncMapping;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\User;
use App\Services\Sites\Calendar\CalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: SiteCalendarEvent, 2: CalendarSyncMapping}
 */
function pushFixtures(array $eventOverrides = []): array
{
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $user = User::factory()->create();

    // One org connection (idempotent so repeated fixtures don't violate the unique key).
    CalendarSyncConnection::firstOrCreate(
        ['provider' => 'google'],
        [
            'status' => CalendarSyncConnection::STATUS_CONNECTED,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
            'account_email' => 'admin@org.test',
        ],
    );

    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'house-a@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'sources' => ['event'],
        'ical_feed_token' => str_repeat('a', 48),
        'is_active' => true,
    ]);

    $event = SiteCalendarEvent::create(array_merge([
        'site_id' => $site->id,
        'event_type' => 'general',
        'title' => 'Team meeting',
        'start_at' => Carbon::parse('2026-06-10 09:00'),
        'end_at' => Carbon::parse('2026-06-10 10:00'),
        'all_day' => false,
        'created_by_user_id' => $user->id,
        'owner_user_id' => $user->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ], $eventOverrides));

    return [$site, $event, $mapping];
}

test('pushing a manual event creates an external event and an idempotency link', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-1'], 200)]);
    [, $event] = pushFixtures();

    app(CalendarSyncService::class)->pushEvent($event);

    expect(CalendarSyncEventLink::count())->toBe(1);
    $link = CalendarSyncEventLink::firstOrFail();
    expect($link->external_event_id)->toBe('ext-1')
        ->and($link->site_calendar_event_id)->toBe($event->id)
        ->and($link->provider)->toBe('google');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/events')
        && $req['summary'] === 'Team meeting'
        && isset($req['start']['dateTime']));
});

test('pushing the same event twice updates rather than duplicating', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-1'], 200)]);
    [, $event] = pushFixtures();

    $svc = app(CalendarSyncService::class);
    $svc->pushEvent($event);
    $svc->pushEvent($event->fresh());

    expect(CalendarSyncEventLink::count())->toBe(1);
});

test('the application provider connection creates an event link without caller compatibility storage', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-global'], 200)]);
    [, $event] = pushFixtures();

    app(CalendarSyncService::class)->pushEvent($event);

    $link = CalendarSyncEventLink::firstOrFail();
    expect($link->external_event_id)->toBe('ext-global');
    Http::assertSent(fn ($request) => $request->method() === 'POST');
});

test('retired sites do not push events to a provider', function (string $state) {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'should-not-exist'], 200)]);
    [$site, $event] = pushFixtures();

    match ($state) {
        'inactive' => $site->update(['is_active' => false]),
        'archived' => $site->update(['archived' => true, 'archived_at' => now()]),
        'deleted' => $site->delete(),
    };

    app(CalendarSyncService::class)->pushEvent($event);

    Http::assertNothingSent();
    expect(CalendarSyncEventLink::count())->toBe(0);
})->with(['inactive', 'archived', 'deleted']);

test('external retraction remains available as cleanup after a site is archived', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-1'], 200)]);
    [$site, $event] = pushFixtures();

    $svc = app(CalendarSyncService::class);
    $svc->pushEvent($event);
    expect(CalendarSyncEventLink::count())->toBe(1);

    $site->update(['archived' => true, 'archived_at' => now()]);
    $svc->deleteEvent($event);

    expect(CalendarSyncEventLink::count())->toBe(0);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('recurring events are not API-pushed (they flow via the .ics feed)', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-1'], 200)]);
    [, $recurring] = pushFixtures(['recurrence_rule' => 'FREQ=WEEKLY']);

    app(CalendarSyncService::class)->pushEvent($recurring);

    expect(CalendarSyncEventLink::count())->toBe(0);
});

test('events awaiting approval are not pushed', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-1'], 200)]);
    [, $pending] = pushFixtures(['approval_status' => 'pending', 'status' => 'pending']);

    app(CalendarSyncService::class)->pushEvent($pending);

    expect(CalendarSyncEventLink::count())->toBe(0);
});

test('event push fails closed when a site has several active mappings', function () {
    Http::fake(['*' => Http::response(['id' => 'must-not-be-created'], 200)]);
    [$site, $event] = pushFixtures();
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'microsoft',
        'external_calendar_id' => 'duplicate@resource.calendar.test',
        'sync_direction' => 'one_way',
        'sources' => ['event'],
        'ical_feed_token' => str_repeat('d', 48),
        'is_active' => true,
    ]);

    app(CalendarSyncService::class)->pushEvent($event);

    Http::assertNothingSent();
    expect(CalendarSyncEventLink::count())->toBe(0);
});

test('direct mapping sync fails closed when a site has several active mappings', function () {
    Http::fake(['*' => Http::response(['id' => 'must-not-be-created'], 200)]);
    [$site, $event, $mapping] = pushFixtures([
        'start_at' => now()->addDay(),
        'end_at' => now()->addDay()->addHour(),
    ]);
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'microsoft',
        'external_calendar_id' => 'duplicate@resource.calendar.test',
        'sync_direction' => 'one_way',
        'sources' => ['event'],
        'ical_feed_token' => str_repeat('e', 48),
        'is_active' => true,
    ]);

    $result = app(CalendarSyncService::class)->syncMapping($mapping);

    expect($result)->toBeNull();
    Http::assertNothingSent();
    expect(CalendarSyncEventLink::query()->where('site_calendar_event_id', $event->id)->count())->toBe(0);
});

test('catch-up sync retains provider failures and reports an incomplete result', function () {
    Http::fake(['*' => Http::response(['error' => 'provider unavailable'], 503)]);
    [, , $mapping] = pushFixtures([
        'start_at' => now()->addDay(),
        'end_at' => now()->addDay()->addHour(),
    ]);

    $result = app(CalendarSyncService::class)->syncMapping($mapping);

    expect($result)->toMatchArray(['pushed' => 0, 'pulled' => 0, 'failed' => 1]);
    $mapping->refresh();
    expect($mapping->last_synced_at)->toBeNull()
        ->and($mapping->last_error)->toContain('Provider did not return an event id');
    expect(CalendarSyncEventLink::count())->toBe(0);
});
