<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubDiscovery;
use App\Domain\CompanyHub\Services\HubEditorial;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubNavigation;
use App\Domain\CompanyHub\Services\HubNotices;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Services\FeedService;
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
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'news', 'A company story');
});

test('interests persist per person with version conflicts and canonical department boundaries', function () {
    $team = HrDepartment::create(['name' => 'Shared team', 'is_active' => true]);
    $private = HrDepartment::create(['name' => 'Other site team', 'is_active' => true]);
    $private->sites()->attach(Site::factory()->create(['is_active' => true])->id);
    $this->actingAs($this->reader)->getJson('/company-hub/interests')->assertOk()->assertJsonPath('version', 1);
    $data = ['topics' => ['Learning'], 'teams' => [$team->id], 'version' => 1];
    $this->putJson('/company-hub/interests', $data)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('topics', ['Learning']);
    $this->putJson('/company-hub/interests', $data)->assertConflict();
    $this->putJson('/company-hub/interests', [...$data, 'version' => 2, 'teams' => [$private->id]])->assertUnprocessable();
    $this->putJson('/company-hub/interests', [...$data, 'version' => 2, 'topics' => ['Made up']])->assertUnprocessable();
    expect(app(HubDiscovery::class)->preferences($this->editor)['topics'])->toBe([]);
    $team->update(['is_active' => false]);
    $this->getJson('/company-hub/interests')->assertJsonPath('teams', []);
    $this->putJson('/company-hub/interests', ['topics' => [], 'teams' => [], 'version' => 2])->assertOk()->assertJsonPath('topics', []);
});

test('interest discovery uses only current authorised published metadata including scheduled and expired pages', function () {
    $team = HrDepartment::create(['name' => 'Learning team', 'is_active' => true]);
    $content = [...$this->page->draft, 'topics' => ['Learning'], 'team_ids' => [$team->id]];
    $this->page = $this->publisher->save($this->editor, $this->page, $content, 1);
    $this->publisher->publish($this->editor, $this->page, 2, null, now()->addDays(3)->toIso8601String());
    app(HubDiscovery::class)->saveInterests($this->reader, ['topics' => ['Learning'], 'teams' => [], 'version' => 1]);
    $updates = app(HubFeed::class)->forUser($this->reader)['updates'];
    expect($updates)->toHaveCount(1)->and($updates[0]['following'])->toBeTrue()->and($updates[0]['topics'])->toBe(['Learning']);
    $content['topics'] = ['Digital tools'];
    $this->page = $this->publisher->save($this->editor, $this->page, $content, 2);
    expect(app(HubFeed::class)->forUser($this->reader)['updates'][0]['topics'])->toBe(['Learning']);
    $this->publisher->publish($this->editor, $this->page, 3, now()->addDay()->toIso8601String(), null);
    expect(app(HubFeed::class)->forUser($this->reader)['updates'][0]['topics'])->toBe(['Learning']);
    $this->travel(2)->days();
    expect(app(HubFeed::class)->forUser($this->reader)['updates'][0]['following'])->toBeFalse();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$outside->id]]);
    $this->editor = $this->editor->fresh();
    $content['audience'] = ['type' => 'sites', 'ids' => [$outside->id]];
    $this->page = $this->publisher->save($this->editor, $this->page, $content, 3);
    $this->publisher->publish($this->editor, $this->page, 4, null, now()->addDay()->toIso8601String());
    expect(app(HubFeed::class)->forUser($this->reader)['updates'])->toBe([]);
    expect(app(HubFeed::class)->forUser($this->editor)['updates'])->toHaveCount(1);
    $this->travel(2)->days();
    expect(app(HubFeed::class)->forUser($this->editor)['updates'])->toBe([]);
    $this->travel(-2)->days();
    $this->page->update(['archived_at' => now()]);
    expect(app(HubFeed::class)->forUser($this->editor)['updates'])->toBe([]);
});

test('update seen batches do not hide new revisions or other users updates and undo is batch scoped', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $feed = fn () => app(HubFeed::class)->forUser($this->reader)['updates'];
    $key = $feed()[0]['key'];
    $this->actingAs($this->reader);
    $batch = $this->postJson('/company-hub/seen', ['keys' => [$key]])->assertOk()->json('batch');
    expect($feed()[0]['unread'])->toBeFalse()->and(app(HubFeed::class)->forUser($this->editor)['updates'][0]['unread'])->toBeTrue();
    $this->postJson('/company-hub/seen', ['keys' => [$key]])->assertOk()->assertJsonPath('marked', []);
    $this->postJson('/company-hub/seen', ['keys' => [$key], 'undo_batch' => (string) Str::uuid()])->assertOk();
    expect($feed()[0]['unread'])->toBeFalse();
    $this->postJson('/company-hub/seen', ['keys' => [$key], 'undo_batch' => $batch])->assertOk();
    expect($feed()[0]['unread'])->toBeTrue();
    $oldRevision = $this->page->revisions()->latest('id')->value('id');
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'title' => 'Updated story'], 1);
    $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->postJson('/company-hub/seen', ['keys' => [$key]])->assertConflict();
    $this->postJson('/company-hub/pages/'.$this->page->id.'/read', ['revision_id' => $oldRevision])->assertNoContent();
    expect($feed()[0]['unread'])->toBeTrue();
    $newRevision = $this->page->revisions()->latest('id')->value('id');
    $this->postJson('/company-hub/pages/'.$this->page->id.'/read', ['revision_id' => $newRevision])->assertNoContent();
    expect($feed()[0]['unread'])->toBeFalse();
    $newKey = $feed()[0]['key'];
    $this->page->update(['archived_at' => now()]);
    $this->postJson('/company-hub/seen', ['keys' => [$newKey]])->assertConflict();
});

test('event discovery deduplicates occurrences and interests never bypass its audience', function () {
    $team = HrDepartment::create(['name' => 'Events team', 'is_active' => true]);
    app(HubDiscovery::class)->saveInterests($this->reader, ['topics' => [], 'teams' => [$team->id], 'version' => 1]);
    $event = HrCalendarEvent::create(['title' => 'Shared learning', 'event_type' => 'company', 'department_id' => $team->id, 'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'rrule' => 'FREQ=WEEKLY;COUNT=3', 'created_by' => $this->editor->id]);
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect($feed['events'])->toHaveCount(3)->and($feed['updates'])->toHaveCount(1)->and($feed['updates'][0]['following'])->toBeTrue();
    $key = $feed['updates'][0]['key'];
    $event->update(['site_id' => Site::factory()->create(['is_active' => true])->id]);
    expect(app(HubFeed::class)->forUser($this->reader)['updates'])->toBe([]);
    $this->actingAs($this->reader)->postJson('/company-hub/seen', ['keys' => [$key]])->assertConflict();
});

test('shared navigation drafts publish independently from page drafts and reject stale changes', function () {
    $service = app(HubNavigation::class);
    $workspace = $service->workspace($this->editor);
    $items = $workspace['draft'];
    $items[1]['label'] = 'Company stories';
    $items[] = ['id' => 'help', 'type' => 'group', 'label' => 'Everyday help', 'target' => '', 'parent' => '', 'hidden' => false];
    $items[4]['parent'] = 'help';
    $url = '/company-hub/editor/shared-navigation';
    $this->actingAs($this->editor)->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $items])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('has_draft', true);
    expect(collect($service->forUser($this->reader))->firstWhere('id', 'news')['label'])->toBe('News');
    $this->putJson($url, ['action' => 'publish', 'version' => 1])->assertConflict();
    $this->putJson($url, ['action' => 'publish', 'version' => 2])->assertOk()->assertJsonPath('has_draft', false);
    $menu = collect($service->forUser($this->reader));
    expect($menu->firstWhere('id', 'news')['label'])->toBe('Company stories')
        ->and($menu->firstWhere('id', 'resources')['parent'])->toBe('help')
        ->and($menu->firstWhere('id', 'help')['label'])->toBe('Everyday help')
        ->and($menu->contains('target', 'publishing'))->toBeFalse()
        ->and($this->page->fresh()->draft_version)->toBe(1)
        ->and($this->page->revisions()->count())->toBe(0);
    $items[1]['label'] = 'Unfinished change';
    $service->change($this->editor, 'save', 3, $items);
    $this->putJson($url, ['action' => 'discard', 'version' => 3])->assertConflict();
    $result = $service->change($this->editor, 'discard', 4);
    expect($result['draft'][1]['label'])->toBe('Company stories')->and($result['has_draft'])->toBeFalse();
});

