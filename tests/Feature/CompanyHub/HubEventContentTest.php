<?php

use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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
    $this->travelTo(Carbon::parse('2026-10-13T00:00:00Z'));
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Our gathering', 'event_type' => 'company',
        'site_id' => $this->site->id, 'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $this->url = '/hr/calendar/events/'.$this->event->id;
    $this->content = ['host_id' => $this->reader->id, 'agenda' => "Welcome\nShare ideas", 'recap' => 'A useful afternoon together.', 'story_id' => null,
        'cover_ref' => 'upload:0', 'cover_alt' => 'Colleagues at the gathering', 'gallery_refs' => ['upload:0']];
    $this->photo = fn () => UploadedFile::fake()->createWithContent('gathering.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    Storage::fake('private');
    Notification::fake();
    $this->actingAs($this->editor);
});

test('canonical event photos and details save together and future recap text stays editor only', function () {
    $this->put($this->url, ['edit_version' => 1, 'hub_content' => $this->content, 'files' => [($this->photo)()]])->assertRedirect()->assertSessionHasNoErrors();
    $file = $this->event->attachments()->sole();
    expect($this->event->fresh()->hub_content['cover_id'])->toBe($file->id)->and($this->event->fresh()->hub_content['gallery_ids'])->toBe([$file->id]);
    $feed = app(HrCalendarAggregator::class);
    $staff = $feed->feed('2026-10-20', '2026-10-20', ['event'], [], $this->reader)[0]['extendedProps']['hubContent'];
    expect($staff['cover']['url'])->toBe('/company-hub/event-files/'.$file->id)->and($staff['host']['id'])->toBe($this->reader->id)
        ->and($staff['agenda'])->toBe("Welcome\nShare ideas")->and($staff['recap'])->toBe('')->and($staff['gallery'])->toBe([])->and($staff['editor'])->toBeNull();
    $editor = $feed->feed('2026-10-20', '2026-10-20', ['event'], [], $this->editor)[0]['extendedProps']['hubContent'];
    expect($editor['editor']['recap'])->toBe($this->content['recap'])->and($editor['editor']['cover_ref'])->toBe('file:'.$file->id);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$file->id)->assertOk();
    $this->event->update(['archived_at' => now()]);
    $this->get('/company-hub/event-files/'.$file->id)->assertNotFound();
});

