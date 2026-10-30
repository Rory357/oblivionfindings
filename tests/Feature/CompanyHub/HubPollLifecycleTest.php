<?php

use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14T00:00:00Z'));
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->other->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->other, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'news', 'Have your say');
    $draft = $this->page->draft;
    $draft['sections'] = [['id' => 'main', 'columns' => 1, 'blocks' => [
        ['id' => 'intro', 'type' => 'text', 'title' => 'A shared voice', 'body' => 'A complete story containing the canonical company poll.'],
        ['id' => 'poll', 'type' => 'poll', 'title' => 'Have your say', 'question' => 'Which activity?', 'options' => ['Walk', 'Lunch'], 'closes_at' => '2026-10-21T17:00:00+13:00'],
    ]]];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 1);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->participation = app(HubParticipation::class);
    $this->poll = fn (User $viewer) => $this->participation->read($viewer, $this->page)['polls']['poll'];
    $this->context = function (User $viewer): array {
        $poll = ($this->poll)($viewer);

        return ['actor_id' => $viewer->id, 'revision_id' => $this->revision->id, 'poll_key' => $poll['key'], 'version' => $poll['version']];
    };
    $this->vote = function (User $viewer, int $choice = 0, array $extra = []) {
        $context = ($this->context)($viewer);

        return $this->actingAs($viewer)->postJson('/company-hub/pages/'.$this->page->id.'/interactions', [
            'kind' => 'vote', 'block_id' => 'poll', 'choice' => $choice, 'actor_id' => $context['actor_id'],
            'revision_id' => $context['revision_id'], 'poll_key' => $context['poll_key'], 'poll_version' => $context['version'], ...$extra,
        ]);
    };
    $this->state = fn (array $data = []) => $this->actingAs($this->editor)->putJson('/company-hub/pages/'.$this->page->id.'/polls/poll/state', [...($this->context)($this->editor), 'action' => 'close', ...$data]);
});

test('polls close at the exact instant and legacy dates keep their canonical local end of day', function () {
    expect(($this->poll)($this->reader))->toMatchArray(['closed' => false, 'closes_at' => '2026-10-21T04:00:00.000000Z', 'timezone' => 'Pacific/Auckland', 'version' => 0]);
    $this->travelTo(Carbon::parse('2026-10-21T03:59:59Z'));
    ($this->vote)($this->reader)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->travelTo(Carbon::parse('2026-10-21T04:00:00Z'));
    expect(($this->poll)($this->other)['closed'])->toBeTrue();
    ($this->vote)($this->other)->assertUnprocessable()->assertJsonPath('message', 'This poll is closed.');
    expect(($this->poll)($this->other))->toMatchArray(['results_visible' => true, 'total' => 1, 'counts' => [1, 0], 'mine' => null]);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][1]['closes_at'] = '2026-10-21';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->travelTo(Carbon::parse('2026-10-21T23:59:59+13:00')->utc());
    expect(($this->poll)($this->other)['closed'])->toBeFalse();
    ($this->vote)($this->other, 1)->assertOk();
    $this->travelTo(Carbon::parse('2026-10-22T00:00:00+13:00')->utc());
    expect(($this->poll)($this->editor)['closed'])->toBeTrue()->and(($this->poll)($this->editor)['total'])->toBe(2);
});

test('results are absent before own vote including editors and canonical duplicate votes replace the current choice', function () {
    ($this->vote)($this->reader, 0)->assertOk()->assertJsonPath('saved', true)->assertJsonPath('version', 0);
    foreach ([$this->other, $this->editor] as $viewer) {
        expect(($this->poll)($viewer))->toMatchArray(['results_visible' => false, 'total' => null, 'counts' => null, 'mine' => null]);
    }
    expect(($this->poll)($this->editor)['can_manage'])->toBeTrue();
    ($this->vote)($this->reader, 1)->assertOk();
    expect(($this->poll)($this->reader))->toMatchArray(['results_visible' => true, 'total' => 1, 'counts' => [0, 1], 'mine' => 1]);
    expect(DB::table('hub_interactions')->where('kind', 'vote')->count())->toBe(1);
    $record = DB::table('hub_interactions')->where('kind', 'vote')->sole();
    expect(json_decode($record->body, true))->toBe(['key' => ($this->poll)($this->reader)['key'], 'choice' => 1]);
    DB::table('hub_interactions')->where('id', $record->id)->update(['body' => json_encode(['key' => ($this->poll)($this->reader)['key'], 'choice' => '1'])]);
    expect(($this->poll)($this->reader))->toMatchArray(['mine' => 1, 'total' => 1, 'counts' => [0, 1]]);
    expect(array_keys(($this->poll)($this->other)))->not->toContain('user_id', 'voters', 'names');
    Notification::assertNothingSent();
});

