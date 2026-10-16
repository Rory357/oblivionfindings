<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubDraft;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Domain\CompanyHub\Services\HubPageCopy;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function duplicatePayload(array $context): array
{
    return array_intersect_key($context, array_flip(['actor_id', 'version', 'source', 'revision_id', 'media_versions']));
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Shared learning', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'news', 'A source story');
    $assets = [];
    foreach ([['photo.png', 'image/png', 'source photograph'], ['guide.pdf', 'application/pdf', 'source document'], ['linked.pdf', 'application/pdf', 'linked-only document']] as [$name, $mime, $bytes]) {
        $path = 'company-hub/page-copy-source-'.$name;
        Storage::disk('private')->put($path, $bytes);
        $assets[] = HubMedia::create([
            'hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => $path,
            'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'alt' => 'Original description',
            'crop' => $mime === 'image/png' ? ['ratio' => '16:9', 'x' => 40, 'y' => 60] : null,
        ]);
    }
    [$this->photo, $this->pdf, $this->linked] = $assets;
    $encodedPdfId = implode('', array_map(fn ($digit) => '%'.strtoupper(bin2hex($digit)), str_split((string) $this->pdf->id)));
    $this->page = $this->publisher->save($this->editor, $this->page, [
        ...$this->page->draft, 'summary' => 'A source summary', 'cover_id' => $this->photo->id,
        'team_ids' => [$this->team->id], 'topics' => ['Learning'], 'featured' => true,
        'show_in_navigation' => true, 'navigation_order' => 17, 'planned_for' => now()->addDays(10)->toDateString(),
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'hero' => [...$this->page->draft['hero'], 'style' => 'image', 'action_label' => 'Open our document', 'action_url' => '/company-hub/media/'.$encodedPdfId.'?download=1#page-2'],
        'sections' => [
            ['id' => 'visible', 'columns' => 2, 'blocks' => [
                ['id' => 'photo', 'type' => 'image', 'title' => 'Our photograph', 'media_id' => $this->photo->id, 'alt' => 'Authored alternative text', 'body' => 'Authored image caption'],
                ['id' => 'note', 'type' => 'text', 'title' => 'Our story', 'body' => 'A complete saved story.'],
            ]],
            ['id' => 'authoring', 'columns' => 1, 'blocks' => [
                ['id' => 'private-document', 'type' => 'file', 'title' => 'Hidden document', 'media_id' => $this->pdf->id, 'hidden' => true],
                ['id' => 'hidden-photo', 'type' => 'image', 'title' => 'Hidden photograph', 'media_id' => $this->photo->id, 'hidden' => true, 'width' => 6, 'min_height' => 88],
                ['id' => 'downloads', 'type' => 'links', 'title' => 'Our downloads', 'links' => [
                    ['id' => 'pdf', 'title' => 'Document', 'url' => '/company-hub/media/'.$this->pdf->id.'?download=1#page-2', 'description' => 'Download our guide'],
                    ['id' => 'linked', 'title' => 'Extra document', 'url' => '/company-hub/media/'.$this->linked->id, 'description' => 'A linked attachment'],
                    ['id' => 'external', 'title' => 'External reference', 'url' => 'https://example.com/company-hub/media/999', 'description' => 'A separate website'],
                ]],
                ['id' => 'photo-link', 'type' => 'button', 'title' => 'Our photo', 'body' => 'Look at our work', 'url' => '/company-hub/media/'.$this->photo->id, 'action_label' => 'Open image'],
            ]],
        ],
    ], 1);
    $this->url = '/company-hub/manage/'.$this->page->id.'/duplicate';
});

