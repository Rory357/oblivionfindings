<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubFeatured;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
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
    $this->team = HrDepartment::create(['name' => 'Learning team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
});

test('authored designer types validate their bounded settings and new Home defaults never rewrite a legacy publication', function () {
    $story = $this->publisher->create($this->editor, 'news', 'Available company story');
    $story->update(['draft' => [...$story->draft, 'sections' => []]]);
    $this->publisher->publish($this->editor, $story, 1, null, null);
    $unpublished = $this->publisher->create($this->editor, 'news', 'Not yet published');
    $future = $this->publisher->create($this->editor, 'news', 'Future publication');
    $future->update(['draft' => [...$future->draft, 'sections' => []]]);
    $this->publisher->publish($this->editor, $future, 1, now()->addHour()->toISOString(), null);
    $page = $this->publisher->create($this->editor, 'page', 'Designer settings');
    $blocks = [
        ['id' => 'feature', 'type' => 'featured', 'title' => '', 'body' => '', 'story_id' => $story->id, 'layout' => 'equal', 'tone' => 'soft', 'min_height' => 600],
        ['id' => 'news', 'type' => 'news', 'title' => 'Team news', 'layout' => 'list', 'team_ids' => [$this->team->id]],
        ['id' => 'resources', 'type' => 'resources', 'title' => 'Team resources', 'team_ids' => [$this->team->id]],
        ['id' => 'links', 'type' => 'links', 'title' => 'Useful links', 'layout' => 'list', 'links' => [['id' => 'one', 'title' => 'Published story', 'description' => 'A useful story', 'url' => '/company-hub/read/'.$story->slug]]],
        ['id' => 'button', 'type' => 'button', 'title' => 'Take the next step', 'body' => 'Find current company guides.', 'action_label' => 'Open resources', 'url' => '/company-hub?view=resources', 'tone' => 'soft'],
        ['id' => 'notice', 'type' => 'notice', 'title' => 'A useful reminder', 'body' => 'An authored callout, separate from announcements.', 'tone' => 'plain'],
    ];
    $draft = [...$page->draft, 'sections' => [['id' => 'designer', 'columns' => 2, 'blocks' => $blocks]]];
    $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$page->id, ['content' => $draft, 'version' => 1])->assertOk();
    expect($page->fresh()->draft['sections'][0]['blocks'][0]['title'])->toBe('');
    foreach ([
        [0, ['story_id' => 999999]], [0, ['story_id' => 2147483648]], [0, ['story_id' => $unpublished->id]], [0, ['story_id' => $future->id]],
        [0, ['story_id' => $page->id]], [0, ['layout' => 'list']], [0, ['tone' => 'arbitrary-css']], [0, ['body' => str_repeat('x', 601)]],
        [1, ['layout' => 'equal']], [1, ['team_ids' => [999999]]], [1, ['team_ids' => [$this->team->id, $this->team->id]]],
        [4, ['url' => 'javascript:alert(1)']],
    ] as [$index, $change]) {
        $invalid = $draft;
        $invalid['sections'][0]['blocks'][$index] = [...$invalid['sections'][0]['blocks'][$index], ...$change];
        $this->putJson('/company-hub/manage/'.$page->id, ['content' => $invalid, 'version' => 2])->assertUnprocessable();
    }
    expect($page->fresh()->draft_version)->toBe(2);
    $home = $this->publisher->create($this->editor, 'home', 'Existing shared Home');
    expect(HubContent::visibleBlocks($home->draft)->first()['type'])->toBe('featured');
    $legacy = [...$home->draft, 'sections' => [['id' => 'legacy', 'columns' => 1, 'blocks' => [['id' => 'welcome', 'type' => 'text', 'title' => 'Our existing Home', 'body' => 'Existing published design.']]]]];
    $home = $this->publisher->save($this->editor, $home, $legacy, 1);
    $release = $this->publisher->publish($this->editor, $home, 2, null, null);
    $snapshot = $release->fresh()->content;
    expect($snapshot)->toEqual($legacy);
    $this->actingAs($this->reader)->get('/company-hub')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('home.sections', 1)->where('home.sections.0.blocks.0.type', 'text')->where('blockData', []));
    expect($home->fresh()->draft)->toBe($snapshot)->and($release->fresh()->content)->toBe($snapshot);
});