test('shared navigation keeps home accessible and rejects unsafe links and invalid groups', function () {
    $items = app(HubNavigation::class)->defaults();
    $url = '/company-hub/editor/shared-navigation';
    $this->actingAs($this->editor);
    $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => ['home' => $items[0]]])->assertUnprocessable();
    $bad = $items;
    $bad[0]['hidden'] = true;
    $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $bad])->assertUnprocessable();
    $bad = $items;
    $bad[1]['parent'] = 'events';
    $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $bad])->assertUnprocessable();
    foreach (['javascript:alert(1)', '//outside.test', '/company-hub/read/a-secret-page'] as $target) {
        $bad = [...$items, ['id' => 'unsafe', 'type' => 'link', 'label' => 'Link', 'target' => $target, 'parent' => '', 'hidden' => false]];
        $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $bad])->assertUnprocessable();
    }
    $bad = [...$items, ['id' => 'duplicate', 'type' => 'view', 'label' => 'Home again', 'target' => 'home', 'parent' => '', 'hidden' => false]];
    $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $bad])->assertUnprocessable();
    expect(DB::table('hub_navigation')->value('version'))->toBe(1);
});

test('shared navigation removes inaccessible page links and empty groups without exposing drafts', function () {
    $service = app(HubNavigation::class);
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $items = $service->defaults();
    $items[] = ['id' => 'team', 'type' => 'group', 'label' => 'Our team', 'target' => '', 'parent' => '', 'hidden' => false];
    $items[] = ['id' => 'team-page', 'type' => 'page', 'label' => 'Team information', 'target' => (string) $this->page->id, 'parent' => 'team', 'hidden' => false];
    $service->change($this->editor, 'save', 1, $items);
    $service->change($this->editor, 'publish', 2);
    expect(collect($service->forUser($this->reader))->contains('id', 'team-page'))->toBeTrue();
    $draft = [...$this->page->draft, 'title' => 'Unpublished confidential title'];
    $this->publisher->save($this->editor, $this->page, $draft, 1);
    expect(json_encode($service->workspace($this->editor)))->not->toContain('Unpublished confidential title');
    $this->page->update(['archived_at' => now()]);
    $menu = collect($service->forUser($this->reader));
    expect($menu->contains('id', 'team-page'))->toBeFalse()->and($menu->contains('id', 'team'))->toBeFalse();
    $workspace = $service->workspace($this->editor);
    expect(collect($workspace['draft'])->firstWhere('id', 'team-page'))->toMatchArray(['label' => 'Unavailable page', 'target' => '']);
    $this->actingAs($this->editor)->putJson('/company-hub/editor/shared-navigation', ['action' => 'save', 'version' => 3, 'items' => $items])->assertUnprocessable();
});

test('shared navigation requires layout permission and a separate publishing capability', function () {
    $url = '/company-hub/editor/shared-navigation';
    $items = app(HubNavigation::class)->defaults();
    $this->actingAs($this->reader)->getJson($url)->assertForbidden();
    $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $items])->assertForbidden();
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.layout')->value('id'), ['allowed' => true]);
    $this->reader = $this->reader->fresh();
    $this->actingAs($this->reader)->getJson($url)->assertOk()->assertJsonPath('can_publish', false);
    $this->putJson($url, ['action' => 'save', 'version' => 1, 'items' => $items])->assertOk();
    $this->putJson($url, ['action' => 'publish', 'version' => 2])->assertForbidden();
    expect(DB::table('hub_navigation')->value('version'))->toBe(2);
});

test('shared navigation publication rechecks page availability after a draft is saved', function () {
    $service = app(HubNavigation::class);
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $items = [...$service->defaults(), ['id' => 'story-link', 'type' => 'page', 'label' => 'Story', 'target' => (string) $this->page->id, 'parent' => '', 'hidden' => false]];
    $service->change($this->editor, 'save', 1, $items);
    $this->page->update(['archived_at' => now()]);
    $this->actingAs($this->editor)->putJson('/company-hub/editor/shared-navigation', ['action' => 'publish', 'version' => 2])->assertUnprocessable();
    expect(DB::table('hub_navigation')->value('published'))->toBeNull()->and(DB::table('hub_navigation')->value('version'))->toBe(2);
});

test('ideas support is idempotent and moderation is not available to readers', function () {
    $this->actingAs($this->reader)->post('/company-hub/ideas', ['title' => 'Walking meetings', 'body' => 'Let us try a short outdoor meeting.', 'category' => 'Our people'])->assertRedirect();
    $id = DB::table('hub_ideas')->value('id');
    $url = '/company-hub/ideas/'.$id;
    $this->post($url, ['action' => 'support', 'active' => true])->assertRedirect();
    $this->post($url, ['action' => 'support', 'active' => true])->assertRedirect();
    expect(DB::table('hub_idea_supports')->where('hub_idea_id', $id)->count())->toBe(1);
    $this->postJson($url, ['action' => 'status', 'status' => 'delivered', 'status_note' => 'Done', 'version' => 1])->assertForbidden();
    $this->actingAs($this->editor)->post($url, ['action' => 'status', 'status' => 'reviewing', 'status_note' => 'We are considering this.', 'version' => 1])->assertRedirect();
    $this->postJson($url, ['action' => 'status', 'status' => 'delivered', 'status_note' => 'Stale response', 'version' => 1])->assertConflict();
    $this->actingAs($this->reader)->post($url, ['action' => 'remove', 'version' => 2])->assertRedirect();
    $this->postJson($url, ['action' => 'support', 'active' => true])->assertNotFound();
    expect(app(HubCommunity::class)->ideas($this->reader))->toBe([]);
});

test('following and read counts require currently readable pages and are idempotent', function () {
    $url = '/company-hub/pages/'.$this->page->id;
    $this->actingAs($this->reader)->postJson($url.'/follow', ['following' => true])->assertNotFound();
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->post($url.'/follow', ['following' => true])->assertRedirect();
    $this->post($url.'/follow', ['following' => true])->assertRedirect();
    $this->postJson($url.'/read')->assertNoContent();
    $this->postJson($url.'/read')->assertNoContent();
    expect(app(HubFeed::class)->forUser($this->reader)['followedPages'])->toEqual([$this->page->id]);
    expect(DB::table('hub_reads')->where('hub_page_id', $this->page->id)->count())->toBe(1);
    $this->page->update(['archived_at' => now()]);
    $this->postJson($url.'/read')->assertNotFound();
    expect(app(HubFeed::class)->forUser($this->reader)['followedPages'])->toBe([]);
});

