<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubPageCopy;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubReadiness;
use App\Domain\CompanyHub\Services\HubSectionLibrary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Published information');
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft,
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [['id' => 'main', 'columns' => 1, 'blocks' => [
            ['id' => 'body', 'type' => 'text', 'title' => 'Current information', 'body' => 'Complete published information.'],
        ]]],
    ], 1);
    $this->release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->published = $this->release->fresh()->content;
    $this->url = '/company-hub/manage/'.$this->page->id;
    $this->unfinished = [
        ['id' => 'text', 'type' => 'text', 'title' => '', 'body' => ''],
        ['id' => 'notice', 'type' => 'notice', 'title' => 'Reminder', 'body' => ''],
        ['id' => 'button', 'type' => 'button', 'title' => 'Explore', 'action_label' => '', 'url' => ''],
        ['id' => 'links', 'type' => 'links', 'title' => 'Useful links', 'links' => [['id' => 'one', 'title' => '', 'url' => '']]],
        ['id' => 'poll', 'type' => 'poll', 'title' => 'Have your say', 'question' => '', 'options' => ['', null]],
        ['id' => 'image', 'type' => 'image', 'title' => 'Our image'],
        ['id' => 'file', 'type' => 'file', 'title' => 'Useful file'],
        ['id' => 'video', 'type' => 'video', 'title' => 'Watch', 'url' => ''],
    ];
});

test('family30 unfinished authored blocks save and survive publication when deliberately hidden while readers stay on immutable releases', function () {
    $draft = [...$this->published, 'sections' => [['id' => 'unfinished', 'columns' => 1, 'blocks' => $this->unfinished]]];
    $this->actingAs($this->editor)->putJson($this->url, ['content' => $draft, 'version' => 2])->assertOk()
        ->assertJsonPath('version', 3)->assertJsonPath('readiness.actor_id', $this->editor->id)->assertJsonPath('readiness.page_id', $this->page->id)
        ->assertJsonPath('readiness.version', 3)->assertJsonPath('readiness.content_ready', false)->assertJsonPath('readiness.editable', true)
        ->assertJsonPath('readiness.blocked_reason', null)->assertJsonPath('readiness.issues.0.block_id', 'text');
    $saved = $this->page->fresh()->draft;
    $this->postJson($this->url.'/submit', ['version' => 3])->assertUnprocessable()->assertJsonValidationErrors(['sections.0.blocks.0.body', 'sections.0.blocks.2.action_label', 'sections.0.blocks.4.question']);
    $this->postJson($this->url.'/publish', ['version' => 3])->assertUnprocessable();
    expect($this->page->revisions()->count())->toBe(1)->and($this->release->fresh()->content)->toBe($this->published)
        ->and($this->page->fresh()->draft)->toBe($saved)->and($this->page->fresh()->draft_version)->toBe(3);
    $this->get($this->url.'/edit')->assertInertia(fn (Assert $p) => $p->where('readiness.content_ready', false)->has('page.draft.sections.0.blocks', 8));
    $hidden = [...$saved, 'sections' => [['id' => 'unfinished', 'columns' => 1, 'blocks' => array_map(fn ($block) => [...$block, 'hidden' => true], $saved['sections'][0]['blocks'])]]];
    $this->putJson($this->url, ['content' => $hidden, 'version' => 3])->assertOk()->assertJsonPath('readiness.content_ready', true);
    $revision = $this->publisher->publish($this->editor, $this->page, 4, null, null);
    expect($revision->content['sections'][0]['blocks'])->toBe($this->page->fresh()->draft['sections'][0]['blocks'])
        ->and($this->release->fresh()->content)->toBe($this->published);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$this->page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('selectedPage.content.sections', [])->where('participation.polls', []));
    Notification::assertNothingSent();
});

