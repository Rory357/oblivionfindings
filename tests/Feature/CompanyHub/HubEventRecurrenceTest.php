<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarReminderNotification;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrCalendarReminderOccurrences;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $role = Role::where('name', 'hr')->firstOrFail();
    $role->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id'));
    $this->editor->roles()->attach($role);
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->travelTo(Carbon::parse('2026-09-22T00:00:00Z'));
    Notification::fake();
    $this->actingAs($this->editor);
});

test('new event repeats default to local time and include the selected final day', function () {
    $payload = ['title' => 'Local series', 'event_type' => 'company', 'starts_at' => '2026-09-20T10:00:00+12:00', 'ends_at' => '2026-09-20T11:00:00+12:00',
        'rrule' => 'FREQ=WEEKLY', 'recurrence_until' => '2026-10-04', 'audience_type' => 'people', 'audience_user_ids' => [$this->editor->id]];
    $this->post('/hr/calendar/events', $payload)->assertRedirect()->assertSessionHasNoErrors();
    $event = HrCalendarEvent::where('title', 'Local series')->sole();
    expect($event->recurrence_timezone)->toBe('Pacific/Auckland')->and($event->recurrence_until->format('Y-m-d H:i:s'))->toBe('2026-10-04 10:59:59');
    $feed = app(HrCalendarAggregator::class)->feed('2026-09-01', '2026-10-10', ['event'], [], $this->editor);
    expect(array_column($feed, 'start'))->toHaveCount(3);
    foreach ($feed as $row) {
        expect(Carbon::parse($row['start'])->tz('Pacific/Auckland')->format('H:i'))->toBe('10:00');
        expect($row['extendedProps']['recurrenceTimezone'])->toBe('Pacific/Auckland')->and($row['extendedProps']['recurrenceUntil'])->toBe('2026-10-04');
    }
    $this->postJson('/hr/calendar/events', [...$payload, 'rrule' => 'FREQ=WEEKLY;BYDAY=MO'])->assertUnprocessable();
});

