<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarEventCancelledNotification;
use App\Domain\Hr\Notifications\CalendarPlaceAvailableNotification;
use App\Domain\Hr\Notifications\CalendarReminderNotification;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrCalendarCancellation;
use App\Domain\Hr\Services\HrCalendarReminderDelivery;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->travelTo(Carbon::parse('2026-10-13T00:00:00Z'));
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $role = Role::where('name', 'hr')->sole();
    $role->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id'));
    $this->editor->roles()->attach($role);
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    $this->reader->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hr.calendar.view')->sole()->id => ['allowed' => true]]);
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'A private shared invitation', 'description' => 'The retained original invitation.',
        'event_type' => 'company', 'site_id' => $this->site->id, 'starts_at' => now()->addDays(2)->setTime(2, 0), 'ends_at' => now()->addDays(2)->setTime(3, 0),
        'hub_content' => ['agenda' => 'Keep the original agenda', 'cover_alt' => 'Original cover description', 'cover_id' => null, 'gallery_ids' => []]]);
    $this->event->forceFill(['hub_managed' => true])->save();
    $this->event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
    Storage::disk('private')->put('calendar/cancellation-guide.pdf', 'Retained private information');
    $this->file = $this->event->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => 'calendar/cancellation-guide.pdf', 'original_name' => 'Guide.pdf', 'mime' => 'application/pdf', 'size' => 28]);
    $this->date = $this->event->starts_at->toDateString();
    $this->registration = app(HrEventRegistrationService::class);
    $this->registration->configure($this->editor, $this->event, ['enabled' => true, 'capacity' => 1, 'version' => 1]);
    $this->url = '/company-hub/events/'.$this->event->id.'/cancellation';
    $this->payload = ['actor_id' => $this->editor->id, 'edit_version' => 1, 'date' => $this->date, 'scope' => 'this', 'reason' => 'The host is unavailable.'];
    $this->feed = fn ($viewer, $from = null, $to = null) => app(HrCalendarAggregator::class)->feed($from ?? $this->date, $to ?? $this->date, ['event'], [], $viewer);
    $this->actingAs($this->editor);
});

test('cancellation retains the canonical identity invitation files and registration history for authorised readers', function () {
    $this->registration->respond($this->reader, $this->event, $this->date, 'going');
    $before = $this->event->fresh()->only(['title', 'description', 'starts_at', 'ends_at', 'hub_content', 'created_by', 'site_id']);
    $registrations = DB::table('hr_calendar_registrations')->get()->toArray();
    $this->putJson($this->url, $this->payload)->assertOk()->assertJsonPath('event_id', $this->event->id)->assertJsonPath('edit_version', 2)
        ->assertJsonPath('cancellation.cancelled', true)->assertJsonPath('cancellation.can_cancel', false)->assertJsonPath('registration.place_reserved', false)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($this->event->fresh()->only(array_keys($before)))->toEqual($before)->and($this->event->fresh()->archived_at)->toBeNull()
        ->and(DB::table('hr_calendar_registrations')->get()->toArray())->toEqual($registrations);
    $row = ($this->feed)($this->reader)[0];
    expect($row['title'])->toBe('A private shared invitation')->and($row['extendedProps']['description'])->toBe('The retained original invitation.')
        ->and($row['extendedProps']['cancellation']['reason'])->toBe('The host is unavailable.')->and($row['extendedProps']['cancellation']['cancelled'])->toBeTrue()
        ->and($row['extendedProps']['registration']['mine'])->toBe('going')->and($row['extendedProps']['registration']['going'])->toBe(1)
        ->and($row['extendedProps']['registration']['place_reserved'])->toBeFalse()->and($row['extendedProps']['cancellation']['scopes'])->toBe([]);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$this->file->id)->assertOk();
    ensureCanonicalHrStaffProfile($this->reader, Site::factory()->create(['is_active' => true, 'archived' => false]));
    expect(($this->feed)($this->reader))->toBe([]);
    $this->get('/company-hub/event-files/'.$this->file->id)->assertNotFound();
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['end_date' => today()->subDay()]);
    $this->get('/company-hub/event-files/'.$this->file->id)->assertForbidden();
    expect(Storage::disk('private')->get($this->file->path))->toBe('Retained private information')
        ->and(AuditLog::where('action', 'calendar.event_cancelled')->count())->toBe(1);
});