test('family30 complete authored content and automatic empty feeds can submit and publish without selected dynamic records', function () {
    Storage::disk('private')->put('company-hub/complete.png', 'image');
    Storage::disk('private')->put('company-hub/complete.pdf', 'pdf');
    $image = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/complete.png', 'name' => 'image.png', 'mime' => 'image/png', 'size' => 5]);
    $file = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/complete.pdf', 'name' => 'file.pdf', 'mime' => 'application/pdf', 'size' => 3]);
    $blocks = [
        ['id' => 'text', 'type' => 'text', 'title' => 'Information', 'body' => 'Complete text.'],
        ['id' => 'notice', 'type' => 'notice', 'title' => 'Reminder', 'body' => 'Complete callout.'],
        ['id' => 'button', 'type' => 'button', 'title' => 'Explore', 'action_label' => 'Open resources', 'url' => '/company-hub?view=resources'],
        ['id' => 'links', 'type' => 'links', 'title' => 'Links', 'links' => [['id' => 'one', 'title' => 'Resources', 'url' => '/company-hub?view=resources']]],
        ['id' => 'poll', 'type' => 'poll', 'title' => 'Question', 'question' => 'Which activity?', 'options' => ['Walk', 'Lunch']],
        ['id' => 'image', 'type' => 'image', 'title' => 'Photo', 'media_id' => $image->id],
        ['id' => 'file', 'type' => 'file', 'title' => 'Download', 'media_id' => $file->id],
        ['id' => 'video', 'type' => 'video', 'title' => 'Watch', 'url' => 'https://player.vimeo.com/video/123'],
    ];
    $automatic = array_map(fn ($type) => ['id' => $type, 'type' => $type, 'title' => $type === 'featured' ? '' : 'Current information'],
        ['featured', 'news', 'resources', 'people', 'teams', 'services', 'events', 'shoutouts', 'celebrations', 'notices']);
    $draft = [...$this->published, 'sections' => [['id' => 'authored', 'columns' => 1, 'blocks' => $blocks], ['id' => 'automatic', 'columns' => 2, 'blocks' => $automatic]]];
    $this->actingAs($this->editor)->putJson($this->url, ['content' => $draft, 'version' => 2])->assertOk()->assertJsonPath('readiness.content_ready', true);
    $revision = $this->publisher->submit($this->editor, $this->page, 3);
    $this->publisher->approve($this->editor, $this->page, $revision, null, null);
    expect($revision->fresh()->state)->toBe('approved')->and($revision->fresh()->content)->toBe($this->page->fresh()->draft)
        ->and($this->release->fresh()->content)->toBe($this->published);
});

test('family30 hidden unfinished draft fields cannot bypass URL media site team or style boundaries', function (string $invalid) {
    $block = ['id' => 'hidden', 'type' => 'button', 'title' => '', 'hidden' => true, 'url' => ''];
    $draft = [...$this->published, 'sections' => [['id' => 'hidden', 'columns' => 1, 'blocks' => [$block]]]];
    if ($invalid === 'url') {
        $draft['sections'][0]['blocks'][0]['url'] = 'javascript:alert(1)';
    } elseif ($invalid === 'media') {
        $draft['sections'][0]['blocks'][0] = ['id' => 'hidden', 'type' => 'image', 'title' => '', 'hidden' => true, 'media_id' => 999999];
    } elseif ($invalid === 'site') {
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $draft['audience'] = ['type' => 'sites', 'ids' => [$outside->id]];
    } elseif ($invalid === 'team') {
        $draft['sections'][0]['blocks'][0] = ['id' => 'hidden', 'type' => 'people', 'title' => '', 'hidden' => true, 'team_ids' => [999999]];
    } elseif ($invalid === 'video') {
        $draft['sections'][0]['blocks'][0] = ['id' => 'hidden', 'type' => 'video', 'title' => '', 'hidden' => true, 'url' => 'https://example.com/video'];
    } else {
        $draft['sections'][0]['blocks'][0]['tone'] = 'arbitrary-style';
    }
    $this->actingAs($this->editor)->putJson($this->url, ['content' => $draft, 'version' => 2])->assertUnprocessable();
    expect($this->page->fresh()->draft_version)->toBe(2)->and($this->page->fresh()->draft)->toBe($this->published)
        ->and($this->release->fresh()->content)->toBe($this->published);
})->with(['url', 'media', 'site', 'team', 'video', 'style']);

test('family30 active review and future schedule require explicit canonical transitions before draft edits restore ownership or another submission', function (string $state) {
    $page = $this->publisher->save($this->editor, $this->page, [...$this->published, 'title' => 'Proposed information'], 2);
    $blocking = $state === 'review' ? $this->publisher->submit($this->editor, $page, 3)
        : $this->publisher->publish($this->editor, $page, 3, now()->addDay()->toISOString(), null);
    $snapshot = $blocking->fresh()->content;
    $this->actingAs($this->editor)->putJson($this->url, ['content' => [...$page->draft, 'title' => 'Blocked edit'], 'version' => 3])->assertConflict();
    $this->postJson($this->url.'/revisions/'.$this->release->id.'/restore', ['version' => 3])->assertConflict();
    $this->postJson($this->url.'/owner', ['owner_id' => $this->editor->id, 'version' => 3])->assertConflict();
    $this->postJson($this->url.'/submit', ['version' => 3])->assertConflict();
    $this->postJson($this->url.'/publish', ['version' => 3])->assertConflict();
    $this->get($this->url.'/edit')->assertInertia(fn (Assert $p) => $p->where('readiness.editable', false)
        ->where('readiness.blocked_reason', $state)->where('readiness.blocking_revision_id', $blocking->id));
    expect($this->page->fresh()->draft_version)->toBe(3)->and($this->page->revisions()->count())->toBe(2)
        ->and($blocking->fresh()->content)->toBe($snapshot)->and($this->release->fresh()->content)->toBe($this->published)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id);
    $action = $state === 'review' ? 'withdraw' : 'cancel_schedule';
    $this->postJson($this->url.'/revisions/'.$blocking->id.'/review', ['action' => $action, 'version' => 2])->assertConflict();
    $this->actingAs($this->reader)->postJson($this->url.'/revisions/'.$blocking->id.'/review', ['action' => $action, 'version' => 3])->assertNotFound();
    $this->actingAs($this->editor)->postJson($this->url.'/revisions/'.$blocking->id.'/review', ['action' => $action, 'version' => 3])->assertOk();
    $this->putJson($this->url, ['content' => [...$page->draft, 'title' => 'Allowed edit'], 'version' => 3])->assertOk()
        ->assertJsonPath('readiness.editable', true)->assertJsonPath('readiness.blocked_reason', null);
    $new = $this->publisher->publish($this->editor, $this->page, 4, null, null);
    expect($blocking->fresh()->content)->toBe($snapshot)->and($blocking->fresh()->state)->toBe($state === 'review' ? 'withdrawn' : 'cancelled')
        ->and($new->fresh()->content['title'])->toBe('Allowed edit')->and($this->release->fresh()->content)->toBe($this->published);
})->with(['review', 'schedule']);

