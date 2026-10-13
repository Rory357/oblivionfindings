<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Services\HubPlanning;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAggregator;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $role = Role::where('name', 'hr')->firstOrFail();
    $role->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['hr.calendar.view', 'calendar.create', 'calendar.manage', 'calendar.manage_recurring'])->pluck('id'));
    $this->publisher = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->publisher->roles()->attach($role);
    $this->site = ensureCanonicalHrStaffProfile($this->publisher);
    $this->author = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->author->roles()->attach($role);
    $this->author->permissionOverrides()->attach(Permission::whereIn('key', ['company_hub.publish', 'company_hub.edit_all'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    ensureCanonicalHrStaffProfile($this->author, $this->site);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->travelTo(Carbon::parse('2026-10-13T00:00:00Z'));
    $this->payload = ['draft_key' => (string) Str::uuid(), 'mode' => 'draft', 'title' => 'Our company afternoon', 'description' => 'A useful afternoon together.',
        'event_type' => 'company', 'starts_at' => '2026-10-20T00:00:00Z', 'ends_at' => '2026-10-20T01:00:00Z', 'is_all_day' => false,
        'site_id' => $this->site->id, 'department_id' => null, 'audience_type' => 'org', 'recurrence_timezone' => 'Pacific/Auckland',
        'reminders' => [['offset_minutes' => 60, 'channel' => 'notification']], 'location' => 'Meeting room'];
    $this->photo = fn () => UploadedFile::fake()->createWithContent('afternoon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    Storage::fake('private');
    Notification::fake();
    $this->actingAs($this->author)->from('/company-hub?view=events');
});

test('drafts and uploaded images stay private until the exact reviewed version is approved', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review', 'files' => [($this->photo)()], 'hub_content' => ['cover_ref' => 'upload:0', 'cover_alt' => 'People together', 'gallery_refs' => ['upload:0']]])->assertRedirect()->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $file = $draft->files()->sole();
    expect($draft->state)->toBe('review')->and(HrCalendarEvent::count())->toBe(0)->and($draft->payload['hub_content']['cover_ref'])->toBe('file:-'.$file->id);
    Notification::assertNothingSent();
    $this->get('/company-hub/event-draft-files/'.$file->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->reader)->get('/company-hub/event-drafts')->assertForbidden();
    $this->get('/company-hub/event-draft-files/'.$file->id)->assertNotFound();
    $this->actingAs($this->author)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertForbidden();
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertOk();
    $event = HrCalendarEvent::sole();
    expect((bool) $event->hub_managed)->toBeTrue()->and($event->created_by)->toBe($this->author->id)->and($event->hub_content['cover_alt'])->toBe('People together');
    $live = $event->attachments()->sole();
    expect(Storage::disk('private')->get($live->path))->toBe(Storage::disk('private')->get($file->path));
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$live->id)->assertOk();
    $this->get('/company-hub/event-draft-files/'.$file->id)->assertNotFound();
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertConflict();
    expect(HrCalendarEvent::count())->toBe(1);
});

test('return feedback withdrawal and stale draft saves preserve the submitted version', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review'])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $url = '/company-hub/event-drafts/'.$draft->id;
    $this->postJson($url, [...$this->payload, 'version' => 1, 'title' => 'A hidden replacement'])->assertConflict();
    $this->actingAs($this->publisher)->postJson($url.'/transition', ['version' => 1, 'action' => 'return', 'feedback' => 'Please clarify the location.'])->assertOk();
    expect($draft->fresh()->feedback)->toBe('Please clarify the location.');
    $this->actingAs($this->author)->post($url, [...$this->payload, 'version' => 2, 'mode' => 'review', 'location' => 'Main meeting room'])->assertSessionHasNoErrors();
    $this->postJson($url.'/transition', ['version' => 3, 'action' => 'withdraw'])->assertOk();
    $this->actingAs($this->publisher)->postJson($url.'/transition', ['version' => 3, 'action' => 'approve'])->assertConflict();
    expect($draft->fresh()->state)->toBe('draft')->and(HrCalendarEvent::count())->toBe(0);
});

