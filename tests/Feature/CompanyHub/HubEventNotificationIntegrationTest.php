<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Services\HubEventScheduling;
use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarEventCancelledNotification;
use App\Domain\Hr\Notifications\CalendarEventChangedNotification;
use App\Domain\Hr\Notifications\CalendarEventInviteNotification;
use App\Domain\Hr\Notifications\CalendarEventRsvpNotification;
use App\Domain\Hr\Notifications\CalendarPlaceAvailableNotification;
use App\Domain\Hr\Notifications\CalendarReminderNotification;
use App\Domain\Hr\Services\HrCalendarChanges;
use App\Domain\Hr\Services\HrCalendarPersonalReminders;
use App\Domain\Hr\Services\HrCalendarReminderDelivery;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->travelTo(Carbon::parse('2026-10-20T00:00:00Z'));
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $role = Role::where('name', 'hr')->sole();
    $role->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id'));
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach($role);
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->people = collect(range(1, 3))->map(function () {
        $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $person->roles()->attach(Role::where('name', 'support_worker')->sole());
        $person->permissionOverrides()->attach(Permission::where('key', 'hr.calendar.view')->sole(), ['allowed' => true]);
        ensureCanonicalHrStaffProfile($person, $this->site);

        return $person;
    });
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Private work gathering', 'description' => 'Keep private details in the reader.',
        'event_type' => 'company', 'site_id' => $this->site->id, 'starts_at' => '2026-10-23T01:00:00Z', 'ends_at' => '2026-10-23T02:00:00Z',
        'rrule' => 'FREQ=WEEKLY;COUNT=4', 'recurrence_timezone' => 'UTC', 'location' => 'Original room']);
    foreach ($this->people as $person) {
        $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $person->id]);
    }
    $this->date = '2026-10-23';
    $this->registration = app(HrEventRegistrationService::class);
    $this->registration->configure($this->editor, $this->event, ['enabled' => true, 'capacity' => 1, 'version' => 1]);
    foreach ($this->people->take(2) as $person) {
        $this->registration->respond($person, $this->event, $this->date, 'going');
    }
    $this->preferences = function (User $person, bool $enabled): void {
        DB::table('hub_preferences')->updateOrInsert(['user_id' => $person->id], ['notifications' => json_encode(['events' => $enabled]), 'updated_at' => now()]);
    };
    $this->updateEvent = function (array $changes = []) {
        return $this->actingAs($this->editor)->put('/hr/calendar/events/'.$this->event->id, ['edit_version' => $this->event->fresh()->edit_version, ...$changes]);
    };
    $this->draft = function (array $changes = []) {
        $event = $this->event->fresh();
        $this->actingAs($this->editor)->post('/company-hub/event-drafts', ['draft_key' => (string) Str::uuid(), 'mode' => 'draft', 'actor_id' => $this->editor->id,
            'source_event_id' => $event->id, 'edit_version' => $event->edit_version, 'title' => $event->title, 'description' => $event->description,
            'event_type' => $event->event_type, 'is_all_day' => false, 'starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(),
            'rrule' => $event->rrule, 'recurrence_timezone' => 'UTC', 'site_id' => $this->site->id, 'location' => $event->location,
            'audience_type' => 'people', 'audience_user_ids' => $this->people->pluck('id')->all(),
            'registration_enabled' => true, 'registration_capacity' => 1, 'registration_version' => $event->registration_version, ...$changes])
            ->assertRedirect()->assertSessionHasNoErrors();

        return HubEventDraft::latest('id')->firstOrFail();
    };
    $this->actualChanges = function (int $times): void {
        Notification::shouldReceive('sendNow')->times($times)->andReturnUsing(function (User $person, $notice): void {
            expect($notice)->toBeInstanceOf(CalendarEventChangedNotification::class)->and($notice->shouldSend($person, 'database'))->toBeTrue();
            app(DatabaseChannel::class)->send($person, $notice);
        });
    };
});