test('thread replies cannot target another page and removing a parent hides its replies', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $service = app(HubParticipation::class);
    $service->write($this->editor, $this->page, ['kind' => 'comment', 'body' => 'Original']);
    $parent = DB::table('hub_interactions')->where('hub_page_id', $this->page->id)->value('id');
    $other = $this->publisher->create($this->editor, 'news', 'Other story');
    $this->publisher->publish($this->editor, $other, 1, null, null);
    $reply = ['kind' => 'comment', 'body' => 'A reply', 'parent_id' => $parent];
    $this->actingAs($this->reader)->postJson('/company-hub/pages/'.$other->id.'/interactions', $reply)->assertUnprocessable();
    $this->post('/company-hub/pages/'.$this->page->id.'/interactions', $reply)->assertRedirect();
    expect($service->read($this->reader, $this->page)['comments'])->toHaveCount(2);
    $service->remove($this->editor, $this->page, $parent);
    expect($service->read($this->reader, $this->page)['comments'])->toBe([]);
    $this->postJson('/company-hub/pages/'.$this->page->id.'/interactions', $reply)->assertUnprocessable();
});

test('campaign completions follow the published activity and editing it resets its count', function () {
    $draft = $this->page->draft;
    $step = ['id' => 'read-guide', 'title' => 'Read our guide', 'url' => '/company-hub?view=resources'];
    $draft['campaign'] = ['ends_on' => null, 'steps' => [$step]];
    $page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $page, 2, null, null);
    $data = ['kind' => 'campaign', 'step_id' => $step['id'], 'step_key' => HubParticipation::stepKey($step), 'active' => true];
    $url = '/company-hub/pages/'.$page->id.'/interactions';
    $this->actingAs($this->reader)->post($url, $data)->assertRedirect();
    $this->post($url, $data)->assertRedirect();
    expect(app(HubParticipation::class)->read($this->reader, $page->fresh())['campaign'][0]['count'])->toBe(1);
    $draft['campaign']['steps'][0]['title'] = 'Read the updated guide';
    $page = $this->publisher->save($this->editor, $page, $draft, 2);
    $this->publisher->publish($this->editor, $page, 3, null, null);
    $this->postJson($url, $data)->assertConflict();
    expect(app(HubParticipation::class)->read($this->reader, $page->fresh())['campaign'][0]['done'])->toBeFalse();
});

test('people cards disclose work contacts only and exclude staff outside approved sites', function () {
    $this->reader->hrEmployeeProfile->update(['work_email' => 'work@example.test', 'work_phone' => '123456']);
    $elsewhere = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($elsewhere, Site::factory()->create());
    $cards = app(HubDirectory::class)->people($this->reader);
    $mine = collect($cards)->firstWhere('user_id', $this->reader->id);
    expect($mine['work_email'])->toBe('work@example.test');
    expect(array_keys($mine))->not->toContain('date_of_birth', 'email', 'salary', 'address', 'personal_email');
    expect(collect($cards)->pluck('user_id')->all())->not->toContain($elsewhere->id);
});

test('polls bind votes to the published question and replace duplicate responses', function () {
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][] = ['id' => 'poll', 'type' => 'poll', 'title' => 'Your say', 'question' => 'Next activity?', 'options' => ['Walk', 'Lunch']];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $key = HubParticipation::pollKey($draft['sections'][0]['blocks'][1]);
    $url = '/company-hub/pages/'.$this->page->id.'/interactions';
    $vote = ['kind' => 'vote', 'block_id' => 'poll', 'poll_key' => $key, 'choice' => 0];
    $this->actingAs($this->reader)->post($url, $vote)->assertRedirect();
    $this->post($url, [...$vote, 'choice' => 1])->assertRedirect();
    $poll = app(HubParticipation::class)->read($this->reader, $this->page->fresh())['polls']['poll'];
    expect($poll['total'])->toBe(1)->and($poll['mine'])->toBe(1)->and($poll['counts'])->toBe([0, 1]);
    $draft['sections'][0]['blocks'][1]['question'] = 'New question?';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->post($url, $vote)->assertConflict();
    expect(app(HubParticipation::class)->read($this->reader, $this->page->fresh())['polls']['poll']['total'])->toBe(0);
});

test('interactions obey live settings and hidden pages deny direct writes', function () {
    $url = '/company-hub/pages/'.$this->page->id.'/interactions';
    $this->actingAs($this->reader)->postJson($url, ['kind' => 'comment', 'body' => 'Draft comment'])->assertNotFound();
    $draft = [...$this->page->draft, 'comments_enabled' => false, 'reactions_enabled' => false];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->postJson($url, ['kind' => 'comment', 'body' => 'Disabled comment'])->assertForbidden();
    $this->postJson($url, ['kind' => 'reaction', 'reaction' => 'heart', 'active' => true])->assertForbidden();
});

test('comment moderation is scoped to the page and requires ownership or permission', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    app(HubParticipation::class)->write($this->editor, $this->page, ['kind' => 'comment', 'body' => 'Company update']);
    $id = DB::table('hub_interactions')->where('hub_page_id', $this->page->id)->value('id');
    $url = '/company-hub/pages/'.$this->page->id.'/interactions/'.$id;
    $this->actingAs($this->reader)->delete($url)->assertForbidden();
    $this->actingAs($this->editor)->delete($url)->assertRedirect();
    expect(app(HubParticipation::class)->read($this->reader, $this->page)['comments'])->toBe([]);
});

test('news notices honour opt-out and repeat delivery does not duplicate the bell', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->actingAs($this->reader)->put('/company-hub/notifications', ['news' => false, 'reviews' => true])->assertRedirect();
    app(HubNotices::class)->deliver();
    expect($this->reader->notifications()->count())->toBe(0)->and($this->editor->notifications()->count())->toBe(1);
    app(HubNotices::class)->deliver();
    expect($this->editor->notifications()->count())->toBe(1);
    $this->put('/company-hub/notifications', ['news' => true, 'reviews' => true])->assertRedirect();
    app(HubNotices::class)->deliver();
    expect($this->reader->notifications()->count())->toBe(1);
    expect($this->reader->notifications()->first()->data['message'])->not->toContain($this->page->draft['title']);
});

test('submitted snapshots cannot be mutated in place', function () {
    $revision = $this->publisher->submit($this->editor, $this->page, 1);
    expect(fn () => $revision->update(['content' => [...$revision->content, 'title' => 'Rewritten history']]))->toThrow(LogicException::class);
});

test('page ownership cannot be handed to a reader without editing rights', function () {
    $this->actingAs($this->editor)->postJson('/company-hub/manage/'.$this->page->id.'/owner', ['owner_id' => $this->reader->id, 'version' => 1])->assertUnprocessable();
    expect($this->page->fresh()->owner_id)->toBe($this->editor->id);
});

test('the fictional showcase is editor only and never inserts company content', function () {
    $before = HubPage::count();
    $this->actingAs($this->reader)->get('/company-hub/showcase')->assertForbidden();
    $this->actingAs($this->editor)->get('/company-hub/showcase')->assertOk();
    $this->get('/company-hub/showcase/edit')->assertOk();
    expect(HubPage::count())->toBe($before);
});

test('published content remains unchanged while an editor autosaves a new draft', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $draft = $this->page->draft;
    $draft['title'] = 'Unannounced confidential draft';
    $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$this->page->id, ['content' => $draft, 'version' => 1])
        ->assertOk()->assertJsonPath('version', 2);
    expect(app(HubAccess::class)->assertRead($this->reader, $this->page->fresh())->content['title'])->toBe('A company story');
    expect($this->page->fresh()->draft['title'])->toBe('Unannounced confidential draft');
});

