<?php

use App\Domain\CompanyHub\Models\HubCollection;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubCollections;
use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarHubContent;
use App\Http\Controllers\CompanyHub\HubCollectionController;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    // Root owns production routes; these equivalents exercise the canonical controllers.
    foreach ([['get', '', 'index'], ['post', '', 'store'], ['patch', '/{collection}', 'update'], ['post', '/adopt', 'adopt']] as [$verb, $suffix, $method]) {
        if (! Route::has('company-hub.collections.'.$method)) {
            $route = Route::middleware(['web', 'auth', 'verified', 'permission:company_hub.view'])
                ->{$verb}('/company-hub/collections'.$suffix, [HubCollectionController::class, $method])->name('company-hub.collections.'.$method);
            if ($method === 'update') {
                $route->whereNumber('collection');
            }
        }
    }
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['bio' => 'Private collection editor HR details']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->publishPage = function (string $title, array $extra = [], string $kind = 'knowledge') {
        $page = $this->publisher->create($this->editor, $kind, $title, 'blank');
        $content = [...$page->draft, 'sections' => [['id' => 'main', 'columns' => 1, 'blocks' => [
            ['id' => 'body', 'type' => 'text', 'title' => 'About this resource', 'body' => 'Complete visible guidance.'],
        ]]], ...$extra];
        $page = $this->publisher->save($this->editor, $page, $content, 1);
        $this->publisher->publish($this->editor, $page, 2, null, null);

        return $page->fresh();
    };
    $this->createCollection = fn ($name = 'Shared resources') => $this->actingAs($this->editor)->postJson('/company-hub/collections', ['actor_id' => $this->editor->id, 'name' => $name])->assertCreated()->json('collection');
});

test('versioned shared registry creation and renaming respects actor ownership and current capabilities without publishing', function () {
    $item = ($this->createCollection)('  Getting started  ');
    expect($item)->toBe(['id' => $item['id'], 'name' => 'Getting started', 'version' => 1, 'count' => 0, 'can_rename' => true]);
    $this->getJson('/company-hub/collections')->assertOk()->assertJsonPath('ready', true)->assertJsonPath('can_create', true)
        ->assertJsonPath('options.0.id', $item['id'])->assertHeader('Cache-Control', 'no-store, private');
    $this->patchJson('/company-hub/collections/'.$item['id'], ['actor_id' => $this->editor->id, 'version' => 1, 'name' => 'Orientation'])->assertOk()->assertJsonPath('collection.version', 2);
    $this->patchJson('/company-hub/collections/'.$item['id'], ['actor_id' => $this->editor->id, 'version' => 1, 'name' => 'Stale rename'])->assertConflict();
    $this->patchJson('/company-hub/collections/'.$item['id'], ['actor_id' => $this->reader->id, 'version' => 2, 'name' => 'Changed actor'])->assertConflict();
    foreach (['   ', str_repeat('x', 61)] as $name) {
        $this->postJson('/company-hub/collections', ['actor_id' => $this->editor->id, 'name' => $name])->assertUnprocessable();
    }
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($other, $this->site);
    $other->permissionOverrides()->attach(Permission::where('key', 'company_hub.edit_all')->sole(), ['allowed' => false]);
    $this->actingAs($other)->getJson('/company-hub/collections')->assertOk()->assertJsonPath('items.0.can_rename', false);
    $this->patchJson('/company-hub/collections/'.$item['id'], ['actor_id' => $other->id, 'version' => 2, 'name' => 'Forged ownership'])->assertNotFound();
    $this->actingAs($this->reader)->getJson('/company-hub/collections')->assertOk()->assertJsonPath('can_create', false)->assertJsonPath('items', [])->assertJsonPath('options', []);
    $this->postJson('/company-hub/collections', ['actor_id' => $this->reader->id, 'name' => 'Denied'])->assertForbidden();
    expect(HubPage::count())->toBe(0)->and(AuditLog::where('action', 'like', 'company_hub.collection.%')->count())->toBe(2);
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.layout')->sole(), ['allowed' => false]);
    $this->actingAs($this->editor)->patchJson('/company-hub/collections/'.$item['id'], ['actor_id' => $this->editor->id, 'version' => 2, 'name' => 'Revoked capability'])->assertForbidden();
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->reader)->getJson('/company-hub/collections')->assertForbidden();
    Notification::assertNothingSent();
});

