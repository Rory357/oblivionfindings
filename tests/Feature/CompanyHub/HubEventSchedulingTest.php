<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubEventPublishing;
use App\Domain\CompanyHub\Services\HubEventScheduling;
use App\Domain\CompanyHub\Services\HubNotices;
use App\Domain\CompanyHub\Services\HubPlanning;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarEventChangedNotification;
use App\Domain\Hr\Notifications\CalendarEventInviteNotification;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->travelTo(Carbon::parse('2026-10-13T00:00:00Z'));
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $role = Role::where('name', 'hr')->sole();
    $role->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id'));
    $this->publisher = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->publisher->roles()->attach($role);
    $this->site = ensureCanonicalHrStaffProfile($this->publisher);
    $this->author = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->author->roles()->attach($role);
    $this->author->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', ['company_hub.publish', 'company_hub.edit_all'])->pluck('id')
        ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    ensureCanonicalHrStaffProfile($this->author, $this->site);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publishAt = '2026-10-15T00:00:00Z';
    $this->payload = ['draft_key' => (string) Str::uuid(), 'mode' => 'draft', 'title' => 'The exact approved invitation',
        'description' => 'Private until its separate publication time', 'event_type' => 'company', 'starts_at' => '2026-10-20T00:00:00Z',
        'ends_at' => '2026-10-20T01:00:00Z', 'is_all_day' => false, 'site_id' => $this->site->id,
        'audience_type' => 'people', 'audience_user_ids' => [$this->reader->id], 'recurrence_timezone' => 'UTC',
        'reminders' => [['offset_minutes' => 60, 'channel' => 'notification']], 'location' => 'Meeting room'];
    $this->photo = fn () => UploadedFile::fake()->createWithContent('afternoon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $this->schedule = function (array $changes = []) {
        $this->actingAs($this->publisher)->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(),
            'actor_id' => $this->publisher->id, 'mode' => 'schedule', 'publish_at' => $this->publishAt, ...$changes])->assertRedirect()->assertSessionHasNoErrors();

        return HubEventDraft::latest('id')->firstOrFail();
    };
    $this->due = fn ($limit = 100) => app(HubEventScheduling::class)->publishDue($limit);
    $this->actingAs($this->publisher)->from('/company-hub?view=events');
});

test('scheduled invitation and files remain private until due then publish once with independent files and current invitations', function () {
    $draft = ($this->schedule)(['files' => [($this->photo)()], 'hub_content' => ['cover_ref' => 'upload:0', 'cover_alt' => 'Approved photograph', 'gallery_refs' => ['upload:0']]]);
    $file = $draft->files()->sole();
    expect($draft->state)->toBe('scheduled')->and($draft->event_id)->toBeNull()->and(HrCalendarEvent::count())->toBe(0)
        ->and(($this->due)())->toBe(['published' => 0, 'blocked' => 0, 'failed' => 0]);
    $this->actingAs($this->reader)->get('/company-hub/event-draft-files/'.$file->id)->assertNotFound();
    expect(app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader))->toBe([]);
    Notification::assertNothingSent();
    $this->actingAs($this->publisher)->getJson('/company-hub/event-drafts')->assertJsonPath('actor_id', $this->publisher->id)->assertJsonPath('can_schedule', true)
        ->assertJsonPath('drafts.0.publish_at', '2026-10-15T00:00:00+00:00');
    $this->getJson('/company-hub/events/options')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('actor_id', $this->publisher->id)->assertJsonPath('can_schedule', true);
    $publication = collect(app(HubPlanning::class)->workspace($this->publisher, '2026-10')['items'])->firstWhere('key', 'event-publication:'.$draft->id);
    expect($publication['status'])->toBe('Scheduled event publication')->and($publication['date'])->toBe('2026-10-15');
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)())->toBe(['published' => 1, 'blocked' => 0, 'failed' => 0]);
    $event = HrCalendarEvent::sole();
    $live = $event->attachments()->sole();
    expect($draft->fresh()->state)->toBe('published')->and($draft->fresh()->version)->toBe(2)->and($event->created_by)->toBe($this->publisher->id)
        ->and($event->title)->toBe($this->payload['title'])->and($draft->fresh()->published_at->eq(now()))->toBeTrue()
        ->and($draft->fresh()->published_at->eq($event->starts_at))->toBeFalse()->and($live->path)->not->toBe($file->path)
        ->and(Storage::disk('private')->get($live->path))->toBe(Storage::disk('private')->get($file->path));
    Notification::assertSentToTimes($this->reader, CalendarEventInviteNotification::class, 1);
    expect(($this->due)())->toBe(['published' => 0, 'blocked' => 0, 'failed' => 0])->and(HrCalendarEvent::count())->toBe(1);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$live->id)->assertOk();
});

