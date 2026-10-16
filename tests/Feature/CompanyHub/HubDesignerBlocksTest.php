<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\ItCatalogItem;
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
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Learning team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id, 'bio' => 'Private HR biography']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
});

test('hidden blocks retain exact editor history snapshots while every reader search extract file and poll path excludes them', function () {
    Storage::fake('private');
    $page = $this->publisher->create($this->editor, 'knowledge', 'Working guide');
    Storage::disk('private')->put('company-hub/designer-hidden.pdf', 'hidden file');
    $media = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/designer-hidden.pdf', 'name' => 'hidden.pdf', 'mime' => 'application/pdf', 'size' => 11]);
    $poll = ['id' => 'secret-poll', 'type' => 'poll', 'title' => 'Hidden question', 'hidden' => true, 'question' => 'Hidden activity?', 'options' => ['Walk', 'Lunch']];
    $draft = [...$page->draft, 'sections' => [
        ['id' => 'public', 'columns' => 1, 'blocks' => [['id' => 'intro', 'type' => 'text', 'title' => 'Visible introduction', 'body' => 'Open guide content', 'min_height' => 360]]],
        ['id' => 'secret-section', 'title' => 'Hidden section name', 'columns' => 1, 'blocks' => [
            ['id' => 'secret-text', 'type' => 'text', 'title' => 'Hidden text title', 'body' => 'Zqxvisibilityneedle', 'hidden' => true],
            ['id' => 'secret-file', 'type' => 'file', 'title' => 'Hidden downloadable file', 'media_id' => $media->id, 'hidden' => true],
            ['id' => 'secret-link', 'type' => 'links', 'title' => 'Hidden resources', 'links' => [['id' => 'reference', 'title' => 'Hidden reference', 'url' => '/hr/my/policies']], 'hidden' => true],
            $poll,
            ['id' => 'secret-people', 'type' => 'people', 'title' => 'Hidden finder', 'mode' => 'finder', 'hidden' => true],
        ]],
    ]];
    $page = $this->publisher->save($this->editor, $page, $draft, 1);
    $release = $this->publisher->publish($this->editor, $page, 2, null, null);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('selectedPage.content.sections', 1)->has('selectedPage.content.sections.0.blocks', 1)
        ->where('selectedPage.content.sections.0.blocks.0.min_height', 360)->where('resourceItems', [])
        ->where('blockData', [])->where('people', [])->where('participation.polls', []));
    $this->getJson('/company-hub/search?query=Zqxvisibilityneedle')->assertOk()->assertJsonCount(0, 'results');
    expect(app(HubFeed::class)->forUser($this->reader, 'Zqxvisibilityneedle')['pages'])->toBe([]);
    $this->get('/company-hub/media/'.$media->id)->assertNotFound();
    $this->postJson('/company-hub/pages/'.$page->id.'/interactions', ['kind' => 'vote', 'block_id' => $poll['id'], 'poll_key' => HubParticipation::pollKey($poll), 'choice' => 0])->assertNotFound();
    $this->actingAs($this->editor)->get('/company-hub/manage/'.$page->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('page.draft.sections', 2)->has('page.draft.sections.1.blocks', 5)
        ->where('page.draft.sections.1.blocks.0.body', 'Zqxvisibilityneedle')->has('previewData.blockData.secret-people.people', 2));
    $this->getJson('/company-hub/manage/'.$page->id.'/revisions/'.$release->id)->assertOk()
        ->assertJsonPath('content.sections.1.blocks.0.body', 'Zqxvisibilityneedle')->assertJsonPath('content.sections.1.blocks.0.hidden', true);
    $this->get('/company-hub/media/'.$media->id)->assertOk();
    $draft['sections'][1]['blocks'][0]['hidden'] = false;
    $this->publisher->save($this->editor, $page, $draft, 2);
    expect($release->fresh()->content['sections'][1]['blocks'][0]['hidden'])->toBeTrue()
        ->and(HubContent::visibleBlocks($release->fresh()->content)->pluck('id')->all())->toBe(['intro']);
    $this->actingAs($this->reader)->getJson('/company-hub/manage/'.$page->id.'/revisions/'.$release->id)->assertNotFound();
    Notification::assertNothingSent();
});