test('collection membership changes only through publication and counts distinct readable parents rather than extracts', function () {
    $first = ($this->createCollection)('First collection');
    $second = ($this->createCollection)('Second collection');
    $blocks = [['id' => 'links', 'type' => 'links', 'title' => 'Useful links', 'links' => [
        ['id' => 'one', 'title' => 'One resource', 'url' => 'https://example.com/one'],
        ['id' => 'two', 'title' => 'Two resources', 'url' => 'https://example.com/two'],
    ]], ['id' => 'hidden', 'type' => 'links', 'title' => 'Hiddenextractneedle', 'hidden' => true, 'links' => [
        ['id' => 'secret', 'title' => 'Hiddenextractneedle', 'url' => 'https://example.com/hidden'],
    ]]];
    $page = ($this->publishPage)('A grouped resource', ['collection_id' => $first['id'], 'collection' => 'Retained original caption', 'sections' => [['id' => 'resources', 'columns' => 1, 'blocks' => $blocks]]]);
    $original = $page->currentRelease;
    $snapshot = $original->content;
    $this->publisher->save($this->editor, $page, [...$page->draft, 'collection_id' => $second['id']], 2);
    $feed = app(HubFeed::class)->forUser($this->reader, 'Unmatched search');
    expect($feed['pages'])->toBe([])->and($feed['resourceCollections']['items'])->toHaveCount(1)
        ->and($feed['resourceCollections']['items'][0])->toMatchArray(['id' => $first['id'], 'count' => 1]);
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect($feed['resourceItems'])->toHaveCount(2)->and(array_column($feed['resourceItems'], 'collection_id'))->toBe([$first['id'], $first['id']])
        ->and(json_encode($feed))->not->toContain('Hiddenextractneedle', 'Retained original caption', 'Private collection editor HR details');
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('selectedPage.content.collection_id', $first['id'])->where('selectedPage.content.collection', 'First collection'));
    $this->publisher->publish($this->editor, $page->fresh(), 3, null, null);
    $this->getJson('/company-hub/collections')->assertOk()->assertJsonPath('items.0.id', $second['id'])->assertJsonPath('items.0.count', 1);
    expect($original->fresh()->content)->toBe($snapshot);
    $this->actingAs($this->editor);
    $copy = $this->getJson('/company-hub/manage/'.$page->id.'/duplicate?source=published')->assertOk()->json();
    $id = $this->postJson('/company-hub/manage/'.$page->id.'/duplicate', ['actor_id' => $copy['actor_id'], 'version' => $copy['version'], 'source' => 'published', 'revision_id' => $copy['revision_id'], 'media_versions' => $copy['media_versions']])->assertCreated()->json('page_id');
    expect(HubPage::findOrFail($id)->draft['collection_id'])->toBe($second['id'])->and(HubPage::findOrFail($id)->revisions()->count())->toBe(0);
    $this->actingAs($this->reader)->get('/company-hub/read/'.HubPage::findOrFail($id)->slug)->assertNotFound();
    $this->getJson('/company-hub/collections')->assertJsonPath('items.0.count', 1);
    $this->publisher->archive($this->editor, $page->fresh(), 3);
    $this->getJson('/company-hub/collections')->assertOk()->assertJsonPath('items', []);
    $this->get('/company-hub/read/'.$page->slug)->assertNotFound();
});

