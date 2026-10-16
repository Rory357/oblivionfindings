<?php

use App\Domain\Hr\Models\HrCalendarEvent;
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
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->travelTo(Carbon::parse('2026-10-13T00:00:00Z'));
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    $this->reader->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hr.calendar.view')->sole()->id => ['allowed' => false]]);
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Our protected gathering',
        'description' => 'Retained invitation details', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => now()->addWeek()->setTime(2, 0), 'ends_at' => now()->addWeek()->setTime(3, 0)]);
    $this->event->forceFill(['hub_managed' => true, 'registration_enabled' => true])->save();
    $this->event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
    Storage::disk('private')->put('calendar/reader-guide.pdf', 'Private reader guide');
    $this->file = $this->event->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => 'calendar/reader-guide.pdf',
        'original_name' => 'Guide.pdf', 'mime' => 'application/pdf', 'size' => 20]);
    Storage::disk('private')->put('calendar/reader-photo.png', 'Private event photo');
    $this->photo = $this->event->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => 'calendar/reader-photo.png',
        'original_name' => 'Gathering.png', 'mime' => 'image/png', 'size' => 19]);
    $this->event->update(['hub_content' => ['agenda' => 'The original agenda', 'recap' => 'A retained recap',
        'cover_id' => $this->photo->id, 'cover_alt' => 'Our gathering', 'gallery_ids' => [$this->photo->id]]]);
    $this->date = $this->event->starts_at->toDateString();
    $this->url = '/company-hub/events/'.$this->event->id.'?date='.$this->date;
    $this->actingAs($this->reader);
});

test('reloadable event reading retains shared navigation and protected content independently of the browse window', function () {
    expect($this->reader->canDo('hr.calendar.view'))->toBeFalse();
    $before = $this->event->fresh()->getAttributes();
    $this->get($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
        ->component('company-hub/index')->where('view', 'events')->where('selectedPage', null)->has('sharedNavigation')->has('home')
        ->where('selectedEvent.extendedProps.eventId', $this->event->id)->where('selectedEvent.title', 'Our protected gathering')
        ->where('selectedEvent.extendedProps.registration.date', $this->date)->where('selectedEvent.extendedProps.registration.can_respond', true)
        ->where('selectedEvent.extendedProps.attachments.0.url', '/company-hub/event-files/'.$this->file->id)
        ->where('selectedEvent.extendedProps.hubContent.cover.url', '/company-hub/event-files/'.$this->photo->id)
        ->where('selectedEvent.extendedProps.hubContent.recap', '')->where('selectedEvent.extendedProps.hubContent.editor', null));
    $this->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedEvent.extendedProps.eventId', $this->event->id));
    expect($this->event->fresh()->getAttributes())->toEqual($before)->and(DB::table('hr_calendar_registrations')->count())->toBe(0);

    $future = now()->addDays(120)->setTime(2, 0);
    $this->event->update(['starts_at' => $future, 'ends_at' => $future->copy()->addHour()]);
    $this->get('/company-hub/events/'.$this->event->id.'?date='.$future->toDateString())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('events', 0)->where('selectedEvent.extendedProps.eventId', $this->event->id));
    $past = now()->subWeek()->setTime(2, 0);
    $this->event->update(['starts_at' => $past, 'ends_at' => $past->copy()->addHour()]);
    $this->get('/company-hub/events/'.$this->event->id.'?date='.$past->toDateString())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('events', 0)->where('selectedEvent.extendedProps.hubContent.past', true)->where('selectedEvent.extendedProps.hubContent.recap', 'A retained recap')
        ->where('selectedEvent.extendedProps.hubContent.gallery.0.url', '/company-hub/event-files/'.$this->photo->id));
    Notification::assertNothingSent();
});