test('showing a block needs publication and restores only its own reader paths', function () {
    $page = $this->publisher->create($this->editor, 'page', 'Visibility changes');
    $poll = ['id' => 'poll', 'type' => 'poll', 'title' => 'Current activity', 'hidden' => true, 'question' => 'Choose an activity', 'options' => ['Walk', 'Lunch']];
    $draft = [...$page->draft, 'sections' => [['id' => 'one', 'columns' => 1, 'blocks' => [$poll, ['id' => 'keep-hidden', 'type' => 'text', 'title' => 'Still hidden', 'body' => 'Stillhiddenneedle', 'hidden' => true]]]]];
    $page = $this->publisher->save($this->editor, $page, $draft, 1);
    $this->publisher->publish($this->editor, $page, 2, null, null);
    $draft['sections'][0]['blocks'][0]['hidden'] = false;
    $page = $this->publisher->save($this->editor, $page, $draft, 2);
    $this->actingAs($this->reader)->postJson('/company-hub/pages/'.$page->id.'/interactions', ['kind' => 'vote', 'block_id' => 'poll', 'poll_key' => HubParticipation::pollKey($poll), 'choice' => 1])->assertNotFound();
    $this->publisher->publish($this->editor, $page, 3, null, null);
    $this->post('/company-hub/pages/'.$page->id.'/interactions', ['kind' => 'vote', 'block_id' => 'poll', 'poll_key' => HubParticipation::pollKey($poll), 'choice' => 1])->assertRedirect();
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('selectedPage.content.sections.0.blocks', 1)->where('selectedPage.content.sections.0.blocks.0.id', 'poll')
        ->where('participation.polls.poll.mine', 1));
    $this->getJson('/company-hub/search?query=Stillhiddenneedle')->assertJsonCount(0, 'results');
});

test('designer values are bounded and canonical team selectors cannot be forged or silently expanded', function () {
    $page = $this->publisher->create($this->editor, 'page', 'Designer settings');
    $base = [...$page->draft, 'sections' => [['id' => 'dynamic', 'columns' => 1, 'blocks' => [['id' => 'finder', 'type' => 'people', 'title' => 'People finder', 'mode' => 'finder', 'team_ids' => [$this->team->id], 'hidden' => '1', 'min_height' => '800']]]]];
    $valid = app(HubContent::class)->validate($base, $this->editor, $page);
    expect($valid['sections'][0]['blocks'][0]['hidden'])->toBeTrue()->and($valid['sections'][0]['blocks'][0]['min_height'])->toBe(800);
    $this->actingAs($this->editor);
    foreach ([['min_height' => 801], ['min_height' => -1], ['min_height' => '360px'], ['min_height' => 24.5], ['hidden' => 'sometimes'], ['mode' => 'orgchart'], ['team_ids' => [999999]], ['team_ids' => [$this->team->id, $this->team->id]], ['style' => 'position:fixed']] as $change) {
        $invalid = $base;
        $invalid['sections'][0]['blocks'][0] = [...$invalid['sections'][0]['blocks'][0], ...$change];
        $this->putJson('/company-hub/manage/'.$page->id, ['content' => $invalid, 'version' => 1])->assertUnprocessable();
    }
    $outside = HrDepartment::create(['name' => 'Unavailable department', 'is_active' => true]);
    $outside->sites()->attach(Site::factory()->create(['is_active' => true, 'archived' => false]));
    $invalid = $base;
    $invalid['sections'][0]['blocks'][0]['team_ids'] = [$outside->id];
    $this->putJson('/company-hub/manage/'.$page->id, ['content' => $invalid, 'version' => 1])->assertUnprocessable();
    $invalid['sections'][0]['blocks'][0] = ['id' => 'service', 'type' => 'services', 'title' => 'Help', 'team_ids' => [$this->team->id]];
    $this->putJson('/company-hub/manage/'.$page->id, ['content' => $invalid, 'version' => 1])->assertUnprocessable();
    expect($page->fresh()->draft_version)->toBe(1);
});

