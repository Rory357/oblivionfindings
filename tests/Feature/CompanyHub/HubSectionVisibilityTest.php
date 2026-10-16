<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPageCopy;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubSectionLibrary;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Section visibility resource', 'blank')->fresh();
    Storage::disk('private')->put('company-hub/section-visibility.pdf', 'Private retained section document');
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/section-visibility.pdf', 'name' => 'Retained document.pdf', 'mime' => 'application/pdf', 'size' => 33])->fresh();
    $this->poll = ['id' => 'section-poll', 'type' => 'poll', 'title' => 'Choose an activity', 'hidden' => false,
        'question' => 'Which activity?', 'options' => ['Walk', 'Lunch']];
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft,
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [
            ['id' => 'public', 'columns' => 1, 'blocks' => [['id' => 'intro', 'type' => 'text', 'title' => 'Published instructions', 'body' => 'Useful current resource instructions.']]],
            ['id' => 'private-section', 'title' => 'Authored section retained in full', 'columns' => 2, 'hidden' => true, 'blocks' => [
                ['id' => 'section-text', 'type' => 'text', 'title' => 'Saved note', 'body' => 'ZqxWholeSectionNeedle', 'hidden' => false],
                ['id' => 'section-file', 'type' => 'file', 'title' => 'Saved document', 'media_id' => $this->media->id, 'hidden' => false],
                ['id' => 'section-links', 'type' => 'links', 'title' => 'Saved links', 'links' => [['id' => 'policy', 'title' => 'Saved reference', 'url' => '/hr/my/policies']]],
                $this->poll,
                ['id' => 'section-people', 'type' => 'people', 'title' => 'Saved colleague finder', 'mode' => 'finder'],
                ['id' => 'unfinished', 'type' => 'text', 'title' => '', 'body' => '', 'hidden' => false],
                ['id' => 'individually-hidden', 'type' => 'text', 'title' => '', 'body' => 'ZqxNeverShowSectionChild', 'hidden' => true],
            ]],
        ],
    ], $this->page->draft_version);
    $this->saved = $this->page->fresh()->draft;
    $this->version = $this->page->draft_version;
    $this->manageUrl = '/company-hub/manage/'.$this->page->id;
    $this->actingAs($this->editor);
});

test('hidden sections preserve complete history and preview while reader search extracts media and poll writes require a visible published section', function () {
    $release = $this->publisher->publish($this->editor, $this->page, $this->version, null, null);
    $snapshot = $release->fresh()->content;
    $vote = ['kind' => 'vote', 'block_id' => $this->poll['id'], 'poll_key' => HubParticipation::pollKey($this->poll), 'choice' => 0];
    $this->actingAs($this->reader)->get('/company-hub/read/'.$this->page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('selectedPage.content.sections', 1)->where('selectedPage.content.sections.0.id', 'public')
        ->where('resourceItems', [])->where('blockData', [])->where('participation.polls', []));
    $this->getJson('/company-hub/search?query=ZqxWholeSectionNeedle')->assertOk()->assertJsonCount(0, 'results');
    expect(app(HubFeed::class)->forUser($this->reader, 'ZqxWholeSectionNeedle')['pages'])->toBe([]);
    $this->get('/company-hub/media/'.$this->media->id)->assertNotFound();
    $this->postJson('/company-hub/pages/'.$this->page->id.'/interactions', $vote)->assertNotFound();
    $this->actingAs($this->editor)->get($this->manageUrl.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('page.draft.sections.1.hidden', true)->has('page.draft.sections.1.blocks', 7)
        ->where('page.draft.sections.1.blocks.0.hidden', false)->where('page.draft.sections.1.blocks.6.hidden', true)
        ->has('previewData.blockData.section-people.people')->where('readiness.content_ready', true));
    $this->getJson($this->manageUrl.'/revisions/'.$release->id)->assertOk()->assertJsonPath('content.sections.1.hidden', true)
        ->assertJsonPath('content.sections.1.blocks.0.body', 'ZqxWholeSectionNeedle')->assertJsonPath('content.sections.1.blocks.0.hidden', false);
    $this->get('/company-hub/media/'.$this->media->id)->assertOk();
    $shown = $this->saved;
    $shown['sections'][1]['hidden'] = false;
    $page = $this->publisher->save($this->editor, $this->page, $shown, $this->version);
    $this->postJson($this->manageUrl.'/publish', ['version' => $page->draft_version])->assertUnprocessable();
    $this->actingAs($this->reader)->get('/company-hub/media/'.$this->media->id)->assertNotFound();
    $this->postJson('/company-hub/pages/'.$this->page->id.'/interactions', $vote)->assertNotFound();
    $shown['sections'][1]['blocks'][5]['hidden'] = true;
    $page = $this->publisher->save($this->editor, $page, $shown, $page->draft_version);
    $current = $this->publisher->publish($this->editor, $page, $page->draft_version, null, null);
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('selectedPage.content.sections', 2)->has('selectedPage.content.sections.1.blocks', 5)
        ->has('participation.polls.section-poll')->has('blockData.section-people.people'));
    $this->get('/company-hub/media/'.$this->media->id)->assertOk();
    $this->post('/company-hub/pages/'.$page->id.'/interactions', $vote)->assertRedirect()->assertSessionHasNoErrors();
    $this->getJson('/company-hub/search?query=ZqxWholeSectionNeedle')->assertJsonCount(1, 'results');
    $this->getJson('/company-hub/search?query=ZqxNeverShowSectionChild')->assertJsonCount(0, 'results');
    expect($release->fresh()->content)->toEqual($snapshot)->and($current->fresh()->content['sections'][1]['blocks'][6]['hidden'])->toBeTrue()
        ->and(HubContent::visibleBlocks($release->fresh()->content)->pluck('id')->all())->toBe(['intro']);
    Notification::assertNothingSent();
});

