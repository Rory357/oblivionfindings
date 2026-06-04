<?php

use App\Models\CalendarSyncBusyBlock;
use App\Models\CalendarSyncConnection;
use App\Models\CalendarSyncMapping;
use App\Models\Site;
use App\Services\Sites\Calendar\CalendarSyncService;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Two-way mapping fixture (connected Google + a house mapped for pull).
 *
 * @return array{0: Site, 1: CalendarSyncMapping}
 */
function twoWayFixture(): array
{
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

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
        'sync_direction' => 'two_way',
        'sources' => ['event'],
        'ical_feed_token' => str_repeat('b', 48),
        'is_active' => true,
    ]);

    return [$site, $mapping];
}

/** @param array<int, array<string, mixed>> $items */
function fakeGoogle(array $items): void
{
    Http::fake([
        'www.googleapis.com/*' => Http::response(['items' => $items], 200),
    ]);
}

test('two-way sync persists external busy blocks (skipping cancelled, flagging free)', function () {
    [$site, $mapping] = twoWayFixture();
    $start = now()->addDays(2)->setTime(9, 0);

    fakeGoogle([
        ['id' => 'g1', 'summary' => 'Resident outing', 'status' => 'confirmed',
            'start' => ['dateTime' => $start->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addHour()->toRfc3339String()]],
        ['id' => 'g2', 'summary' => 'Pencilled in', 'transparency' => 'transparent',
            'start' => ['dateTime' => $start->copy()->addDay()->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addDay()->addHour()->toRfc3339String()]],
        ['id' => 'g3', 'summary' => 'Cancelled', 'status' => 'cancelled',
            'start' => ['dateTime' => $start->copy()->addDays(2)->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addDays(2)->addHour()->toRfc3339String()]],
        ['id' => 'g4', 'summary' => 'Maintenance day',
            'start' => ['date' => $start->copy()->addDays(3)->toDateString()],
            'end' => ['date' => $start->copy()->addDays(4)->toDateString()]],
    ]);

    app(CalendarSyncService::class)->syncMapping($mapping);

    // Cancelled (g3) is skipped; the other three persist.
    expect(CalendarSyncBusyBlock::count())->toBe(3);
    expect(CalendarSyncBusyBlock::where('external_event_id', 'g2')->value('is_busy'))->toBeFalse();
    $allDay = CalendarSyncBusyBlock::where('external_event_id', 'g4')->firstOrFail();
    expect($allDay->all_day)->toBeTrue();
});

test('the aggregator surfaces busy blocks as a read-only external source', function () {
    [$site, $mapping] = twoWayFixture();
    $start = now()->addDays(2)->setTime(9, 0);

    fakeGoogle([
        ['id' => 'g1', 'summary' => 'Resident outing',
            'start' => ['dateTime' => $start->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addHour()->toRfc3339String()]],
        ['id' => 'g2', 'summary' => 'Free slot', 'transparency' => 'transparent',
            'start' => ['dateTime' => $start->copy()->addDay()->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addDay()->addHour()->toRfc3339String()]],
    ]);

    app(CalendarSyncService::class)->syncMapping($mapping);

    $items = app(SiteCalendarAggregator::class)->itemsForRange(
        [$site->id],
        now()->subWeek(),
        now()->addMonth(),
        ['sources' => ['external']],
    );

    // Only the busy block surfaces; the free one is filtered out.
    expect($items)->toHaveCount(1);
    expect($items[0]->source)->toBe('external')
        ->and($items[0]->group)->toBe('auto')
        ->and($items[0]->editable)->toBeFalse()
        ->and($items[0]->title)->toBe('Resident outing');
});

test('busy blocks that vanish from the source are pruned on the next sync', function () {
    [, $mapping] = twoWayFixture();
    $start = now()->addDays(2)->setTime(9, 0);

    // First pull returns g1; the second returns nothing (a sequence — re-calling
    // Http::fake only *appends* stubs, so the first match would otherwise win).
    Http::fake([
        'www.googleapis.com/*' => Http::sequence()
            ->push(['items' => [[
                'id' => 'g1', 'summary' => 'Outing',
                'start' => ['dateTime' => $start->toRfc3339String()],
                'end' => ['dateTime' => $start->copy()->addHour()->toRfc3339String()],
            ]]], 200)
            ->push(['items' => []], 200),
    ]);

    app(CalendarSyncService::class)->syncMapping($mapping);
    expect(CalendarSyncBusyBlock::where('external_event_id', 'g1')->exists())->toBeTrue();

    app(CalendarSyncService::class)->syncMapping($mapping->fresh());
    expect(CalendarSyncBusyBlock::where('external_event_id', 'g1')->exists())->toBeFalse();
});

test('one-way mappings do not pull external busy', function () {
    [, $mapping] = twoWayFixture();
    $mapping->update(['sync_direction' => 'one_way']);

    fakeGoogle([
        ['id' => 'g1', 'summary' => 'Outing',
            'start' => ['dateTime' => now()->addDay()->toRfc3339String()],
            'end' => ['dateTime' => now()->addDay()->addHour()->toRfc3339String()]],
    ]);

    $counts = app(CalendarSyncService::class)->syncMapping($mapping->fresh());

    expect($counts['pulled'])->toBe(0);
    expect(CalendarSyncBusyBlock::count())->toBe(0);
});