test('publication history avoids duplicate promoted files and draft file reads remain bound after archive', function () {
    $this->actingAs($this->publisher)->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $published = HubEventDraft::sole();
    $event = HrCalendarEvent::sole();
    $file = $event->attachments()->sole();
    $this->getJson('/company-hub/event-drafts')->assertJsonCount(1, 'drafts.0.initial.attachments')->assertJsonPath('drafts.0.new_event', true);
    $this->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'source_event_id' => $event->id, 'edit_version' => $event->edit_version])->assertSessionHasNoErrors();
    $draft = HubEventDraft::latest('id')->first();
    $url = '/company-hub/event-drafts/'.$draft->id.'/event-files/'.$file->id;
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/company-hub/event-drafts/'.$published->id.'/event-files/'.$file->id)->assertNotFound();
    $this->delete('/hr/calendar/events/'.$event->id, ['edit_version' => $event->edit_version, 'scope' => 'all'])->assertRedirect()->assertSessionHasNoErrors();
    $this->get($url)->assertOk();
    $this->get('/company-hub/event-files/'.$file->id)->assertNotFound();
    $this->actingAs($this->reader)->get($url)->assertNotFound();
});

test('multipart draft values reload as typed editor fields and appear only in an authorised editorial calendar', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'is_all_day' => '0', 'site_id' => (string) $this->site->id,
        'registration_enabled' => '0', 'registration_capacity' => '25', 'hub_content' => ['host_id' => (string) $this->author->id, 'gallery_refs' => []]])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $this->getJson('/company-hub/event-drafts')->assertOk()->assertJsonPath('drafts.0.initial.is_all_day', false)
        ->assertJsonPath('drafts.0.initial.registration_enabled', false)->assertJsonPath('drafts.0.initial.site_id', $this->site->id)
        ->assertJsonPath('drafts.0.initial.hub_content.host_id', $this->author->id);
    $items = app(HubPlanning::class)->workspace($this->author, '2026-10')['items'];
    expect(collect($items)->firstWhere('key', 'event-draft:'.$draft->id)['status'])->toBe('Private event draft');
    expect(HrCalendarEvent::count())->toBe(0);
});

test('saving edits leaves the published event unchanged and direct calendar edits cannot bypass approval', function () {
    $this->actingAs($this->publisher)->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish'])->assertSessionHasNoErrors();
    $event = HrCalendarEvent::sole();
    $this->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'source_event_id' => $event->id, 'edit_version' => $event->edit_version, 'title' => 'Prepared change'])->assertSessionHasNoErrors();
    $draft = HubEventDraft::latest('id')->first();
    expect($event->fresh()->title)->toBe($this->payload['title']);
    expect(app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader)[0]['title'])->toBe($this->payload['title']);
    $this->putJson('/hr/calendar/events/'.$event->id, ['edit_version' => $event->edit_version, 'title' => 'Bypass', 'hub.approved_publication' => true])->assertUnprocessable();
    $this->postJson('/hr/calendar/events/'.$event->id.'/attachments', ['edit_version' => $event->edit_version, 'file' => ($this->photo)()])->assertUnprocessable();
    $this->post('/company-hub/event-drafts/'.$draft->id, [...$this->payload, 'version' => 1, 'mode' => 'publish', 'title' => 'Prepared change'])->assertSessionHasNoErrors();
    expect($event->fresh()->title)->toBe('Prepared change');
});

test('publication rechecks current audience author access and live edit versions', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review'])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $this->author->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertConflict();
    expect(HrCalendarEvent::count())->toBe(0);
    $this->author->hrEmployeeProfile->update(['is_active' => true]);
    $this->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertOk();
    $event = HrCalendarEvent::sole();
    $this->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'source_event_id' => $event->id, 'edit_version' => $event->edit_version, 'mode' => 'review'])->assertSessionHasNoErrors();
    $next = HubEventDraft::latest('id')->first();
    $event->increment('edit_version');
    $this->postJson('/company-hub/event-drafts/'.$next->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertConflict();
    expect($next->fresh()->state)->toBe('review');
});

test('foreign draft files inaccessible sites and duplicate creation keys are denied without leakage', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $file = $draft->files()->sole();
    $this->postJson('/company-hub/event-drafts', $this->payload)->assertConflict();
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'hub_content' => ['cover_ref' => 'file:-'.$file->id]])->assertUnprocessable();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'site_id' => $outside->id])->assertUnprocessable();
    expect(HubEventDraft::count())->toBe(1)->and(Storage::disk('private')->allFiles())->toHaveCount(1);
    $this->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'archive'])->assertOk();
    expect($draft->fresh()->archived_at)->not->toBeNull();
    $this->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 2, 'action' => 'restore'])->assertOk();
    expect($draft->fresh()->archived_at)->toBeNull();
});