test('reloadable moved event links resolve the original occurrence key and exact current child', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $moved = $this->event->starts_at->copy()->addDays(2);
    $child = HrCalendarEvent::create([...$this->event->only(['title', 'event_type', 'site_id', 'created_by']), 'title' => 'The moved gathering',
        'starts_at' => $moved, 'ends_at' => $moved->copy()->addHour(), 'recurrence_parent_id' => $this->event->id,
        'is_exception' => true, 'exception_date' => $this->date]);
    foreach ([$this->event->id, $child->id] as $id) {
        $this->get('/company-hub/events/'.$id.'?date='.$this->date)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedEvent.title', 'The moved gathering')->where('selectedEvent.extendedProps.eventId', $child->id)
            ->where('selectedEvent.extendedProps.recurrenceParentId', $this->event->id)->where('selectedEvent.extendedProps.registration.date', $this->date)
            ->where('selectedEvent.start', $moved->toIso8601String())->where('selectedEvent.extendedProps.attachments.0.url', '/company-hub/event-files/'.$this->file->id));
    }
    $this->get('/company-hub/events/'.$child->id.'?date='.$moved->toDateString())->assertNotFound();
    $this->get('/company-hub/events/'.$this->event->id.'?date='.$moved->toDateString())->assertNotFound();
    $child->update(['archived_at' => now()]);
    $this->get($this->url)->assertNotFound();
    Notification::assertNothingSent();
});

test('reloadable cancelled event reading retains details files and response evidence after the event date', function () {
    DB::table('hr_calendar_registrations')->insert(['event_id' => $this->event->id, 'occurrence_date' => $this->date, 'user_id' => $this->reader->id,
        'status' => 'going', 'created_at' => now(), 'updated_at' => now()]);
    $this->event->update(['cancelled_at' => now(), 'cancelled_by' => $this->editor->id, 'cancellation_reason' => 'The host is unavailable.']);
    $before = DB::table('hr_calendar_registrations')->get()->toArray();
    $this->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedEvent.extendedProps.cancellation.cancelled', true)->where('selectedEvent.extendedProps.cancellation.reason', 'The host is unavailable.')
        ->where('selectedEvent.extendedProps.cancellation.can_cancel', false)->where('selectedEvent.extendedProps.registration.mine', 'going')
        ->where('selectedEvent.extendedProps.registration.going', 1)->where('selectedEvent.extendedProps.registration.place_reserved', false)
        ->where('selectedEvent.extendedProps.registration.can_respond', false)->where('selectedEvent.extendedProps.description', 'Retained invitation details'));
    $this->travelTo($this->event->ends_at->copy()->addDay());
    $this->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedEvent.extendedProps.cancellation.cancelled', true)->where('selectedEvent.extendedProps.hubContent.past', true)
        ->where('selectedEvent.extendedProps.hubContent.recap', 'A retained recap')->where('selectedEvent.extendedProps.attachments.0.url', '/company-hub/event-files/'.$this->file->id));
    $this->get('/company-hub/event-files/'.$this->file->id)->assertOk();
    expect(DB::table('hr_calendar_registrations')->get()->toArray())->toEqual($before)->and($this->event->fresh()->archived_at)->toBeNull();
    Notification::assertNothingSent();
});

test('reloadable event direct objects recheck private audience current site staff and Hub entry without disclosing content', function () {
    $this->getJson('/company-hub/events/'.$this->event->id)->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->getJson('/company-hub/events/'.$this->event->id.'?date=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->event->attendees()->delete();
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $this->get($this->url)->assertNotFound()->assertDontSee('Our protected gathering')->assertDontSee('Retained invitation details');
    $this->get('/company-hub/event-files/'.$this->file->id)->assertNotFound();
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'none']);
    $this->get($this->url)->assertOk();
    $this->reader->load('hrEmployeeProfile');
    ensureCanonicalHrStaffProfile($this->reader, Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->get($this->url)->assertNotFound()->assertDontSee('Retained invitation details');
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['end_date' => today()->subDay()]);
    $this->get($this->url)->assertForbidden();
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->reader->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'company_hub.view')->sole()->id => ['allowed' => false]]);
    $this->get($this->url)->assertForbidden();
    $this->reader->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'company_hub.view')->sole()->id => ['allowed' => true]]);
    $this->event->update(['archived_at' => now()]);
    $this->get($this->url)->assertNotFound();
    Notification::assertNothingSent();
});