test('collection counts exclude drafts scheduled expired private and nonresource pages on the current site', function () {
    $item = ($this->createCollection)();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$outside->id]]);
    ($this->publishPage)('Visible resource', ['collection_id' => $item['id']]);
    ($this->publishPage)('Privateresourcecountneedle', ['collection_id' => $item['id'], 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]);
    $expired = ($this->publishPage)('Expiredresourcecountneedle', ['collection_id' => $item['id']]);
    $expired->currentRelease->update(['expires_at' => now()->subMinute()]);
    $draft = $this->publisher->create($this->editor, 'knowledge', 'Draftresourcecountneedle', 'blank');
    $this->publisher->save($this->editor, $draft, [...$draft->draft, 'collection_id' => $item['id']], 1);
    $scheduled = $this->publisher->create($this->editor, 'knowledge', 'Scheduledresourcecountneedle', 'blank');
    $scheduled = $this->publisher->save($this->editor, $scheduled, [...$scheduled->draft, 'collection_id' => $item['id']], 1);
    $this->publisher->publish($this->editor, $scheduled, 2, now()->addDay()->toISOString(), null);
    ($this->publishPage)('News with a legacy label', ['collection' => 'Legacy news label'], 'news');
    $this->actingAs($this->reader)->getJson('/company-hub/collections')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.count', 1)->assertJsonPath('legacy_groups', []);
    expect(json_encode(app(HubCollections::class)->context($this->reader)))->not->toContain('Privateresourcecountneedle', 'Expiredresourcecountneedle', 'Draftresourcecountneedle', 'Scheduledresourcecountneedle', 'Legacy news label');
});

test('explicit legacy adoption retains complete drafts revisions copies and history while binding only the authorised captured pages', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$outside->id]]);
    $visible = ($this->publishPage)('A legacy resource', ['collection' => 'Legacy shared label']);
    $private = ($this->publishPage)('Outside legacy resource', ['collection' => 'Legacy shared label', 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]);
    // Layout/ownership permits curation but does not grant all-Site visibility.
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'hr.employees.viewAllSites')->sole(), ['allowed' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => []]);
    $draft = $visible->draft;
    $snapshot = $visible->currentRelease->content;
    $context = $this->actingAs($this->editor)->getJson('/company-hub/collections')->assertOk()->assertJsonPath('legacy_groups.0.count', 1)->json();
    $group = $context['legacy_groups'][0];
    $row = $this->postJson('/company-hub/collections/adopt', ['actor_id' => $this->editor->id, 'key' => $group['key'], 'adoption_key' => $group['adoption_key'], 'name' => 'Canonical adopted collection'])->assertCreated()->assertJsonPath('collection.count', 1)->json('collection');
    expect($visible->fresh()->draft)->toBe($draft)->and($visible->currentRelease->fresh()->content)->toBe($snapshot)
        ->and($private->fresh()->currentRelease->content['collection'])->toBe('Legacy shared label')
        ->and(DB::table('hub_collection_legacy_pages')->pluck('hub_page_id')->all())->toBe([$visible->id]);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$visible->slug)->assertInertia(fn (Assert $p) => $p->where('selectedPage.content.collection', 'Canonical adopted collection')->where('selectedPage.content.collection_id', $row['id']));
    $this->get('/company-hub/read/'.$private->slug)->assertNotFound();
    $this->actingAs($this->editor)->getJson('/company-hub/manage/'.$visible->id.'/revisions/'.$visible->currentRelease->id)->assertOk()->assertJsonPath('content.collection', 'Legacy shared label');
    $copy = $this->getJson('/company-hub/manage/'.$visible->id.'/duplicate?source=published')->assertOk()->json();
    $id = $this->postJson('/company-hub/manage/'.$visible->id.'/duplicate', ['actor_id' => $copy['actor_id'], 'version' => $copy['version'], 'source' => 'published', 'revision_id' => $copy['revision_id'], 'media_versions' => $copy['media_versions']])->assertCreated()->json('page_id');
    expect(HubPage::findOrFail($id)->draft['collection'])->toBe('Legacy shared label')->and(DB::table('hub_collection_legacy_pages')->where('hub_page_id', $id)->exists())->toBeFalse();
    $this->publisher->save($this->editor, $visible->fresh(), [...$draft, 'collection' => 'A deliberately changed legacy group'], 2);
    $this->publisher->publish($this->editor, $visible->fresh(), 3, null, null);
    $this->actingAs($this->reader)->getJson('/company-hub/collections')->assertJsonPath('items', [])->assertJsonPath('legacy_groups.0.name', 'A deliberately changed legacy group');
    expect(AuditLog::where('action', 'company_hub.collection.legacy_adopted')->count())->toBe(1);
    Notification::assertNothingSent();
});