test('a stale save cannot overwrite a newer draft', function () {
    $this->publisher->save($this->editor, $this->page, $this->page->draft, 1);
    $stale = $this->page->draft;
    $stale['title'] = 'Stale overwrite';
    $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$this->page->id, ['content' => $stale, 'version' => 1])->assertConflict();
    expect($this->page->fresh()->draft['title'])->toBe('A company story');
});

test('a reader cannot create edit publish restore or archive content even using direct ids', function () {
    $revision = $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->actingAs($this->reader)->post('/company-hub/pages', ['kind' => 'news', 'title' => 'Unauthorised'])->assertForbidden();
    $this->get('/company-hub/manage/'.$this->page->id.'/edit')->assertNotFound();
    $this->putJson('/company-hub/manage/'.$this->page->id, ['content' => $this->page->draft, 'version' => 1])->assertNotFound();
    $this->post('/company-hub/manage/'.$this->page->id.'/publish', ['version' => 1])->assertNotFound();
    $this->post('/company-hub/manage/'.$this->page->id.'/revisions/'.$revision->id.'/restore', ['version' => 1])->assertNotFound();
    $this->post('/company-hub/manage/'.$this->page->id.'/archive', ['version' => 1])->assertNotFound();
});

test('scheduling keeps the current story until release and expiry does not resurrect older versions', function () {
    $this->travelTo(now()->startOfSecond());
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $draft = $this->page->draft;
    $draft['title'] = 'Tomorrow story';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $this->page, 2, now()->addDay()->toIso8601String(), now()->addDays(2)->toIso8601String());
    expect($this->page->liveRevision()->content['title'])->toBe('A company story');
    $this->travel(25)->hours();
    expect($this->page->liveRevision()->content['title'])->toBe('Tomorrow story');
    $this->travel(25)->hours();
    expect($this->page->liveRevision())->toBeNull();
});

test('publication with required approval cannot be self approved', function () {
    $draft = $this->page->draft;
    $draft['requires_approval'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $revision = $this->publisher->submit($this->editor, $this->page, 2);
    $this->actingAs($this->editor)->postJson('/company-hub/manage/'.$this->page->id.'/publish', ['version' => 2])->assertUnprocessable();
    $this->post('/company-hub/manage/'.$this->page->id.'/revisions/'.$revision->id.'/approve')->assertForbidden();
    $reviewer = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $reviewer->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($reviewer, $this->site);
    $this->actingAs($reviewer)->post('/company-hub/manage/'.$this->page->id.'/revisions/'.$revision->id.'/approve')->assertRedirect();
    expect($this->page->liveRevision()->approved_by)->toBe($reviewer->id);
});

test('restoring a revision creates a draft without replacing the current live story', function () {
    $first = $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $draft = $this->page->draft;
    $draft['title'] = 'New story';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->page = $this->publisher->restore($this->editor, $this->page, $first, 2);
    expect($this->page->draft['title'])->toBe('A company story');
    expect($this->page->liveRevision()->content['title'])->toBe('New story');
});

test('a revision belonging to another page cannot be restored using a forged identifier', function () {
    $other = $this->publisher->create($this->editor, 'news', 'Other page');
    $revision = $this->publisher->publish($this->editor, $other, 1, null, null);
    $this->actingAs($this->editor)->post('/company-hub/manage/'.$this->page->id.'/revisions/'.$revision->id.'/restore', ['version' => 1])->assertNotFound();
});

test('site audiences hide published content and search material from unrelated staff', function () {
    $unrelated = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->page->update(['draft' => [...$this->page->draft, 'audience' => ['type' => 'sites', 'ids' => [$unrelated->id]]]]);
    $this->page->revisions()->create([
        'created_by' => $this->editor->id, 'draft_version' => 1, 'content' => $this->page->draft,
        'state' => 'approved', 'approved_by' => $this->editor->id, 'approved_at' => now(), 'publish_at' => now(),
    ]);
    expect(app(HubAccess::class)->visibleRevision($this->reader, $this->page))->toBeNull();
});

test('birthday visibility is off until individually enabled and never includes year or age', function () {
    $this->reader->hrEmployeeProfile->update(['date_of_birth' => now()->subYears(30)->format('Y-m-d')]);
    expect(collect(app(FeedService::class)->getMilestones()['birthdays'])->pluck('user_id'))->not->toContain($this->reader->id);
    $this->actingAs($this->reader)->put('/company-hub/celebrations/'.$this->reader->id, ['birthday_enabled' => true])->assertForbidden();
    $this->actingAs($this->editor)->put('/company-hub/celebrations/'.$this->reader->id, ['birthday_enabled' => true])->assertRedirect();
    $birthday = collect(app(FeedService::class)->getMilestones()['birthdays'])->firstWhere('user_id', $this->reader->id);
    expect($birthday)->not->toBeNull()->and(array_keys($birthday))->not->toContain('age', 'date_of_birth', 'year');
    expect($birthday['date'])->toBe(now()->format('M d'));
    $this->put('/company-hub/celebrations/'.$this->reader->id, ['birthday_enabled' => false])->assertRedirect();
    expect(collect(app(FeedService::class)->getMilestones()['birthdays'])->pluck('user_id'))->not->toContain($this->reader->id);
});

test('an explicit permission denial defeats role grants', function () {
    $permission = Permission::where('key', 'company_hub.publish')->firstOrFail();
    $this->editor->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    $this->actingAs($this->editor->fresh())->post('/company-hub/manage/'.$this->page->id.'/publish', ['version' => 1])->assertForbidden();
});

test('unsafe links and cross page media cannot be saved', function () {
    $draft = $this->page->draft;
    $draft['sections'] = [['id' => 'links', 'columns' => 1, 'blocks' => [[
        'id' => 'links', 'type' => 'links', 'title' => 'Tools', 'links' => [['id' => 'bad', 'title' => 'Bad link', 'url' => 'javascript:alert(1)']],
    ]]]];
    $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$this->page->id, ['content' => $draft, 'version' => 1])->assertUnprocessable();
    $draft = [...$this->page->draft, 'cover_id' => 99999];
    $this->putJson('/company-hub/manage/'.$this->page->id, ['content' => $draft, 'version' => 1])->assertUnprocessable();
});

test('former staff lose hub access even when their role still carries permission', function () {
    $this->editor->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($this->editor)->post('/company-hub/pages', ['kind' => 'news', 'title' => 'Former employee'])->assertForbidden();
});

test('hub search and navigation never include an unpublished draft', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $draft = $this->page->draft;
    $draft['title'] = 'Unpublished reorganisation';
    $draft['show_in_navigation'] = true;
    $this->publisher->save($this->editor, $this->page, $draft, 1);
    $result = app(HubFeed::class)->forUser($this->reader, 'reorganisation');
    expect($result['pages'])->toBeEmpty()->and($result['navigation'])->toBeEmpty()->and($result['editablePages'])->toBeEmpty();
});

test('a reader cannot download an unreferenced draft image from a published page', function () {
    Storage::fake('private');
    Storage::disk('private')->put('company-hub/draft-image.png', 'test-image');
    $media = HubMedia::create([
        'hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/draft-image.png', 'name' => 'draft.png', 'mime' => 'image/png', 'size' => 10,
    ]);
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->actingAs($this->reader)->get('/company-hub/media/'.$media->id)->assertNotFound();
    $this->actingAs($this->editor)->get('/company-hub/media/'.$media->id)->assertOk();
});

