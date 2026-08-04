<?php

use App\Jobs\SyncResourceCalendarsJob;
use App\Models\CalendarSyncMapping;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** An admin (RbacSeeder grants every permission to admin, incl. integrations.manage_secrets). */
function calSyncAdmin(): User
{
    $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'admin')->first()->id]);

    return $user;
}

test('the calendar-sync settings page requires the manage-integrations permission', function () {
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->first()->id]);

    $this->actingAs($worker)->get('/settings/calendar-sync')->assertForbidden();
});

test('an admin sees the calendar-sync settings page with providers, sites and settings', function () {
    Site::factory()->create(['type' => 'house', 'is_active' => true]);

    $this->actingAs(calSyncAdmin())
        ->get('/settings/calendar-sync')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/calendar-sync')
            ->has('providers', 2)
            ->has('sites', 1)
            ->has('sources')
            ->has('settings'));
});

test('the settings page lists only current operational sites', function () {
    $active = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);
    Site::factory()->create([
        'type' => 'house',
        'is_active' => false,
        'archived' => false,
    ]);
    Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => true,
        'archived_at' => now(),
    ]);

    $this->actingAs(calSyncAdmin())
        ->get('/settings/calendar-sync')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sites', 1)
            ->where('sites.0.id', $active->id));
});

test('the settings page serializes only the active mapping for each site', function () {
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'active@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('a', 48),
        'is_active' => true,
    ]);
    CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'microsoft',
        'external_calendar_id' => 'inactive@resource.calendar.windows.net',
        'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('b', 48),
        'is_active' => false,
    ]);

    $this->actingAs(calSyncAdmin())
        ->get('/settings/calendar-sync')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sites.0.mapping.provider', 'google')
            ->where('sites.0.mapping.isActive', true));
});

test('the settings page fails closed when a site has several active mappings', function () {
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);
    foreach (['google', 'microsoft'] as $provider) {
        CalendarSyncMapping::create([
            'site_id' => $site->id,
            'provider' => $provider,
            'external_calendar_id' => $provider.'@resource.calendar.test',
            'sync_direction' => 'one_way',
            'ical_feed_token' => str_repeat($provider === 'google' ? 'g' : 'm', 48),
            'is_active' => true,
        ]);
    }

    $this->actingAs(calSyncAdmin())
        ->get('/settings/calendar-sync')
        ->assertStatus(409);
});

test('saving a mapping creates an active row and generates a secret feed token', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

    $this->actingAs(calSyncAdmin())
        ->put('/settings/calendar-sync/mapping', [
            'site_id' => $site->id,
            'provider' => 'google',
            'external_calendar_id' => 'house-a@resource.calendar.google.com',
            'external_calendar_name' => 'House A',
            'sync_direction' => 'two_way',
            'sources' => ['event', 'inspection'],
            'is_active' => true,
        ])
        ->assertRedirect();

    $mapping = CalendarSyncMapping::query()->where('site_id', $site->id)->firstOrFail();
    expect($mapping->provider)->toBe('google')
        ->and($mapping->external_calendar_id)->toBe('house-a@resource.calendar.google.com')
        ->and($mapping->sync_direction)->toBe('two_way')
        ->and($mapping->sources)->toBe(['event', 'inspection'])
        ->and($mapping->is_active)->toBeTrue()
        ->and(strlen((string) $mapping->ical_feed_token))->toBeGreaterThan(20);
});

test('switching a site provider leaves exactly one active mapping', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

    foreach (['google', 'microsoft'] as $provider) {
        $this->actingAs(calSyncAdmin())
            ->put('/settings/calendar-sync/mapping', [
                'site_id' => $site->id,
                'provider' => $provider,
                'external_calendar_id' => $provider.'@resource.calendar.test',
                'external_calendar_name' => ucfirst($provider),
                'sync_direction' => 'one_way',
                'is_active' => true,
            ])
            ->assertRedirect();
    }

    expect(CalendarSyncMapping::query()->where('site_id', $site->id)->active()->count())->toBe(1)
        ->and(CalendarSyncMapping::query()->where('site_id', $site->id)->where('provider', 'google')->firstOrFail()->is_active)
        ->toBeFalse()
        ->and(CalendarSyncMapping::query()->where('site_id', $site->id)->where('provider', 'microsoft')->firstOrFail()->is_active)
        ->toBeTrue();
});