test('Home and page dynamic blocks use current authorised services team spaces and the complete scoped finder pool', function () {
    $outsideSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$outsideSite->id], 'department_id' => $this->team->id, 'bio' => 'Private HR biography']);
    $this->editor = $this->editor->fresh();
    foreach (['current' => null, 'former' => today()->subDay(), 'outside' => null] as $status => $end) {
        $person = User::factory()->create(['approved_at' => now()]);
        ensureCanonicalHrStaffProfile($person, $status === 'outside' ? $outsideSite : $this->site, ['department_id' => $this->team->id, 'end_date' => $end, 'preferred_name' => ucfirst($status).' finder colleague']);
    }
    foreach (['public', 'draft', 'outside', 'expired'] as $status) {
        $space = $this->publisher->create($this->editor, 'department', $status.' learning space', 'team');
        $space = $this->publisher->save($this->editor, $space, [...$space->draft, 'team_ids' => [$this->team->id], 'audience' => $status === 'outside' ? ['type' => 'sites', 'ids' => [$outsideSite->id]] : ['type' => 'all', 'ids' => []]], 1);
        if ($status !== 'draft') {
            $this->publisher->publish($this->editor, $space, 2, null, $status === 'expired' ? now()->addMinute()->toISOString() : null);
        }
    }
    $service = ItCatalogItem::factory()->create(['name' => 'Current equipment service', 'site_scope' => [$this->site->id], 'form_schema' => ['fields' => [['key' => 'internal-secret', 'label' => 'Private technician field', 'type' => 'text', 'visibility' => 'internal']]]]);
    ItCatalogItem::factory()->create(['name' => 'Outside equipment service', 'site_scope' => [$outsideSite->id]]);
    ItCatalogItem::factory()->create(['name' => 'Internal equipment service', 'internal_only' => true]);
    $home = $this->publisher->create($this->editor, 'home', 'Shared home');
    $blocks = [
        ['id' => 'finder', 'type' => 'people', 'title' => 'Find a colleague', 'mode' => 'finder', 'limit' => 1, 'team_ids' => [$this->team->id]],
        ['id' => 'services', 'type' => 'services', 'title' => 'Get help', 'limit' => 12],
        ['id' => 'teams', 'type' => 'teams', 'title' => 'Our teams', 'limit' => 12, 'team_ids' => [$this->team->id]],
    ];
    $home = $this->publisher->save($this->editor, $home, [...$home->draft, 'sections' => [['id' => 'help', 'columns' => 3, 'blocks' => $blocks]]], 1);
    $this->publisher->publish($this->editor, $home, 2, null, null);
    $this->travel(2)->minutes();
    $this->actingAs($this->reader)->get('/company-hub')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('blockData.finder.people', 3)->has('blockData.teams.teams', 1)->where('blockData.teams.teams.0.title', 'public learning space')
        ->where('blockData.services.services', fn ($items) => collect($items)->contains('name', 'Current equipment service') && collect($items)->contains('url', '/hr/my/leave')
            && ! collect($items)->contains('name', 'Outside equipment service') && ! collect($items)->contains('name', 'Internal equipment service')));
    $data = app(HubFeed::class)->forUser($this->reader)['blockData'];
    expect(collect($data['services']['services'])->firstWhere('id', $service->id)['url'])->toBe('/company-hub?view=services&search=Current%20equipment%20service')
        ->and(json_encode($data))->not->toContain('Private HR biography', 'Private technician field', 'Former finder colleague', 'Outside finder colleague');
    $page = $this->publisher->create($this->editor, 'page', 'Help in a page');
    $page = $this->publisher->save($this->editor, $page, [...$page->draft, 'sections' => [['id' => 'page-help', 'columns' => 3, 'blocks' => $blocks]]], 1);
    $this->publisher->publish($this->editor, $page, 2, null, null);
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('blockData.finder.people', 3)->has('blockData.teams.teams', 1)->has('blockData.services.services'));
    $this->team->update(['is_active' => false]);
    $withdrawn = app(HubFeed::class)->forUser($this->reader);
    expect($withdrawn['home']['sections'][0]['blocks'][0]['team_ids'])->toBe([])
        ->and($withdrawn['blockData']['finder']['people'])->toBe([])->and($withdrawn['blockData']['teams']['teams'])->toBe([]);
});

test('editor preview loads authorised data for draft and hidden blocks without publishing or leaking private service forms', function () {
    $home = $this->publisher->create($this->editor, 'home', 'Draft dynamic home');
    $draft = [...$home->draft, 'sections' => [['id' => 'new-help', 'columns' => 2, 'blocks' => [
        ['id' => 'draft-services', 'type' => 'services', 'title' => 'Draft help', 'category' => 'People & Culture', 'hidden' => true],
        ['id' => 'draft-finder', 'type' => 'people', 'title' => 'Draft colleagues', 'mode' => 'finder', 'team_ids' => [$this->team->id], 'hidden' => true],
    ]]]];
    $home = $this->publisher->save($this->editor, $home, $draft, 1);
    $this->actingAs($this->editor)->get('/company-hub/manage/'.$home->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('page.draft.sections.0.blocks.0.hidden', true)->has('previewData.blockData.draft-services.services', 4)
        ->has('previewData.blockData.draft-finder.people', 2)->has('previewData.searchServices'));
    $this->actingAs($this->reader)->get('/company-hub')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('blockData.featured-stories.featured', ['lead' => null, 'supporting' => []])->where('blockData.news.news', [])
        ->missing('blockData.draft-services')->missing('blockData.draft-finder'));
    expect($home->fresh()->revisions()->count())->toBe(0)
        ->and(app(HubBlocks::class)->forUser($this->reader, $draft, app(HubFeed::class)->forUser($this->reader)))->toBe([]);
});
