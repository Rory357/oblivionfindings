<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Models\HrCalendarEventReminder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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

test('an event can invite specific people who can then RSVP', function () {
    $invitee = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $invitee->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);

    $this->actingAs($this->hr)
        ->post('/hr/calendar/events', [
            'title' => 'Team lunch',
            'event_type' => 'social',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addHour()->toDateTimeString(),
            'audience_type' => 'people',
            'audience_user_ids' => [$invitee->id],
        ])
        ->assertRedirect();

    $event = HrCalendarEvent::query()->where('title', 'Team lunch')->firstOrFail();

    $this->assertDatabaseHas('hr_calendar_event_attendees', [
        'event_id' => $event->id,
        'user_id' => $invitee->id,
        'audience_type' => 'person',
        'rsvp_status' => 'none',
    ]);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->addMonth()->toDateString();
    $row = collect(
        $this->actingAs($this->hr)
            ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
            ->json('events')
    )->firstWhere('id', 'event-'.$event->id);

    expect($row['extendedProps']['attendeeCount'])->toBe(1);

    // The invitee responds.
    $this->actingAs($invitee)
        ->post("/hr/calendar/events/{$event->id}/rsvp", ['status' => 'yes'])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_calendar_event_attendees', [
        'event_id' => $event->id,
        'user_id' => $invitee->id,
        'rsvp_status' => 'yes',
    ]);
});

test('a user who was not invited cannot RSVP', function () {
    $outsider = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $outsider->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);

    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Private review',
        'event_type' => 'company',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(),
        'is_all_day' => false,
    ]);

    $this->actingAs($outsider)
        ->post("/hr/calendar/events/{$event->id}/rsvp", ['status' => 'yes'])
        ->assertForbidden();
});

test('reminders are stored and the scheduler dispatches them at lead time', function () {
    $invitee = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $invitee->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);

    $this->actingAs($this->hr)
        ->post('/hr/calendar/events', [
            'title' => 'Standup',
            'event_type' => 'team',
            'starts_at' => now()->addMinutes(10)->toDateTimeString(),
            'ends_at' => now()->addMinutes(40)->toDateTimeString(),
            'audience_type' => 'people',
            'audience_user_ids' => [$invitee->id],
            'reminders' => [['offset_minutes' => 10, 'channel' => 'notification']],
        ])
        ->assertRedirect();

    $event = HrCalendarEvent::query()->where('title', 'Standup')->firstOrFail();

    $this->assertDatabaseHas('hr_calendar_event_reminders', [
        'event_id' => $event->id,
        'offset_minutes' => 10,
        'channel' => 'notification',
    ]);

    Notification::fake();

    // The 10-minute reminder for an event 10 minutes out is due now.
    $this->artisan('hr:dispatch-calendar-reminders')->assertExitCode(0);

    $reminder = HrCalendarEventReminder::query()->where('event_id', $event->id)->firstOrFail();
    expect($reminder->last_sent_at)->not->toBeNull();
});

test('an attachment uploads to the private disk, surfaces in the feed and downloads', function () {
    Storage::fake('private');

    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Board meeting',
        'event_type' => 'company',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'is_all_day' => false,
    ]);

    $response = $this->actingAs($this->hr)
        ->post("/hr/calendar/events/{$event->id}/attachments", [
            'file' => UploadedFile::fake()->create('agenda.pdf', 80, 'application/pdf'),
        ])
        ->assertOk();

    $attachmentId = $response->json('attachment.id');
    $attachment = HrCalendarEventAttachment::query()->findOrFail($attachmentId);
    Storage::disk('private')->assertExists($attachment->path);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->addMonth()->toDateString();
    $row = collect(
        $this->actingAs($this->hr)
            ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
            ->json('events')
    )->firstWhere('id', 'event-'.$event->id);

    expect($row['extendedProps']['attachments'])->toHaveCount(1);

    $this->actingAs($this->hr)
        ->get("/hr/calendar/attachments/{$attachmentId}/download")
        ->assertOk();
});

test('the attachment upload rejects disallowed mime types', function () {
    Storage::fake('private');

    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Board meeting',
        'event_type' => 'company',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'is_all_day' => false,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/calendar/events/{$event->id}/attachments", [
            'file' => UploadedFile::fake()->create('malware.exe', 10),
        ])
        ->assertSessionHasErrors('file');

    expect(HrCalendarEventAttachment::query()->count())->toBe(0);
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