test('review approval schedules the exact version while withdraw and explicit publish-authorised cancel protect editing', function () {
    $this->actingAs($this->author)->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review', 'actor_id' => $this->author->id,
        'publish_at' => $this->publishAt, 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $payload = $draft->payload;
    $url = '/company-hub/event-drafts/'.$draft->id;
    $this->postJson($url.'/transition', ['actor_id' => $this->author->id, 'version' => 1, 'action' => 'approve'])->assertForbidden();
    $this->actingAs($this->publisher)->postJson($url.'/transition', ['actor_id' => $this->author->id, 'version' => 1, 'action' => 'approve'])->assertConflict();
    $this->postJson($url.'/transition', ['actor_id' => $this->publisher->id, 'version' => 1, 'action' => 'approve'])->assertOk()->assertJsonPath('draft.state', 'scheduled');
    expect($draft->fresh()->payload)->toEqual($payload)->and(HrCalendarEvent::count())->toBe(0);
    $this->postJson($url, [...$this->payload, 'version' => 2, 'title' => 'Unreviewed replacement'])->assertConflict();
    $this->postJson($url.'/transition', ['version' => 2, 'action' => 'archive'])->assertConflict();
    $this->postJson($url.'/transition', ['version' => 2, 'action' => 'restore'])->assertConflict();
    $this->postJson($url.'/transition', ['version' => 2, 'action' => 'withdraw'])->assertConflict();
    $this->actingAs($this->author)->postJson($url.'/transition', ['actor_id' => $this->author->id, 'version' => 2, 'action' => 'cancel_schedule'])->assertForbidden();
    $this->actingAs($this->publisher)->postJson($url.'/transition', ['actor_id' => $this->publisher->id, 'version' => 1, 'action' => 'cancel_schedule'])->assertConflict();
    $this->postJson($url.'/transition', ['actor_id' => $this->publisher->id, 'version' => 2, 'action' => 'cancel_schedule'])->assertOk()->assertJsonPath('draft.state', 'draft')->assertJsonPath('draft.publish_at', null);
    expect($draft->fresh()->payload)->toEqual($payload)->and($draft->files()->count())->toBe(1);
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)()['published'])->toBe(0)->and(HrCalendarEvent::count())->toBe(0);
    $this->actingAs($this->author)->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'mode' => 'review'])->assertSessionHasNoErrors();
    $other = HubEventDraft::latest('id')->first();
    $this->postJson('/company-hub/event-drafts/'.$other->id.'/transition', ['version' => 1, 'action' => 'withdraw'])->assertOk();
    expect($other->fresh()->state)->toBe('draft');
    Notification::assertNothingSent();
});

