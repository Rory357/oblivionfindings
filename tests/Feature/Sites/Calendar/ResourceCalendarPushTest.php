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
        ['tenant_id' => 0, 'provider' => 'google'],
        [
            'status' => CalendarSyncConnection::STATUS_CONNECTED,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
            'account_email' => 'admin@org.test',
        ],
    );

    $mapping = CalendarSyncMapping::create([
        'tenant_id' => 0,
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
        'tenant_id' => $site->tenant_id,
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

test('deleting an event retracts it from the resource calendar and clears the link', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ext-1'], 200)]);
    [, $event] = pushFixtures();

    $svc = app(CalendarSyncService::class);
    $svc->pushEvent($event);
    expect(CalendarSyncEventLink::count())->toBe(1);

    $svc->deleteEvent($event);
    expect(CalendarSyncEventLink::count())->toBe(0);
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