test('featured current authorisation controls copied overrides search and direct media without changing history', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$outside->id]]);
    $this->editor = $this->editor->fresh();
    $fallback = $this->publisher->create($this->editor, 'news', 'Canonical public fallback');
    $fallback = $this->publisher->save($this->editor, $fallback, [...$fallback->draft, 'summary' => 'Current public summary.', 'sections' => []], 1);
    $this->publisher->publish($this->editor, $fallback, 2, null, null);
    $chosen = $this->publisher->create($this->editor, 'news', 'Chosen public story');
    Storage::disk('private')->put('company-hub/chosen-source.jpg', 'source image');
    $sourceMedia = HubMedia::create(['hub_page_id' => $chosen->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/chosen-source.jpg', 'name' => 'chosen.jpg', 'mime' => 'image/jpeg', 'size' => 12]);
    $chosen = $this->publisher->save($this->editor, $chosen, [...$chosen->draft, 'summary' => 'Chosen source summary.', 'cover_id' => $sourceMedia->id, 'sections' => []], 1);
    $this->publisher->publish($this->editor, $chosen, 2, null, null);
    $page = $this->publisher->create($this->editor, 'knowledge', 'Featured company guide');
    $media = collect(['override', 'hidden', 'independent'])->mapWithKeys(function ($name) use ($page) {
        $path = 'company-hub/featured-'.$name.'.jpg';
        Storage::disk('private')->put($path, $name.' image');

        return [$name => HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $this->editor->id, 'path' => $path, 'name' => $name.'.jpg', 'mime' => 'image/jpeg', 'size' => 12])];
    });
    $feature = ['id' => 'feature', 'type' => 'featured', 'title' => 'CopiedHeadingNeedle', 'body' => 'CopiedSummaryNeedle', 'alt' => 'CopiedAltNeedle', 'story_id' => $chosen->id, 'media_id' => $media['override']->id, 'layout' => 'single', 'tone' => 'soft'];
    $draft = [...$page->draft, 'sections' => [['id' => 'feature-section', 'columns' => 1, 'blocks' => [
        $feature,
        [...$feature, 'id' => 'hidden-feature', 'title' => 'HiddenFeatureHeading', 'body' => 'HiddenFeatureNeedle', 'media_id' => $media['hidden']->id, 'hidden' => true],
        ['id' => 'photo', 'type' => 'image', 'title' => 'Our independent photograph', 'body' => 'A retained caption.', 'media_id' => $media['independent']->id],
    ]]]];
    $page = $this->publisher->save($this->editor, $page, $draft, 1);
    $release = $this->publisher->publish($this->editor, $page, 2, null, null);
    $snapshot = $release->fresh()->content;
    expect($snapshot)->toEqual($draft);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('selectedPage.content.sections.0.blocks', 2)->where('blockData.feature.featured.lead.id', $chosen->id)
        ->where('blockData.feature.featured.lead.title', 'CopiedHeadingNeedle')->where('blockData.feature.featured.supporting', [])
        ->where('blockData.feature.featured.lead.cover_url', '/company-hub/media/'.$media['override']->id)->missing('blockData.hidden-feature'));
    $this->get('/company-hub/media/'.$media['override']->id)->assertOk();
    $this->get('/company-hub/media/'.$media['hidden']->id)->assertNotFound();
    $this->getJson('/company-hub/search?query=CopiedSummaryNeedle')->assertJsonCount(1, 'results');
    $this->getJson('/company-hub/search?query=HiddenFeatureNeedle')->assertJsonCount(0, 'results');

    $chosen = $this->publisher->save($this->editor, $chosen, [...$chosen->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]], 2);
    $this->publisher->publish($this->editor, $chosen, 3, null, null);
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('blockData.feature.featured.lead.id', $fallback->id)->where('blockData.feature.featured.lead.title', 'Canonical public fallback')
        ->where('blockData.feature.featured.lead.summary', 'Current public summary.')->where('blockData.feature.featured.lead.cover_url', null)
        ->where('selectedPage.content.sections.0.blocks.0.story_id', $fallback->id)->where('selectedPage.content.sections.0.blocks.0.title', 'Canonical public fallback')
        ->where('selectedPage.content.sections.0.blocks.0.body', 'Current public summary.')->where('selectedPage.content.sections.0.blocks.0.media_id', null)
        ->where('selectedPage.content.sections.0.blocks.0.alt', ''));
    $this->get('/company-hub/media/'.$media['override']->id)->assertNotFound();
    $this->get('/company-hub/media/'.$sourceMedia->id)->assertNotFound();
    $this->get('/company-hub/media/'.$media['independent']->id)->assertOk();
    $this->getJson('/company-hub/search?query=CopiedSummaryNeedle')->assertJsonCount(0, 'results');
    expect(app(HubFeed::class)->forUser($this->reader, 'CopiedSummaryNeedle')['pages'])->toBe([])
        ->and($release->fresh()->content)->toBe($snapshot)->and($page->fresh()->draft)->toBe($snapshot);
    $this->actingAs($this->editor)->get('/company-hub/manage/'.$page->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('page.draft.sections.0.blocks.0.body', 'CopiedSummaryNeedle')->has('previewData.blockData.hidden-feature.featured')
        ->has('previewData.featuredMedia', 3)->where('previewData.featuredMedia.0.id', $media['override']->id));
    $this->getJson('/company-hub/manage/'.$page->id.'/revisions/'.$release->id)->assertJsonPath('content.sections.0.blocks.0.body', 'CopiedSummaryNeedle');
    Notification::assertNothingSent();
});