test('a failed canonical publication rolls back promoted files and preserves the private draft', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review', 'files' => [($this->photo)()], 'hub_content' => ['cover_ref' => 'upload:0', 'host_id' => 999999]])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertUnprocessable();
    expect($draft->fresh()->state)->toBe('review')->and(HrCalendarEvent::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toHaveCount(1);
    Notification::assertNothingSent();
});

test('publishing a following draft preserves registrations and earlier published content', function () {
    $this->actingAs($this->publisher)->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'publish', 'rrule' => 'FREQ=WEEKLY;COUNT=3'])->assertSessionHasNoErrors();
    $root = HrCalendarEvent::sole();
    $root->forceFill(['registration_enabled' => true, 'registration_capacity' => 10, 'registration_version' => 4])->save();
    DB::table('hr_calendar_registrations')->insert(['event_id' => $root->id, 'user_id' => $this->reader->id, 'occurrence_date' => '2026-10-27', 'status' => 'going', 'created_at' => now(), 'updated_at' => now()]);
    $this->post('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'mode' => 'publish', 'source_event_id' => $root->id, 'edit_version' => $root->edit_version,
        'registration_enabled' => true, 'registration_capacity' => 12, 'registration_version' => 4,
        'scope' => 'following', 'occurrence_date' => '2026-10-27', 'rrule' => 'FREQ=WEEKLY;COUNT=2', 'starts_at' => '2026-10-27T00:00:00Z', 'ends_at' => '2026-10-27T01:00:00Z', 'title' => 'Next chapter'])->assertSessionHasNoErrors();
    $next = HrCalendarEvent::latest('id')->first();
    expect($next->id)->not->toBe($root->id)->and((bool) $next->hub_managed)->toBeTrue()->and($root->fresh()->title)->toBe($this->payload['title'])
        ->and(DB::table('hr_calendar_registrations')->value('event_id'))->toBe($next->id)->and((int) $next->registration_capacity)->toBe(12)->and((int) $root->fresh()->registration_capacity)->toBe(10);
});

test('a late approval audit failure rolls back publication and cleans copied files', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review', 'files' => [($this->photo)()]])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    Event::listen('eloquent.creating: '.AuditLog::class, function ($audit) {
        if ($audit->action === 'hub.event_draft.approve') {
            throw new RuntimeException('Simulated approval receipt failure');
        }
    });
    $this->withoutExceptionHandling();
    try {
        $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve']);
        test()->fail('The approval audit must be atomic.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated approval receipt failure');
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
    expect(HrCalendarEvent::count())->toBe(0)->and($draft->fresh()->state)->toBe('review')->and(Storage::disk('private')->allFiles())->toHaveCount(1);
    Notification::assertNothingSent();
});

test('registration authority and version are checked before publication and single dates cannot replace series settings', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'review', 'registration_enabled' => true, 'registration_capacity' => 10])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertOk();
    $event = HrCalendarEvent::sole();
    $this->actingAs($this->author)->putJson('/company-hub/events/'.$event->id.'/registration', ['enabled' => false, 'capacity' => null, 'version' => $event->registration_version, 'date' => '2026-10-20'])->assertForbidden();
    $base = [...$this->payload, 'draft_key' => (string) Str::uuid(), 'source_event_id' => $event->id, 'edit_version' => $event->edit_version, 'registration_enabled' => true, 'registration_capacity' => 12, 'registration_version' => $event->registration_version];
    $this->postJson('/company-hub/event-drafts', [...$base, 'scope' => 'this', 'occurrence_date' => '2026-10-20'])->assertUnprocessable();
    $this->post('/company-hub/event-drafts', [...$base, 'mode' => 'review'])->assertSessionHasNoErrors();
    $next = HubEventDraft::latest('id')->first();
    $event->increment('registration_version');
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$next->id.'/transition', ['version' => 1, 'action' => 'approve'])->assertConflict();
    expect((int) $event->fresh()->registration_capacity)->toBe(10)->and($next->fresh()->state)->toBe('review');
});
