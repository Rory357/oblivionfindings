<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Services\HubEventScheduling;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarPlaceAvailableNotification;
use App\Domain\Hr\Notifications\CalendarReminderNotification;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarPersonalReminders;
use App\Domain\Hr\Services\HrCalendarReminderDelivery;
use App\Domain\Hr\Services\HrCalendarSeriesService;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Http\Controllers\CompanyHub\HubEventController;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

beforeEach(function () {
    Notification::fake();
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
        ensureCanonicalHrStaffProfile($person, $this->site);

        return $person;
    });
    $this->event = HrCalendarEvent::create(['title' => 'Private garden gathering', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-23T01:00:00Z', 'ends_at' => '2026-10-23T02:00:00Z', 'recurrence_timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;COUNT=3', 'created_by' => $this->editor->id]);
    foreach ($this->people as $person) {
        $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $person->id]);
    }
    $this->date = '2026-10-23';
    $this->registration = app(HrEventRegistrationService::class);
    $this->registration->configure($this->editor, $this->event, ['enabled' => true, 'capacity' => 1, 'version' => 1]);
    // The parent owns production route integration; these exercise the same bound controllers.
    foreach ([['put', '/events/{event}/reminder', 'personalReminder', 'reminder'],
        ['post', '/events/{event}/offer-place', 'offerPlace', 'offer-place'],
        ['get', '/events/{event}/attendance/export', 'attendanceExport', 'attendance.export']] as [$verb, $path, $method, $name]) {
        if (! Route::has('company-hub.events.'.$name)) {
            Route::$verb('/company-hub'.$path, [HubEventController::class, $method])->middleware(['web', 'auth'])->name('company-hub.events.'.$name);
        }
    }
    $this->reminder = function (User $person, array $changes = []) {
        $choice = app(HrCalendarPersonalReminders::class)->summary($person, $this->event->fresh(), $this->date);

        return $this->actingAs($person)->putJson('/company-hub/events/'.$this->event->id.'/reminder', [
            'actor_id' => $person->id, 'edit_version' => $this->event->fresh()->edit_version, 'date' => $this->date,
            'reminder_version' => $choice['version'], 'enabled' => true, ...$changes]);
    };
    $this->freeWaiting = function () {
        foreach ($this->people as $person) {
            $this->registration->respond($person, $this->event, $this->date, 'going');
        }
        foreach ($this->people->slice(1) as $person) {
            ensureCanonicalHrStaffProfile($person, $this->site, ['end_date' => today()->subDay()]);
        }
        $this->registration->respond($this->people[0], $this->event, $this->date, 'declined');
        foreach ($this->people->slice(1) as $person) {
            ensureCanonicalHrStaffProfile($person, $this->site);
        }

        return DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->where('status', 'waiting')->orderBy('id')->get();
    };
    $this->offer = function (object $row, array $changes = []) {
        $event = $this->event->fresh();

        return $this->actingAs($this->editor)->postJson('/company-hub/events/'.$event->id.'/offer-place', [
            'actor_id' => $this->editor->id, 'edit_version' => $event->edit_version, 'registration_version' => $event->registration_version,
            'date' => $this->date, 'registration_id' => $row->id, 'version' => $row->version, ...$changes]);
    };
});

test('booking actions personal choices belong to each registered or waiting reader and deliver once with organiser deduplication', function () {
    foreach ($this->people as $person) {
        $this->registration->respond($person, $this->event, $this->date, 'going');
    }
    ($this->reminder)($this->people[0])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('registration.personal_reminder.enabled', true);
    ($this->reminder)($this->people[1])->assertOk()->assertJsonPath('registration.mine', 'waiting');
    expect(app(HrCalendarPersonalReminders::class)->summary($this->people[2], $this->event, $this->date)['enabled'])->toBeFalse();
    $this->event->reminders()->create(['offset_minutes' => 1440, 'channel' => 'notification']);
    $due = Carbon::parse('2026-10-22T01:00:00Z');
    $this->travelTo($due);
    $delivery = app(HrCalendarReminderDelivery::class);
    expect($delivery->stage($due))->toBe(2)->and($delivery->deliver($due))->toBe(2)->and($delivery->stage($due))->toBe(0)->and($delivery->deliver($due))->toBe(0);
    foreach ($this->people->take(2) as $person) {
        Notification::assertSentToTimes($person, CalendarReminderNotification::class, 1);
    }
    Notification::assertNotSentTo($this->people[2], CalendarReminderNotification::class);
});