test('hiding a section never bypasses foreign media current approved sites teams or unsafe authored field validation', function () {
    $foreign = $this->publisher->create($this->editor, 'page', 'Foreign private file source', 'blank')->fresh();
    $media = HubMedia::create(['hub_page_id' => $foreign->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/foreign-section-file.pdf', 'name' => 'Foreign.pdf', 'mime' => 'application/pdf', 'size' => 1]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    foreach ([
        ['id' => 'file', 'type' => 'file', 'title' => '', 'media_id' => $media->id],
        ['id' => 'people', 'type' => 'people', 'title' => '', 'team_ids' => [999999]],
        ['id' => 'link', 'type' => 'links', 'title' => '', 'links' => [['id' => 'unsafe', 'title' => '', 'url' => 'javascript:alert(1)']]],
        ['id' => 'unknown', 'type' => 'unregistered', 'title' => ''],
    ] as $unsafe) {
        $content = $this->saved;
        $content['sections'][1]['blocks'] = [$unsafe];
        $this->putJson($this->manageUrl, ['content' => $content, 'version' => $this->version])->assertUnprocessable();
    }
    $this->putJson($this->manageUrl, ['content' => [...$this->saved, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]], 'version' => $this->version])->assertUnprocessable();
    expect($this->page->fresh()->draft)->toEqual($this->saved)->and($this->page->fresh()->draft_version)->toBe($this->version)
        ->and($this->page->revisions()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('hidden section flags and child visibility survive independent full page copies and versioned reusable section insertion', function () {
    $library = app(HubSectionLibrary::class);
    $library->save($this->editor, $this->page, 'Hidden authored section', $this->saved['sections'][1], 'personal');
    $preset = $library->workspace($this->editor, $this->page)['presets'][0];
    $expected = $this->saved['sections'][1];
    unset($expected['blocks'][1]['media_id']);
    expect($preset['section'])->toEqual($expected)->and($preset['version'])->toBe(1);
    $target = $this->publisher->create($this->editor, 'page', 'Reusable section target', 'blank')->fresh();
    $section = $library->use($this->editor, $target, $preset['id'], $preset['version']);
    $target = $this->publisher->save($this->editor, $target, [...$target->draft, 'sections' => [$section]], $target->draft_version);
    expect($target->draft['sections'][0])->toEqual($expected)->and(HubContent::visibleBlocks($target->draft)->all())->toBe([])
        ->and($target->draft['sections'][0]['blocks'][0]['hidden'])->toBeFalse()->and($target->draft['sections'][0]['blocks'][6]['hidden'])->toBeTrue();
    $this->postJson('/company-hub/manage/'.$target->id.'/presets/'.$preset['id'].'/use', ['version' => 2])->assertConflict();
    $copies = app(HubPageCopy::class);
    $context = $copies->read($this->editor, $this->page, 'draft');
    expect($context['can_duplicate'])->toBeTrue();
    $copy = $copies->duplicate($this->editor, $this->page, array_intersect_key($context, array_flip(['actor_id', 'version', 'source', 'revision_id', 'media_versions'])));
    $copiedSection = $copy->fresh()->draft['sections'][1];
    $copiedFileId = $copiedSection['blocks'][1]['media_id'];
    expect($copiedFileId)->not->toBe($this->media->id);
    $expectedCopy = $this->saved['sections'][1];
    $expectedCopy['blocks'][1]['media_id'] = $copiedFileId;
    expect($copiedSection)->toEqual($expectedCopy)->and($copy->draft_version)->toBe(1)->and($copy->liveRevision())->toBeNull()
        ->and($this->page->fresh()->draft)->toEqual($this->saved)->and($this->page->fresh()->draft_version)->toBe($this->version);
    $copiedFile = HubMedia::findOrFail($copiedFileId);
    expect($copiedFile->hub_page_id)->toBe($copy->id)->and($copiedFile->path)->not->toBe($this->media->path);
    Storage::disk('private')->assertExists($copiedFile->path);
    $targetDraft = $target->draft;
    $targetDraft['sections'][0]['hidden'] = false;
    $target = $this->publisher->save($this->editor, $target, $targetDraft, $target->draft_version);
    expect($library->use($this->editor, $target, $preset['id'], 1))->toEqual($expected);
    Notification::assertNothingSent();
});
