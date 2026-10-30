<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeTime();
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Attachment team', 'is_active' => true]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$this->outside->id]]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->attachment = function (string $kind, bool $publish = true): array {
        $page = $this->publisher->create($this->editor, $kind, 'Current '.$kind.' parent', 'blank')->fresh();
        $filename = 'Attachment-'.$kind.'-'.$page->id.'.pdf';
        $path = 'company-hub/'.$filename;
        Storage::disk('private')->put($path, "%PDF-1.7\nApproved work attachment\n");
        $media = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $this->editor->id,
            'path' => $path, 'name' => $filename, 'mime' => 'application/pdf', 'size' => 34, 'version' => 1])->fresh();
        $page = $this->publisher->save($this->editor, $page, [...$page->draft,
            'team_ids' => [$this->team->id], 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
            'sections' => [['id' => 'attachments', 'columns' => 1, 'blocks' => [
                ['id' => 'work-file', 'type' => 'file', 'title' => 'Safety attachment for '.$kind, 'body' => 'Approved working instructions.', 'media_id' => $media->id],
                ['id' => 'external-link', 'type' => 'links', 'title' => 'Related work site', 'links' => [['id' => 'company-site', 'title' => 'Company website', 'url' => 'https://example.test/work']]],
            ]]],
        ], 1);
        $revision = $publish ? $this->publisher->publish($this->editor, $page, 2, null, null) : null;

        return compact('page', 'media', 'revision');
    };
    $this->actingAs($this->reader);
});