test('personal shortcuts are isolated to their owner and reject executable links', function () {
    $this->actingAs($this->reader)->put('/company-hub/shortcuts', ['shortcuts' => [['id' => 'one', 'title' => 'My day', 'url' => '/my-day']]])->assertRedirect();
    expect(app(HubFeed::class)->forUser($this->reader)['shortcuts'])->toHaveCount(1);
    expect(app(HubFeed::class)->forUser($this->editor)['shortcuts'])->toBeEmpty();
    $this->putJson('/company-hub/shortcuts', ['shortcuts' => [['id' => 'one', 'title' => 'Unsafe', 'url' => '//untrusted.example']]])->assertUnprocessable();
});

test('bookmarks cannot be used to probe unpublished pages and saving twice is idempotent', function () {
    $this->actingAs($this->reader)->post('/company-hub/bookmarks', ['subject_type' => 'page', 'subject_id' => $this->page->id, 'saved' => true])->assertNotFound();
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    foreach ([1, 2] as $attempt) {
        $this->post('/company-hub/bookmarks', ['subject_type' => 'page', 'subject_id' => $this->page->id, 'saved' => true])->assertRedirect();
    }
    $this->assertDatabaseCount('hub_bookmarks', 1);
});

test('hero and visual layout publish atomically and survive a draft edit', function () {
    $draft = $this->page->draft;
    $draft['hero'] = ['style' => 'brand', 'height' => 'comfortable', 'focal_x' => 25, 'focal_y' => 70, 'overlay' => 65, 'action_label' => 'Explore resources', 'action_url' => '/company-hub?view=resources', 'alignment' => 'center', 'icon' => 'newspaper', 'show_search' => false, 'show_summary' => false, 'show_metrics' => true];
    $draft['sections'][0]['title'] = 'Company story';
    $draft['sections'][0]['tone'] = 'brand';
    $draft['sections'][0]['spacing'] = 'spacious';
    $draft['sections'][0]['blocks'][0]['width'] = 8;
    $draft['sections'][] = ['id' => 'empty', 'columns' => 1, 'blocks' => []];
    $saved = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $saved, 2, null, null);
    $published = app(HubAccess::class)->assertRead($this->reader, $saved->fresh())->content;
    expect($published['hero'])->toEqual($draft['hero'])->and($published['sections'])->toEqual($draft['sections']);
    $draft['hero']['height'] = 'compact';
    $draft['hero']['alignment'] = 'left';
    $draft['hero']['icon'] = 'flag';
    $draft['hero']['show_summary'] = true;
    $this->publisher->save($this->editor, $saved, $draft, 2);
    expect(app(HubAccess::class)->assertRead($this->reader, $saved->fresh())->content['hero']['height'])->toBe('comfortable');
    $this->actingAs($this->reader)->get('/company-hub/read/'.$saved->slug)->assertInertia(fn ($page) => $page
        ->component('company-hub/index')
        ->where('selectedPage.content.hero.alignment', 'center')
        ->where('selectedPage.content.hero.icon', 'newspaper')
        ->where('selectedPage.content.hero.show_summary', false)
        ->where('selectedPage.can_edit', false));
    $this->putJson('/company-hub/manage/'.$saved->id, ['content' => $draft, 'version' => 3])->assertNotFound();
});

test('visual settings reject invalid widths unsafe hero links and incomplete actions', function () {
    foreach ([
        ['sections.0.blocks.0.width', 13],
        ['hero.action_url', 'javascript:alert(1)'],
        ['hero.overlay', 5],
        ['hero.focal_x', 101],
        ['hero.action_label', 'Missing destination'],
        ['hero.alignment', 'right'],
        ['hero.icon', '<script>'],
        ['hero.show_search', 'yes'],
        ['hero.show_summary', null],
        ['hero.show_metrics', 3],
    ] as [$key, $value]) {
        $draft = $this->page->draft;
        data_set($draft, $key, $value);
        $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$this->page->id, ['content' => $draft, 'version' => 1])->assertUnprocessable();
    }
    expect($this->page->fresh()->draft_version)->toBe(1);
});

test('hero summary is stable during search and excludes private unpublished and expired pages', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $guide = $this->publisher->create($this->editor, 'knowledge', 'Staff guide');
    $this->publisher->publish($this->editor, $guide, 1, null, null);
    $this->publisher->create($this->editor, 'news', 'Private draft');
    $private = $this->publisher->create($this->editor, 'news', 'Other site story');
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$otherSite->id]]);
    $this->editor = $this->editor->fresh();
    $private = $this->publisher->save($this->editor, $private, [...$private->draft, 'audience' => ['type' => 'sites', 'ids' => [$otherSite->id]]], 1);
    $this->publisher->publish($this->editor, $private, 2, null, null);
    $expiring = $this->publisher->create($this->editor, 'news', 'Expiring story');
    $this->publisher->publish($this->editor, $expiring, 1, null, now()->addHour()->toIso8601String());
    $this->travel(2)->hours();
    $summary = app(HubFeed::class)->forUser($this->reader)['heroSummary'];
    expect(collect($summary)->keyBy('url')['/company-hub?view=news']['value'])->toBe(1)
        ->and(collect($summary)->keyBy('url')['/company-hub?view=resources']['value'])->toBe(1)
        ->and(app(HubFeed::class)->forUser($this->reader, 'No such guide')['heroSummary'])->toBe($summary);
});

test('the media library denies readers even on a published page', function () {
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $url = '/company-hub/manage/'.$this->page->id.'/media';
    $this->actingAs($this->reader)->getJson($url)->assertNotFound();
    $this->actingAs($this->editor)->getJson($url)->assertOk()->assertJsonPath('media', []);
});

test('approved page templates create private editable drafts', function () {
    foreach (['knowledge' => 'guide', 'department' => 'team', 'campaign' => 'campaign', 'page' => 'blank'] as $kind => $template) {
        $this->actingAs($this->editor)->post('/company-hub/pages', ['kind' => $kind, 'template' => $template, 'title' => 'New '.$kind])->assertRedirect();
        $page = HubPage::where('kind', $kind)->latest('id')->firstOrFail();
        expect($page->draft['title'])->toBe('New '.$kind)->and(app(HubAccess::class)->visibleRevision($this->reader, $page))->toBeNull();
    }
});

test('media reuse copies protected files only between pages the editor can edit', function () {
    Storage::fake('private');
    Storage::disk('private')->put('company-hub/source.png', 'image');
    $media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/source.png', 'name' => 'Source.png', 'mime' => 'image/png', 'size' => 5]);
    $target = $this->publisher->create($this->editor, 'page', 'Resource destination');
    $url = '/company-hub/manage/'.$target->id.'/media';
    $this->actingAs($this->reader)->postJson($url.'/reuse', ['media_id' => $media->id])->assertNotFound();
    $id = $this->actingAs($this->editor)->postJson($url.'/reuse', ['media_id' => $media->id])->assertOk()->json('id');
    $copy = HubMedia::findOrFail($id);
    expect($copy->hub_page_id)->toBe($target->id)->and($copy->path)->not->toBe($media->path);
    Storage::disk('private')->assertExists($copy->path);
    $duplicate = $this->postJson($url.'/reuse', ['media_id' => $id, 'version' => 1, 'copy' => true])->assertOk()->json('id');
    $duplicate = HubMedia::findOrFail($duplicate);
    expect($duplicate->id)->not->toBe($copy->id)->and($duplicate->path)->not->toBe($copy->path)
        ->and(Storage::disk('private')->get($duplicate->path))->toBe('image')->and($duplicate->source_media_id)->toBe($copy->id);
    $this->patchJson($url.'/'.$media->id, ['name' => 'Wrong page'])->assertNotFound();
    $this->patchJson($url.'/'.$id, ['name' => '../unsafe'])->assertUnprocessable();
    $this->patchJson($url.'/'.$id, ['name' => 'Team photograph.png', 'version' => 1])->assertOk();
    $this->actingAs($this->reader)->get('/company-hub/media/'.$id)->assertNotFound();
});