test('a complete authored page duplicates into an independent private actor owned version one with no publication or social identity', function () {
    $release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft, 'title' => 'A saved draft to copy', 'requires_approval' => true], 2);
    DB::table('hub_interactions')->insert(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id, 'kind' => 'comment', 'body' => 'Existing source discussion', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hub_reads')->insert(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id, 'first_read_at' => now(), 'last_read_at' => now()]);
    DB::table('hub_bookmarks')->insert(['user_id' => $this->reader->id, 'subject_type' => 'page', 'subject_id' => $this->page->id, 'created_at' => now(), 'updated_at' => now()]);
    $source = $this->page->draft;
    $context = $this->actingAs($this->editor)->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('source', 'draft')->assertJsonPath('version', 3)->assertJsonPath('can_duplicate', true)->json();
    $result = $this->postJson($this->url, duplicatePayload($context))->assertCreated()->assertJsonPath('version', 1)->json();
    $copy = HubPage::findOrFail($result['page_id']);
    $media = HubMedia::where('hub_page_id', $copy->id)->get()->keyBy('source_media_id');
    expect($copy->id)->not->toBe($this->page->id)->and($copy->slug)->not->toBe($this->page->slug)
        ->and($copy->owner_id)->toBe($this->editor->id)->and($copy->kind)->toBe('news')->and($copy->draft_version)->toBe(1)
        ->and($copy->archived_at)->toBeNull()->and($copy->draft_discarded_at)->toBeNull()->and($copy->liveRevision())->toBeNull()
        ->and($copy->draft['title'])->toBe('A saved draft to copy — copy')->and($copy->draft['requires_approval'])->toBeTrue()
        ->and($copy->draft['show_in_navigation'])->toBeFalse()->and($copy->draft['navigation_order'])->toBe(0)
        ->and($copy->draft['featured'])->toBeFalse()->and($copy->draft['planned_for'])->toBeNull()
        ->and($copy->draft['audience'])->toEqual($source['audience'])->and($copy->draft['team_ids'])->toBe([$this->team->id])
        ->and($copy->draft['topics'])->toBe(['Learning'])->and($copy->draft['summary'])->toBe('A source summary')
        ->and($copy->draft['cover_id'])->toBe($media[$this->photo->id]->id)
        ->and($copy->draft['sections'][1]['blocks'][0]['media_id'])->toBe($media[$this->pdf->id]->id)
        ->and($copy->draft['sections'][1]['blocks'][0]['hidden'])->toBeTrue()
        ->and($copy->draft['sections'][1]['blocks'][1]['media_id'])->toBe($media[$this->photo->id]->id)
        ->and($copy->draft['sections'][1]['blocks'][1]['width'])->toBe(6)
        ->and($copy->draft['sections'][0]['blocks'][0]['body'])->toBe('Authored image caption')
        ->and($copy->draft['hero']['action_url'])->toBe('/company-hub/media/'.$media[$this->pdf->id]->id.'?download=1#page-2')
        ->and($copy->draft['sections'][1]['blocks'][2]['links'][1]['url'])->toBe('/company-hub/media/'.$media[$this->linked->id]->id)
        ->and($copy->draft['sections'][1]['blocks'][2]['links'][2]['url'])->toBe('https://example.com/company-hub/media/999')
        ->and($copy->draft['sections'][1]['blocks'][3]['url'])->toBe('/company-hub/media/'.$media[$this->photo->id]->id)
        ->and($copy->revisions()->count())->toBe(0)->and(app(HubDraft::class)->hasSavedDraft($copy))->toBeTrue();
    $this->assertDatabaseCount('hub_media', 6);
    $this->assertDatabaseCount('hub_revisions', 1);
    $this->assertDatabaseCount('hub_interactions', 1);
    $this->assertDatabaseCount('hub_reads', 1);
    $this->assertDatabaseCount('hub_bookmarks', 1);
    $this->assertDatabaseCount('hub_draft_discards', 0);
    expect($this->page->fresh()->draft)->toEqual($source)->and($release->fresh()->state)->toBe('approved');
    $this->actingAs($this->reader)->get('/company-hub/read/'.$copy->slug)->assertNotFound();
    $this->get('/company-hub/media/'.$media[$this->photo->id]->id)->assertNotFound();
    Notification::assertNothingSent();
});

test('published duplication selects the exact release without copying a newer working draft or its cover and rejects snapshot conflicts', function () {
    $release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    Storage::disk('private')->put('company-hub/page-copy-new-draft.png', 'unpublished new photograph');
    $draftPhoto = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/page-copy-new-draft.png', 'name' => 'Draft-only.png', 'mime' => 'image/png', 'size' => 26]);
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft, 'title' => 'A newer private draft', 'cover_id' => $draftPhoto->id], 2);
    $context = $this->actingAs($this->editor)->getJson($this->url.'?source=published')->assertOk()->assertJsonPath('revision_id', $release->id)->json();
    expect(array_keys($context['media_versions']))->not->toContain($draftPhoto->id);
    $this->postJson($this->url, [...duplicatePayload($context), 'version' => 2])->assertConflict();
    $this->postJson($this->url, [...duplicatePayload($context), 'actor_id' => $this->reader->id])->assertConflict();
    $this->postJson($this->url, [...duplicatePayload($context), 'revision_id' => $release->id + 1000])->assertConflict();
    $id = $this->postJson($this->url, duplicatePayload($context))->assertCreated()->json('page_id');
    $copy = HubPage::findOrFail($id);
    expect($copy->draft['title'])->toBe('A source story — copy')
        ->and(HubMedia::where('hub_page_id', $copy->id)->where('source_media_id', $draftPhoto->id)->exists())->toBeFalse()
        ->and(HubMedia::find($copy->draft['cover_id'])->source_media_id)->toBe($this->photo->id);
    $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->postJson($this->url, duplicatePayload($context))->assertConflict();
    $this->assertDatabaseCount('hub_pages', 2);
    Notification::assertNothingSent();
});