test('published non-guide attachments connect exact metadata reader search saving and recent document identity', function (string $kind) {
    ['page' => $parent, 'media' => $media, 'revision' => $revision] = ($this->attachment)($kind);
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect($feed['resourceItems'])->toHaveCount(1);
    $item = $feed['resourceItems'][0];
    expect($item)->toMatchArray([
        'id' => $parent->id, 'kind' => $kind, 'page_title' => $parent->draft['title'], 'block_id' => 'work-file',
        'resource_key' => 'file:'.$parent->id.':work-file', 'resource_type' => 'File',
        'document_id' => $media->id, 'document_file_version' => 1, 'media_id' => $media->id, 'media_version' => 1,
        'filename' => $media->name, 'mime' => 'application/pdf', 'size' => 34,
        'reader_url' => '/company-hub/documents/'.$media->id,
        'preview_url' => '/company-hub/media/'.$media->id.'/preview',
        'download_url' => '/company-hub/media/'.$media->id.'?download=1',
        'bookmark_subject_type' => 'media', 'bookmark_subject_id' => $media->id, 'bookmarked' => false,
        'team_ids' => [$this->team->id],
    ])->not->toHaveKeys(['path', 'document_version', 'uploaded_by', 'extracted_text']);
    $this->get($item['reader_url'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('company-hub/document')->where('document.id', $media->id)->where('document.media_id', $media->id)
        ->where('document.revision_id', $revision->id)->where('document.page.id', $parent->id));
    $this->get($item['preview_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get($item['download_url'])->assertOk();
    $this->getJson('/company-hub/search?query='.rawurlencode($media->name))->assertOk()
        ->assertJsonPath('results.0.kind', 'Document')->assertJsonPath('results.0.document_id', $media->id)
        ->assertJsonPath('results.0.url', $item['reader_url']);
    expect(app(HubFeed::class)->forUser($this->reader, $media->name)['resourceItems'])->toHaveCount(1);
    $this->postJson('/company-hub/bookmarks', ['hub_receipt' => true, 'actor_id' => $this->reader->id,
        'subject_type' => 'media', 'subject_id' => $media->id, 'saved' => true, 'expected_saved' => false, 'expected_version' => null])
        ->assertOk()->assertJsonPath('subject_id', $media->id)->assertJsonPath('bookmarked', true);
    $this->postJson('/company-hub/documents/'.$media->id.'/read', ['actor_id' => $this->reader->id, 'media_id' => $media->id, 'revision_id' => $revision->id])
        ->assertOk()->assertJsonPath('document_id', $media->id);
    $saved = app(HubFeed::class)->forUser($this->reader);
    expect($saved['resourceItems'][0]['bookmarked'])->toBeTrue()
        ->and($saved['recentDocuments'])->toBe([$media->id])->and($saved['recentResources'])->toBe(['media:'.$media->id]);
    $this->assertDatabaseHas('hub_bookmarks', ['user_id' => $this->reader->id, 'subject_type' => 'media', 'subject_id' => $media->id]);
    Notification::assertNothingSent();
})->with(['news', 'department', 'campaign']);

test('non-guide attachment projection retains the live edition during private replacement and follows canonical publication', function (string $kind) {
    ['page' => $parent, 'media' => $media, 'revision' => $revision] = ($this->attachment)($kind);
    $snapshot = $revision->content;
    $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $media->id, 'saved' => true])->assertRedirect();
    $path = 'company-hub/next-'.$parent->id.'.pdf';
    Storage::disk('private')->put($path, "%PDF-1.7\nPrivate revised attachment\n");
    $next = HubMedia::create(['hub_page_id' => $parent->id, 'uploaded_by' => $this->editor->id,
        'path' => $path, 'name' => 'Revised-'.$kind.'.pdf', 'mime' => 'application/pdf', 'size' => 35, 'version' => 1,
        'document_root_id' => $media->id, 'document_sequence' => 2])->fresh();
    $draft = $parent->draft;
    $draft['sections'][0]['blocks'][0]['media_id'] = $next->id;
    $parent = $this->publisher->save($this->editor, $parent->fresh(), $draft, 2);
    $item = app(HubFeed::class)->forUser($this->reader)['resourceItems'][0];
    expect($item['media_id'])->toBe($media->id)->and($item['document_file_version'])->toBe(1)->and($item['bookmarked'])->toBeTrue();
    expect(app(HubFeed::class)->forUser($this->reader, $next->name)['resourceItems'])->toBe([]);
    $this->get('/company-hub/documents/'.$media->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.media_id', $media->id));
    $release = $this->publisher->publish($this->editor, $parent, 3, null, null);
    $item = app(HubFeed::class)->forUser($this->reader)['resourceItems'][0];
    expect($item['document_id'])->toBe($media->id)->and($item['media_id'])->toBe($next->id)
        ->and($item['document_file_version'])->toBe(2)->and($item['bookmarked'])->toBeTrue()
        ->and($revision->fresh()->content)->toEqual($snapshot);
    $this->get($item['reader_url'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('document.id', $media->id)->where('document.media_id', $next->id)->where('document.revision_id', $release->id));
    $this->getJson('/company-hub/search?query='.rawurlencode($next->name))->assertOk()
        ->assertJsonPath('results.0.document_id', $media->id)->assertJsonPath('results.0.media_id', $next->id);
})->with(['news', 'department', 'campaign']);

test('attachment resources never expose hidden private draft expired or archived parent content', function () {
    $withdrawn = [];
    foreach ([['news', 'block'], ['department', 'section'], ['campaign', 'audience'], ['news', 'draft'], ['department', 'expired'], ['campaign', 'archived']] as [$kind, $state]) {
        $entry = ($this->attachment)($kind, $state !== 'draft');
        $parent = $entry['page'];
        if (in_array($state, ['block', 'section', 'audience'], true)) {
            $draft = $parent->draft;
            if ($state === 'block') {
                $draft['sections'][0]['blocks'][0]['hidden'] = true;
            } elseif ($state === 'section') {
                $draft['sections'][0]['hidden'] = true;
            } else {
                $draft['audience'] = ['type' => 'sites', 'ids' => [$this->outside->id]];
            }
            $parent = $this->publisher->save($this->editor, $parent->fresh(), $draft, 2);
            $this->publisher->publish($this->editor, $parent, 3, null, null);
        } elseif ($state === 'expired') {
            $this->publisher->publish($this->editor, $parent, 2, null, now()->addMinute()->toIso8601String());
        } elseif ($state === 'archived') {
            $this->publisher->archive($this->editor, $parent->fresh(), 2);
        }
        $withdrawn[] = $entry;
    }
    $this->travel(2)->minutes();
    expect(app(HubFeed::class)->forUser($this->reader)['resourceItems'])->toBe([]);
    foreach ($withdrawn as $entry) {
        $id = $entry['media']->id;
        $this->get('/company-hub/documents/'.$id)->assertNotFound();
        $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $id, 'saved' => true])->assertNotFound();
        $this->getJson('/company-hub/search?query='.rawurlencode($entry['media']->name))->assertOk()->assertJsonCount(0, 'results');
    }
    $this->assertDatabaseCount('hub_bookmarks', 0);
});

test('attachment resource metadata and saved hydration fail closed after current actor site or staff access changes', function () {
    foreach (['news', 'department', 'campaign'] as $kind) {
        $entry = ($this->attachment)($kind);
        $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $entry['media']->id, 'saved' => true])->assertRedirect();
    }
    $feed = app(HubFeed::class);
    $actor = $this->reader->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
    expect($feed->forUser($actor)['resourceItems'])->toHaveCount(3);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->outside->id]);
    expect($feed->forUser($actor)['resourceItems'])->toBe([]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
    $this->get('/company-hub')->assertForbidden();
    $this->reader->permissionOverrides()->detach();
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->get('/company-hub')->assertForbidden();
    expect(DB::table('hub_bookmarks')->where('user_id', $this->reader->id)->count())->toBe(3);
    Notification::assertNothingSent();
});