test('a bad image selection rolls back uploaded bytes metadata and a new date exception', function () {
    $this->postJson($this->url, ['_method' => 'put', 'edit_version' => 1, 'scope' => 'this', 'occurrence_date' => '2026-10-20', 'title' => 'Must not persist',
        'files' => [($this->photo)()], 'hub_content' => [...$this->content, 'cover_ref' => 'upload:9']])->assertUnprocessable()->assertJsonValidationErrors('hub_content.cover_ref');
    expect($this->event->fresh()->title)->toBe('Our gathering')->and($this->event->fresh()->edit_version)->toBe(1)
        ->and(HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
    $this->putJson($this->url, ['edit_version' => 1, 'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'Not a photo')], 'hub_content' => $this->content])->assertUnprocessable()->assertJsonValidationErrors('hub_content.cover_ref');
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('unrelated private photos unpublished stories and inaccessible hosts cannot be assigned', function () {
    $other = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Other event', 'event_type' => 'company', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    $file = $other->attachments()->create(['disk' => 'private', 'path' => 'private.png', 'original_name' => 'private.png', 'mime' => 'image/png', 'size' => 1, 'uploaded_by' => $this->editor->id]);
    $draft = app(HubPublishing::class)->create($this->editor, 'news', 'Unpublished story');
    $unavailable = User::factory()->create();
    foreach ([['cover_ref' => 'file:'.$file->id], ['story_id' => $draft->id], ['host_id' => $unavailable->id]] as $bad) {
        $this->putJson($this->url, ['edit_version' => 1, 'hub_content' => [...$this->content, 'cover_ref' => '', 'gallery_refs' => [], ...$bad]])->assertUnprocessable();
    }
    expect($this->event->fresh()->hub_content)->toBeNull()->and($this->event->fresh()->edit_version)->toBe(1);
});

test('date overrides and following splits remap selected photos without changing earlier recaps', function () {
    $this->put($this->url, ['edit_version' => 1, 'hub_content' => $this->content, 'files' => [($this->photo)()]])->assertRedirect()->assertSessionHasNoErrors();
    $original = $this->event->attachments()->sole();
    $this->put($this->url, ['edit_version' => 2, 'scope' => 'this', 'occurrence_date' => '2026-11-03', 'files' => [($this->photo)()],
        'hub_content' => [...$this->content, 'cover_ref' => 'file:'.$original->id, 'gallery_refs' => ['file:'.$original->id, 'upload:0'], 'recap' => 'Only this date']])->assertRedirect()->assertSessionHasNoErrors();
    $child = HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->sole();
    $own = $child->attachments()->sole();
    $this->put($this->url, ['edit_version' => 3, 'scope' => 'following', 'occurrence_date' => '2026-10-27'])->assertRedirect()->assertSessionHasNoErrors();
    $next = HrCalendarEvent::whereNull('recurrence_parent_id')->whereKeyNot($this->event->id)->sole();
    $copy = $next->attachments()->sole();
    expect($copy->id)->not->toBe($original->id)->and($copy->path)->not->toBe($original->path)
        ->and($next->hub_content['cover_id'])->toBe($copy->id)->and($child->fresh()->hub_content['gallery_ids'])->toBe([$copy->id, $own->id])
        ->and($child->fresh()->hub_content['recap'])->toBe('Only this date')->and($this->event->fresh()->hub_content['cover_id'])->toBe($original->id);
    $this->putJson('/hr/calendar/events/'.$next->id, ['edit_version' => 3, 'hub_content' => [...$this->content, 'cover_ref' => '', 'gallery_refs' => []]])->assertUnprocessable()->assertJsonValidationErrors('edit_version');
});

test('history uses New Zealand year boundaries and waits for all day events to finish', function () {
    foreach ([['NZ new year', '2025-12-31 11:05:00', false], ['Previous NZ year', '2025-12-31 10:30:00', false], ['Today all day', '2026-10-13 00:00:00', true], ['Yesterday all day', '2026-10-12 00:00:00', true]] as [$title, $end, $allDay]) {
        HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => $title, 'event_type' => 'company', 'site_id' => $this->site->id,
            'starts_at' => $end, 'ends_at' => $end, 'is_all_day' => $allDay, 'hub_content' => ['recap' => 'Shared afterwards']]);
    }
    $response = $this->actingAs($this->reader)->getJson('/company-hub/events/history?year=2026')->assertOk();
    expect(collect($response->json('events'))->pluck('title')->all())->toBe(['Yesterday all day', 'NZ new year']);
    expect($response->json('events.0.extendedProps.hubContent.recap'))->toBe('Shared afterwards');
    $this->getJson('/company-hub/events/history?year=2025')->assertOk()->assertJsonPath('events.0.title', 'Previous NZ year');
    $today = app(HrCalendarAggregator::class)->feed('2026-10-13', '2026-10-13', ['event'], [], $this->reader)[0]['extendedProps']['hubContent'];
    expect($today['past'])->toBeFalse()->and($today['recap'])->toBe('');
    $this->getJson('/company-hub/events/history?year=2027')->assertUnprocessable();
});

test('past content rechecks story publication and private event audience on every read', function () {
    $publisher = app(HubPublishing::class);
    $story = $publisher->create($this->editor, 'news', 'The gathering story');
    $publisher->publish($this->editor, $story, 1, null, null);
    $this->event->update(['rrule' => null, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    $this->put($this->url, ['edit_version' => 1, 'hub_content' => [...$this->content, 'story_id' => $story->id], 'files' => [($this->photo)()]])->assertRedirect()->assertSessionHasNoErrors();
    $response = $this->actingAs($this->reader)->getJson('/company-hub/events/history?year=2026')->assertOk();
    $response->assertJsonPath('events.0.extendedProps.hubContent.story.url', '/company-hub/read/'.$story->slug)->assertJsonPath('events.0.extendedProps.hubContent.editor', null);
    expect($response->json('events.0.extendedProps.hubContent.gallery'))->toHaveCount(1);
    $story->update(['archived_at' => now()]);
    $this->getJson('/company-hub/events/history?year=2026')->assertOk()->assertJsonPath('events.0.extendedProps.hubContent.story', null);
    $this->actingAs($this->editor)->put($this->url, ['edit_version' => 2, 'hub_content' => [...$this->content, 'story_id' => null, 'cover_ref' => '', 'gallery_refs' => []]])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->event->fresh()->hub_content['story_id'])->toBe($story->id);
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $this->actingAs($this->reader)->getJson('/company-hub/events/history?year=2026')->assertOk()->assertJsonCount(0, 'events');
    $this->get('/company-hub/event-files/'.$this->event->attachments()->sole()->id)->assertNotFound();
    $this->reader->hrEmployeeProfile->update(['is_active' => false]);
    $this->getJson('/company-hub/events/history?year=2026')->assertForbidden();
});