test('duplication requires a current signed in writer with source edit and current site authority and gives the new actor ownership', function () {
    $context = $this->actingAs($this->editor)->getJson($this->url)->assertOk()->json();
    $write = Permission::where('key', 'company_hub.write')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$write->id => ['allowed' => true]]);
    $this->actingAs($this->reader->fresh())->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, duplicatePayload($context))->assertNotFound();
    $author = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $author->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($author, $this->site, ['department_id' => $this->team->id]);
    $context = $this->actingAs($author)->getJson($this->url)->assertOk()->json();
    $id = $this->postJson($this->url, duplicatePayload($context))->assertCreated()->json('page_id');
    expect(HubPage::findOrFail($id)->owner_id)->toBe($author->id)->and(HubMedia::where('hub_page_id', $id)->pluck('uploaded_by')->unique()->all())->toBe([$author->id]);
    $this->editor->permissionOverrides()->syncWithoutDetaching([$write->id => ['allowed' => false]]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, duplicatePayload($context))->assertForbidden();
    $this->editor->permissionOverrides()->updateExistingPivot($write->id, ['allowed' => true]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertNotFound();
    $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'end_date' => today()->subDay()]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertForbidden();
    $missing = Mockery::mock(User::class);
    $missing->shouldReceive('fresh')->once()->andReturnNull();
    expect(fn () => app(HubPageCopy::class)->duplicate($missing, $this->page, duplicatePayload($context)))->toThrow(NotFoundHttpException::class);
    $this->assertDatabaseCount('hub_pages', 2);
    Notification::assertNothingSent();
});