test('legacy adoption rejects stale release sets another owner withdrawn resources and switched actors', function () {
    $page = ($this->publishPage)('Legacy resource for review', ['collection' => 'Review me']);
    $group = $this->actingAs($this->editor)->getJson('/company-hub/collections')->assertOk()->json('legacy_groups.0');
    $input = ['actor_id' => $this->editor->id, 'key' => $group['key'], 'adoption_key' => $group['adoption_key'], 'name' => 'New canonical name'];
    $this->postJson('/company-hub/collections/adopt', [...$input, 'actor_id' => $this->reader->id])->assertConflict();
    $this->publisher->save($this->editor, $page, [...$page->draft, 'summary' => 'Updated release'], 2);
    $this->publisher->publish($this->editor, $page->fresh(), 3, null, null);
    $this->postJson('/company-hub/collections/adopt', $input)->assertConflict();
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($other, $this->site);
    $other->permissionOverrides()->attach(Permission::where('key', 'company_hub.edit_all')->sole(), ['allowed' => false]);
    $this->actingAs($other)->getJson('/company-hub/collections')->assertJsonPath('legacy_groups.0.can_adopt', false)->assertJsonPath('legacy_groups.0.adoption_key', null);
    $this->postJson('/company-hub/collections/adopt', [...$input, 'actor_id' => $other->id])->assertForbidden();
    $this->publisher->archive($this->editor, $page->fresh(), 3);
    $this->actingAs($this->editor)->postJson('/company-hub/collections/adopt', $input)->assertNotFound();
    expect(HubCollection::count())->toBe(0)->and(DB::table('hub_collection_legacy_pages')->count())->toBe(0);
});

test('canonical rename updates safe reader search metadata while invalid identifiers never expose copied labels', function () {
    $row = ($this->createCollection)('Oldcollectionsearchneedle');
    $page = ($this->publishPage)('Canonical collection resource', ['collection_id' => $row['id'], 'collection' => 'Copiedcollectionsearchneedle']);
    $snapshot = $page->currentRelease->content;
    $this->patchJson('/company-hub/collections/'.$row['id'], ['actor_id' => $this->editor->id, 'version' => 1, 'name' => 'Newcollectionsearchneedle'])->assertOk();
    $this->actingAs($this->reader)->getJson('/company-hub/search?query=Newcollectionsearchneedle')->assertOk()->assertJsonPath('results.0.url', '/company-hub/read/'.$page->slug);
    $this->getJson('/company-hub/search?query=Oldcollectionsearchneedle')->assertOk()->assertJsonCount(0, 'results');
    $this->getJson('/company-hub/search?query=Copiedcollectionsearchneedle')->assertOk()->assertJsonCount(0, 'results');
    expect(app(HubFeed::class)->forUser($this->reader, 'Newcollectionsearchneedle')['pages'])->toHaveCount(1)
        ->and($page->currentRelease->fresh()->content)->toBe($snapshot);
    $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$page->id, ['version' => 2, 'content' => [...$page->draft, 'collection_id' => 2147483647]])->assertUnprocessable()->assertJsonValidationErrors('collection_id');
    $news = $this->publisher->create($this->editor, 'news', 'A news page', 'blank');
    $this->putJson('/company-hub/manage/'.$news->id, ['version' => 1, 'content' => [...$news->draft, 'collection_id' => $row['id']]])->assertUnprocessable()->assertJsonValidationErrors('collection_id');
    // Corrupt/imported historical references fail closed in reader copies without rewriting storage.
    $page->revisions()->create(['draft_version' => 3, 'content' => [...$snapshot, 'collection_id' => 2147483647], 'created_by' => $this->editor->id,
        'state' => 'approved', 'approved_by' => $this->editor->id, 'approved_at' => now(), 'publish_at' => now()->addSecond()]);
    $this->travel(2)->seconds();
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('selectedPage.content.collection_id', null)->where('selectedPage.content.collection', null));
    $this->getJson('/company-hub/search?query=Copiedcollectionsearchneedle')->assertJsonCount(0, 'results');
    expect(app(HubFeed::class)->forUser($this->reader, 'Copiedcollectionsearchneedle')['pages'])->toBe([])
        ->and($page->fresh()->currentRelease->content['collection_id'])->toBe(2147483647);
});