test('cancellation rechecks actor permission current staff site version occurrence and publication authority', function () {
    $this->putJson($this->url, [...$this->payload, 'actor_id' => $this->reader->id])->assertConflict();
    $this->putJson($this->url, [...$this->payload, 'edit_version' => 2])->assertConflict();
    $this->putJson($this->url, [...$this->payload, 'date' => '2026-10-20'])->assertNotFound();
    $this->putJson($this->url, [...$this->payload, 'scope' => 'all'])->assertUnprocessable();
    $this->putJson($this->url, [...$this->payload, 'reason' => str_repeat('x', 501)])->assertUnprocessable();
    $this->actingAs($this->reader)->putJson($this->url, [...$this->payload, 'actor_id' => $this->reader->id])->assertForbidden();
    $publish = Permission::where('key', 'company_hub.publish')->sole()->id;
    $this->editor->permissionOverrides()->syncWithoutDetaching([$publish => ['allowed' => false]]);
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertForbidden();
    $this->editor->permissionOverrides()->syncWithoutDetaching([$publish => ['allowed' => true]]);
    ensureCanonicalHrStaffProfile($this->editor, Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->putJson($this->url, $this->payload)->assertNotFound();
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['end_date' => today()->subDay()]);
    $this->putJson($this->url, $this->payload)->assertNotFound();
    $ghost = Mockery::mock(User::class)->makePartial();
    $ghost->shouldReceive('fresh')->andReturnNull();
    expect(fn () => app(HrCalendarCancellation::class)->cancel($ghost, $this->event, $this->payload))->toThrow(NotFoundHttpException::class);
    ensureCanonicalHrStaffProfile($this->editor, $this->site);
    $this->travelTo($this->event->starts_at->copy());
    $this->putJson($this->url, $this->payload)->assertUnprocessable();
    $this->travelTo(Carbon::parse('2026-10-13T20:00:00Z'));
    $this->event->update(['is_all_day' => true, 'starts_at' => '2026-10-14T00:00:00Z', 'ends_at' => '2026-10-14T00:00:00Z']);
    $this->putJson($this->url, [...$this->payload, 'date' => '2026-10-14'])->assertUnprocessable();
    expect($this->event->fresh()->cancelled_at)->toBeNull()->and($this->event->fresh()->edit_version)->toBe(1);
    Notification::assertNothingSent();
});

test('one recurring cancellation keeps sibling dates active original date keys and a stable downloadable identity', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $later = $this->event->starts_at->copy()->addWeek()->toDateString();
    $this->registration->respond($this->reader, $this->event->fresh(), $this->date, 'going');
    $beforeCalendar = $this->actingAs($this->reader)->get('/company-hub/events/'.$this->event->id.'/calendar?date='.$this->date)->assertOk()->getContent();
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk();
    $child = HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->sole();
    expect($child->exception_date->toDateString())->toBe($this->date)->and($child->hub_content)->toEqual($this->event->hub_content)
        ->and($this->event->fresh()->cancelled_at)->toBeNull()->and(DB::table('hr_calendar_registrations')->value('event_id'))->toBe($this->event->id);
    $rows = ($this->feed)($this->reader, $this->date, $later);
    expect(collect($rows)->firstWhere('extendedProps.registration.date', $this->date)['extendedProps']['cancellation']['cancelled'])->toBeTrue()
        ->and(collect($rows)->firstWhere('extendedProps.registration.date', $later)['extendedProps']['cancellation']['cancelled'])->toBeFalse();
    $afterCalendar = $this->actingAs($this->reader)->get('/company-hub/events/'.$child->id.'/calendar?date='.$this->date.'&reminder=60')->assertOk()->getContent();
    $uid = 'UID:calendar-'.$this->event->id.'-'.$this->date.'@oblivion-findings';
    expect($beforeCalendar)->toContain($uid, 'STATUS:CONFIRMED')->and($afterCalendar)->toContain($uid, 'STATUS:CANCELLED', 'SEQUENCE:2')->not->toContain('BEGIN:VALARM');
    $this->actingAs($this->editor)->putJson('/company-hub/events/'.$this->event->id.'/registration', ['enabled' => true, 'capacity' => 10, 'version' => 3, 'date' => $this->date])->assertConflict();
    $this->putJson('/company-hub/events/'.$child->id.'/registration', ['enabled' => true, 'capacity' => 10, 'version' => 3])->assertConflict();
    $this->putJson('/company-hub/events/'.$this->event->id.'/registration', ['enabled' => true, 'capacity' => 10, 'version' => 3, 'date' => $later])->assertOk();
    $this->actingAs($this->editor)->putJson($this->url, [...$this->payload, 'edit_version' => 2])->assertConflict();
    $this->putJson($this->url, [...$this->payload, 'date' => $later, 'edit_version' => 2])->assertOk();
    Notification::assertSentToTimes($this->reader, CalendarEventCancelledNotification::class, 1);
    expect(HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->count())->toBe(2);
});