test('event notifications HR-origin material changes notify existing going and waiting participants without private bell details', function () {
    ($this->updateEvent)(['location' => 'Changed private room'])->assertRedirect()->assertSessionHasNoErrors();
    foreach ($this->people->take(2) as $person) {
        Notification::assertSentToTimes($person, CalendarEventChangedNotification::class, 1);
        Notification::assertSentTo($person, CalendarEventChangedNotification::class, function ($notice, $channels) use ($person) {
            $payload = $notice->toArray($person);
            expect($channels)->toBe(['database'])->and($notice->shouldSend($person, 'database'))->toBeTrue()
                ->and(array_keys($payload))->toBe(['type', 'title', 'message', 'url', 'action_url'])
                ->and(json_encode($payload))->not->toContain('Private work gathering', 'Changed private room', 'event_id', 'occurrence_date');

            return true;
        });
    }
    Notification::assertNotSentTo($this->people[2], CalendarEventChangedNotification::class);
    Notification::assertNotSentTo($this->editor, CalendarEventChangedNotification::class);
});

test('event notifications unchanged publication and stale retries produce no changes while durable receipt IDs deduplicate a successful command', function () {
    ($this->updateEvent)(['title' => $this->event->title])->assertRedirect()->assertSessionHasNoErrors();
    Notification::assertNothingSent();
    $service = app(HrCalendarChanges::class);
    $context = $service->capture($this->event->fresh(), 'all', null);
    ($this->actualChanges)(2);
    $version = $this->event->fresh()->edit_version;
    ($this->updateEvent)(['title' => 'New live title'])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('notifications')->where('type', CalendarEventChangedNotification::class)->count())->toBe(2)
        ->and($service->deliver($this->editor, $context, $this->event->fresh()))->toBe(0);
    $this->actingAs($this->editor)->put('/hr/calendar/events/'.$this->event->id, ['edit_version' => $version, 'title' => 'Retry'])
        ->assertRedirect()->assertSessionHasErrors('edit_version');
    expect(DB::table('notifications')->count())->toBe(2);
});

test('event notifications reviewed scheduled live changes wait for canonical release and compare final booking cutoff', function () {
    $draft = ($this->draft)(['mode' => 'review', 'publish_at' => '2026-10-21T00:00:00Z', 'registration_closes_before_minutes' => 1260]);
    Notification::assertNothingSent();
    expect($this->event->fresh()->registration_closes_before_minutes)->toBeNull();
    $this->actingAs($this->editor)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'approve'])
        ->assertOk()->assertJsonPath('draft.state', 'scheduled');
    Notification::assertNothingSent();
    $this->travelTo(Carbon::parse('2026-10-21T00:00:00Z'));
    expect(app(HubEventScheduling::class)->publishDue())->toBe(['published' => 1, 'blocked' => 0, 'failed' => 0])
        ->and($this->event->fresh()->registration_closes_before_minutes)->toBe(1260);
    foreach ($this->people->take(2) as $person) {
        Notification::assertSentToTimes($person, CalendarEventChangedNotification::class, 1);
    }
    expect(app(HubEventScheduling::class)->publishDue()['published'])->toBe(0);
});

test('event notifications late Hub publication failure rolls back actual database receipts and the live edition', function () {
    $draft = ($this->draft)(['mode' => 'review', 'title' => 'Uncommitted edition']);
    ($this->actualChanges)(2);
    $observed = false;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) use (&$observed): void {
        if ($audit->action === 'hub.event_draft.published') {
            $observed = DB::table('notifications')->where('type', CalendarEventChangedNotification::class)->count() === 2;
            throw new RuntimeException('Stop after private change receipts');
        }
    });
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->actingAs($this->editor)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'approve']))
            ->toThrow(RuntimeException::class, 'Stop after private change receipts');
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
    expect($observed)->toBeTrue()->and($this->event->fresh()->title)->toBe('Private work gathering')
        ->and($draft->fresh()->state)->toBe('review')->and(DB::table('notifications')->count())->toBe(0);
});

