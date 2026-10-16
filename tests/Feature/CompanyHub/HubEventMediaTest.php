<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Models\HubEventDraftFile;
use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubEventMedia;
use App\Domain\CompanyHub\Services\HubEventPublishing;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Notification::fake();
    // Keep these file-backed fixtures independent of other isolated agent processes.
    ParallelTesting::resolveTokenUsing(fn () => 'hub_event_media_'.getmypid());
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
    $this->page = app(HubPublishing::class)->create($this->author, 'page', 'Private photo source', 'blank');
    $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $this->album = DB::table('hub_media_albums')->insertGetId(['owner_id' => $this->author->id, 'name' => 'Team moments', 'created_at' => now(), 'updated_at' => now()]);
    Storage::disk('private')->put('company-hub/event-media-source.png', $this->bytes);
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->author->id,
        'path' => 'company-hub/event-media-source.png', 'name' => 'Our afternoon.png', 'mime' => 'image/png', 'size' => strlen($this->bytes),
        'version' => 1, 'alt' => 'Colleagues sharing a table', 'caption' => 'The original library caption', 'album_id' => $this->album]);
    $this->payload = ['draft_key' => (string) Str::uuid(), 'actor_id' => $this->author->id, 'mode' => 'draft', 'title' => 'Our event',
        'event_type' => 'company', 'starts_at' => '2026-10-20T00:00:00Z', 'ends_at' => '2026-10-20T01:00:00Z', 'is_all_day' => false,
        'site_id' => $this->site->id, 'audience_type' => 'org', 'recurrence_timezone' => 'Pacific/Auckland', 'reminders' => []];
    $this->selection = [['media_id' => $this->media->id, 'version' => 1]];
    $this->hub = ['recap_title' => 'A shared afternoon', 'recap' => 'Thank you for making time.', 'cover_ref' => 'library:'.$this->media->id,
        'cover_alt' => 'Our event cover', 'gallery_refs' => ['library:'.$this->media->id]];
    $this->photo = fn ($name = 'Local photo.png') => UploadedFile::fake()->createWithContent($name, $this->bytes);
    $this->actingAs($this->author);
});

afterEach(function () {
    ParallelTesting::resolveTokenUsing(null);
});

test('versioned library copies stay draft private and publish independent metadata without source identities', function () {
    $this->post('/company-hub/event-drafts', [...$this->payload, 'mode' => 'draft', 'hub_library_images' => $this->selection,
        'hub_content' => [...$this->hub, 'photo_details' => [['ref' => 'library:'.$this->media->id, 'title' => 'Planting together', 'caption' => '']]]])
        ->assertRedirect()->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $file = $draft->files()->sole();
    $this->post('/company-hub/event-drafts/'.$draft->id, [...$this->payload, 'version' => 1, 'mode' => 'review',
        'hub_content' => ['recap' => 'Thank you for making time.', 'cover_ref' => 'file:-'.$file->id, 'cover_alt' => 'Our event cover', 'gallery_refs' => ['file:-'.$file->id]]])
        ->assertRedirect()->assertSessionHasNoErrors();
    $draft = $draft->fresh();
    $snapshot = $draft->payload;
    expect($file->path)->not->toBe($this->media->path)->and(Storage::disk('private')->get($file->path))->toBe($this->bytes)
        ->and($snapshot['hub_content']['photo_details'][0])->toEqual(['ref' => 'file:-'.$file->id, 'title' => 'Planting together',
            'alt' => 'Colleagues sharing a table', 'caption' => '', 'album' => 'Team moments'])
        ->and($snapshot)->not->toHaveKey('hub_library_images')->and(HrCalendarEvent::count())->toBe(0);
    Notification::assertNothingSent();
    $this->actingAs($this->reader)->get('/company-hub/event-draft-files/'.$file->id)->assertNotFound();
    $this->get('/company-hub/media/'.$this->media->id)->assertNotFound();
    $this->media->update(['removed_at' => now(), 'version' => 2, 'caption' => 'New library caption']);
    Storage::disk('private')->put($this->media->path, 'Changed source bytes');
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['version' => 2, 'action' => 'approve'])->assertOk();
    $event = HrCalendarEvent::sole();
    $published = $event->attachments()->sole();
    expect(Storage::disk('private')->get($published->path))->toBe($this->bytes)->and($published->path)->not->toBe($file->path)
        ->and($draft->fresh()->payload)->toEqual($snapshot);
    $this->travelTo(Carbon::parse('2026-10-21T00:00:00Z'));
    $gallery = app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader)[0]['extendedProps']['hubContent'];
    expect($gallery['recap_title'])->toBe('A shared afternoon')->and($gallery['gallery'][0]['title'])->toBe('Planting together')
        ->and($gallery['gallery'][0]['caption'])->toBe('')->and($gallery['gallery'][0]['album'])->toBe('Team moments')
        ->and($gallery['gallery'][0]['shared_by']['name'])->toBe($this->publisher->name)
        ->and($gallery['gallery'][0]['shared_at'])->toBe($published->created_at->toIso8601String())
        ->and(array_keys($gallery['gallery'][0]))->toBe(['id', 'name', 'url', 'title', 'alt', 'caption', 'album', 'shared_by', 'shared_at']);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$published->id)->assertOk();
    $this->get('/company-hub/media/'.$this->media->id)->assertNotFound();
});