test('all dates cancellation keeps completed attendance active and exports future cancellation without an infinite expansion', function () {
    $this->event->update(['starts_at' => now()->subWeek()->setTime(2, 0), 'ends_at' => now()->subWeek()->setTime(3, 0), 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
    $past = $this->event->starts_at->toDateString();
    $future = $this->event->starts_at->copy()->addWeeks(2)->toDateString();
    DB::table('hr_calendar_registrations')->insert(['event_id' => $this->event->id, 'occurrence_date' => $past, 'user_id' => $this->reader->id, 'status' => 'going', 'attendance' => 'attended', 'created_at' => now(), 'updated_at' => now()]);
    $this->registration->respond($this->reader, $this->event->fresh(), $future, 'going');
    $this->putJson($this->url, [...$this->payload, 'date' => $future, 'scope' => 'all'])->assertOk()->assertJsonPath('event_id', $this->event->id);
    $rows = ($this->feed)($this->reader, $past, $future);
    expect(collect($rows)->firstWhere('extendedProps.registration.date', $past)['extendedProps']['cancellation']['cancelled'])->toBeFalse()
        ->and(collect($rows)->firstWhere('extendedProps.registration.date', $future)['extendedProps']['cancellation']['cancelled'])->toBeTrue()
        ->and(DB::table('hr_calendar_registrations')->where('occurrence_date', $past)->value('attendance'))->toBe('attended');
    $this->actingAs($this->reader);
    $calendar = $this->get('/company-hub/events/'.$this->event->id.'/calendar?date='.$future.'&scope=series&reminder=60')->assertOk()->getContent();
    expect($calendar)->toContain('STATUS:CONFIRMED', 'STATUS:CANCELLED', 'RECURRENCE-ID;RANGE=THISANDFUTURE:')->not->toContain('BEGIN:VALARM');
    $this->event->update(['rrule' => 'FREQ=WEEKLY', 'recurrence_timezone' => 'Pacific/Auckland']);
    $infinite = $this->get('/company-hub/events/'.$this->event->id.'/calendar?date='.$future.'&scope=series')->assertOk()->getContent();
    expect($infinite)->toContain('RRULE:FREQ=WEEKLY;INTERVAL=1', 'STATUS:CANCELLED', 'RECURRENCE-ID;RANGE=THISANDFUTURE;TZID=Pacific/Auckland:')
        ->and(substr_count($infinite, 'BEGIN:VEVENT'))->toBe(2);
    Notification::assertSentToTimes($this->reader, CalendarEventCancelledNotification::class, 1);
});

test('root date resolution follows a moved occurrence without changing its registration key or widening current access', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $moved = $this->event->starts_at->copy()->addDays(2);
    $child = HrCalendarEvent::create([...$this->event->only(['title', 'event_type', 'site_id', 'created_by']), 'starts_at' => $moved, 'ends_at' => $moved->copy()->addHour(),
        'recurrence_parent_id' => $this->event->id, 'is_exception' => true, 'exception_date' => $this->date]);
    $row = $this->registration->occurrence($this->reader, $this->event->fresh(), $this->date);
    expect($row['extendedProps']['eventId'])->toBe($child->id)->and($row['extendedProps']['registration']['date'])->toBe($this->date)
        ->and(Carbon::parse($row['start'])->eq($moved))->toBeTrue();
    expect(fn () => $this->registration->occurrence($this->reader, $child, $moved->toDateString()))->toThrow(NotFoundHttpException::class);
    expect($this->registration->respond($this->reader, $this->event->fresh(), $this->date, 'going')['mine'])->toBe('going');
    $this->putJson($this->url, $this->payload)->assertOk()->assertJsonPath('event_id', $child->id)->assertJsonPath('cancellation.cancelled', true);
    expect($child->fresh()->starts_at->eq($moved))->toBeTrue()
        ->and(DB::table('hr_calendar_registrations')->value('occurrence_date'))->toBe($this->date);
    $this->actingAs($this->reader)->postJson('/company-hub/events/'.$this->event->id.'/registration', ['date' => $this->date, 'status' => 'declined'])->assertConflict();
    $later = $this->event->starts_at->copy()->addWeek()->toDateString();
    $this->postJson('/company-hub/events/'.$this->event->id.'/registration', ['date' => $later, 'status' => 'going'])->assertOk()->assertJsonPath('registration.place_reserved', true);
    ensureCanonicalHrStaffProfile($this->reader, Site::factory()->create(['is_active' => true, 'archived' => false]));
    expect(fn () => $this->registration->occurrence($this->reader, $this->event->fresh(), $this->date))->toThrow(NotFoundHttpException::class);
    Notification::assertSentToTimes($this->reader, CalendarEventCancelledNotification::class, 1);
});

test('following cancellation reuses the canonical split preserving earlier places future queues audience and independent files', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=4']);
    $split = $this->event->starts_at->copy()->addWeek()->toDateString();
    $later = $this->event->starts_at->copy()->addWeeks(2);
    $this->registration->respond($this->reader, $this->event->fresh(), $this->date, 'going');
    $this->registration->respond($this->reader, $this->event->fresh(), $split, 'going');
    $this->registration->respond($this->editor, $this->event->fresh(), $split, 'going');
    $queued = DB::table('hr_calendar_registrations')->where('status', 'waiting')->sole();
    $child = HrCalendarEvent::create([...$this->event->only(['title', 'event_type', 'site_id', 'created_by']), 'starts_at' => $later->copy()->addHour(), 'ends_at' => $later->copy()->addHours(2), 'recurrence_parent_id' => $this->event->id, 'is_exception' => true, 'exception_date' => $later]);
    $result = $this->putJson($this->url, [...$this->payload, 'date' => $split, 'scope' => 'following'])->assertOk()->assertJsonPath('cancellation.cancelled', true);
    $next = HrCalendarEvent::findOrFail($result->json('event_id'));
    expect($next->id)->not->toBe($this->event->id)->and($next->rrule)->toBe('FREQ=WEEKLY;COUNT=3')
        ->and($next->created_by)->toBe($this->editor->id)->and((bool) $next->hub_managed)->toBeTrue()->and($next->hub_content)->toEqual($this->event->hub_content)
        ->and($this->event->fresh()->cancelled_at)->toBeNull()->and($this->event->fresh()->recurrence_until->lt($this->event->starts_at->copy()->addWeek()))->toBeTrue()
        ->and(DB::table('hr_calendar_registrations')->where('occurrence_date', $this->date)->value('event_id'))->toBe($this->event->id)
        ->and(DB::table('hr_calendar_registrations')->where('id', $queued->id)->value('event_id'))->toBe($next->id)
        ->and(DB::table('hr_calendar_registrations')->where('id', $queued->id)->value('queued_at'))->toBe($queued->queued_at)
        ->and($child->fresh()->recurrence_parent_id)->toBe($next->id);
    $copy = $next->attachments()->sole();
    expect($copy->path)->not->toBe($this->file->path)->and(Storage::disk('private')->get($copy->path))->toBe(Storage::disk('private')->get($this->file->path));
    $rows = ($this->feed)($this->reader, $this->date, $later->toDateString());
    expect(collect($rows)->firstWhere('extendedProps.registration.date', $this->date)['extendedProps']['cancellation']['cancelled'])->toBeFalse()
        ->and(collect($rows)->firstWhere('extendedProps.registration.date', $split)['extendedProps']['cancellation']['cancelled'])->toBeTrue();
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$copy->id)->assertOk();
    Notification::assertSentToTimes($this->reader, CalendarEventCancelledNotification::class, 1);
    Notification::assertSentToTimes($this->editor, CalendarEventCancelledNotification::class, 1);
});