test('scheduled edits retain the published edition until due and later stale source versions block replacement', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $event = HrCalendarEvent::sole();
    $live = $event->attachments()->sole();
    Notification::fake();
    $draft = ($this->schedule)(['source_event_id' => $event->id, 'edit_version' => $event->edit_version, 'title' => 'The future edition',
        'hub_content' => ['cover_ref' => 'file:'.$live->id, 'gallery_refs' => []]]);
    expect($event->fresh()->title)->toBe($this->payload['title']);
    expect(app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader)[0]['title'])->toBe($this->payload['title']);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'source_event_id' => $event->id,
        'edit_version' => $event->edit_version, 'mode' => 'schedule', 'actor_id' => $this->publisher->id, 'publish_at' => $this->publishAt])->assertConflict();
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)()['published'])->toBe(1)->and($event->fresh()->title)->toBe('The future edition')->and(HrCalendarEvent::count())->toBe(1);
    $this->publishAt = now()->addDay()->toIso8601String();
    $stale = ($this->schedule)(['source_event_id' => $event->id, 'edit_version' => $event->fresh()->edit_version, 'title' => 'Must not replace current content']);
    $event->increment('edit_version');
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)())->toBe(['published' => 0, 'blocked' => 1, 'failed' => 0])->and($stale->fresh()->state)->toBe('schedule_blocked')
        ->and($event->fresh()->title)->toBe('The future edition')->and(Storage::disk('private')->exists($live->path))->toBeTrue()
        ->and($draft->fresh()->state)->toBe('published');
    Notification::assertNothingSent();
});

test('due publication blocks revoked current eligibility or changed approved files without early sharing', function (string $change) {
    $this->actingAs($this->author)->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review', 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['actor_id' => $this->publisher->id, 'version' => 1,
        'action' => 'approve', 'publish_at' => $this->publishAt])->assertOk();
    match ($change) {
        'publisher permission' => $this->publisher->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'company_hub.publish')->sole()->id => ['allowed' => false]]),
        'publisher staff' => $this->publisher->hrEmployeeProfile()->update(['is_active' => false]),
        'owner staff' => $this->author->hrEmployeeProfile()->update(['is_active' => false]),
        'publisher site' => ensureCanonicalHrStaffProfile($this->publisher, Site::factory()->create(['is_active' => true, 'archived' => false])),
        'invitee staff' => $this->reader->hrEmployeeProfile()->update(['is_active' => false]),
        'missing file' => Storage::disk('private')->delete($draft->files()->sole()->path),
        'changed bytes' => Storage::disk('private')->put($draft->files()->sole()->path, 'Different unapproved bytes'),
    };
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)())->toBe(['published' => 0, 'blocked' => 1, 'failed' => 0])->and(HrCalendarEvent::count())->toBe(0)
        ->and($draft->fresh()->state)->toBe('schedule_blocked')->and($draft->fresh()->version)->toBe(3)
        ->and($draft->fresh()->schedule_error)->toBe('publication_unavailable')->and($draft->fresh()->feedback)->not->toContain($this->payload['title']);
    expect(($this->due)())->toBe(['published' => 0, 'blocked' => 0, 'failed' => 0]);
    Notification::assertNothingSent();
})->with(['publisher permission', 'publisher staff', 'owner staff', 'publisher site', 'invitee staff', 'missing file', 'changed bytes']);

