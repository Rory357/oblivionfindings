<?php

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Models\HubEventDraftFile;
use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubEventPublishing;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Services\HrCalendarAggregator;
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
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    ParallelTesting::resolveTokenUsing(fn () => 'hub_event_documents_'.getmypid());
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
    $this->page = app(HubPublishing::class)->create($this->author, 'page', 'Private document source', 'blank');
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $this->pdf = "%PDF-1.7\n% A work document\n";
    $this->packageBytes = function (string $extension, array $extra = []): string {
        $parts = ['docx' => ['word/document.xml', 'wordprocessingml.document'], 'xlsx' => ['xl/workbook.xml', 'spreadsheetml.sheet'], 'pptx' => ['ppt/presentation.xml', 'presentationml.presentation']];
        $path = tempnam(sys_get_temp_dir(), 'hub-event-package-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$parts[$extension][0].'" ContentType="application/vnd.openxmlformats-officedocument.'.$parts[$extension][1].'.main+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<Relationships/>');
            $zip->addFromString($parts[$extension][0], '<document/>');
            foreach ($extra as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    };
    $this->source = function (string $extension, ?string $bytes = null, ?string $name = null): HubMedia {
        $bytes ??= match ($extension) {
            'pdf' => $this->pdf,
            'png' => $this->png,
            default => ($this->packageBytes)($extension),
        };
        $path = 'company-hub/event-document-sources/'.Str::uuid().'.'.$extension;
        Storage::disk('private')->put($path, $bytes);

        return HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->author->id,
            'path' => $path, 'name' => $name ?? 'Work file.'.$extension, 'mime' => array_search($extension, HubMediaLibrary::MIME_EXTENSIONS, true),
            'size' => strlen($bytes), 'version' => 1, 'caption' => 'Library photo caption', 'alt' => 'A shared photograph']);
    };
    $this->payload = ['draft_key' => (string) Str::uuid(), 'actor_id' => $this->author->id, 'mode' => 'draft', 'title' => 'An event with documents',
        'event_type' => 'company', 'starts_at' => '2026-10-20T00:00:00Z', 'ends_at' => '2026-10-20T01:00:00Z', 'is_all_day' => false,
        'site_id' => $this->site->id, 'audience_type' => 'org', 'recurrence_timezone' => 'Pacific/Auckland', 'reminders' => []];
    $this->select = fn (HubMedia $media) => ['media_id' => $media->id, 'version' => 1];
    $this->actingAs($this->author);
});

afterEach(function () {
    ParallelTesting::resolveTokenUsing(null);
});

test('event library documents retain independently reviewed bytes and safe reader metadata', function (string $extension) {
    // A renamed display title must not defeat Office package validation when approval promotes the staged file.
    $source = ($this->source)($extension, null, 'Renamed work document');
    $bytes = Storage::disk('private')->get($source->path);
    $this->post('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertRedirect()->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $file = $draft->files()->sole();
    $dto = app(HubEventPublishing::class)->serialize($draft)['initial']['attachments'][0];
    expect($file->path)->not->toBe($source->path)->and($file->mime)->toBe($source->mime)
        ->and(Storage::disk('private')->get($file->path))->toBe($bytes)
        ->and($dto['id'])->toBe(-$file->id)->and($dto['download_url'])->toBe('/company-hub/event-draft-files/'.$file->id)
        ->and($dto['preview_url'])->toBe($extension === 'pdf' ? '/company-hub/event-draft-files/'.$file->id.'?preview=1' : null)
        ->and($dto)->not->toHaveKeys(['path', 'media_id', 'document_id', 'uploaded_by'])
        ->and($draft->payload['hub_content']['photo_details'] ?? [])->toBe([])->and(HrCalendarEvent::count())->toBe(0);
    $this->get($dto['download_url'])->assertOk()->assertDownload('Renamed work document');
    $this->get($dto['download_url'].'?preview=1')->assertStatus($extension === 'pdf' ? 200 : 404);
    $this->actingAs($this->reader)->get($dto['download_url'])->assertNotFound();
    $this->get('/company-hub/media/'.$source->id)->assertNotFound();
    $this->actingAs($this->author)->post('/company-hub/event-drafts/'.$draft->id, [...$this->payload, 'version' => 1, 'mode' => 'review'])->assertRedirect()->assertSessionHasNoErrors();
    $snapshot = $draft->fresh()->payload;
    Notification::assertNothingSent();
    $source->update(['removed_at' => now(), 'version' => 2]);
    Storage::disk('private')->put($source->path, 'A changed library source');
    $this->actingAs($this->publisher)->postJson('/company-hub/event-drafts/'.$draft->id.'/transition', ['actor_id' => $this->publisher->id, 'version' => 2, 'action' => 'approve'])->assertOk();
    $event = HrCalendarEvent::sole();
    $published = $event->attachments()->sole();
    expect($published->path)->not->toBe($file->path)->and($published->mime)->toBe($source->mime)
        ->and(Storage::disk('private')->get($published->path))->toBe($bytes)->and($draft->fresh()->payload)->toEqual($snapshot)
        ->and($event->hub_content['photo_details'] ?? [])->toBe([]);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$published->id)->assertOk()->assertDownload('Renamed work document');
    $this->get('/company-hub/event-files/'.$published->id.'?preview=1')->assertStatus($extension === 'pdf' ? 200 : 404);
    $feed = collect(app(HubFeed::class)->forUser($this->reader)['events'])->firstWhere('extendedProps.eventId', $event->id);
    $attachment = $feed['extendedProps']['attachments'][0];
    expect($attachment['url'])->toBe('/company-hub/event-files/'.$published->id)->and($attachment['download_url'])->toBe($attachment['url'])
        ->and($attachment['preview_url'])->toBe($extension === 'pdf' ? $attachment['url'].'?preview=1' : null)
        ->and($attachment)->not->toHaveKeys(['path', 'media_id', 'document_id']);
    $this->get('/company-hub/media/'.$source->id)->assertNotFound();
})->with(['pdf', 'docx', 'xlsx', 'pptx']);