test('cancelled places cannot respond promote record attendance or dispatch pending and newly due reminders', function () {
    $this->event->update(['starts_at' => now()->addMinutes(50), 'ends_at' => now()->addHours(2)]);
    $date = $this->event->starts_at->toDateString();
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
    $this->registration->respond($this->reader, $this->event->fresh(), $date, 'going');
    $this->registration->respond($this->editor, $this->event->fresh(), $date, 'going');
    expect(app(HrCalendarReminderDelivery::class)->stage(now()))->toBe(1);
    $this->putJson($this->url, [...$this->payload, 'date' => $date])->assertOk();
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->value('state'))->toBe('skipped')->and(DB::table(HrCalendarReminderDelivery::TABLE)->value('outcome'))->toBe('event_cancelled');
    $this->artisan('hr:dispatch-calendar-reminders')->assertSuccessful();
    $base = '/company-hub/events/'.$this->event->id;
    $this->actingAs($this->reader)->postJson($base.'/registration', ['date' => $date, 'status' => 'declined'])->assertConflict();
    $this->postJson('/hr/calendar/events/'.$this->event->id.'/rsvp', ['occurrence_date' => $date, 'status' => 'yes'])->assertConflict();
    $this->actingAs($this->editor)->putJson($base.'/registration', ['enabled' => true, 'capacity' => 10, 'version' => 3, 'date' => $date])->assertConflict();
    $row = DB::table('hr_calendar_registrations')->where('user_id', $this->reader->id)->sole();
    $this->travelTo($this->event->ends_at->copy()->addMinute());
    $this->getJson($base.'/attendance?date='.$date)->assertOk()->assertJsonPath('can_record', false);
    $this->putJson($base.'/attendance', ['date' => $date, 'registration_id' => $row->id, 'version' => $row->version, 'attendance' => 'attended'])->assertConflict();
    Notification::assertNotSentTo($this->reader, CalendarReminderNotification::class);
    Notification::assertNotSentTo($this->editor, CalendarPlaceAvailableNotification::class);
    expect(DB::table('hr_calendar_registrations')->where('user_id', $this->editor->id)->value('status'))->toBe('waiting');
});