test('scheduled recurring editions preserve the canonical date and registration graph', function (string $scope) {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'rrule' => 'FREQ=WEEKLY;COUNT=3', 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $root = HrCalendarEvent::sole();
    $root->forceFill(['registration_enabled' => true, 'registration_capacity' => 10])->save();
    DB::table('hr_calendar_registrations')->insert(['event_id' => $root->id, 'user_id' => $this->reader->id, 'occurrence_date' => '2026-10-27', 'status' => 'going', 'created_at' => now(), 'updated_at' => now()]);
    Notification::fake();
    $date = $scope === 'all' ? '2026-10-20' : '2026-10-27';
    $draft = ($this->schedule)(['source_event_id' => $root->id, 'edit_version' => $root->edit_version, 'scope' => $scope, 'occurrence_date' => $date,
        'rrule' => $scope === 'following' ? 'FREQ=WEEKLY;COUNT=2' : 'FREQ=WEEKLY;COUNT=3', 'starts_at' => $date.'T00:00:00Z', 'ends_at' => $date.'T01:00:00Z', 'title' => 'The scheduled '.$scope.' edition']);
    expect($root->fresh()->title)->toBe($this->payload['title'])->and(HrCalendarEvent::count())->toBe(1);
    Notification::assertNothingSent();
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)())->toBe(['published' => 1, 'blocked' => 0, 'failed' => 0]);
    $saved = $draft->fresh()->event;
    expect($saved->title)->toBe('The scheduled '.$scope.' edition')->and((bool) $saved->recurrenceParent?->hub_managed || (bool) $saved->hub_managed)->toBeTrue();
    if ($scope !== 'all') {
        expect($root->fresh()->title)->toBe($this->payload['title'])->and($saved->id)->not->toBe($root->id);
    }
    expect((int) DB::table('hr_calendar_registrations')->value('event_id'))->toBe($scope === 'following' ? $saved->id : $root->id)
        ->and(DB::table('hr_calendar_registrations')->value('status'))->toBe('going');
    if ($scope === 'following') {
        expect($saved->attachments()->sole()->path)->not->toBe($root->attachments()->sole()->path);
    }
    Notification::assertSentToTimes($this->reader, CalendarEventChangedNotification::class, 1);
    Notification::assertSentTo($this->reader, CalendarEventChangedNotification::class, fn ($notice, $channels) => $channels === ['database'] && $notice->shouldSend($this->reader, 'database'));
    Notification::assertNotSentTo($this->reader, CalendarEventInviteNotification::class);
    Notification::assertCount(1);
    expect(($this->due)())->toBe(['published' => 0, 'blocked' => 0, 'failed' => 0]);
    Notification::assertSentToTimes($this->reader, CalendarEventChangedNotification::class, 1);
    Notification::assertCount(1);
})->with(['all', 'this', 'following']);

test('scheduled editions cannot override changed registration settings or cancelled and archived sources', function (string $change) {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'registration_enabled' => true, 'registration_capacity' => 2])->assertSessionHasNoErrors();
    $event = HrCalendarEvent::sole();
    DB::table('hr_calendar_registrations')->insert(['event_id' => $event->id, 'user_id' => $this->reader->id, 'occurrence_date' => '2026-10-20', 'status' => 'going', 'created_at' => now(), 'updated_at' => now()]);
    Notification::fake();
    $draft = ($this->schedule)(['source_event_id' => $event->id, 'edit_version' => $event->edit_version, 'title' => 'A guarded future edition',
        'registration_enabled' => true, 'registration_capacity' => 3, 'registration_version' => $event->registration_version]);
    match ($change) {
        'registration version' => $event->increment('registration_version'),
        'cancelled source' => $event->update(['cancelled_at' => now()]),
        'archived source' => $event->update(['archived_at' => now()]),
    };
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)())->toBe(['published' => 0, 'blocked' => 1, 'failed' => 0])->and($draft->fresh()->state)->toBe('schedule_blocked')
        ->and($event->fresh()->title)->toBe($this->payload['title'])->and((int) $event->fresh()->registration_capacity)->toBe(2)
        ->and(DB::table('hr_calendar_registrations')->value('status'))->toBe('going');
    Notification::assertNothingSent();
})->with(['registration version', 'cancelled source', 'archived source']);