test('media version changes and foreign references are rejected while copied files and metadata stay independent', function () {
    $context = $this->actingAs($this->editor)->getJson($this->url)->assertOk()->json();
    app(HubMediaLibrary::class)->update($this->editor, $this->page, $this->photo, ['version' => 1, 'name' => 'Changed source.png', 'alt' => 'Changed source description']);
    $this->postJson($this->url, duplicatePayload($context))->assertConflict();
    $context = $this->getJson($this->url)->assertOk()->json();
    $this->postJson($this->url, [...duplicatePayload($context), 'media_versions' => [...$context['media_versions'], 999999 => 1]])->assertConflict();
    $id = $this->postJson($this->url, duplicatePayload($context))->assertCreated()->json('page_id');
    $copy = HubPage::findOrFail($id);
    $photo = HubMedia::findOrFail($copy->draft['cover_id']);
    expect($photo->path)->not->toBe($this->photo->path)->and($photo->crop)->toEqual($this->photo->crop)
        ->and($photo->alt)->toBe('Changed source description')->and($photo->version)->toBe(1)
        ->and(Storage::disk('private')->get($photo->path))->toBe('source photograph');
    app(HubMediaLibrary::class)->update($this->editor, $copy, $photo, ['version' => 1, 'name' => 'Copy only.png', 'alt' => 'Copy description']);
    expect($this->photo->fresh()->name)->toBe('Changed source.png')->and($this->photo->fresh()->alt)->toBe('Changed source description');
    Storage::disk('private')->delete($this->photo->path);
    Storage::disk('private')->assertExists($photo->path);
    Storage::disk('private')->put($this->photo->path, 'source photograph');
    $foreign = $this->publisher->create($this->editor, 'news', 'Separate private page');
    Storage::disk('private')->put('company-hub/page-copy-foreign.pdf', 'foreign attachment');
    $asset = HubMedia::create(['hub_page_id' => $foreign->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/page-copy-foreign.pdf', 'name' => 'Foreign.pdf', 'mime' => 'application/pdf', 'size' => 18]);
    $original = $this->page->draft;
    $this->page->update(['draft' => [...$original, 'cover_id' => $asset->id]]);
    $this->getJson($this->url)->assertJsonPath('can_duplicate', false)->assertJsonPath('blocked_reason', 'content_unavailable');
    $this->postJson($this->url, duplicatePayload($context))->assertUnprocessable()->assertJsonValidationErrors('cover_id');
    $foreignUrl = $original;
    $foreignUrl['sections'][1]['blocks'][2]['links'][1]['url'] = '/company-hub/media/'.$asset->id;
    $this->page->update(['draft' => $foreignUrl]);
    $this->postJson($this->url, duplicatePayload($context))->assertUnprocessable()->assertJsonValidationErrors('media_versions');
    $this->assertDatabaseCount('hub_pages', 3);
    Notification::assertNothingSent();
});

test('a late failure after every file was copied rolls back the new page records assets and files without touching its source', function () {
    $context = $this->actingAs($this->editor)->getJson($this->url)->assertOk()->json();
    $sourceFiles = Storage::disk('private')->allFiles();
    sort($sourceFiles);
    $source = $this->page->draft;
    $failAudit = true;
    DB::listen(function ($query) use (&$failAudit): void {
        if ($failAudit && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')) {
            $failAudit = false;
            throw new RuntimeException('Isolated whole-page copy audit failure');
        }
    });
    expect(fn () => app(HubPageCopy::class)->duplicate($this->editor, $this->page, duplicatePayload($context)))
        ->toThrow(RuntimeException::class, 'Isolated whole-page copy audit failure');
    $this->assertDatabaseCount('hub_pages', 1);
    $this->assertDatabaseCount('hub_media', 3);
    $files = Storage::disk('private')->allFiles();
    sort($files);
    expect($files)->toBe($sourceFiles)->and($this->page->fresh()->draft)->toEqual($source)->and($this->page->fresh()->draft_version)->toBe(2);
    Notification::assertNothingSent();
});

test('stale canonical references unavailable files discarded drafts and the singleton homepage fail without creating a copy', function () {
    $story = $this->publisher->create($this->editor, 'news', 'An authorised featured story');
    $this->publisher->publish($this->editor, $story, 1, null, null);
    $content = $this->page->draft;
    $content['sections'][0]['blocks'][] = ['id' => 'selected-story', 'type' => 'featured', 'title' => '', 'story_id' => $story->id, 'layout' => 'single'];
    $this->page = $this->publisher->save($this->editor, $this->page, $content, 2);
    $context = $this->actingAs($this->editor)->getJson($this->url)->assertOk()->json();
    $this->publisher->archive($this->editor, $story, 1);
    $this->getJson($this->url)->assertJsonPath('can_duplicate', false)->assertJsonPath('blocked_reason', 'content_unavailable');
    $this->postJson($this->url, duplicatePayload($context))->assertUnprocessable();
    $this->publisher->publish($this->editor, $story->fresh(), 2, null, null);
    $context = $this->getJson($this->url)->assertOk()->json();
    Storage::disk('private')->delete($this->pdf->path);
    $this->getJson($this->url)->assertJsonPath('can_duplicate', false);
    $this->postJson($this->url, duplicatePayload($context))->assertUnprocessable()->assertJsonValidationErrors('media_versions');
    Storage::disk('private')->put($this->pdf->path, 'source document');
    app(HubDraft::class)->discard($this->editor, $this->page, ['actor_id' => $this->editor->id, 'version' => 3]);
    $this->getJson($this->url.'?source=draft')->assertJsonPath('can_duplicate', false)->assertJsonPath('blocked_reason', 'discarded');
    $this->postJson($this->url, [...duplicatePayload($context), 'version' => 4])->assertConflict();
    $home = $this->publisher->create($this->editor, 'home', 'Company Hub');
    $homeUrl = '/company-hub/manage/'.$home->id.'/duplicate';
    $homeContext = $this->getJson($homeUrl)->assertOk()->assertJsonPath('can_duplicate', false)->assertJsonPath('blocked_reason', 'home')->json();
    $this->postJson($homeUrl, duplicatePayload($homeContext))->assertUnprocessable();
    $this->assertDatabaseCount('hub_pages', 3);
    $this->assertDatabaseCount('hub_media', 3);
    Notification::assertNothingSent();
});