test('only current authorised registrants receive one generic transactional in app cancellation receipt', function () {
    $people = collect(['going', 'waiting', 'maybe', 'declined', 'outside', 'ended', 'denied'])->mapWithKeys(function ($status) {
        $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $person->roles()->attach(Role::where('name', 'support_worker')->sole());
        ensureCanonicalHrStaffProfile($person, $this->site);
        DB::table('hr_calendar_registrations')->insert(['event_id' => $this->event->id, 'occurrence_date' => $this->date, 'user_id' => $person->id,
            'status' => in_array($status, ['going', 'waiting', 'maybe', 'declined']) ? $status : 'going', 'created_at' => now(), 'updated_at' => now()]);

        return [$status => $person];
    });
    ensureCanonicalHrStaffProfile($people['outside'], Site::factory()->create(['is_active' => true, 'archived' => false]));
    ensureCanonicalHrStaffProfile($people['ended'], $this->site, ['end_date' => today()->subDay()]);
    $denials = Permission::whereIn('key', ['company_hub.view', 'hr.calendar.view'])->pluck('id')->all();
    $people['denied']->permissionOverrides()->syncWithoutDetaching(array_fill_keys($denials, ['allowed' => false]));
    $this->putJson($this->url, $this->payload)->assertOk();
    foreach (['going', 'waiting', 'maybe'] as $status) {
        Notification::assertSentToTimes($people[$status], CalendarEventCancelledNotification::class, 1);
        Notification::assertSentTo($people[$status], CalendarEventCancelledNotification::class, function ($notice, $channels) use ($people, $status) {
            $payload = $notice->toArray($people[$status]);
            expect($channels)->toBe(['database'])->and($payload)->not->toHaveKeys(['event_id', 'occurrence_date', 'reason'])
                ->and(json_encode($payload))->not->toContain('A private shared invitation', 'The retained original invitation.', 'The host is unavailable.');

            return true;
        });
    }
    foreach (['declined', 'outside', 'ended', 'denied'] as $status) {
        Notification::assertNotSentTo($people[$status], CalendarEventCancelledNotification::class);
    }
    Notification::assertNotSentTo($this->reader, CalendarEventCancelledNotification::class);
    $this->putJson($this->url, [...$this->payload, 'edit_version' => 2])->assertConflict();
    expect(Notification::sent($people['going'], CalendarEventCancelledNotification::class))->toHaveCount(1);
});