test('Home feature uses the complete published pool while expired and archived references fall back to canonical or empty', function () {
    $source = $this->publisher->create($this->editor, 'news', 'Expiring selected story');
    $source->update(['draft' => [...$source->draft, 'sections' => []]]);
    $this->publisher->publish($this->editor, $source, 1, null, now()->addMinute()->toISOString());
    $fallback = $this->publisher->create($this->editor, 'news', 'Remaining public story');
    $fallback->update(['draft' => [...$fallback->draft, 'sections' => []]]);
    $this->publisher->publish($this->editor, $fallback, 1, null, null);
    $home = $this->publisher->create($this->editor, 'home', 'Shared authored Home');
    $homeDraft = $home->draft;
    $homeDraft['sections'][0]['blocks'][0] = [...$homeDraft['sections'][0]['blocks'][0], 'story_id' => $source->id, 'title' => 'RetainedFeatureHeading', 'body' => 'RetainedFeatureNeedle'];
    $home = $this->publisher->save($this->editor, $home, $homeDraft, 1);
    $release = $this->publisher->publish($this->editor, $home, 2, null, null);
    $snapshot = $release->fresh()->content;
    expect($snapshot)->toEqual($homeDraft);
    expect(app(HubFeed::class)->forUser($this->reader, 'does-not-match-any-story')['blockData']['featured-stories']['featured']['lead']['id'])->toBe($source->id);
    $this->travel(2)->minutes();
    $this->actingAs($this->reader)->get('/company-hub')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('blockData.featured-stories.featured.lead.id', $fallback->id)->where('home.sections.0.blocks.0.body', '')
        ->where('blockData.news.news.0.id', $fallback->id));
    $this->publisher->archive($this->editor, $fallback, 1);
    $this->get('/company-hub')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('blockData.featured-stories.featured', ['lead' => null, 'supporting' => []])->where('home.sections.0.blocks.0.story_id', null)
        ->where('home.sections.0.blocks.0.title', '')->where('home.sections.0.blocks.0.body', ''));
    expect(app(HubFeatured::class)->stories($this->reader))->toBe([])->and($release->fresh()->content)->toBe($snapshot);
});