test('media metadata is versioned and leaves published snapshots and original bytes unchanged', function () {
    Storage::fake('private');
    Storage::disk('private')->put('company-hub/metadata.png', 'original');
    $media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/metadata.png', 'name' => 'Original.png', 'mime' => 'image/png', 'size' => 8]);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][] = ['id' => 'photo', 'type' => 'image', 'title' => 'Our place', 'media_id' => $media->id, 'alt' => 'Published description'];
    $this->publisher->save($this->editor, $this->page, $draft, 1);
    $revision = $this->publisher->publish($this->editor, $this->page->fresh(), 2, null, null);
    $before = $revision->content;
    $url = '/company-hub/manage/'.$this->page->id.'/media/'.$media->id;
    $this->actingAs($this->editor);
    $album = $this->postJson('/company-hub/editor/media/albums', ['name' => 'Workplace'])->assertOk()->json('id');
    $this->patchJson($url, ['name' => 'Welcome.png', 'alt' => 'A welcoming workplace', 'album_id' => $album, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
    $this->patchJson($url, ['name' => 'Old edit', 'version' => 1])->assertConflict();
    $this->postJson('/company-hub/manage/'.$this->page->id.'/media/reuse', ['media_id' => $media->id, 'version' => 1])->assertConflict();
    expect($revision->fresh()->content)->toBe($before)->and(Storage::disk('private')->get($media->path))->toBe('original');
    $this->patchJson($url, ['name' => 'Welcome.png', 'alt' => '', 'album_id' => null, 'version' => 2])->assertOk()->assertJsonPath('alt', '')->assertJsonPath('album_id', null);
    $this->actingAs($this->reader)->patchJson($url, ['name' => 'Denied', 'version' => 3])->assertNotFound();
    $this->getJson('/company-hub/editor/media')->assertForbidden();
    $this->postJson('/company-hub/editor/media/albums', ['name' => 'Denied'])->assertForbidden();
});

test('media albums and searches never reveal inaccessible files or another editors empty albums', function () {
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($other, $outside);
    $private = $this->publisher->create($other, 'page', 'Restricted page');
    $private->update(['draft' => [...$private->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]]);
    $album = DB::table('hub_media_albums')->insertGetId(['owner_id' => $other->id, 'name' => 'Private collection', 'created_at' => now(), 'updated_at' => now()]);
    HubMedia::create(['hub_page_id' => $private->id, 'uploaded_by' => $other->id, 'path' => 'private.png', 'name' => 'Private image', 'mime' => 'image/png', 'size' => 1, 'album_id' => $album]);
    $visible = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'visible.png', 'name' => 'Visible image', 'mime' => 'image/png', 'size' => 1, 'alt' => 'Welcoming people']);
    $this->actingAs($this->editor)->getJson('/company-hub/editor/media')->assertOk()->assertJsonCount(1, 'media')->assertJsonCount(0, 'albums')->assertJsonMissing(['title' => 'Restricted page']);
    $this->getJson('/company-hub/editor/media?q=Welcoming')->assertOk()->assertJsonPath('media.0.id', $visible->id);
    $this->getJson('/company-hub/editor/media?album_id='.$album)->assertOk()->assertJsonCount(0, 'media');
    $this->patchJson('/company-hub/manage/'.$this->page->id.'/media/'.$visible->id, ['name' => 'Image', 'album_id' => $album, 'version' => 1])->assertUnprocessable();
    $this->getJson('/company-hub/editor/media?q=missing')->assertOk()->assertJsonCount(0, 'media');
});

test('image crops follow the preview focus and preserve the source and published reader access', function () {
    Storage::fake('private');
    $image = imagecreatetruecolor(80, 40);
    imagefilledrectangle($image, 0, 0, 39, 39, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 40, 0, 79, 39, imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    Storage::disk('private')->put('company-hub/crop-source.png', $bytes);
    $media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/crop-source.png', 'name' => 'Source.png', 'mime' => 'image/png', 'size' => strlen($bytes), 'alt' => 'Red and blue']);
    $draft = [...$this->page->draft, 'cover_id' => $media->id];
    $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->publisher->publish($this->editor, $this->page->fresh(), 2, null, null);
    $url = '/company-hub/manage/'.$this->page->id.'/media/'.$media->id.'/crop';
    $payload = ['version' => 1, 'ratio' => '1:1', 'x' => 100, 'y' => 50];
    $this->actingAs($this->reader)->postJson($url, $payload)->assertNotFound();
    $result = $this->actingAs($this->editor)->postJson($url, $payload)->assertOk()->assertJsonPath('alt', 'Red and blue')->json();
    $copy = HubMedia::findOrFail($result['id']);
    $cropped = imagecreatefromstring(Storage::disk('private')->get($copy->path));
    expect(imagesx($cropped))->toBe(40)->and(imagesy($cropped))->toBe(40)->and(imagecolorat($cropped, 20, 20) & 0xFFFFFF)->toBe(255);
    imagedestroy($cropped);
    expect($copy->path)->not->toBe($media->path)->and($copy->source_media_id)->toBe($media->id)
        ->and(Storage::disk('private')->get($media->path))->toBe($bytes)->and($this->page->fresh()->draft['cover_id'])->toBe($media->id);
    $this->postJson($url, [...$payload, 'version' => 2])->assertConflict();
    $this->postJson($url, [...$payload, 'x' => 101])->assertUnprocessable();
    $this->postJson($url, [...$payload, 'ratio' => '0:0'])->assertUnprocessable();
    $this->actingAs($this->reader)->get('/company-hub/media/'.$copy->id)->assertNotFound();
    $this->get('/company-hub/media/'.$media->id)->assertOk();
    expect(DB::table('audit_logs')->where('action', 'company_hub.media_crop_copy')->count())->toBe(1);
});

test('media crops reject invalid files and wrong page bindings and retain transparency', function () {
    Storage::fake('private');
    $target = $this->publisher->create($this->editor, 'page', 'Another page');
    $media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'bad.png', 'name' => 'Bad.png', 'mime' => 'image/png', 'size' => 3]);
    Storage::disk('private')->put('bad.png', 'bad');
    $payload = ['version' => 1, 'ratio' => '4:3', 'x' => 50, 'y' => 50];
    $this->actingAs($this->editor)->postJson('/company-hub/manage/'.$target->id.'/media/'.$media->id.'/crop', $payload)->assertNotFound();
    $url = '/company-hub/manage/'.$this->page->id.'/media/'.$media->id.'/crop';
    $this->postJson($url, $payload)->assertUnprocessable();
    $image = imagecreatetruecolor(40, 30);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    Storage::disk('private')->put('bad.png', $bytes);
    $copy = HubMedia::findOrFail($this->postJson($url, $payload)->assertOk()->json('id'));
    $png = imagecreatefromstring(Storage::disk('private')->get($copy->path));
    expect((imagecolorat($png, 10, 10) >> 24) & 0x7F)->toBe(127);
    imagedestroy($png);
    $media->update(['mime' => 'application/pdf']);
    $this->postJson($url, $payload)->assertUnprocessable();
});

