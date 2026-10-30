<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubTemplates;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Shared initiatives', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id, 'bio' => 'Private HR biography']);
    $this->team->sites()->attach($this->site);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->campaignSettings = ['steps' => [], 'ends_on' => today()->addMonth()->toDateString(),
        'milestones' => [['id' => 'published', 'title' => 'Shared guide released', 'status' => 'done'], ['id' => 'next', 'title' => 'Discuss what helped', 'status' => 'next']]];
    $this->publishPage = function (string $kind, string $title, array $extra = []) {
        $page = $this->publisher->create($this->editor, $kind, $title, 'blank');
        $content = [...$page->draft, 'sections' => [['id' => 'main', 'columns' => 1, 'blocks' => [
            ['id' => 'body', 'type' => 'text', 'title' => 'About this page', 'body' => 'Complete authored information.'],
        ]]], ...($kind === 'campaign' ? ['campaign' => $this->campaignSettings, 'team_ids' => [$this->team->id]] : []), ...$extra];
        $page = $this->publisher->save($this->editor, $page, $content, 1);
        $this->publisher->publish($this->editor, $page, 2, null, null);

        return $page->fresh();
    };
    $this->campaignBlock = fn ($id = 'campaigns', $hidden = false) => ['id' => $id, 'type' => 'campaign', 'title' => $hidden ? 'Hiddencampaignneedle' : 'Shared initiatives', 'hidden' => $hidden, 'tone' => 'soft', 'limit' => 12];
});

test('approved starter metadata creates only the exact unfinished private compositions and preserves old saved releases', function () {
    $legacySections = [['id' => 'old-story', 'columns' => 1, 'blocks' => [['id' => 'legacy-body', 'type' => 'text', 'title' => 'Our story', 'body' => 'Previously authored content.']]]];
    $legacy = ($this->publishPage)('news', 'An existing story', ['sections' => $legacySections]);
    $release = $legacy->currentRelease;
    $draft = $legacy->draft;
    $plans = array_slice(HubTemplates::catalogue(), 0, 8);
    foreach ($plans as $plan) {
        $this->actingAs($this->editor)->post('/company-hub/pages', ['kind' => $plan['kind'], 'template' => $plan['key'], 'title' => 'New '.$plan['label']])->assertRedirect();
        $page = HubPage::latest('id')->firstOrFail();
        expect(collect($page->draft['sections'])->flatMap(fn ($section) => $section['blocks'])->pluck('type')->all())->toBe($plan['block_types'])
            ->and($page->draft['sections'])->toEqual(HubTemplates::sections($plan['key']))
            ->and($page->revisions()->count())->toBe(0)->and(app(HubAccess::class)->visibleRevision($this->reader, $page))->toBeNull();
        $this->putJson('/company-hub/manage/'.$page->id, ['version' => 1, 'content' => $page->draft])->assertOk()->assertJsonPath('version', 2);
        $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertNotFound();
    }
    $this->actingAs($this->editor)->get('/company-hub/manage/'.$page->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('blockTypes', fn ($types) => collect($types)->contains('campaign'))
        ->has('starterLayouts', 10)->where('previewData.starterTemplates', HubTemplates::catalogue()));
    $this->postJson('/company-hub/pages', ['kind' => 'news', 'template' => 'forged-plan', 'title' => 'Invalid plan'])->assertUnprocessable();
    expect($legacy->fresh()->draft)->toBe($draft)->and($release->fresh()->content['sections'])->toEqual($legacySections);
    $this->actingAs($this->reader)->postJson('/company-hub/pages', ['kind' => 'news', 'template' => 'event-recap', 'title' => 'Denied authoring'])->assertForbidden();
    Notification::assertNothingSent();
});

test('Home and page campaign blocks read only current authorised releases and never unpublished progress or private fields', function () {
    $outsideSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$outsideSite->id]]);
    $this->editor = $this->editor->fresh();
    $visible = ($this->publishPage)('campaign', 'Currently shared initiative');
    $private = ($this->publishPage)('campaign', 'Outsideprivatecampaignneedle', ['audience' => ['type' => 'sites', 'ids' => [$outsideSite->id]]]);
    $expired = ($this->publishPage)('campaign', 'Expiredcampaignneedle');
    $expired->currentRelease()->sole()->update(['expires_at' => now()->subMinute()]);
    $archived = ($this->publishPage)('campaign', 'Archivedcampaignneedle');
    $this->publisher->archive($this->editor, $archived, 2);
    $unreleased = $this->publisher->create($this->editor, 'campaign', 'Draftcampaignneedle', 'blank');
    $scheduled = $this->publisher->create($this->editor, 'campaign', 'Scheduledcampaignneedle', 'blank');
    $this->publisher->publish($this->editor, $scheduled, 1, now()->addDay()->toISOString(), null);
    $home = ($this->publishPage)('home', 'Company home', ['sections' => [['id' => 'initiatives', 'columns' => 1, 'blocks' => [($this->campaignBlock)()]]]]);
    $page = ($this->publishPage)('page', 'Initiatives in a page', ['sections' => [['id' => 'initiatives', 'columns' => 1, 'blocks' => [($this->campaignBlock)()]]]]);
    $settings = $this->campaignSettings;
    $settings['milestones'][1]['status'] = 'done';
    $this->publisher->save($this->editor, $visible, [...$visible->draft, 'title' => 'Unpublisheddraftneedle', 'campaign' => $settings], 2);

    $this->actingAs($this->reader)->get('/company-hub')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('blockData.campaigns.campaigns', 1)->where('blockData.campaigns.campaigns.0.id', $visible->id)
        ->where('blockData.campaigns.campaigns.0.title', 'Currently shared initiative')->where('blockData.campaigns.campaigns.0.campaign_progress.done', 1));
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('blockData.campaigns.campaigns', 1)->where('blockData.campaigns.campaigns.0.slug', $visible->slug)
        ->where('blockData.campaigns.campaigns.0.campaign_progress.total', 2));
    $feed = app(HubFeed::class)->forUser($this->reader->fresh(), 'No matching page');
    expect($feed['pages'])->toBe([])->and(array_column($feed['blockData']['campaigns']['campaigns'], 'id'))->toBe([$visible->id]);
    expect(json_encode($feed['blockData']))->not->toContain('Private HR biography', 'Outsideprivatecampaignneedle', 'Expiredcampaignneedle', 'Archivedcampaignneedle', 'Draftcampaignneedle', 'Scheduledcampaignneedle', 'Unpublisheddraftneedle');
    $this->get('/company-hub/read/'.$private->slug)->assertNotFound();
    $this->get('/company-hub/read/'.$unreleased->slug)->assertNotFound();
    $this->publisher->archive($this->editor, $visible->fresh(), 3);
    $this->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('blockData.campaigns.campaigns', []));
    expect($home->fresh()->currentRelease->content['sections'][0]['blocks'][0]['type'])->toBe('campaign');
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->reader->fresh())->get('/company-hub')->assertForbidden();
    Notification::assertNothingSent();
});