test('legacy explicit RSVP cancellations notify only yes and maybe colleagues and retain original responses', function () {
    $this->event->attendees()->delete();
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'yes']);
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'no']);
    $this->event->forceFill(['registration_enabled' => false])->save();
    $this->putJson($this->url, $this->payload)->assertOk();
    Notification::assertSentToTimes($this->reader, CalendarEventCancelledNotification::class, 1);
    Notification::assertNotSentTo($this->editor, CalendarEventCancelledNotification::class);
    $this->actingAs($this->reader)->postJson('/company-hub/events/'.$this->event->id.'/rsvp', ['status' => 'no'])->assertConflict();
    $this->postJson('/hr/calendar/events/'.$this->event->id.'/rsvp', ['status' => 'no'])->assertConflict();
    expect($this->event->attendees()->where('user_id', $this->reader->id)->value('rsvp_status'))->toBe('yes');
});

test('legacy recurring RSVP respects the current bound cancellation while unaffected dates and settings stay usable', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $this->event->forceFill(['registration_enabled' => false])->save();
    $this->event->attendees()->delete();
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'yes']);
    $this->putJson($this->url, $this->payload)->assertOk();
    $cancelled = HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->sole();
    $this->actingAs($this->reader)->postJson('/company-hub/events/'.$cancelled->id.'/rsvp', ['status' => 'no'])->assertConflict();
    $this->postJson('/hr/calendar/events/'.$cancelled->id.'/rsvp', ['status' => 'no'])->assertConflict();
    expect($this->event->attendees()->value('rsvp_status'))->toBe('yes');
    $next = $this->event->starts_at->copy()->addWeek();
    $active = HrCalendarEvent::create([...$this->event->only(['title', 'event_type', 'site_id', 'created_by']), 'starts_at' => $next, 'ends_at' => $next->copy()->addHour(),
        'recurrence_parent_id' => $this->event->id, 'is_exception' => true, 'exception_date' => $next->toDateString()]);
    $this->postJson('/company-hub/events/'.$active->id.'/rsvp', ['status' => 'maybe'])->assertRedirect();
    $this->postJson('/hr/calendar/events/'.$active->id.'/rsvp', ['status' => 'yes'])->assertRedirect();
    expect($this->event->attendees()->value('rsvp_status'))->toBe('yes');
    $rows = ($this->feed)($this->reader, $this->date, $next->toDateString());
    expect(collect($rows)->firstWhere('extendedProps.eventId', $cancelled->id)['extendedProps']['registration']['can_configure'])->toBeFalse()
        ->and(collect($rows)->firstWhere('extendedProps.eventId', $active->id)['extendedProps']['cancellation']['cancelled'])->toBeFalse();
    Notification::assertSentToTimes($this->reader, CalendarEventCancelledNotification::class, 1);
});