test('booking actions reminder guards account event choice version registration and elapsed lead time without another-person writes', function () {
    $this->registration->respond($this->people[0], $this->event, $this->date, 'going');
    ($this->reminder)($this->people[0], ['actor_id' => $this->editor->id])->assertConflict();
    ($this->reminder)($this->people[0], ['edit_version' => 999])->assertConflict();
    ($this->reminder)($this->people[2])->assertUnprocessable();
    ($this->reminder)($this->people[0], ['user_id' => $this->people[2]->id])->assertOk();
    expect(DB::table(HrCalendarPersonalReminders::TABLE)->sole()->user_id)->toBe($this->people[0]->id);
    ($this->reminder)($this->people[0], ['reminder_version' => 0, 'enabled' => false])->assertConflict();
    ($this->reminder)($this->people[0], ['enabled' => false])->assertOk();
    $this->travelTo(Carbon::parse('2026-10-22T01:00:00Z'));
    ($this->reminder)($this->people[0])->assertUnprocessable();
    expect(DB::table(HrCalendarPersonalReminders::TABLE)->sole()->enabled)->toBe(0);
    Notification::assertNothingSent();
});

test('booking actions staged personal reminders recheck current eligibility and preferences', function (string $change) {
    $this->registration->respond($this->people[0], $this->event, $this->date, 'going');
    ($this->reminder)($this->people[0])->assertOk();
    $due = Carbon::parse('2026-10-22T01:00:00Z');
    $this->travelTo($due);
    $delivery = app(HrCalendarReminderDelivery::class);
    expect($delivery->stage($due))->toBe(1);
    match ($change) {
        'choice' => ($this->reminder)($this->people[0], ['enabled' => false])->assertOk(),
        'decline' => $this->registration->respond($this->people[0], $this->event, $this->date, 'declined'),
        'staff' => ensureCanonicalHrStaffProfile($this->people[0], $this->site, ['end_date' => today()->subDay()]),
        'site' => ensureCanonicalHrStaffProfile($this->people[0], Site::factory()->create(['is_active' => true, 'archived' => false])),
        'archive' => $this->event->update(['archived_at' => now()]),
        'cancel' => $this->event->update(['cancelled_at' => now()]),
        'permission' => $this->people[0]->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'company_hub.view')->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all()),
        'preference' => UserNotificationPreference::updateOrCreate(['user_id' => $this->people[0]->id, 'key' => 'hr.calendar.reminder'], ['enabled' => false, 'channel_inapp' => false, 'channel_email' => false]),
    };
    expect($delivery->deliver($due))->toBe(0)->and(DB::table(HrCalendarReminderDelivery::TABLE)->sole()->state)->toBe('skipped');
    Notification::assertNothingSent();
})->with(['choice', 'decline', 'staff', 'site', 'archive', 'cancel', 'permission', 'preference']);

test('booking actions moved dates and following splits retain own choices and use the current occurrence instant', function () {
    $this->registration->respond($this->people[0], $this->event, $this->date, 'going');
    ($this->reminder)($this->people[0])->assertOk();
    $child = app(HrCalendarSeriesService::class)->change($this->editor, $this->event, $this->date,
        ['starts_at' => Carbon::parse('2026-10-24T03:00:00Z'), 'ends_at' => Carbon::parse('2026-10-24T04:00:00Z')], false);
    expect(app(HrCalendarPersonalReminders::class)->summary($this->people[0], $child, $this->date)['due_at'])->toBe('2026-10-23T03:00:00+00:00');
    $nextDate = '2026-10-30';
    $this->registration->respond($this->people[0], $this->event, $nextDate, 'going');
    $this->date = $nextDate;
    ($this->reminder)($this->people[0])->assertOk();
    $next = app(HrCalendarSeriesService::class)->change($this->editor, $this->event->fresh(), $nextDate, ['starts_at' => Carbon::parse('2026-10-31T01:00:00Z'), 'ends_at' => Carbon::parse('2026-10-31T02:00:00Z')], true);
    expect(DB::table(HrCalendarPersonalReminders::TABLE)->where('event_id', $next->id)->sole()->occurrence_date)->toBe('2026-10-31');
    expect(DB::table(HrCalendarPersonalReminders::TABLE)->where('event_id', $this->event->id)->sole()->occurrence_date)->toBe('2026-10-23');
    $this->travelTo(Carbon::parse('2026-10-30T01:00:00Z'));
    $delivery = app(HrCalendarReminderDelivery::class);
    expect($delivery->stage(now()))->toBe(1)->and($delivery->deliver(now()))->toBe(1);
    Notification::assertSentToTimes($this->people[0], CalendarReminderNotification::class, 1);
});