test('reusable sections are personal and omit page-bound private media', function () {
    $section = $this->page->draft['sections'][0];
    $url = '/company-hub/manage/'.$this->page->id.'/presets';
    $this->actingAs($this->reader)->getJson($url)->assertNotFound();
    $this->actingAs($this->editor)->postJson($url, ['name' => 'Story layout', 'section' => $section])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'presets');
    $preset = DB::table('hub_section_presets')->first();
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($other, $this->site);
    $this->actingAs($other)->getJson($url)->assertOk()->assertJsonCount(0, 'presets');
    $this->deleteJson($url.'/'.$preset->id)->assertNotFound();
});

test('shared sections are available to authorised editors and strip private media from independent copies', function () {
    $media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/source.png', 'name' => 'Source.png', 'mime' => 'image/png', 'size' => 5]);
    $section = $this->page->draft['sections'][0];
    $section['blocks'][] = ['id' => 'photo', 'type' => 'image', 'title' => 'Team photo', 'media_id' => $media->id];
    $url = '/company-hub/manage/'.$this->page->id.'/presets';
    $this->actingAs($this->editor)->postJson($url, ['name' => 'Welcome layout', 'section' => $section, 'scope' => 'shared'])->assertOk();
    $id = DB::table('hub_section_presets')->value('id');
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($other, $this->site);
    $target = $this->publisher->create($other, 'page', 'Another page');
    $targetUrl = '/company-hub/manage/'.$target->id.'/presets';
    $this->actingAs($other)->getJson($targetUrl)->assertOk()->assertJsonCount(1, 'presets')->assertJsonPath('presets.0.scope', 'shared');
    $copy = $this->postJson($targetUrl.'/'.$id.'/use', ['version' => 1])->assertOk()->json('section');
    expect($copy['blocks'][1])->not->toHaveKey('media_id');
    $copy['blocks'][0]['body'] = 'Independent new text';
    expect(json_decode(DB::table('hub_section_presets')->where('id', $id)->value('section'), true)['blocks'][0]['body'])->not->toBe('Independent new text');
    expect($target->fresh()->draft_version)->toBe(1)->and($this->page->fresh()->draft_version)->toBe(1);
    $this->actingAs($this->reader)->postJson($targetUrl.'/'.$id.'/use', ['version' => 1])->assertNotFound();
});

test('shared sections recheck original and current source audience before listing inserting or removing', function () {
    $sourceUrl = '/company-hub/manage/'.$this->page->id.'/presets';
    $this->actingAs($this->editor)->postJson($sourceUrl, ['name' => 'Private section', 'section' => $this->page->draft['sections'][0], 'scope' => 'shared'])->assertOk();
    $id = DB::table('hub_section_presets')->value('id');
    $target = $this->publisher->create($this->editor, 'page', 'Destination');
    $url = '/company-hub/manage/'.$target->id.'/presets';
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $privateAudience = ['type' => 'sites', 'ids' => [$outside->id]];
    $this->page->update(['draft' => [...$this->page->draft, 'audience' => $privateAudience]]);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'presets');
    $this->postJson($url.'/'.$id.'/use', ['version' => 1])->assertNotFound();
    $this->deleteJson($url.'/'.$id, ['version' => 1])->assertNotFound();
    $this->page->update(['draft' => [...$this->page->draft, 'audience' => ['type' => 'all', 'ids' => []]]]);
    DB::table('hub_section_presets')->where('id', $id)->update(['source_audience' => json_encode($privateAudience)]);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'presets');
    $this->postJson($url.'/'.$id.'/use', ['version' => 1])->assertNotFound();
});

test('section library removal is recoverable versioned and leaves existing pages unchanged', function () {
    $url = '/company-hub/manage/'.$this->page->id.'/presets';
    $this->actingAs($this->editor)->postJson($url, ['name' => 'Reusable story', 'section' => $this->page->draft['sections'][0], 'scope' => 'shared'])->assertOk();
    $id = DB::table('hub_section_presets')->value('id');
    $this->deleteJson($url.'/'.$id, ['version' => 2])->assertConflict();
    $this->deleteJson($url.'/'.$id, ['version' => 1])->assertOk()->assertJsonPath('version', 2);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'presets');
    $this->postJson($url.'/'.$id.'/use', ['version' => 1])->assertNotFound();
    $this->postJson($url.'/'.$id.'/restore', ['version' => 1])->assertConflict();
    $this->postJson($url.'/'.$id.'/restore', ['version' => 2])->assertOk()->assertJsonPath('version', 3);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'presets');
    $this->postJson($url.'/'.$id.'/use', ['version' => 1])->assertConflict();
    $this->postJson($url.'/'.$id.'/use', ['version' => 3])->assertOk();
    expect($this->page->fresh()->draft_version)->toBe(1)
        ->and(DB::table('audit_logs')->whereIn('action', ['company_hub.section_save', 'company_hub.section_remove', 'company_hub.section_restore'])->count())->toBe(3);
});

test('shared section management requires layout permission but authorised authors can use existing sections', function () {
    $url = '/company-hub/manage/'.$this->page->id.'/presets';
    $payload = ['name' => 'Shared story', 'section' => $this->page->draft['sections'][0], 'scope' => 'shared'];
    $this->actingAs($this->editor)->postJson($url, $payload)->assertOk();
    $id = DB::table('hub_section_presets')->value('id');
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.layout')->value('id'), ['allowed' => false]);
    $this->editor = $this->editor->fresh();
    $this->actingAs($this->editor)->getJson($url)->assertOk()->assertJsonPath('can_share', false)->assertJsonPath('presets.0.can_remove', false);
    $this->postJson($url.'/'.$id.'/use', ['version' => 1])->assertOk();
    $this->postJson($url, $payload)->assertForbidden();
    $this->deleteJson($url.'/'.$id, ['version' => 1])->assertForbidden();
    $this->postJson($url, [...$payload, 'scope' => 'personal'])->assertOk();
});

test('navigation reorder is atomic and does not change the published navigation', function () {
    $second = $this->publisher->create($this->editor, 'page', 'Second');
    $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $items = [['id' => $this->page->id, 'version' => 1, 'visible' => true], ['id' => $second->id, 'version' => 2, 'visible' => true]];
    $this->actingAs($this->reader)->putJson('/company-hub/editor/navigation', ['items' => $items])->assertForbidden();
    $this->actingAs($this->editor)->putJson('/company-hub/editor/navigation', ['items' => $items])->assertConflict();
    expect($this->page->fresh()->draft_version)->toBe(1);
    $items[1]['version'] = 1;
    $this->put('/company-hub/editor/navigation', ['items' => $items])->assertRedirect();
    expect($this->page->fresh()->draft['show_in_navigation'])->toBeTrue();
    expect(app(HubFeed::class)->forUser($this->reader)['navigation'])->toBe([]);
});