test('late cancellation audit failure rolls back an exception and a following split including copied private files', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $split = $this->event->starts_at->copy()->addWeek()->toDateString();
    $this->registration->respond($this->reader, $this->event->fresh(), $split, 'going');
    $before = DB::table('hr_calendar_registrations')->get()->toArray();
    $paths = Storage::disk('private')->allFiles();
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::creating(function ($audit) {
        if ($audit->action === 'calendar.event_cancelled') {
            throw new RuntimeException('Injected cancellation audit failure');
        }
    });
    try {
        foreach (['this', 'following'] as $scope) {
            expect(fn () => app(HrCalendarCancellation::class)->cancel($this->editor, $this->event, [...$this->payload, 'scope' => $scope, 'date' => $split]))->toThrow(RuntimeException::class, 'Injected cancellation audit failure');
            expect(HrCalendarEvent::count())->toBe(1)->and($this->event->fresh()->edit_version)->toBe(1)->and($this->event->fresh()->cancelled_at)->toBeNull()
                ->and($this->event->fresh()->recurrence_until)->toBeNull()->and(DB::table('hr_calendar_registrations')->get()->toArray())->toEqual($before)
                ->and(Storage::disk('private')->allFiles())->toBe($paths);
        }
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    Notification::assertNothingSent();
});

test('cancelled dates cannot be republished or edited and archive restore does not uncancel while pending schema reads stay compatible', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $later = $this->event->starts_at->copy()->addWeek()->toDateString();
    $pending = new class extends HrCalendarCancellation
    {
        public function ready(): bool
        {
            return false;
        }
    };
    app()->instance(HrCalendarCancellation::class, $pending);
    expect(($this->feed)($this->reader)[0]['extendedProps']['cancellation']['cancelled'])->toBeFalse()
        ->and(($this->feed)($this->editor)[0]['extendedProps']['cancellation']['can_cancel'])->toBeFalse();
    $this->putJson($this->url, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('scope');
    app()->forgetInstance(HrCalendarCancellation::class);
    $this->putJson($this->url, $this->payload)->assertOk();
    $child = HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->sole();
    $draft = ['draft_key' => (string) Str::uuid(), 'source_event_id' => $this->event->id, 'edit_version' => 2, 'mode' => 'draft', 'title' => $this->event->title,
        'description' => $this->event->description, 'event_type' => 'company', 'starts_at' => $this->event->starts_at->toIso8601String(), 'ends_at' => $this->event->ends_at->toIso8601String(),
        'is_all_day' => false, 'recurrence_timezone' => 'UTC', 'audience_type' => 'org', 'site_id' => $this->site->id, 'scope' => 'this', 'occurrence_date' => $this->date];
    $this->postJson('/company-hub/event-drafts', $draft)->assertConflict();
    $this->putJson('/hr/calendar/events/'.$this->event->id, ['edit_version' => 2, 'scope' => 'this', 'occurrence_date' => $this->date, 'title' => 'Reopened'])->assertUnprocessable();
    $this->postJson('/hr/calendar/events/'.$child->id.'/attachments', ['edit_version' => 2, 'file' => UploadedFile::fake()->create('Extra.pdf', 1, 'application/pdf')])->assertUnprocessable();
    $this->postJson('/company-hub/event-drafts', [...$draft, 'draft_key' => (string) Str::uuid(), 'occurrence_date' => $later])->assertRedirect()->assertSessionHasNoErrors();
    expect(HubEventDraft::count())->toBe(1);
    $this->delete('/hr/calendar/events/'.$this->event->id, ['edit_version' => 2, 'scope' => 'all'])->assertRedirect()->assertSessionHasNoErrors();
    expect(($this->feed)($this->reader))->toBe([]);
    $this->post('/hr/calendar/events/'.$this->event->id.'/restore')->assertRedirect()->assertSessionHasNoErrors();
    expect(($this->feed)($this->reader)[0]['extendedProps']['cancellation']['cancelled'])->toBeTrue();
});
