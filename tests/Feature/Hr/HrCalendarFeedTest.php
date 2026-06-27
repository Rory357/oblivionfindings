<?php

use App\Domain\Hr\Models\HrCalendarEvent;
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

test('unknown layer keys are ignored and default layers apply', function () {
    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    // A bogus layer should not error; the endpoint falls back to defaults.
    $this->actingAs($this->hr)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=__nope__")
        ->assertOk()
        ->assertJsonStructure(['events']);
});