test('mixed library selections give photo controls only images and retain combined file limits', function () {
    $image = ($this->source)('png');
    $pdf = ($this->source)('pdf');
    $selections = [($this->select)($image), ($this->select)($pdf)];
    $baseline = Storage::disk('private')->allFiles();
    foreach (['cover_ref' => 'library:'.$pdf->id, 'gallery_refs' => ['library:'.$pdf->id], 'photo_details' => [['ref' => 'library:'.$pdf->id, 'caption' => 'Not a photo']]] as $field => $value) {
        $this->postJson('/company-hub/event-drafts', [...$this->payload, 'draft_key' => (string) Str::uuid(), 'hub_library_images' => $selections, 'hub_content' => [$field => $value]])->assertUnprocessable();
        expect(HubEventDraft::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toEqual($baseline);
    }
    $this->post('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $selections,
        'files' => array_map(fn () => UploadedFile::fake()->createWithContent('Extra.pdf', $this->pdf), range(1, 9))])->assertSessionHasErrors('hub_library_images');
    expect(HubEventDraft::count())->toBe(0);
    $this->post('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $selections,
        'hub_content' => ['cover_ref' => 'library:'.$image->id, 'gallery_refs' => ['library:'.$image->id]]])->assertRedirect()->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $files = $draft->files()->orderBy('id')->get();
    expect($files)->toHaveCount(2)->and($draft->payload['hub_content']['cover_ref'])->toBe('file:-'.$files[0]->id)
        ->and($draft->payload['hub_content']['photo_details'])->toHaveCount(1)
        ->and($draft->payload['hub_content']['photo_details'][0]['ref'])->toBe('file:-'.$files[0]->id);
    $this->postJson('/company-hub/event-drafts/'.$draft->id, [...$this->payload, 'version' => 2, 'hub_library_images' => [($this->select)($pdf)]])->assertConflict();
    expect($draft->files()->count())->toBe(2);
});

