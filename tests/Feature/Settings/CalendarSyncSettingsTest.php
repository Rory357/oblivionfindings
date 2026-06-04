<?php

use App\Jobs\SyncResourceCalendarsJob;
use App\Models\CalendarSyncMapping;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

/** An admin (RbacSeeder grants every permission to admin, incl. integrations.manage_tenant_secrets). */
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

test('clearing the provider deactivates the house mapping', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $mapping = CalendarSyncMapping::create([
        'tenant_id' => 0, 'site_id' => $site->id, 'provider' => 'google',
        'external_calendar_id' => 'x', 'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('a', 48), 'is_active' => true,
    ]);

    $this->actingAs(calSyncAdmin())
        ->put('/settings/calendar-sync/mapping', ['site_id' => $site->id, 'provider' => null])
        ->assertRedirect();

    expect($mapping->fresh()->is_active)->toBeFalse();
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

test('resetting a house feed rotates its token', function () {
    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $mapping = CalendarSyncMapping::create([
        'tenant_id' => 0, 'site_id' => $site->id, 'provider' => 'google',
        'external_calendar_id' => 'x', 'sync_direction' => 'one_way',
        'ical_feed_token' => str_repeat('a', 48), 'is_active' => true,
    ]);
    $original = $mapping->ical_feed_token;

    $this->actingAs(calSyncAdmin())
        ->post("/settings/calendar-sync/mapping/{$mapping->id}/reset-feed")
        ->assertRedirect();

    expect($mapping->fresh()->ical_feed_token)->not->toBe($original);
});