test('authorised immediate close and reopen preserve votes and immutable snapshots with version guarded future deadlines', function () {
    ($this->vote)($this->reader)->assertOk();
    $snapshot = $this->revision->fresh()->getAttributes();
    $draft = $this->page->fresh()->draft;
    ($this->state)()->assertOk()->assertExactJson(['saved' => true, 'page_id' => $this->page->id, 'block_id' => 'poll', 'key' => ($this->poll)($this->editor)['key'], 'version' => 1]);
    expect(($this->poll)($this->other))->toMatchArray(['closed' => true, 'total' => 1, 'counts' => [1, 0]]);
    ($this->vote)($this->other)->assertUnprocessable();
    ($this->state)(['action' => 'reopen', 'version' => 0])->assertConflict();
    ($this->state)(['action' => 'reopen'])->assertOk()->assertJsonPath('version', 2);
    expect(($this->poll)($this->other))->toMatchArray(['closed' => false, 'total' => null, 'counts' => null]);
    expect(($this->poll)($this->reader)['total'])->toBe(1);
    $this->travelTo(Carbon::parse('2026-10-21T04:00:00Z'));
    ($this->state)(['action' => 'reopen'])->assertUnprocessable()->assertJsonValidationErrors('closes_at');
    ($this->state)(['action' => 'reopen', 'closes_at' => '2026-10-21T03:59:00Z'])->assertUnprocessable();
    ($this->state)(['action' => 'reopen', 'closes_at' => '2026-10-22T17:00:00+13:00'])->assertOk()->assertJsonPath('version', 3);
    expect(($this->poll)($this->other)['closes_at'])->toBe('2026-10-22T04:00:00.000000Z');
    ($this->vote)($this->other, 1)->assertOk();
    expect($this->revision->fresh()->getAttributes())->toBe($snapshot)->and($this->page->fresh()->draft)->toBe($draft);
    $audit = AuditLog::where('action', 'company_hub.poll.reopen')->latest('id')->firstOrFail();
    expect($audit->meta)->toMatchArray(['revision_id' => $this->revision->id, 'old_version' => 2, 'new_version' => 3]);
    expect(json_encode($audit->meta))->not->toContain('Which activity?', 'Walk', $this->reader->name, 'user_id');
    $migration = require base_path('database/migrations/2026_10_14_250000_add_company_hub_poll_states.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'reviewed rollback');
    Notification::assertNothingSent();
});

test('new approved revisions use their authored schedule and changed questions never inherit old votes or operational states', function () {
    ($this->vote)($this->reader)->assertOk();
    ($this->state)()->assertOk();
    $old = ($this->context)($this->editor);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][1]['closes_at'] = '2026-10-25T17:00:00+13:00';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    expect(($this->poll)($this->reader))->toMatchArray(['closed' => false, 'total' => 1, 'closes_at' => '2026-10-25T04:00:00.000000Z', 'version' => 1]);
    ($this->state)($old)->assertConflict();
    ($this->vote)($this->other, 0, ['revision_id' => $old['revision_id']])->assertConflict();
    ($this->state)()->assertOk()->assertJsonPath('version', 2);
    $draft['sections'][0]['blocks'][1]['question'] = 'A different question?';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 3);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 4, null, null);
    expect(($this->poll)($this->reader))->toMatchArray(['mine' => null, 'total' => null, 'counts' => null, 'closed' => false, 'version' => 0]);
    expect(($this->poll)($this->reader)['key'])->not->toBe($old['poll_key']);
    ($this->vote)($this->reader, 1, ['poll_key' => $old['poll_key']])->assertConflict();
    expect(DB::table('hub_interactions')->where('kind', 'vote')->count())->toBe(1)->and(DB::table('hub_poll_states')->count())->toBe(1);
});