test('booking actions cutoff closes new bookings at the exact instant but preserves decline and FIFO confirmation', function () {
    $event = $this->event->fresh();
    $this->registration->configure($this->editor, $event, ['enabled' => true, 'capacity' => 1, 'version' => $event->registration_version,
        'actor_id' => $this->editor->id, 'edit_version' => $event->edit_version, 'closes_before_minutes' => 1260]);
    $this->registration->respond($this->people[0], $this->event, $this->date, 'going');
    $this->registration->respond($this->people[1], $this->event, $this->date, 'going');
    expect($this->registration->summary($this->people[0], $this->event->fresh(), $this->date)['closes_at'])->toBe('2026-10-22T04:00:00+00:00');
    $this->travelTo(Carbon::parse('2026-10-22T04:00:00Z'));
    $existing = DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->orderBy('id')->get()->toJson();
    foreach ($this->people->take(2) as $person) {
        $this->registration->respond($person, $this->event, $this->date, 'going');
    }
    expect(DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->orderBy('id')->get()->toJson())->toBe($existing);
    $this->actingAs($this->people[2])->postJson('/company-hub/events/'.$this->event->id.'/registration', ['date' => $this->date, 'status' => 'going'])->assertUnprocessable();
    $this->registration->respond($this->people[0], $this->event, $this->date, 'declined');
    expect($this->registration->summary($this->people[1], $this->event->fresh(), $this->date)['mine'])->toBe('going');
    Notification::assertSentToTimes($this->people[1], CalendarPlaceAvailableNotification::class, 1);
    $next = app(HrCalendarSeriesService::class)->change($this->editor, $this->event->fresh(), '2026-10-30', [], true);
    expect($next->registration_closes_before_minutes)->toBe(1260);
});

test('booking actions all-day cutoff and personal due time use New Zealand midnight and cannot allocate places after start', function () {
    $this->event->update(['is_all_day' => true, 'starts_at' => '2026-10-23T00:00:00Z', 'ends_at' => '2026-10-23T23:59:59Z']);
    $waiting = ($this->freeWaiting)();
    ($this->reminder)($this->people[1])->assertOk()->assertJsonPath('registration.personal_reminder.due_at', '2026-10-21T11:00:00+00:00');
    expect($this->registration->summary($this->people[1], $this->event->fresh(), $this->date)['closes_at'])->toBe('2026-10-22T11:00:00+00:00');
    $this->travelTo(Carbon::parse('2026-10-22T11:00:00Z'));
    ($this->offer)($waiting[0])->assertUnprocessable();
    expect($this->registration->roster($this->editor, $this->event->fresh(), $this->date)['can_record'])->toBeTrue();
    Notification::assertNothingSent();
});

test('booking actions explicit place confirmation preserves FIFO rejects stale context and delivers one private receipt', function () {
    $waiting = ($this->freeWaiting)();
    $roster = $this->registration->roster($this->editor, $this->event->fresh(), $this->date);
    expect(collect($roster['registrations'])->firstWhere('id', $waiting[0]->id)['can_offer_place'])->toBeTrue();
    ($this->offer)($waiting[1])->assertUnprocessable();
    foreach ([['actor_id' => $this->people[0]->id], ['edit_version' => 999], ['registration_version' => 999], ['version' => 999]] as $stale) {
        ($this->offer)($waiting[0], $stale)->assertConflict();
    }
    ($this->offer)($waiting[0])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('registration.going', 1);
    $updated = DB::table('hr_calendar_registrations')->where('id', $waiting[0]->id)->sole();
    ($this->offer)($updated)->assertOk();
    ($this->offer)($waiting[1])->assertUnprocessable();
    Notification::assertSentToTimes($this->people[1], CalendarPlaceAvailableNotification::class, 1);
    Notification::assertNotSentTo($this->people[2], CalendarPlaceAvailableNotification::class);
});