test('canonical metadata uses authored gallery order retains omitted fields and hides future recap details', function () {
    $event = HrCalendarEvent::create(['created_by' => $this->author->id, 'title' => 'An ordered event', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00']);
    $url = '/hr/calendar/events/'.$event->id;
    $content = ['recap_title' => 'Our independent recap title', 'recap' => 'Our recap body', 'cover_ref' => 'upload:0', 'cover_alt' => 'A cover',
        'gallery_refs' => ['upload:1', 'upload:0'], 'photo_details' => [['ref' => 'upload:0', 'title' => 'First upload', 'alt' => 'First description', 'caption' => 'First caption', 'album' => 'An album'],
            ['ref' => 'upload:1', 'title' => 'Second upload', 'alt' => 'Second description', 'caption' => 'Second caption', 'album' => 'An album']]];
    $this->put($url, ['edit_version' => 1, 'hub_content' => $content, 'files' => [($this->photo)('First.png'), ($this->photo)('Second.png')]])->assertSessionHasNoErrors();
    $files = $event->attachments()->orderBy('id')->get();
    $future = app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader)[0]['extendedProps']['hubContent'];
    expect($future['recap_title'])->toBe('')->and($future['gallery'])->toBe([])->and($future['editor'])->toBeNull();
    $this->put($url, ['edit_version' => 2, 'hub_content' => ['recap' => 'Updated by an older editor', 'cover_ref' => 'file:'.$files[0]->id,
        'gallery_refs' => ['file:'.$files[1]->id, 'file:'.$files[0]->id]]])->assertSessionHasNoErrors();
    expect($event->fresh()->hub_content['recap_title'])->toBe($content['recap_title'])
        ->and($event->fresh()->hub_content['photo_details'][$files[0]->id]['caption'])->toBe('First caption');
    $this->travelTo(Carbon::parse('2026-10-21T00:00:00Z'));
    $past = app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader)[0]['extendedProps']['hubContent'];
    expect(array_column($past['gallery'], 'id'))->toBe([$files[1]->id, $files[0]->id])->and($past['gallery'][0]['alt'])->toBe('Second description');
    $this->put($url, ['edit_version' => 3, 'hub_content' => ['recap_title' => '', 'gallery_refs' => ['file:'.$files[0]->id],
        'photo_details' => [['ref' => 'file:'.$files[0]->id, 'caption' => null]]]])->assertSessionHasNoErrors();
    expect($event->fresh()->hub_content['recap_title'])->toBe('')->and($event->fresh()->hub_content['photo_details'][$files[0]->id]['caption'])->toBe('')
        ->and($event->fresh()->hub_content['photo_details'][$files[0]->id]['alt'])->toBe('First description');
    $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'company_hub.view')->pluck('id')
        ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    $hr = app(HrCalendarAggregator::class)->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader->fresh())[0]['extendedProps']['hubContent'];
    expect($hr['gallery'][0]['url'])->toBe('/hr/calendar/attachments/'.$files[0]->id.'/download')
        ->and($hr['gallery'][0]['shared_by']['profile_url'])->toBeNull();
});