test('event notifications recurring scope excludes completed and unaffected places and following remaps existing participants', function (string $scope) {
    $this->registration->respond($this->people[2], $this->event, '2026-10-30', 'going');
    $this->travelTo(Carbon::parse('2026-10-24T00:00:00Z'));
    $changes = ['scope' => $scope, 'occurrence_date' => '2026-10-30', 'location' => 'Future room'];
    if ($scope === 'following') {
        $changes += ['starts_at' => '2026-10-31T01:00:00Z', 'ends_at' => '2026-10-31T02:00:00Z'];
    }
    ($this->updateEvent)($changes)->assertRedirect()->assertSessionHasNoErrors();
    Notification::assertSentToTimes($this->people[2], CalendarEventChangedNotification::class, 1);
    foreach ($this->people->take(2) as $person) {
        Notification::assertNotSentTo($person, CalendarEventChangedNotification::class);
    }
    if ($scope === 'following') {
        $place = DB::table('hr_calendar_registrations')->where('user_id', $this->people[2]->id)->first();
        expect($place->event_id)->not->toBe($this->event->id)->and($place->occurrence_date)->toBe('2026-10-31');
    }
})->with(['this', 'all', 'following']);

test('event notifications changing audience excludes revoked declined opted-out and newly invited people', function () {
    ($this->preferences)($this->people[0], false);
    DB::table('hr_calendar_registrations')->where('user_id', $this->people[1]->id)->update(['status' => 'declined']);
    $fresh = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $fresh->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($fresh, $this->site);
    ($this->updateEvent)(['title' => 'Changed live event', 'audience_type' => 'people', 'audience_user_ids' => [$this->people[0]->id, $fresh->id]])
        ->assertRedirect()->assertSessionHasNoErrors();
    foreach ([$fresh, ...$this->people->all()] as $person) {
        Notification::assertNotSentTo($person, CalendarEventChangedNotification::class);
    }
    expect($this->event->fresh()->attendees()->where('user_id', $fresh->id)->exists())->toBeTrue();
});

test('event notifications actual change delivery rechecks source participants actor site and preferences', function (string $change) {
    ($this->updateEvent)(['location' => 'Changed room'])->assertRedirect()->assertSessionHasNoErrors();
    $person = $this->people[0];
    $notice = Notification::sent($person, CalendarEventChangedNotification::class)->sole();
    match ($change) {
        'preference' => ($this->preferences)($person, false),
        'decline' => DB::table('hr_calendar_registrations')->where('user_id', $person->id)->update(['status' => 'declined']),
        'staff' => ensureCanonicalHrStaffProfile($person, $this->site, ['end_date' => today()->subDay()]),
        'site' => ensureCanonicalHrStaffProfile($person, Site::factory()->create(['is_active' => true, 'archived' => false])),
        'actor' => ensureCanonicalHrStaffProfile($this->editor, $this->site, ['end_date' => today()->subDay()]),
        'edition' => $this->event->increment('edit_version'),
        'ended' => $this->travelTo(Carbon::parse('2026-10-23T02:00:00Z')),
    };
    expect($notice->shouldSend($person, 'database'))->toBeFalse();
})->with(['preference', 'decline', 'staff', 'site', 'actor', 'edition', 'ended']);