test('event document copying rejects source readers stale removed unsafe bytes and withdrawn site or staff access', function () {
    $source = ($this->source)('pdf');
    $baseline = Storage::disk('private')->allFiles();
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [['media_id' => $source->id, 'version' => 2]]])->assertConflict();
    $source->update(['removed_at' => now()]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertConflict();
    $source->update(['removed_at' => null]);
    Storage::disk('private')->put($source->path, '<html>Disguised PDF</html>');
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertUnprocessable();
    Storage::disk('private')->put($source->path, str_repeat('x', 10485761));
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertUnprocessable();
    Storage::disk('private')->put($source->path, $this->pdf);
    $unsafe = ($this->source)('docx', ($this->packageBytes)('docx', ['word/vbaProject.bin' => 'macro']));
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($unsafe)]])->assertUnprocessable();
    $other = app(HubPublishing::class)->create($this->publisher, 'page', 'Readable source owned by someone else', 'blank');
    $other = app(HubPublishing::class)->save($this->publisher, $other, [...$other->draft, 'sections' => [['id' => 'intro', 'columns' => 1,
        'blocks' => [['id' => 'intro-text', 'type' => 'text', 'title' => 'Published instructions', 'body' => 'A reader may read this page without copying its files.']]]]], 1);
    app(HubPublishing::class)->publish($this->publisher, $other, 2, null, null);
    expect(app(HubAccess::class)->visibleRevision($this->author, $other->fresh()))->not->toBeNull();
    $source->update(['hub_page_id' => $other->id]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertNotFound();
    $source->update(['hub_page_id' => $this->page->id]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'actor_id' => $this->publisher->id, 'hub_library_images' => [($this->select)($source)]])->assertConflict();
    $draftContent = $this->page->draft;
    $draftContent['audience'] = ['type' => 'sites', 'ids' => [2147483647]];
    $this->page->update(['draft' => $draftContent]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertNotFound();
    $this->page->update(['draft' => [...$draftContent, 'audience' => ['type' => 'all', 'ids' => []]]]);
    $this->author->hrEmployeeProfile->update(['is_active' => false]);
    $this->postJson('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => [($this->select)($source)]])->assertNotFound();
    expect(HubEventDraft::count())->toBe(0)->and(HubEventDraftFile::count())->toBe(0)->and(HrCalendarEventAttachment::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toEqual(collect([...$baseline, $unsafe->path])->sort()->values()->all());
    Notification::assertNothingSent();
});

test('canonical recurring document reuse binds the effective scope and preserves earlier attachment ownership', function (string $scope) {
    $source = ($this->source)('pptx');
    $event = HrCalendarEvent::create(['created_by' => $this->author->id, 'title' => 'A recurring event', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $this->put('/hr/calendar/events/'.$event->id, ['edit_version' => 1, 'files' => [UploadedFile::fake()->createWithContent('Earlier.pdf', $this->pdf)]])->assertRedirect()->assertSessionHasNoErrors();
    $earlier = $event->attachments()->sole();
    $this->put('/hr/calendar/events/'.$event->id, ['actor_id' => $this->author->id, 'edit_version' => 2, 'scope' => $scope, 'occurrence_date' => '2026-10-27',
        'hub_library_images' => [($this->select)($source)]])->assertRedirect()->assertSessionHasNoErrors();
    $target = $scope === 'this' ? HrCalendarEvent::where('recurrence_parent_id', $event->id)->sole() : HrCalendarEvent::whereNull('recurrence_parent_id')->whereKeyNot($event->id)->sole();
    $copy = $target->attachments()->where('original_name', $source->name)->sole();
    expect($copy->event_id)->toBe($target->id)->and($copy->path)->not->toBe($source->path)
        ->and(Storage::disk('private')->get($copy->path))->toBe(Storage::disk('private')->get($source->path))
        ->and($event->attachments()->pluck('id')->all())->toBe([$earlier->id])->and($event->fresh()->edit_version)->toBe(3);
    if ($scope === 'following') {
        $retained = $target->attachments()->where('original_name', 'Earlier.pdf')->sole();
        expect($retained->path)->not->toBe($earlier->path)->and(Storage::disk('private')->get($retained->path))->toBe($this->pdf);
    }
    $dto = app(HrCalendarAggregator::class)->feed('2026-10-27', '2026-10-27', ['event'], [], $this->reader)[0]['extendedProps']['attachments'];
    $document = collect($dto)->firstWhere('id', $copy->id);
    expect($document['mime'])->toBe($source->mime)->and($document['preview_url'])->toBeNull()
        ->and($document['download_url'])->toBe(url('/hr/calendar/attachments/'.$copy->id.'/download'));
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$copy->id)->assertOk();
    $this->actingAs($this->author)->putJson('/hr/calendar/events/'.$target->id, ['edit_version' => 3, 'removed_attachment_ids' => [$earlier->id]])->assertNotFound();
    expect($event->attachments()->count())->toBe(1)->and($target->attachments()->whereKey($copy->id)->exists())->toBeTrue();
})->with(['this', 'following']);

test('late document-copy failures compensate private files metadata and recurrence changes atomically', function () {
    $first = ($this->source)('pdf');
    $second = ($this->source)('pptx', null, 'Fail.pptx');
    $baseline = Storage::disk('private')->allFiles();
    $selections = [($this->select)($first), ($this->select)($second)];
    $this->withoutExceptionHandling();
    foreach ([HubEventDraftFile::class, HrCalendarEventAttachment::class] as $type) {
        $event = $type === HrCalendarEventAttachment::class ? HrCalendarEvent::create(['created_by' => $this->author->id, 'title' => 'Unchanged recurring event', 'event_type' => 'company',
            'site_id' => $this->site->id, 'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']) : null;
        $listener = 'eloquent.creating: '.$type;
        Event::listen($listener, function ($file) {
            if ($file->original_name === 'Fail.pptx') {
                throw new RuntimeException('A later document record failed');
            }
        });
        try {
            if ($event) {
                $this->put('/hr/calendar/events/'.$event->id, ['actor_id' => $this->author->id, 'edit_version' => 1, 'scope' => 'this', 'occurrence_date' => '2026-10-27',
                    'title' => 'Must roll back', 'hub_library_images' => $selections]);
            } else {
                $this->post('/company-hub/event-drafts', [...$this->payload, 'hub_library_images' => $selections]);
            }
            test()->fail('The late write failure must roll back.');
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('A later document record failed');
        } finally {
            Event::forget($listener);
        }
        expect(HubEventDraft::count())->toBe(0)->and(HubEventDraftFile::count())->toBe(0)->and(HrCalendarEventAttachment::count())->toBe(0)
            ->and(Storage::disk('private')->allFiles())->toEqual($baseline)->and(DB::table('audit_logs')->where('action', 'hub.event_media.reused')->count())->toBe(0);
        if ($event) {
            expect($event->fresh()->title)->toBe('Unchanged recurring event')->and($event->fresh()->edit_version)->toBe(1)
                ->and(HrCalendarEvent::where('recurrence_parent_id', $event->id)->exists())->toBeFalse();
        }
    }
    Notification::assertNothingSent();
});

test('protected inline previews retain current audience draft ownership and exact retained-file boundaries', function () {
    // Exercise the HR audience boundary too, rather than stopping at its module permission middleware.
    $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'hr.calendar.view')->pluck('id')
        ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
    $event = HrCalendarEvent::create(['created_by' => $this->author->id, 'title' => 'A private event', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00']);
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->author->id, 'rsvp_status' => 'none']);
    $this->put('/hr/calendar/events/'.$event->id, ['edit_version' => 1, 'files' => [UploadedFile::fake()->createWithContent('Private.pdf', $this->pdf), UploadedFile::fake()->createWithContent('Photo.png', $this->png)]])->assertRedirect()->assertSessionHasNoErrors();
    $pdf = $event->attachments()->where('mime', 'application/pdf')->sole();
    $image = $event->attachments()->where('mime', 'image/png')->sole();
    foreach ([$pdf, $image] as $file) {
        foreach (['/company-hub/event-files/'.$file->id, '/hr/calendar/attachments/'.$file->id.'/download'] as $url) {
            $response = $this->get($url.'?preview=1')->assertOk()->assertHeader('Content-Type', $file->mime);
            expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->and($response->headers->get('Cache-Control'))->toContain('no-store');
            $this->actingAs($this->reader)->get($url.'?preview=1')->assertNotFound();
            $this->actingAs($this->author);
        }
    }
    $this->post('/company-hub/event-drafts', [...$this->payload, 'source_event_id' => $event->id, 'edit_version' => 2])->assertRedirect()->assertSessionHasNoErrors();
    $draft = HubEventDraft::sole();
    $dto = collect(app(HubEventPublishing::class)->serialize($draft)['initial']['attachments'])->firstWhere('id', $pdf->id);
    expect($dto['preview_url'])->toBe('/company-hub/event-drafts/'.$draft->id.'/event-files/'.$pdf->id.'?preview=1');
    $this->get($dto['preview_url'])->assertOk();
    $source = ($this->source)('pdf');
    $this->post('/company-hub/event-drafts/'.$draft->id, [...$this->payload, 'version' => 1, 'hub_library_images' => [($this->select)($source)], 'removed_attachment_ids' => [$pdf->id]])->assertRedirect()->assertSessionHasNoErrors();
    $this->get($dto['preview_url'])->assertNotFound();
    $owned = $draft->files()->sole();
    $this->get('/company-hub/event-draft-files/'.$owned->id.'?preview=1')->assertOk();
    $owned->update(['retained' => false]);
    $this->get('/company-hub/event-draft-files/'.$owned->id.'?preview=1')->assertNotFound();
    $owned->update(['retained' => true]);
    $this->actingAs($this->reader)->get('/company-hub/event-draft-files/'.$owned->id.'?preview=1')->assertNotFound();
    $this->get($dto['preview_url'])->assertNotFound();
    $event->attendees()->delete();
    $this->get('/company-hub/event-files/'.$pdf->id.'?preview=1')->assertOk();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile->update(['primary_site_id' => $outside->id]);
    $this->get('/company-hub/event-files/'.$pdf->id.'?preview=1')->assertNotFound();
    $this->reader->hrEmployeeProfile->update(['primary_site_id' => $this->site->id]);
    $this->reader->hrEmployeeProfile->update(['is_active' => false]);
    $this->get('/company-hub/event-files/'.$pdf->id.'?preview=1')->assertForbidden();
    $this->actingAs($this->author);
    $event->update(['archived_at' => now()]);
    $this->get('/company-hub/event-files/'.$pdf->id.'?preview=1')->assertNotFound();
});