test('booking actions unavailable people and foreign registrations cannot be selected or exported', function () {
    $waiting = ($this->freeWaiting)();
    ensureCanonicalHrStaffProfile($this->people[1], $this->site, ['end_date' => today()->subDay()]);
    ($this->offer)($waiting[0])->assertNotFound();
    ($this->offer)($waiting[1], ['date' => '2026-10-30'])->assertNotFound();
    ($this->offer)($waiting[1])->assertOk();
    $this->actingAs($this->people[0])->get('/company-hub/events/'.$this->event->id.'/attendance/export?date='.$this->date)->assertNotFound();
    $this->actingAs($this->editor)->get('/company-hub/events/'.$this->event->id.'/attendance/export?date='.$this->date)->assertOk();
    ensureCanonicalHrStaffProfile($this->editor, Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->get('/company-hub/events/'.$this->event->id.'/attendance/export?date='.$this->date)->assertNotFound();
});

test('booking actions late confirmation failure rolls back the seat audit and transactional private receipt', function () {
    $waiting = ($this->freeWaiting)();
    Notification::shouldReceive('send')->once()->andReturnUsing(function ($person, $notice) {
        expect($notice)->toBeInstanceOf(CalendarPlaceAvailableNotification::class)->and($notice->via($person))->toBe(['database']);
        $notice->id = (string) Str::uuid();
        app(DatabaseChannel::class)->send($person, $notice);
    });
    $before = DB::table('notifications')->count();
    Event::listen('eloquent.creating: '.AuditLog::class, function ($audit) use ($before) {
        if ($audit->action === 'calendar.waiting_place_confirmed') {
            expect(DB::table('notifications')->count())->toBe($before + 1);
            throw new RuntimeException('Simulated late confirmation failure');
        }
    });
    $this->withoutExceptionHandling();
    try {
        ($this->offer)($waiting[0]);
        test()->fail('Expected the late failure.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Simulated late confirmation failure');
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
    expect(DB::table('hr_calendar_registrations')->where('id', $waiting[0]->id)->sole()->status)->toBe('waiting')->and(DB::table('notifications')->count())->toBe($before);
});

test('booking actions CSV exports only current authorised roster fields and neutralises formula-leading names without mutation', function () {
    $this->people[0]->update(['name' => '=HYPERLINK("https://example.invalid")']);
    $this->people[1]->update(['name' => '  @SUM(1)']);
    foreach ($this->people as $person) {
        $this->registration->respond($person, $this->event, $this->date, 'going');
    }
    ensureCanonicalHrStaffProfile($this->people[2], $this->site, ['end_date' => today()->subDay()]);
    $before = DB::table('hr_calendar_registrations')->orderBy('id')->get()->toJson();
    $response = $this->actingAs($this->editor)->get('/company-hub/events/'.$this->event->id.'/attendance/export?date='.$this->date)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $csv = $response->streamedContent();
    expect($csv)->toContain('Occurrence date', "'=HYPERLINK", "'  @SUM(1)")->not->toContain($this->people[2]->name, $this->people[0]->email, $this->event->title)
        ->and(DB::table('hr_calendar_registrations')->orderBy('id')->get()->toJson())->toBe($before);
    Notification::assertNothingSent();
});

test('booking actions reviewed scheduled cutoff stays private until canonical release and versioned edits preserve the live cutoff', function () {
    $payload = ['draft_key' => (string) Str::uuid(), 'mode' => 'review', 'actor_id' => $this->editor->id, 'publish_at' => '2026-10-21T00:00:00Z',
        'title' => 'Private future booking', 'event_type' => 'company', 'starts_at' => '2026-10-23T01:00:00Z', 'ends_at' => '2026-10-23T02:00:00Z',
        'is_all_day' => false, 'site_id' => $this->site->id, 'recurrence_timezone' => 'UTC', 'audience_type' => 'people', 'audience_user_ids' => [$this->people[0]->id],
        'registration_enabled' => true, 'registration_capacity' => 5, 'registration_closes_before_minutes' => 1260];
    $this->actingAs($this->editor)->post('/company-hub/event-drafts', $payload)->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $reviewed = $draft->payload;
    $this->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['actor_id' => $this->editor->id, 'version' => $draft->version, 'action' => 'approve'])
        ->assertOk()->assertJsonPath('draft.state', 'scheduled');
    expect($draft->fresh()->payload)->toEqual($reviewed);
    expect(HrCalendarEvent::where('title', $payload['title'])->exists())->toBeFalse();
    $this->travelTo(Carbon::parse($payload['publish_at']));
    expect(app(HubEventScheduling::class)->publishDue()['published'])->toBe(1);
    $event = HrCalendarEvent::where('title', $payload['title'])->sole();
    expect($event->registration_closes_before_minutes)->toBe(1260)->and($this->registration->summary($this->people[0], $event, '2026-10-23')['closes_at'])->toBe('2026-10-22T04:00:00+00:00');
    $this->putJson('/company-hub/events/'.$event->id.'/registration', ['enabled' => true, 'capacity' => 5, 'version' => $event->registration_version,
        'closes_before_minutes' => 60, 'actor_id' => $this->people[0]->id, 'edit_version' => $event->edit_version])->assertConflict();
    expect($event->fresh()->registration_closes_before_minutes)->toBe(1260);
});

test('booking actions new schema is optional for reads and clearly guards unsupported mutations', function () {
    $schema = Schema::getFacadeRoot();
    $pending = Mockery::mock(get_class($schema), [$schema->getConnection()])->makePartial();
    $pending->shouldReceive('hasTable')->andReturnUsing(fn ($table) => $table === HrCalendarPersonalReminders::TABLE ? false : $schema->hasTable($table));
    $pending->shouldReceive('hasColumn')->andReturnUsing(fn ($table, $column) => $table === 'hr_calendar_events' && $column === 'registration_closes_before_minutes' ? false : $schema->hasColumn($table, $column));
    Schema::swap($pending);
    try {
        $summary = $this->registration->summary($this->people[0], $this->event->fresh(), $this->date);
        expect($summary['cutoff_supported'])->toBeFalse()->and($summary['personal_reminder']['supported'])->toBeFalse()->and($summary['closes_at'])->toBe('2026-10-23T01:00:00+00:00');
        ($this->reminder)($this->people[0])->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $event = $this->event->fresh();
        $this->actingAs($this->editor)->putJson('/company-hub/events/'.$event->id.'/registration', ['enabled' => true, 'capacity' => 1,
            'actor_id' => $this->editor->id, 'edit_version' => $event->edit_version, 'version' => $event->registration_version, 'closes_before_minutes' => 60])->assertUnprocessable()->assertJsonValidationErrors('closes_before_minutes');
    } finally {
        Schema::swap($schema);
    }
});

test('booking actions private direct objects deny before account version or schema conflicts', function () {
    ensureCanonicalHrStaffProfile($this->people[0], Site::factory()->create(['is_active' => true, 'archived' => false]));
    ($this->reminder)($this->people[0], ['actor_id' => $this->editor->id, 'edit_version' => 999])->assertNotFound();
    $pending = Mockery::mock(HrCalendarPersonalReminders::class)->makePartial();
    $pending->shouldReceive('ready')->andReturnFalse();
    app()->instance(HrCalendarPersonalReminders::class, $pending);
    ($this->reminder)($this->people[0])->assertNotFound();
    ensureCanonicalHrStaffProfile($this->editor, Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->actingAs($this->editor)->postJson('/company-hub/events/'.$this->event->id.'/offer-place', ['actor_id' => $this->people[0]->id,
        'edit_version' => 999, 'registration_version' => 999, 'date' => $this->date, 'registration_id' => 1, 'version' => 1])->assertNotFound();
    Notification::assertNothingSent();
});

test('booking actions roster uses current canonical ownership even when an old owner remains a permitted reader', function () {
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id]);
    $this->editor->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', ['calendar.manage', 'hr.calendar.manage'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    $bound = $this->event->fresh();
    expect($this->registration->roster($this->editor, $bound, $this->date)['can_export'])->toBeTrue();
    HrCalendarEvent::whereKey($bound->id)->update(['created_by' => $this->people[0]->id]);
    expect(app(HrCalendarAccessService::class)->canViewEvent($this->editor, $bound->fresh()))->toBeTrue();
    try {
        $this->registration->roster($this->editor, $bound, $this->date);
        test()->fail('An old ownership model cannot authorise the current roster.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe(404);
    }
    $this->actingAs($this->editor)->get('/company-hub/events/'.$bound->id.'/attendance/export?date='.$this->date)->assertNotFound();
    Notification::assertNothingSent();
});