test('source access versions removed images malformed metadata and actor changes cannot copy draft files', function () {
    $other = app(HubPublishing::class)->create($this->publisher, 'page', 'Another private source', 'blank');
    $outside = HubMedia::create(['hub_page_id' => $other->id, 'uploaded_by' => $this->publisher->id, 'path' => $this->media->path,
        'name' => 'Other photo.png', 'mime' => 'image/png', 'size' => strlen($this->bytes), 'version' => 1]);
    $invalid = [
        [['hub_library_images' => [['media_id' => $outside->id, 'version' => 1]]], 404],
        [['hub_library_images' => [['media_id' => $this->media->id, 'version' => 2]]], 409],
        [['actor_id' => $this->publisher->id], 409],
        [['hub_library_images' => []], 422],
        [['hub_content' => [...$this->hub, 'photo_details' => [['ref' => 'file:'.$outside->id, 'caption' => 'Unrelated photo']]]], 422],
        [['hub_content' => [...$this->hub, 'photo_details' => [['ref' => 'library:'.$this->media->id, 'url' => 'https://example.com/private.png']]]], 422],
        [['hub_library_images' => [['media_id' => 2147483648, 'version' => 1]]], 422],
    ];
    foreach ($invalid as [$changes, $status]) {
        $this->postJson('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'hub_library_images' => $this->selection, 'hub_content' => $this->hub, ...$changes])->assertStatus($status);
        expect(HubEventDraft::count())->toBe(0)->and(HubEventDraftFile::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([$this->media->path]);
    }
    $this->post('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub,
        'files' => array_map(fn () => ($this->photo)(), range(1, 10))])->assertSessionHasErrors('hub_library_images');
    expect(HubEventDraft::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([$this->media->path]);
    $this->media->update(['removed_at' => now()]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertConflict();
    $this->media->update(['removed_at' => null, 'mime' => 'application/pdf']);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertUnprocessable();
    $this->author->hrEmployeeProfile->update(['is_active' => false]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertNotFound();
    expect(HubEventDraft::count())->toBe(0);
});

test('canonical library reuse respects destination versions private readership and actual attribution privacy', function () {
    $event = HrCalendarEvent::create(['created_by' => $this->author->id, 'title' => 'Private reuse', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-12 00:00:00', 'ends_at' => '2026-10-12 01:00:00']);
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->author->id, 'rsvp_status' => 'none']);
    $url = '/hr/calendar/events/'.$event->id;
    $this->put($url, ['actor_id' => $this->author->id, 'edit_version' => 1, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertSessionHasNoErrors();
    $file = $event->attachments()->sole();
    expect($event->fresh()->hub_content['gallery_ids'])->toBe([$file->id])->and($file->uploaded_by)->toBe($this->author->id)
        ->and(Storage::disk('private')->get($file->path))->toBe($this->bytes)->and($file->path)->not->toBe($this->media->path);
    $this->putJson($url, ['actor_id' => $this->author->id, 'edit_version' => 1, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertUnprocessable();
    $this->putJson($url, ['actor_id' => $this->publisher->id, 'edit_version' => 2, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertConflict();
    expect($event->attachments()->count())->toBe(1);
    $this->put($url, ['actor_id' => $this->author->id, 'edit_version' => 2, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub,
        'files' => array_map(fn () => ($this->photo)(), range(1, 10))])->assertSessionHasErrors('hub_library_images');
    expect($event->attachments()->count())->toBe(1)->and($event->fresh()->edit_version)->toBe(2);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$file->id)->assertNotFound();
    $event->attendees()->delete();
    $this->author->hrEmployeeProfile->update(['is_active' => false]);
    $read = app(HrCalendarAggregator::class)->feed('2026-10-12', '2026-10-12', ['event'], [], $this->reader)[0]['extendedProps']['hubContent'];
    expect($read['gallery'][0]['shared_by'])->toBeNull()->and($read['gallery'][0]['shared_at'])->toBe($file->created_at->toIso8601String());
    $this->get('/company-hub/event-files/'.$file->id)->assertOk();
    $event->update(['archived_at' => now()]);
    $this->get('/company-hub/event-files/'.$file->id)->assertNotFound();
});

test('following splits remap per-photo details while retaining earlier and date-owned recaps', function () {
    $event = HrCalendarEvent::create(['created_by' => $this->author->id, 'title' => 'A recurring gathering', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $url = '/hr/calendar/events/'.$event->id;
    $this->put($url, ['actor_id' => $this->author->id, 'edit_version' => 1, 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertSessionHasNoErrors();
    $rootFile = $event->attachments()->sole();
    $before = $event->fresh()->hub_content;
    $this->put($url, ['edit_version' => 2, 'scope' => 'this', 'occurrence_date' => '2026-11-03', 'files' => [($this->photo)('Date-owned.png')],
        'hub_content' => ['recap_title' => 'Only this date', 'recap' => 'Date-owned recap', 'cover_ref' => 'file:'.$rootFile->id,
            'gallery_refs' => ['upload:0', 'file:'.$rootFile->id], 'photo_details' => [['ref' => 'upload:0', 'caption' => 'Only this date photo']]]])->assertSessionHasNoErrors();
    $child = HrCalendarEvent::where('recurrence_parent_id', $event->id)->sole();
    $owned = $child->attachments()->sole();
    $this->put($url, ['edit_version' => 3, 'scope' => 'following', 'occurrence_date' => '2026-10-27'])->assertSessionHasNoErrors();
    $next = HrCalendarEvent::whereNull('recurrence_parent_id')->whereKeyNot($event->id)->sole();
    $copy = $next->attachments()->sole();
    expect($copy->id)->not->toBe($rootFile->id)->and($copy->path)->not->toBe($rootFile->path)
        ->and($next->hub_content['photo_details'][$copy->id]['caption'])->toBe('The original library caption')
        ->and($child->fresh()->hub_content['gallery_ids'])->toBe([$owned->id, $copy->id])
        ->and($child->fresh()->hub_content['photo_details'][$copy->id]['album'])->toBe('Team moments')
        ->and($child->fresh()->hub_content['photo_details'][$owned->id]['caption'])->toBe('Only this date photo')
        ->and($child->fresh()->hub_content['recap_title'])->toBe('Only this date')->and($event->fresh()->hub_content)->toEqual($before);
});

test('later copy failures roll back metadata records audits and every private copy for drafts and canonical events', function () {
    $second = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->author->id, 'path' => $this->media->path,
        'name' => 'Fail.png', 'mime' => 'image/png', 'size' => strlen($this->bytes), 'version' => 1]);
    $selections = [...$this->selection, ['media_id' => $second->id, 'version' => 1]];
    $this->withoutExceptionHandling();
    foreach ([HubEventDraftFile::class, HrCalendarEventAttachment::class] as $type) {
        $listener = 'eloquent.creating: '.$type;
        Event::listen($listener, function ($file) {
            if ($file->original_name === 'Fail.png') {
                throw new RuntimeException('Simulated second media copy failure');
            }
        });
        try {
            if ($type === HubEventDraftFile::class) {
                $this->post('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $selections, 'hub_content' => $this->hub]);
            } else {
                $this->post('/hr/calendar/events', [...$this->payload, 'hub_library_images' => $selections, 'hub_content' => $this->hub]);
            }
            test()->fail('The copy failure must escape and roll back.');
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('Simulated second media copy failure');
        } finally {
            Event::forget($listener);
        }
        expect(HubEventDraft::count())->toBe(0)->and(HrCalendarEvent::count())->toBe(0)
            ->and(HubEventDraftFile::count())->toBe(0)->and(HrCalendarEventAttachment::count())->toBe(0)
            ->and(Storage::disk('private')->allFiles())->toBe([$this->media->path])
            ->and(DB::table('audit_logs')->where('action', 'hub.event_media.reused')->count())->toBe(0);
    }
    Notification::assertNothingSent();
});

test('scheduled approval freezes owned photo copies and cannot edit them through reviewed draft or direct calendar routes', function () {
    $this->actingAs($this->publisher)->post('/company-hub/event-drafts', [...$this->payload, 'actor_id' => $this->publisher->id,
        'mode' => 'schedule', 'publish_at' => '2026-10-15T00:00:00Z', 'hub_library_images' => $this->selection, 'hub_content' => $this->hub])->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $snapshot = $draft->payload;
    $file = $draft->files()->sole();
    $this->postJson('/company-hub/event-drafts/'.$draft->id, [...$this->payload, 'actor_id' => $this->publisher->id, 'version' => $draft->version,
        'hub_library_images' => $this->selection, 'hub_content' => [...$this->hub, 'recap_title' => 'Unreviewed replacement']])->assertConflict();
    $this->media->update(['version' => 2, 'removed_at' => now(), 'caption' => 'New source caption']);
    Storage::disk('private')->put($this->media->path, 'Changed source');
    $this->travelTo(Carbon::parse('2026-10-15T00:00:00Z'));
    expect(app(HubEventPublishing::class)->releaseScheduled($draft->id, $draft->version))->toBeTrue();
    $event = HrCalendarEvent::sole();
    expect($event->hub_content['recap_title'])->toBe('A shared afternoon')
        ->and($event->hub_content['photo_details'][$event->attachments()->sole()->id]['caption'])->toBe('The original library caption')
        ->and(Storage::disk('private')->get($event->attachments()->sole()->path))->toBe($this->bytes)
        ->and($draft->fresh()->payload)->toEqual($snapshot)->and(Storage::disk('private')->get($file->path))->toBe($this->bytes);
    $this->putJson('/hr/calendar/events/'.$event->id, ['edit_version' => $event->edit_version, 'hub_content' => ['recap_title' => 'Bypassed release']])->assertUnprocessable();
    expect($event->fresh()->hub_content['recap_title'])->toBe('A shared afternoon');
    $written = [];
    expect(fn () => app(HubEventMedia::class)->copyToEvent($this->publisher, $event, $this->selection, $written))->toThrow(ValidationException::class);
    expect($written)->toBe([])->and($event->attachments()->count())->toBe(1);
});