test('clearing the provider deactivates the house mapping', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id, 'provider' => 'google',
        'external_calendar_id' => 'x', 'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('a', 48), 'is_active' => true,
    ]);

    $this->actingAs(calSyncAdmin())
        ->put('/settings/calendar-sync/mapping', ['site_id' => $site->id, 'provider' => null])
        ->assertRedirect();

    expect($mapping->fresh()->is_active)->toBeFalse();
});

test('mapping mutations reject inactive and archived sites', function () {
    $inactive = Site::factory()->create([
        'type' => 'house',
        'is_active' => false,
        'archived' => false,
    ]);
    $archived = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => true,
        'archived_at' => now(),
    ]);

    foreach ([$inactive, $archived] as $site) {
        $this->actingAs(calSyncAdmin())
            ->put('/settings/calendar-sync/mapping', [
                'site_id' => $site->id,
                'provider' => 'google',
                'external_calendar_id' => 'closed-site@resource.calendar.google.com',
                'external_calendar_name' => 'Closed Site',
                'sync_direction' => 'one_way',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    expect(CalendarSyncMapping::query()->count())->toBe(0);
});

test('mapping mutation conceals a missing site and does not write', function () {
    $this->actingAs(calSyncAdmin())
        ->put('/settings/calendar-sync/mapping', [
            'site_id' => 999999,
            'provider' => 'google',
            'external_calendar_id' => 'forged@resource.calendar.google.com',
            'external_calendar_name' => 'Forged',
            'sync_direction' => 'one_way',
            'is_active' => true,
        ])
        ->assertForbidden();

    expect(CalendarSyncMapping::query()->count())->toBe(0);
});

test('global settings persist to app_settings', function () {
    $this->actingAs(calSyncAdmin())
        ->put('/settings/calendar-sync/settings', [
            'cadence_minutes' => 30,
            'conflict_policy' => 'ignore',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('app_settings', ['key' => 'sites.calendar_sync.settings']);
});

test('sync now queues the resource-calendar sync job', function () {
    Queue::fake();

    $this->actingAs(calSyncAdmin())
        ->post('/settings/calendar-sync/sync-now')
        ->assertRedirect();

    Queue::assertPushed(SyncResourceCalendarsJob::class);
});

test('sync now queues only the authorised mapping requested', function () {
    Queue::fake();

    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);
    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'house@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('c', 48),
        'is_active' => true,
    ]);

    $this->actingAs(calSyncAdmin())
        ->post('/settings/calendar-sync/sync-now', ['mapping_id' => $mapping->id])
        ->assertRedirect();

    Queue::assertPushed(
        SyncResourceCalendarsJob::class,
        fn (SyncResourceCalendarsJob $job): bool => $job->mappingId === $mapping->id,
    );
});

test('resetting a house feed rotates its token', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id, 'provider' => 'google',
        'external_calendar_id' => 'x', 'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('a', 48), 'is_active' => true,
    ]);
    $original = $mapping->ical_feed_token;

    $this->actingAs(calSyncAdmin())
        ->post("/settings/calendar-sync/mapping/{$mapping->id}/reset-feed")
        ->assertRedirect();

    expect($mapping->fresh()->ical_feed_token)->not->toBe($original);
});

test('manual sync rejects a mapping for a site that is no longer operational', function () {
    Queue::fake();

    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => true,
        'archived_at' => now(),
    ]);
    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'closed-site@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('a', 48),
        'is_active' => true,
    ]);
    $this->actingAs(calSyncAdmin())
        ->post('/settings/calendar-sync/sync-now', ['mapping_id' => $mapping->id])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('feed reset conceals mappings for sites that are no longer operational', function (string $state) {
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);
    $mapping = CalendarSyncMapping::create([
        'site_id' => $site->id,
        'provider' => 'google',
        'external_calendar_id' => 'closed-site@resource.calendar.google.com',
        'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('d', 48),
        'is_active' => true,
    ]);
    $original = $mapping->ical_feed_token;

    match ($state) {
        'inactive' => $site->update(['is_active' => false]),
        'archived' => $site->update(['archived' => true, 'archived_at' => now()]),
        'deleted' => $site->delete(),
    };

    $this->actingAs(calSyncAdmin())
        ->post("/settings/calendar-sync/mapping/{$mapping->id}/reset-feed")
        ->assertNotFound();

    expect($mapping->fresh()->ical_feed_token)->toBe($original);
})->with(['inactive', 'archived', 'deleted']);

test('feed reset conceals a missing mapping', function () {
    $this->actingAs(calSyncAdmin())
        ->post('/settings/calendar-sync/mapping/999999/reset-feed')
        ->assertNotFound();
});