test('migration pending keeps legacy reading and absent null reference saves while registry writes fail explicitly', function () {
    $page = ($this->publishPage)('Still available resource', ['collection' => 'Existing legacy collection']);
    $schema = Schema::getFacadeRoot();
    $pending = Mockery::mock(get_class($schema), [$schema->getConnection()])->makePartial();
    $pending->shouldReceive('hasTable')->with('hub_collections')->andReturn(false);
    Schema::swap($pending);
    try {
        $this->actingAs($this->reader)->getJson('/company-hub/collections')->assertOk()->assertJsonPath('ready', false)->assertJsonPath('items', [])->assertJsonPath('options', [])
            ->assertJsonPath('legacy_groups.0.name', 'Existing legacy collection')->assertJsonPath('legacy_groups.0.can_adopt', false);
        $this->get('/company-hub')->assertInertia(fn (Assert $p) => $p->where('resourceCollections.ready', false));
        $this->actingAs($this->editor)->postJson('/company-hub/collections', ['actor_id' => $this->editor->id, 'name' => 'Pending'])->assertStatus(503);
        $this->putJson('/company-hub/manage/'.$page->id, ['version' => 2, 'content' => [...$page->draft, 'collection_id' => null]])->assertOk();
        $this->putJson('/company-hub/manage/'.$page->id, ['version' => 3, 'content' => [...$page->draft, 'collection_id' => 1]])->assertUnprocessable()->assertJsonValidationErrors('collection_id');
        expect(HubCollection::count())->toBe(0)->and($page->currentRelease->fresh()->content['collection'])->toBe('Existing legacy collection');
    } finally {
        Schema::swap($schema);
    }
});

test('event host profile links use current authorised profile ids and stay nullable outside Hub or staff scope', function () {
    $host = User::factory()->create(['id' => 100001, 'role' => 'support_worker', 'approved_at' => now()]);
    $host->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($host, $this->site, ['bio' => 'Private event host HR record']);
    $profileId = $host->hrEmployeeProfile()->sole()->id;
    expect($profileId)->not->toBe($host->id);
    $event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'A shared event', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'hub_content' => ['host_id' => $host->id]]);
    $event->forceFill(['hub_managed' => true])->save();
    $event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
    $this->actingAs($this->reader)->get('/company-hub/events/'.$event->id.'?date='.$event->starts_at->toDateString())->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('selectedEvent.extendedProps.hubContent.host.id', $host->id)->where('selectedEvent.extendedProps.hubContent.host.profile_url', '/company-hub/people/'.$profileId));
    $this->get('/company-hub/people/'.$profileId)->assertOk();
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
    $projection = app(HrCalendarHubContent::class)->read($this->editor->fresh(), $event, $event->ends_at, collect());
    expect($projection['host'])->toMatchArray(['id' => $host->id, 'profile_url' => null]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($host, $outside);
    expect(app(HubDirectory::class)->profileUrlForUser($this->reader, $host->id))->toBeNull();
    $host->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    expect(app(HrCalendarHubContent::class)->read($this->reader, $event->fresh(), $event->ends_at, collect())['host'])->toBeNull();
    $event->update(['hub_content' => []]);
    expect(app(HrCalendarHubContent::class)->read($this->reader, $event->fresh(), $event->ends_at, collect())['host'])->toBeNull();
    Notification::assertNothingSent();
});