test('event notifications mixed origin reminder digests filter opted-out people while retaining receipt DND and channel boundaries', function () {
    $other = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Hub-authored work event', 'event_type' => 'training', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-23T01:00:00Z', 'ends_at' => '2026-10-23T02:00:00Z']);
    $other->forceFill(['hub_managed' => true])->save();
    $other->attendees()->create(['audience_type' => 'org']);
    $this->event->forceFill(['registration_enabled' => false])->save();
    foreach ([$this->event, $other] as $event) {
        $event->reminders()->create(['offset_minutes' => 1440, 'channel' => 'email']);
    }
    foreach ($this->people->take(2) as $person) {
        $person->forceFill(['email_digest_frequency' => 'daily'])->save();
        UserNotificationPreference::updateOrCreate(['user_id' => $person->id, 'key' => 'hr.calendar.reminder'], ['enabled' => true, 'channel_email' => true, 'channel_inapp' => true]);
    }
    ($this->preferences)($this->people[0], false);
    $this->people[1]->forceFill(['dnd_enabled' => true, 'dnd_until' => Carbon::parse('2026-10-22T20:00:00Z')])->save();
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
    $this->travelTo(Carbon::parse('2026-10-22T01:00:00Z'));
    $delivery = app(HrCalendarReminderDelivery::class);
    $delivery->stage(now());
    expect($delivery->deliver(now()))->toBe(0);
    Notification::assertNothingSent();
    $this->travelTo(Carbon::parse('2026-10-22T20:00:00Z'));
    expect($delivery->deliver(now()))->toBe(2)->and($delivery->deliver(now()))->toBe(0);
    Notification::assertNotSentTo($this->people[0], CalendarReminderNotification::class);
    Notification::assertSentToTimes($this->people[1], CalendarReminderNotification::class, 1);
    Notification::assertSentTo($this->people[1], CalendarReminderNotification::class, fn ($notice) => $notice->channel === 'email' && $notice->count === 2);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->where('user_id', $this->people[0]->id)->where('state', 'skipped')->count())->toBe(2);
});

test('event notifications personal allowance is truthful across HR and Hub origin without suppressing initial invitations', function () {
    $person = $this->people[0];
    $reminders = app(HrCalendarPersonalReminders::class);
    expect($reminders->summary($person, $this->event, $this->date)['inapp_allowed'])->toBeTrue();
    ($this->preferences)($person, false);
    expect($reminders->summary($person, $this->event, $this->date)['inapp_allowed'])->toBeFalse();
    $this->event->forceFill(['hub_managed' => true])->save();
    expect($reminders->summary($person, $this->event->fresh(), $this->date)['inapp_allowed'])->toBeFalse()
        ->and((new CalendarEventInviteNotification($this->event))->shouldSend($person, 'mail'))->toBeTrue();
    $person->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'company_hub.view')->sole()->id => ['allowed' => false]]);
    expect(app(HubNotificationPreferences::class)->allowsEvent($person->fresh(), $this->event))->toBeFalse();
    ($this->preferences)($person, true);
    UserNotificationPreference::updateOrCreate(['user_id' => $person->id, 'key' => 'hr.calendar.reminder'], ['enabled' => false, 'channel_inapp' => false, 'channel_email' => false]);
    expect($reminders->summary($person->fresh(), $this->event->fresh(), $this->date)['inapp_allowed'])->toBeFalse();
});

test('event notifications booking and cancellation opt-outs preserve operational places and retained response history', function () {
    ($this->preferences)($this->people[1], false);
    $this->registration->respond($this->people[0], $this->event, $this->date, 'declined');
    expect($this->registration->summary($this->people[1], $this->event->fresh(), $this->date)['mine'])->toBe('going');
    Notification::assertNotSentTo($this->people[1], CalendarPlaceAvailableNotification::class);
    expect((new CalendarPlaceAvailableNotification($this->event->id, $this->date))->shouldSend($this->people[1], 'database'))->toBeFalse();
    $this->actingAs($this->editor)->putJson('/company-hub/events/'.$this->event->id.'/cancellation', ['actor_id' => $this->editor->id,
        'edit_version' => $this->event->fresh()->edit_version, 'date' => $this->date, 'scope' => 'this'])->assertOk();
    Notification::assertNotSentTo($this->people[1], CalendarEventCancelledNotification::class);
    expect($this->registration->summary($this->people[1], $this->event->fresh(), $this->date)['mine'])->toBe('going');
});

test('event notifications queued organiser RSVP rechecks current response ownership and event preference', function () {
    $this->event->forceFill(['registration_enabled' => false])->save();
    $person = $this->people[0];
    $this->event->attendees()->where('user_id', $person->id)->update(['rsvp_status' => 'no']);
    $notice = new CalendarEventRsvpNotification($this->event->fresh(), $person, 'no');
    expect($notice->shouldSend($this->editor, 'database'))->toBeTrue()->and($notice->via($this->editor))->toBe(['database']);
    ($this->preferences)($this->editor, false);
    expect($notice->shouldSend($this->editor, 'database'))->toBeFalse();
    ($this->preferences)($this->editor, true);
    $this->event->attendees()->where('user_id', $person->id)->update(['rsvp_status' => 'yes']);
    expect($notice->shouldSend($this->editor, 'database'))->toBeFalse();
});