test('department campaign blocks intersect available source teams and cannot broaden empty or withdrawn team selections', function () {
    $otherTeam = HrDepartment::create(['name' => 'Other current team', 'is_active' => true]);
    $otherTeam->sites()->attach($this->site);
    $first = ($this->publishPage)('campaign', 'Our team initiative');
    ($this->publishPage)('campaign', 'Other team initiative', ['team_ids' => [$otherTeam->id]]);
    $blocks = [[...($this->campaignBlock)(), 'team_ids' => [$this->team->id, $otherTeam->id]]];
    $space = ($this->publishPage)('department', 'Our connected space', ['team_ids' => [$this->team->id], 'sections' => [['id' => 'initiatives', 'columns' => 1, 'blocks' => $blocks]]]);
    $empty = ($this->publishPage)('department', 'Unconnected space', ['team_ids' => [], 'sections' => [['id' => 'initiatives', 'columns' => 1, 'blocks' => $blocks]]]);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$space->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('blockData.campaigns.campaigns', 1)->where('blockData.campaigns.campaigns.0.id', $first->id));
    $this->get('/company-hub/read/'.$empty->slug)->assertOk()->assertInertia(fn (Assert $p) => $p->where('blockData.campaigns.campaigns', []));
    $this->actingAs($this->editor)->get('/company-hub/manage/'.$empty->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('previewData.blockData.campaigns.campaigns', []));
    $this->team->update(['is_active' => false]);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$space->slug)->assertInertia(fn (Assert $p) => $p->where('blockData.campaigns.campaigns', []));
});

test('hidden campaign blocks retain immutable history and private copies without reader search or personal interaction access', function () {
    $campaign = ($this->publishPage)('campaign', 'Shared initiative');
    $blocks = [['id' => 'body', 'type' => 'text', 'title' => 'Introduction', 'body' => 'Complete visible content.'], ($this->campaignBlock)('hidden-campaign', true)];
    $page = ($this->publishPage)('page', 'Reusable campaign page', ['sections' => [['id' => 'main', 'columns' => 1, 'blocks' => $blocks]]]);
    $release = $page->currentRelease;
    $snapshot = $release->content;
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('selectedPage.content.sections.0.blocks', 1)->missing('blockData.hidden-campaign')->where('participation.campaign', []));
    $this->getJson('/company-hub/search?query=Hiddencampaignneedle')->assertOk()->assertJsonCount(0, 'results');
    $this->postJson('/company-hub/pages/'.$page->id.'/interactions', ['kind' => 'campaign', 'step_id' => 'published', 'step_key' => str_repeat('0', 64), 'active' => true])->assertNotFound();
    $this->getJson('/company-hub/manage/'.$page->id.'/revisions/'.$release->id)->assertNotFound();
    $this->actingAs($this->editor)->get('/company-hub/manage/'.$page->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('page.draft.sections.0.blocks.1.hidden', true)->has('previewData.blockData.hidden-campaign.campaigns', 1)
        ->where('previewData.blockData.hidden-campaign.campaigns.0.id', $campaign->id));
    $this->getJson('/company-hub/manage/'.$page->id.'/revisions/'.$release->id)->assertOk()->assertJsonPath('content.sections.0.blocks.1.hidden', true);
    $context = $this->getJson('/company-hub/manage/'.$page->id.'/duplicate?source=published')->assertOk()->json();
    $id = $this->postJson('/company-hub/manage/'.$page->id.'/duplicate', [
        'actor_id' => $context['actor_id'], 'version' => $context['version'], 'source' => 'published',
        'revision_id' => $context['revision_id'], 'media_versions' => $context['media_versions'],
    ])->assertCreated()->json('page_id');
    $copy = HubPage::findOrFail($id);
    expect($copy->draft['sections'][0]['blocks'][1])->toMatchArray(['type' => 'campaign', 'hidden' => true, 'title' => 'Hiddencampaignneedle', 'limit' => 12, 'tone' => 'soft'])
        ->and($copy->revisions()->count())->toBe(0)->and($release->fresh()->content)->toBe($snapshot);
    $shown = $page->draft;
    $shown['sections'][0]['blocks'][1]['hidden'] = false;
    $this->publisher->save($this->editor, $page, $shown, 2);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->missing('blockData.hidden-campaign'));
    $this->get('/company-hub/read/'.$copy->slug)->assertNotFound();
    expect(HubContent::visibleBlocks($release->fresh()->content)->pluck('id')->all())->toBe(['body']);
    Notification::assertNothingSent();
});
