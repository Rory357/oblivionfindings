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

test('the legacy POST /hr/calendar route does not exist (the old 405 bug)', function () {
    // /hr/calendar is GET-only; the create dialog used to POST here → 405.
    $this->actingAs($this->hr)
        ->post('/hr/calendar', ['title' => 'x'])
        ->assertStatus(405);
});

test('an HR calendar event can be created via the events endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/calendar/events', [
            'title' => 'All-staff hui',
            'event_type' => 'company',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addHours(2)->toDateTimeString(),
            'is_all_day' => false,
            'site_id' => null,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_calendar_events', [
        'title' => 'All-staff hui',
        'event_type' => 'company',
        'tenant_id' => 1,
    ]);
});

test('an HR calendar event can be updated and deleted', function () {
    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Draft',
        'event_type' => 'team',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(),
        'is_all_day' => false,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/calendar/events/{$event->id}", [
            'title' => 'Renamed',
            'event_type' => 'training',
            'starts_at' => $event->starts_at->toDateTimeString(),
            'ends_at' => $event->ends_at->toDateTimeString(),
        ])
        ->assertRedirect();

    expect($event->fresh()->title)->toBe('Renamed');
    expect($event->fresh()->event_type)->toBe('training');

    $this->actingAs($this->hr)
        ->delete("/hr/calendar/events/{$event->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('hr_calendar_events', ['id' => $event->id]);
});