test('event notifications legacy accepted responses receive material changes while unjoined audience members do not', function () {
    $this->event->forceFill(['registration_enabled' => false])->save();
    DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->delete();
    $this->event->attendees()->where('user_id', $this->people[0]->id)->update(['rsvp_status' => 'yes']);
    $this->event->attendees()->where('user_id', $this->people[1]->id)->update(['rsvp_status' => 'maybe']);
    ($this->updateEvent)(['description' => 'Changed live invitation'])->assertRedirect()->assertSessionHasNoErrors();
    foreach ($this->people->take(2) as $person) {
        Notification::assertSentToTimes($person, CalendarEventChangedNotification::class, 1);
    }
    Notification::assertNotSentTo($this->people[2], CalendarEventChangedNotification::class);
});

test('event notifications independent following file copies preserve reader order and do not invent a material change', function () {
    $date = '2026-10-30';
    $this->registration->respond($this->people[2], $this->event, $date, 'going');
    $child = $this->event->replicate();
    $child->forceFill(['recurrence_parent_id' => $this->event->id, 'rrule' => null, 'is_exception' => true, 'exception_date' => $date,
        'starts_at' => '2026-10-30T01:00:00Z', 'ends_at' => '2026-10-30T02:00:00Z'])->save();
    foreach ([$this->event, $child] as $index => $event) {
        $path = 'calendar/change-order-'.$index.'.pdf';
        Storage::disk('private')->put($path, 'Independent bytes '.$index);
        $event->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => $path,
            'original_name' => 'Information '.$index.'.pdf', 'mime' => 'application/pdf', 'size' => strlen('Independent bytes '.$index)]);
    }
    ($this->updateEvent)(['scope' => 'following', 'occurrence_date' => $date])->assertRedirect()->assertSessionHasNoErrors();
    $place = DB::table('hr_calendar_registrations')->where('user_id', $this->people[2]->id)->first();
    $next = HrCalendarEvent::findOrFail($place->event_id);
    $copy = $next->attachments()->sole();
    expect($next->id)->not->toBe($this->event->id)->and($copy->path)->not->toBe('calendar/change-order-0.pdf')
        ->and(Storage::disk('private')->get($copy->path))->toBe('Independent bytes 0');
    Notification::assertNothingSent();
});

test('event notifications ongoing and completed all-day exceptions use the canonical New Zealand completion boundary', function () {
    $child = $this->event->replicate();
    $child->forceFill(['recurrence_parent_id' => $this->event->id, 'rrule' => null, 'is_exception' => true, 'exception_date' => $this->date,
        'is_all_day' => true, 'starts_at' => '2026-10-23T00:00:00Z', 'ends_at' => '2026-10-23T00:00:00Z'])->save();
    // Midnight in New Zealand has passed; the date remains active until its local day ends.
    $this->travelTo(Carbon::parse('2026-10-22T12:00:00Z'));
    ($this->updateEvent)(['hub_content' => ['agenda' => 'Useful current agenda']])->assertRedirect()->assertSessionHasNoErrors();
    foreach ($this->people->take(2) as $person) {
        Notification::assertSentToTimes($person, CalendarEventChangedNotification::class, 1);
    }
    $notice = Notification::sent($this->people[0], CalendarEventChangedNotification::class)->sole();
    expect($notice->shouldSend($this->people[0], 'database'))->toBeTrue();
    $this->travelTo(Carbon::parse('2026-10-23T11:00:00Z'));
    expect($notice->shouldSend($this->people[0], 'database'))->toBeFalse();
    ($this->updateEvent)(['hub_content' => ['agenda' => 'Completed history update']])->assertRedirect()->assertSessionHasNoErrors();
    foreach ($this->people->take(2) as $person) {
        Notification::assertSentToTimes($person, CalendarEventChangedNotification::class, 1);
    }
});