test('event registration is per occurrence idempotent and promotes the waiting list', function () {
    $event = HrCalendarEvent::create(['title' => 'Team session', 'event_type' => 'company', 'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'rrule' => 'FREQ=WEEKLY;COUNT=2', 'created_by' => $this->editor->id]);
    $date = $event->starts_at->toDateString();
    $url = '/company-hub/events/'.$event->id.'/registration';
    $this->actingAs($this->editor)->putJson($url, ['enabled' => true, 'capacity' => 1, 'version' => 1])->assertOk();
    $this->actingAs($this->reader)->postJson($url, ['date' => $date, 'status' => 'going'])->assertOk()->assertJsonPath('registration.mine', 'going');
    $this->postJson($url, ['date' => $date, 'status' => 'going'])->assertOk()->assertJsonPath('registration.going', 1);
    $this->actingAs($this->editor)->postJson($url, ['date' => $date, 'status' => 'going'])->assertOk()->assertJsonPath('registration.mine', 'waiting');
    $this->postJson($url, ['date' => $event->starts_at->copy()->addWeek()->toDateString(), 'status' => 'going'])->assertOk()->assertJsonPath('registration.mine', 'going');
    $this->actingAs($this->reader)->postJson($url, ['date' => $date, 'status' => 'declined'])->assertOk()->assertJsonPath('registration.waiting', 0)->assertJsonPath('registration.going', 1);
    expect(DB::table('hr_calendar_registrations')->where('user_id', $this->editor->id)->where('occurrence_date', $date)->value('status'))->toBe('going');
    $this->postJson($url, ['date' => $event->starts_at->copy()->addDay()->toDateString(), 'status' => 'going'])->assertNotFound();
    $this->actingAs($this->editor)->putJson($url, ['enabled' => true, 'capacity' => 2, 'version' => 1])->assertConflict();
});

test('registration direct objects capacity and calendar download obey calendar access', function () {
    $event = HrCalendarEvent::create(['title' => "Team, together\nBEGIN:BAD", 'event_type' => 'company', 'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'created_by' => $this->editor->id, 'site_id' => $this->site->id]);
    $date = $event->starts_at->toDateString();
    $url = '/company-hub/events/'.$event->id;
    $this->actingAs($this->reader)->putJson($url.'/registration', ['enabled' => true, 'capacity' => 1, 'version' => 1])->assertNotFound();
    $this->actingAs($this->editor)->putJson($url.'/registration', ['enabled' => true, 'capacity' => 2, 'version' => 1])->assertOk();
    foreach ([$this->reader, $this->editor] as $person) {
        $this->actingAs($person)->postJson($url.'/registration', ['date' => $date, 'status' => 'going'])->assertOk();
    }
    $this->putJson($url.'/registration', ['enabled' => true, 'capacity' => 1, 'version' => 2])->assertUnprocessable();
    $calendar = $this->get($url.'/calendar?date='.$date.'&reminder=15')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($calendar)->toContain('BEGIN:VALARM', 'SUMMARY:Team\\, together\\nBEGIN:BAD')->not->toContain("\r\nBEGIN:BAD");
    $hidden = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $hidden->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($hidden, Site::factory()->create());
    $this->actingAs($hidden)->get($url.'/calendar?date='.$date)->assertNotFound();
    $this->postJson($url.'/registration', ['date' => $date, 'status' => 'going'])->assertNotFound();
    $event->update(['archived_at' => now()]);
    $this->actingAs($this->reader)->postJson($url.'/registration', ['date' => $date, 'status' => 'going'])->assertNotFound();
});

test('resource reports remain private to editors and resolution checks the current version', function () {
    $page = $this->publisher->create($this->editor, 'knowledge', 'Team guide');
    $url = '/company-hub/pages/'.$page->id.'/reports';
    $data = ['issue' => 'Out of date', 'note' => 'The room name has changed.'];
    $this->actingAs($this->reader)->postJson($url, $data)->assertNotFound();
    $this->publisher->publish($this->editor, $page, 1, null, null);
    $this->postJson($url, $data)->assertOk();
    $this->postJson($url, $data)->assertOk();
    $report = DB::table('hub_interactions')->where('kind', 'resource_report')->sole();
    expect(app(HubParticipation::class)->read($this->reader, $page)['comments'])->toBe([]);
    $resolve = '/company-hub/manage/'.$page->id.'/reports/'.$report->id;
    $body = ['resolved' => true, 'response' => 'Corrected in the page draft.', 'version' => 1];
    $this->putJson($resolve, $body)->assertNotFound();
    $this->actingAs($this->editor)->putJson($resolve, $body)->assertOk();
    $this->putJson($resolve, $body)->assertConflict();
    $editorial = collect(app(HubEditorial::class)->workspace($this->editor))->firstWhere('id', $page->id);
    expect($editorial['reports'][0]['resolved'])->toBeTrue()->and($editorial['reports'][0]['response'])->toBe('Corrected in the page draft.');
});

test('hub staff see only audience-authorised events and protected event files', function () {
    Storage::fake('private');
    $event = HrCalendarEvent::create(['title' => 'Company event', 'event_type' => 'company', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'created_by' => $this->editor->id, 'site_id' => $this->site->id]);
    $path = 'hr/calendar/proof.png';
    Storage::disk('private')->put($path, 'image');
    $file = $event->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => $path, 'original_name' => 'event.png', 'mime' => 'image/png', 'size' => 5]);
    expect(app(HubFeed::class)->forUser($this->reader)['events'])->toHaveCount(1);
    $this->actingAs($this->reader)->get('/company-hub/event-files/'.$file->id)->assertOk();
    $event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $this->get('/company-hub/event-files/'.$file->id)->assertNotFound();
    expect(app(HubFeed::class)->forUser($this->reader)['events'])->toBe([]);
});

test('discovery notices retain acknowledgement obligations after seen and respect expiry', function () {
    $notice = HrAnnouncement::create([
        'title' => 'Important company notice', 'content' => 'Please review the new guide.', 'priority' => 'high',
        'status' => 'published', 'target_audience' => 'all', 'published_at' => now()->subHour(),
        'expires_at' => now()->addDay(), 'requires_acknowledgement' => true, 'created_by' => $this->editor->id,
    ]);
    $updates = fn () => collect(app(HubFeed::class)->forUser($this->reader)['updates'])->where('type', 'announcements')->values();
    expect($updates())->toHaveCount(1)->and($updates()[0]['required'])->toBeTrue();
    $key = $updates()[0]['key'];
    $this->actingAs($this->reader)->postJson('/company-hub/seen', ['keys' => [$key]])->assertOk();
    expect($updates()[0]['unread'])->toBeFalse()->and($updates()[0]['required'])->toBeTrue()
        ->and($notice->acknowledgements()->count())->toBe(0);
    $notice->update(['content' => 'Please review the revised guide.']);
    expect($updates()[0]['key'])->not->toBe($key)->and($updates()[0]['unread'])->toBeTrue();
    $this->travel(2)->days();
    expect($updates())->toHaveCount(0);
    $this->postJson('/company-hub/seen', ['keys' => [$key]])->assertConflict();
});

test('discovery recognition follows visible teams and honours removal of recognition permission', function () {
    Notification::fake();
    $team = HrDepartment::create(['name' => 'Care team', 'is_active' => true]);
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $team->id]);
    app(HubDiscovery::class)->saveInterests($this->reader, ['topics' => [], 'teams' => [$team->id], 'version' => 1]);
    $kudos = app(FeedService::class)->sendKudos($this->editor, $this->reader->id, 'teamwork', 'Thank you for sharing your learning.');
    $items = collect(app(HubFeed::class)->forUser($this->reader)['updates'])->where('type', 'shoutouts')->values();
    expect($items)->toHaveCount(1)->and($items[0]['following'])->toBeTrue()->and($items[0]['key'])->toBe('kudos:'.$kudos->id);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.view')->value('id'), ['allowed' => false]);
    $this->reader = $this->reader->fresh();
    expect(collect(app(HubFeed::class)->forUser($this->reader)['updates'])->where('type', 'shoutouts'))->toHaveCount(0);
    $this->actingAs($this->reader)->postJson('/company-hub/seen', ['keys' => [$items[0]['key']]])->assertConflict();
});