test('future timing conversion moves registration keys but preserves past history and queue order', function () {
    $event = HrCalendarEvent::create(['title' => 'Existing fixed schedule', 'event_type' => 'company', 'created_by' => $this->editor->id, 'site_id' => $this->site->id,
        'starts_at' => '2026-09-20 00:30:00', 'ends_at' => '2026-09-20 01:30:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $event->refresh();
    $registration = app(HrEventRegistrationService::class);
    $registration->configure($this->editor, $event, ['enabled' => true, 'capacity' => 1, 'version' => 1]);
    $this->travelTo(Carbon::parse('2026-09-18T00:00:00Z'));
    $registration->respond($this->reader, $event, '2026-09-20', 'going');
    $registration->respond($this->reader, $event, '2026-09-27', 'going');
    $registration->respond($this->editor, $event, '2026-09-27', 'going');
    $waiting = DB::table('hr_calendar_registrations')->where('status', 'waiting')->sole();
    $this->travelTo(Carbon::parse('2026-09-22T00:00:00Z'));
    $url = '/hr/calendar/events/'.$event->id;
    $this->putJson($url, ['edit_version' => 1, 'recurrence_timezone' => 'Pacific/Auckland'])->assertUnprocessable();
    $this->put($url, ['edit_version' => 1, 'scope' => 'following', 'occurrence_date' => '2026-09-27', 'recurrence_timezone' => 'Pacific/Auckland',
        'starts_at' => '2026-09-27T00:30:00+12:00', 'ends_at' => '2026-09-27T01:30:00+12:00'])->assertRedirect()->assertSessionHasNoErrors();
    $next = HrCalendarEvent::where('id', '!=', $event->id)->sole();
    expect($next->rrule)->toBe('FREQ=WEEKLY;COUNT=2')->and($event->fresh()->recurrence_until->format('Y-m-d H:i:s'))->toBe('2026-09-27 00:29:59');
    expect(DB::table('hr_calendar_registrations')->where('event_id', $event->id)->pluck('occurrence_date')->all())->toBe(['2026-09-20']);
    $moved = DB::table('hr_calendar_registrations')->where('id', $waiting->id)->sole();
    expect((int) $moved->event_id)->toBe($next->id)->and($moved->occurrence_date)->toBe('2026-09-26')->and($moved->queued_at)->toBe($waiting->queued_at)->and($moved->status)->toBe('waiting');
});

test('date-only and following edits use the real occurrence time after the clocks change', function () {
    $event = HrCalendarEvent::create(['title' => 'DST series', 'event_type' => 'company', 'created_by' => $this->editor->id, 'recurrence_timezone' => 'Pacific/Auckland',
        'starts_at' => '2026-09-19 22:00:00', 'ends_at' => '2026-09-19 23:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
    $url = '/hr/calendar/events/'.$event->id;
    $this->put($url, ['edit_version' => 1, 'scope' => 'this', 'occurrence_date' => '2026-09-26', 'title' => 'Changed date'])->assertRedirect()->assertSessionHasNoErrors();
    $child = HrCalendarEvent::where('recurrence_parent_id', $event->id)->sole();
    expect($child->starts_at->format('Y-m-d H:i:s'))->toBe('2026-09-26 21:00:00');
    $this->put($url, ['edit_version' => 2, 'scope' => 'following', 'occurrence_date' => '2026-10-03', 'title' => 'Later series'])->assertRedirect()->assertSessionHasNoErrors();
    $next = HrCalendarEvent::where('title', 'Later series')->sole();
    expect($next->starts_at->format('Y-m-d H:i:s'))->toBe('2026-10-03 21:00:00');
});

test('whole-series downloads honour site audience archive and exact exception dates', function () {
    $event = HrCalendarEvent::create(['title' => 'Shared sessions', 'event_type' => 'company', 'created_by' => $this->editor->id, 'site_id' => $this->site->id,
        'recurrence_timezone' => 'Pacific/Auckland', 'starts_at' => '2026-09-19 22:00:00', 'ends_at' => '2026-09-19 23:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $child = HrCalendarEvent::create(['title' => 'Moved session', 'event_type' => 'company', 'created_by' => $this->editor->id, 'site_id' => $this->site->id,
        'starts_at' => '2026-09-27 22:00:00', 'ends_at' => '2026-09-27 23:00:00', 'recurrence_parent_id' => $event->id, 'is_exception' => true, 'exception_date' => '2026-09-26']);
    $url = '/company-hub/events/'.$event->id.'/calendar?scope=series&date=2026-09-19';
    $this->actingAs($this->reader);
    $ics = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($ics)->toContain('RECURRENCE-ID:20260926T210000Z', 'SUMMARY:Moved session');
    $child->update(['archived_at' => now()]);
    expect($this->get($url)->assertOk()->getContent())->toContain('EXDATE:20260926T210000Z')->not->toContain('Moved session');
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $this->get($url)->assertNotFound();
    $this->actingAs($this->editor)->get(str_replace('2026-09-19', '2026-09-20', $url))->assertNotFound();
    $event->update(['site_id' => Site::factory()->create()->id]);
    $this->get($url)->assertNotFound();
    $event->update(['site_id' => $this->site->id, 'archived_at' => now()]);
    $this->get($url)->assertNotFound();
});

test('reminders use local recurrence times and skip cancelled or independently reminded dates', function () {
    $event = HrCalendarEvent::create(['title' => 'DST reminders', 'event_type' => 'company', 'created_by' => $this->editor->id,
        'recurrence_timezone' => 'Pacific/Auckland', 'starts_at' => '2026-09-19 22:00:00', 'ends_at' => '2026-09-19 23:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $reminder = $event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
    $this->travelTo(Carbon::parse('2026-09-26T20:00:00Z'));
    $this->artisan('hr:dispatch-calendar-reminders')->assertSuccessful();
    $this->artisan('hr:dispatch-calendar-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->editor, CalendarReminderNotification::class, 1);
    expect(DB::table('hr_calendar_reminder_deliveries')->where('event_id', $event->id)->where('state', 'delivered')->value('due_at'))->toBe('2026-09-26 20:00:00');
    $child = HrCalendarEvent::create(['title' => 'Moved', 'event_type' => 'company', 'created_by' => $this->editor->id, 'recurrence_parent_id' => $event->id, 'is_exception' => true,
        'exception_date' => '2026-10-03', 'starts_at' => '2026-10-04 22:00:00', 'ends_at' => '2026-10-04 23:00:00']);
    $service = app(HrCalendarReminderOccurrences::class);
    expect($service->due($event, Carbon::parse('2026-10-03 20:59:00'), Carbon::parse('2026-10-03 21:00:00')))->toBe([]);
    $from = Carbon::parse('2026-10-04 21:59:00');
    $to = Carbon::parse('2026-10-04 22:00:00');
    expect($service->due($event, $from, $to)[0]['event']->id)->toBe($child->id);
    $child->forceFill(['reminders_override' => true])->save();
    expect($service->due($event, $from, $to))->toBe([])->and($service->due($child->fresh(), $from, $to))->toHaveCount(1);
    $child->update(['archived_at' => now()]);
    expect($service->due($event, $from, $to))->toBe([])->and($service->due($child->fresh(), $from, $to))->toBe([]);
});

test('private reminders do not expand to unrelated managers or carry event details', function () {
    $uninvited = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $uninvited->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($uninvited, $this->site);
    $event = HrCalendarEvent::create(['title' => 'Private topic must not reach the bell', 'event_type' => 'company', 'created_by' => $this->editor->id,
        'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
    $this->artisan('hr:dispatch-calendar-reminders')->assertSuccessful();
    Notification::assertSentTo($this->editor, CalendarReminderNotification::class, function ($notice, $channels) {
        expect($channels)->toBe(['database'])->and($notice->toArray($this->editor)['context'])->toBe([])
            ->and(json_encode($notice->toArray($this->editor)))->not->toContain('Private topic must not reach the bell');

        return true;
    });
    Notification::assertNotSentTo($uninvited, CalendarReminderNotification::class);
});