test('family30 approval rechecks completeness of an older immutable submission rather than relying on a ready current draft', function () {
    $incomplete = $this->page->revisions()->create(['draft_version' => 2, 'state' => 'submitted', 'created_by' => $this->editor->id,
        'content' => [...$this->published, 'sections' => [['id' => 'legacy', 'columns' => 1, 'blocks' => [['id' => 'unfinished', 'type' => 'text', 'title' => 'Incomplete', 'body' => '']]]]]]);
    $snapshot = $incomplete->fresh()->content;
    $this->actingAs($this->editor)->postJson($this->url.'/revisions/'.$incomplete->id.'/approve')->assertUnprocessable()->assertJsonValidationErrors('sections.0.blocks.0.body');
    expect($incomplete->fresh()->state)->toBe('submitted')->and($incomplete->fresh()->content)->toBe($snapshot)
        ->and($this->page->fresh()->draft_version)->toBe(2)->and($this->release->fresh()->content)->toBe($this->published);
});

test('family30 saved unfinished work remains recoverable through immutable restore history draft duplication and reusable sections', function () {
    $draft = [...$this->published, 'sections' => [['id' => 'work', 'columns' => 1, 'blocks' => $this->unfinished]]];
    $page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $saved = $page->fresh()->draft;
    app(HubSectionLibrary::class)->save($this->editor, $page, 'Unfinished arrangement', $saved['sections'][0], 'personal');
    $preset = app(HubSectionLibrary::class)->workspace($this->editor, $page)['presets'][0];
    expect($preset['section'])->toBe($saved['sections'][0]);
    $copies = app(HubPageCopy::class);
    $options = $copies->read($this->editor, $page, 'draft');
    expect($options['can_duplicate'])->toBeTrue();
    $copy = $copies->duplicate($this->editor, $page, [...$options, 'media_versions' => [], 'revision_id' => null]);
    expect($copy->draft['sections'][0]['blocks'][2]['url'])->toBe($saved['sections'][0]['blocks'][2]['url'])->and($copy->liveRevision())->toBeNull();
    $this->publisher->restore($this->editor, $page, $this->release, 3);
    $retained = $this->page->revisions()->where('draft_version', 3)->sole();
    expect($retained->fresh()->content)->toBe($saved);
    $restored = $this->publisher->restore($this->editor, $this->page, $retained, 4);
    expect($restored->draft)->toBe($saved)->and(HubReadiness::issues($restored->draft))->not->toBeEmpty()
        ->and($this->release->fresh()->content)->toBe($this->published);
});

test('family30 draft writes recheck retained actor permissions sites and current staff status', function (string $change) {
    if ($change === 'permissions') {
        $this->editor->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'company_hub.write')->sole()->id => ['allowed' => false],
            Permission::where('key', 'company_hub.edit_all')->sole()->id => ['allowed' => false],
        ]);
    } elseif ($change === 'site') {
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    } else {
        $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    }
    try {
        $this->publisher->save($this->editor, $this->page, [...$this->published, 'title' => 'Stale actor edit'], 2);
        $this->fail('Current write access must be required.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(404);
    }
    expect($this->page->fresh()->draft_version)->toBe(2)->and($this->release->fresh()->content)->toBe($this->published);
})->with(['permissions', 'site', 'former']);