test('poll mutations deny spoofed contexts revoked publication staff sites hidden blocks and withdrawn live audiences', function () {
    $url = '/company-hub/pages/'.$this->page->id.'/polls/poll/state';
    $valid = [...($this->context)($this->editor), 'action' => 'close'];
    $this->actingAs($this->reader)->putJson($url, [...$valid, 'actor_id' => $this->reader->id])->assertForbidden();
    ($this->state)(['actor_id' => $this->reader->id])->assertConflict();
    ($this->state)(['poll_key' => str_repeat('a', 64)])->assertConflict();
    foreach (['user_id', 'author_id', 'hub_page_id', 'page_id', 'parent_id', 'body', 'choice'] as $spoof) {
        ($this->state)([$spoof => $spoof === 'body' ? 'Private content' : 1])->assertUnprocessable();
    }
    ($this->vote)($this->reader, 0, ['actor_id' => $this->other->id])->assertConflict();
    ($this->vote)($this->reader, 0, ['poll_version' => 1])->assertConflict();
    $permission = Permission::where('key', 'company_hub.publish')->value('id');
    $this->editor->permissionOverrides()->attach($permission, ['allowed' => false]);
    $this->actingAs($this->editor)->putJson($url, $valid)->assertForbidden();
    $this->editor->permissionOverrides()->updateExistingPivot($permission, ['allowed' => true]);
    $profile = $this->editor->hrEmployeeProfile;
    $profile->update(['is_active' => false]);
    $this->actingAs($this->editor)->putJson($url, $valid)->assertForbidden();
    $profile->update(['is_active' => true]);
    $this->site->update(['is_active' => false]);
    $this->actingAs($this->editor)->putJson($url, $valid)->assertForbidden();
    $this->actingAs($this->reader)->postJson('/company-hub/pages/'.$this->page->id.'/interactions', ['kind' => 'vote', 'block_id' => 'poll', 'poll_key' => $valid['poll_key'], 'choice' => 0])->assertForbidden();
    $this->site->update(['is_active' => true]);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][1]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    expect($this->participation->read($this->reader, $this->page)['polls'])->toBe([]);
    $this->actingAs($this->editor)->putJson($url, [...$valid, 'revision_id' => $this->revision->id])->assertNotFound();
    $draft['sections'][0]['blocks'][1]['hidden'] = false;
    $draft['audience'] = ['type' => 'sites', 'ids' => [$this->site->id]];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 3);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 4, null, null);
    $outside = Site::factory()->create(['is_active' => true]);
    $this->reader->hrEmployeeProfile->update(['primary_site_id' => $outside->id]);
    $this->actingAs($this->reader)->postJson('/company-hub/pages/'.$this->page->id.'/interactions', ['kind' => 'vote', 'block_id' => 'poll', 'poll_key' => $valid['poll_key'], 'choice' => 0])->assertNotFound();
    $this->page->update(['archived_at' => now()]);
    $this->actingAs($this->editor)->putJson($url, [...$valid, 'revision_id' => $this->revision->id])->assertNotFound();
    expect(DB::table('hub_poll_states')->count())->toBe(0)->and(DB::table('hub_interactions')->where('kind', 'vote')->count())->toBe(0);
});

test('new poll saves trim option labels and reject case insensitive duplicates or invalid exact instants without changing old snapshots', function () {
    $snapshot = $this->revision->fresh()->getAttributes();
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][1]['options'] = [' Walk ', ' Lunch '];
    $draft['sections'][0]['blocks'][1]['closes_at'] = '2026-10-21T17:00:00.123+13:00';
    $saved = app(HubContent::class)->validate($draft, $this->editor, $this->page);
    expect($saved['sections'][0]['blocks'][1])->toMatchArray(['options' => ['Walk', 'Lunch'], 'closes_at' => '2026-10-21T04:00:00.123000Z']);
    $draft['sections'][0]['blocks'][1]['options'] = [' WALK ', 'walk'];
    expect(fn () => app(HubContent::class)->validate($draft, $this->editor, $this->page))->toThrow(ValidationException::class);
    $draft['sections'][0]['blocks'][1]['options'] = ['Walk', 'Lunch'];
    foreach (['2026-02-30T17:00:00+13:00', '2026-10-21T17:00', '2026-13-21', '2026-10-21T24:00:00Z'] as $invalid) {
        $draft['sections'][0]['blocks'][1]['closes_at'] = $invalid;
        expect(fn () => app(HubContent::class)->validate($draft, $this->editor, $this->page))->toThrow(ValidationException::class);
    }
    expect($this->revision->fresh()->getAttributes())->toBe($snapshot)->and(DB::table('hub_poll_states')->count())->toBe(0);
});

