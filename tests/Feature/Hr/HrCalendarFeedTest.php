<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the unified feed validates its date range', function () {
    $this->actingAs($this->hr)
        ->getJson('/hr/calendar/feed')
        ->assertStatus(422);
});

test('the feed returns HR events on the event layer', function () {
    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'All-staff hui',
        'event_type' => 'company',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'is_all_day' => false,
    ]);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->addMonth()->toDateString();

    $response = $this->actingAs($this->hr)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
        ->assertOk()
        ->assertJsonStructure(['events' => [['layer', 'id', 'title', 'start', 'end', 'editable', 'extendedProps']]]);

    $events = collect($response->json('events'));
    $row = $events->firstWhere('id', 'event-'.$event->id);

    expect($row)->not->toBeNull();
    expect($row['layer'])->toBe('event');
    expect($row['editable'])->toBeTrue();
    expect($row['title'])->toBe('All-staff hui');
});

test('the feed never bleeds events across tenants', function () {
    HrCalendarEvent::query()->create([
        'tenant_id' => 999, // a different tenant
        'created_by' => $this->hr->id,
        'title' => 'Other-tenant secret',
        'event_type' => 'company',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'is_all_day' => false,
    ]);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->addMonth()->toDateString();

    $events = collect(
        $this->actingAs($this->hr)
            ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
            ->assertOk()
            ->json('events')
    );

    expect($events->pluck('title'))->not->toContain('Other-tenant secret');
});

test('a recurring event expands into occurrences in the feed', function () {
    HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Weekly standup',
        'event_type' => 'team',
        'starts_at' => '2026-07-01 09:00:00', // a Wednesday
        'ends_at' => '2026-07-01 09:30:00',
        'is_all_day' => false,
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $events = collect(
        $this->actingAs($this->hr)
            ->getJson('/hr/calendar/feed?from=2026-07-01&to=2026-07-31&layers=event')
            ->assertOk()
            ->json('events')
    )->where('title', 'Weekly standup');

    // July 2026 has five Wednesdays from the 1st (1, 8, 15, 22, 29).
    expect($events->count())->toBe(5);
    expect($events->pluck('id')->every(fn ($id) => str_starts_with($id, 'event-')))->toBeTrue();
});

test('editing one occurrence of a series stores an override', function () {
    $this->hr->roles()->first()->permissions()->syncWithoutDetaching(
        Permission::query()->where('key', 'calendar.manage_recurring')->pluck('id')->all()
    );

    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Weekly standup',
        'event_type' => 'team',
        'starts_at' => '2026-07-01 09:00:00',
        'ends_at' => '2026-07-01 09:30:00',
        'is_all_day' => false,
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/calendar/events/{$event->id}", [
            'title' => 'Standup (moved)',
            'event_type' => 'team',
            'starts_at' => '2026-07-15 10:00:00',
            'ends_at' => '2026-07-15 10:30:00',
            'scope' => 'this',
            'occurrence_date' => '2026-07-15',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_calendar_events', [
        'recurrence_parent_id' => $event->id,
        'is_exception' => true,
        'title' => 'Standup (moved)',
    ]);

    $titles = collect(
        $this->actingAs($this->hr)
            ->getJson('/hr/calendar/feed?from=2026-07-13&to=2026-07-19&layers=event')
            ->assertOk()
            ->json('events')
    )->pluck('title');

    // The override shows on the 15th; the base series occurrence is suppressed.
    expect($titles)->toContain('Standup (moved)');
    expect($titles)->not->toContain('Weekly standup');
});

test('unknown layer keys are ignored and default layers apply', function () {
    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    // A bogus layer should not error; the endpoint falls back to defaults.
    $this->actingAs($this->hr)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=__nope__")
        ->assertOk()
        ->assertJsonStructure(['events']);
});