test('family30 a blank page title partial hero link and missing hero image remain safe saved work but block release', function () {
    $draft = [...$this->published, 'title' => '', 'hero' => [...$this->published['hero'], 'style' => 'image', 'action_label' => 'Explore', 'action_url' => '']];
    $this->actingAs($this->editor)->putJson($this->url, ['content' => $draft, 'version' => 2])->assertOk()->assertJsonCount(3, 'readiness.issues')
        ->assertJsonPath('readiness.content_ready', false)->assertJsonPath('readiness.issues.0.code', 'page_title');
    $this->postJson($this->url.'/submit', ['version' => 3])->assertUnprocessable()->assertJsonValidationErrors(['title', 'cover_id', 'hero.action_url']);
    $this->putJson($this->url, ['content' => $draft, 'version' => 2])->assertConflict();
    expect($this->release->fresh()->content)->toBe($this->published)->and($this->page->revisions()->count())->toBe(1);
});

test('family30 readiness conceals a blocking historical revision identifier outside current author scope', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $blocking = $this->page->revisions()->create(['draft_version' => 1, 'created_by' => $this->editor->id, 'state' => 'approved',
        'approved_at' => now(), 'publish_at' => now()->addDay(), 'content' => [...$this->published,
            'title' => 'Private historical schedule', 'audience' => ['type' => 'sites', 'ids' => [$outside->id]],
        ]]);
    $context = app(HubReadiness::class)->forPage($this->editor, $this->page);
    expect($context['editable'])->toBeFalse()->and($context['blocked_reason'])->toBe('schedule')->and($context['blocking_revision_id'])->toBeNull();
    $this->actingAs($this->editor)->postJson($this->url.'/revisions/'.$blocking->id.'/review', ['action' => 'cancel_schedule', 'version' => 2])->assertNotFound();
    expect($blocking->fresh()->state)->toBe('approved')->and($this->release->fresh()->content)->toBe($this->published);
});

test('family30 nullable unfinished authored fields reload and duplicate as strings while older snapshots remain unchanged', function () {
    $blocks = [
        ['id' => 'links', 'type' => 'links', 'title' => null, 'links' => [['id' => 'one', 'title' => null, 'url' => null]]],
        ['id' => 'poll', 'type' => 'poll', 'title' => 'Question', 'question' => '', 'options' => [null, '']],
        ['id' => 'empty-links', 'type' => 'links', 'title' => 'More links', 'links' => null],
        ['id' => 'empty-poll', 'type' => 'poll', 'title' => 'Another question', 'options' => null],
    ];
    $older = $this->page->revisions()->create(['draft_version' => 1, 'state' => 'superseded', 'created_by' => $this->editor->id,
        'content' => [...$this->published, 'sections' => [['id' => 'legacy', 'columns' => 1,
            'blocks' => array_map(fn ($block) => [...$block, 'hidden' => true], $blocks),
        ]]],
    ]);
    $snapshot = $older->fresh()->content;
    $draft = [...$this->published, 'sections' => [['id' => 'working', 'columns' => 1, 'blocks' => $blocks]]];
    $this->actingAs($this->editor)->putJson($this->url, ['content' => $draft, 'version' => 2])->assertOk()->assertJsonPath('readiness.content_ready', false);
    $saved = $this->page->fresh()->draft;
    $this->get($this->url.'/edit')->assertInertia(fn (Assert $p) => $p
        ->where('page.draft.sections.0.blocks.0.title', '')
        ->where('page.draft.sections.0.blocks.0.links.0.title', '')->where('page.draft.sections.0.blocks.0.links.0.url', '')
        ->where('page.draft.sections.0.blocks.1.options', ['', ''])
        ->where('page.draft.sections.0.blocks.2.links', [])->where('page.draft.sections.0.blocks.3.options', []));
    $copies = app(HubPageCopy::class);
    $options = $copies->read($this->editor, $this->page->fresh(), 'draft');
    $copy = $copies->duplicate($this->editor, $this->page, [...$options, 'media_versions' => [], 'revision_id' => null]);
    expect($copy->draft['sections'][0]['blocks'][0]['links'][0]['url'])->toBe('')
        ->and($copy->draft['sections'][0]['blocks'][1]['options'])->toBe(['', ''])
        ->and($copy->draft['sections'][0]['blocks'][2]['links'])->toBe([])->and($copy->draft['sections'][0]['blocks'][3]['options'])->toBe([]);
    $restored = $this->publisher->restore($this->editor, $this->page, $older, 3);
    expect($restored->draft['sections'][0]['blocks'][0]['links'][0])->toMatchArray(['id' => 'one', 'url' => '', 'title' => ''])
        ->and($restored->draft['sections'][0]['blocks'][1]['options'])->toBe(['', ''])
        ->and($older->fresh()->content)->toBe($snapshot)->and($this->release->fresh()->content)->toBe($this->published)
        ->and($this->page->revisions()->where('draft_version', 3)->sole()->content)->toBe($saved);
});