test('schema pending poll reads retain the result gate while transitions fail clearly and canonical voting stays compatible', function () {
    ($this->vote)($this->reader)->assertOk();
    $valid = [...($this->context)($this->editor), 'action' => 'close'];
    $schema = Schema::getFacadeRoot();
    $pending = Mockery::mock(get_class($schema), [$schema->getConnection()])->makePartial();
    $pending->shouldReceive('hasTable')->with('hub_poll_states')->andReturn(false);
    Schema::swap($pending);
    try {
        expect(($this->poll)($this->editor))->toMatchArray(['can_manage' => false, 'results_visible' => false, 'counts' => null, 'total' => null, 'version' => 0]);
        $this->actingAs($this->editor)->putJson('/company-hub/pages/'.$this->page->id.'/polls/poll/state', $valid)->assertUnprocessable()->assertJsonPath('message', 'Poll management is not available yet. Reload after the update is complete.');
        expect(($this->poll)($this->reader)['total'])->toBe(1);
        $this->actingAs($this->other)->post('/company-hub/pages/'.$this->page->id.'/interactions', ['kind' => 'vote', 'block_id' => 'poll', 'poll_key' => $valid['poll_key'], 'choice' => '1'])->assertRedirect();
        expect(($this->poll)($this->other)['counts'])->toBe([1, 1]);
        $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls.0.response_count', null)->assertJsonPath('polls.0.responses_in_period', null);
    } finally {
        Schema::swap($schema);
    }
    expect(DB::table('hub_poll_states')->count())->toBe(0);
});

test('Ideas and current poll insights aggregate authorised visible participation without identities private text or pre vote results', function () {
    $ideas = app(HubIdeas::class);
    $idea = $ideas->create($this->reader, ['title' => 'A clearer welcome kit', 'body' => 'Private discussion wording must not appear in aggregate insights.', 'category' => 'Learning']);
    $hidden = $ideas->create($this->reader, ['title' => 'Hidden idea title', 'body' => 'Hidden private wording.', 'category' => 'Learning']);
    $ideas->mutate($this->reader, $idea, ['action' => 'support', 'active' => true]);
    $ideas->mutate($this->other, $idea, ['action' => 'support', 'active' => true]);
    DB::table('hub_idea_supports')->where('hub_idea_id', $idea)->where('user_id', $this->other->id)->update(['created_at' => now()->subDays(14)]);
    DB::table('hub_ideas')->where('id', $hidden)->update(['hidden_at' => now()]);
    ($this->vote)($this->reader)->assertOk();
    $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()
        ->assertJsonPath('ideas.0', ['id' => $idea, 'title' => 'A clearer welcome kit', 'status' => 'open', 'url' => '/company-hub?view=ideas&idea='.$idea, 'support_count' => 2, 'supports_in_period' => 1])
        ->assertJsonPath('polls.0.response_count', null)->assertJsonPath('polls.0.responses_in_period', null)->assertJsonPath('polls.0.results_visible', false)
        ->assertDontSee('Hidden idea title')->assertDontSee('Private discussion wording')->assertDontSee('user_id')->assertDontSee($this->reader->name);
    ($this->state)()->assertOk();
    $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls.0.response_count', 1)->assertJsonPath('polls.0.responses_in_period', 1)
        ->assertJsonMissingPath('polls.0.counts')->assertJsonMissingPath('polls.0.voters');
    DB::table('hub_interactions')->where('kind', 'vote')->update(['created_at' => now()->subDays(14)]);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls.0.response_count', 1)->assertJsonPath('polls.0.responses_in_period', 0);
    $this->getJson('/company-hub/editor/insights?days=28')->assertOk()->assertJsonPath('polls.0.responses_in_period', 1)->assertJsonPath('ideas.0.supports_in_period', 2);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][1]['question'] = 'New question excludes old responses';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls.0.response_count', null);
    $draft['sections'][0]['blocks'][1]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 3);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 4, null, null);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls', []);
    $this->actingAs($this->reader)->getJson('/company-hub/editor/insights?days=7')->assertForbidden();
    Notification::assertNothingSent();
});