test('late due-publication failure rolls back live records files and invitations then retries once', function () {
    $draft = ($this->schedule)(['files' => [($this->photo)()]]);
    $paths = Storage::disk('private')->allFiles();
    Event::listen('eloquent.creating: '.AuditLog::class, function ($audit) {
        if ($audit->action === 'hub.event_draft.scheduled_published') {
            throw new RuntimeException('Simulated due-publication receipt failure');
        }
    });
    $this->travelTo(Carbon::parse($this->publishAt));
    try {
        expect(($this->due)())->toBe(['published' => 0, 'blocked' => 0, 'failed' => 1]);
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
    expect($draft->fresh()->state)->toBe('scheduled')->and($draft->fresh()->version)->toBe(1)->and(HrCalendarEvent::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe($paths);
    Notification::assertNothingSent();
    expect(($this->due)()['published'])->toBe(1)->and(($this->due)()['published'])->toBe(0)->and(HrCalendarEvent::count())->toBe(1);
    Notification::assertSentToTimes($this->reader, CalendarEventInviteNotification::class, 1);
});

test('reviewed and scheduled source identities cannot drift to a different series with the same version', function (string $stage) {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'rrule' => 'FREQ=WEEKLY;COUNT=3'])->assertSessionHasNoErrors();
    $original = HrCalendarEvent::sole();
    $child = HrCalendarEvent::create([...$original->only(['created_by', 'site_id', 'event_type']), 'title' => 'Original date details',
        'starts_at' => '2026-10-27T00:00:00Z', 'ends_at' => '2026-10-27T01:00:00Z', 'recurrence_parent_id' => $original->id,
        'is_exception' => true, 'exception_date' => '2026-10-27']);
    Notification::fake();
    $this->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'mode' => 'review', 'source_event_id' => $child->id,
        'edit_version' => $original->edit_version, 'scope' => 'this', 'occurrence_date' => '2026-10-27', 'starts_at' => '2026-10-27T00:00:00Z',
        'ends_at' => '2026-10-27T01:00:00Z', 'title' => 'A reviewed change for the original series'])->assertSessionHasNoErrors();
    $draft = HubEventDraft::latest('id')->first();
    $url = '/company-hub/event-drafts/'.$draft->id.'/transition';
    if ($stage === 'scheduled') {
        $this->postJson($url, ['actor_id' => $this->publisher->id, 'version' => 1, 'action' => 'approve', 'publish_at' => $this->publishAt])->assertOk();
    }
    $other = HrCalendarEvent::create([...$original->only(['created_by', 'site_id', 'event_type', 'starts_at', 'ends_at', 'rrule', 'recurrence_timezone']), 'title' => 'Another authorised series']);
    $other->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'none']);
    expect((int) $other->fresh()->edit_version)->toBe($draft->base_edit_version);
    // A valid canonical relation changed; an equal counter must not identify the old series.
    $child->update(['recurrence_parent_id' => $other->id]);
    $dto = collect($this->getJson('/company-hub/event-drafts')->assertOk()->json('drafts'))->firstWhere('id', $draft->id);
    expect(array_key_exists('_source_root_id', $dto['initial']))->toBeFalse();
    if ($stage === 'reviewed') {
        $this->postJson($url, ['actor_id' => $this->publisher->id, 'version' => 1, 'action' => 'approve', 'publish_at' => $this->publishAt])->assertConflict();
        expect($draft->fresh()->state)->toBe('review');
    } else {
        $this->travelTo(Carbon::parse($this->publishAt));
        expect(($this->due)())->toBe(['published' => 0, 'blocked' => 1, 'failed' => 0])->and($draft->fresh()->state)->toBe('schedule_blocked');
    }
    expect($child->fresh()->title)->toBe('Original date details')->and($original->fresh()->title)->toBe($this->payload['title'])
        ->and($other->fresh()->title)->toBe('Another authorised series');
    Notification::assertNothingSent();
})->with(['reviewed', 'scheduled']);

test('schedule time actor guards readiness and bounded due work remain truthful before migration', function () {
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'mode' => 'schedule', 'actor_id' => $this->author->id, 'publish_at' => $this->publishAt])->assertConflict();
    foreach ([null, '2026-10-12T00:00:00Z', '2026-10-15T13:00'] as $time) {
        $this->postJson('/company-hub/event-drafts', [...$this->payload, 'mode' => 'schedule', 'actor_id' => $this->publisher->id, 'publish_at' => $time])->assertUnprocessable();
    }
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'actor_id' => $this->publisher->id, 'publish_at' => $this->publishAt])->assertUnprocessable();
    expect(HrCalendarEvent::count())->toBe(0)->and(HubEventDraft::count())->toBe(0);
    $first = ($this->schedule)();
    $second = ($this->schedule)();
    $this->travelTo(Carbon::parse($this->publishAt));
    expect(($this->due)(1)['published'])->toBe(1)->and($first->fresh()->state)->toBe('published')->and($second->fresh()->state)->toBe('scheduled');
    expect(($this->due)(1)['published'])->toBe(1);
    Notification::fake();
    $unavailable = Mockery::mock(HubEventPublishing::class, [app(HubAccess::class), app(HrCalendarAccessService::class)])->makePartial();
    $unavailable->shouldReceive('schedulingReady')->andReturnFalse();
    app()->instance(HubEventPublishing::class, $unavailable);
    $this->getJson('/company-hub/event-drafts')->assertOk()->assertJsonPath('can_schedule', false);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'mode' => 'schedule', 'actor_id' => $this->publisher->id,
        'publish_at' => now()->addDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('publish_at');
    expect((new HubEventScheduling($unavailable))->publishDue())->toBe(['published' => 0, 'blocked' => 0, 'failed' => 0]);
    Notification::assertNothingSent();
});

test('queued calendar invitations recheck current event audience staff site permission archive and cancellation per channel', function () {
    $event = HrCalendarEvent::create(['created_by' => $this->publisher->id, 'title' => 'A canonical invitation', 'event_type' => 'company',
        'site_id' => $this->site->id, 'starts_at' => $this->payload['starts_at'], 'ends_at' => $this->payload['ends_at']]);
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'none']);
    $notice = new CalendarEventInviteNotification($event);
    expect($notice->via($this->reader))->toBe(['database', 'mail'])->and($notice->shouldSend($this->reader, 'mail'))->toBeTrue()
        ->and($notice->toArray($this->reader)['action_url'])->toBe('/hr/calendar');
    $event->attendees()->delete();
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->publisher->id, 'rsvp_status' => 'none']);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'none']);
    ensureCanonicalHrStaffProfile($this->reader, Site::factory()->create(['is_active' => true, 'archived' => false]));
    expect($notice->shouldSend($this->reader, 'mail'))->toBeFalse();
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['end_date' => today()->subDay()]);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $denials = Permission::whereIn('key', ['company_hub.view', 'hr.calendar.view'])->pluck('id');
    $this->reader->permissionOverrides()->syncWithoutDetaching($denials->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    expect($notice->shouldSend($this->reader, 'mail'))->toBeFalse();
    $this->reader->permissionOverrides()->syncWithoutDetaching($denials->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
    $event->update(['archived_at' => now()]);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    $event->update(['archived_at' => null, 'cancelled_at' => now()]);
    expect($notice->shouldSend($this->reader, 'mail'))->toBeFalse();
    Notification::assertNothingSent();
});

test('the existing Hub command releases due events before notices and reports transient failures', function (int $failed, int $exit) {
    $this->mock(HubEventScheduling::class)->shouldReceive('publishDue')->once()->globally()->ordered()->andReturn(['published' => 2, 'blocked' => 1, 'failed' => $failed]);
    $this->mock(HubNotices::class)->shouldReceive('deliver')->once()->globally()->ordered()->andReturn(0);
    $this->artisan('company-hub:deliver-notices')->expectsOutput('Hub events: 2 published, 1 blocked, '.$failed.' retrying.')->expectsOutput('0 hub notices delivered.')->assertExitCode($exit);
    Notification::assertNothingSent();
})->with([[0, 0], [1, 1]]);

test('the Hub delivery command remains registered for five minute releases with overlap and single server guards', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'company-hub:deliver-notices'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
    Notification::assertNothingSent();
});
