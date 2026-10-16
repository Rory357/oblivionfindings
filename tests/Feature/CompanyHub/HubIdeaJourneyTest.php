<?php

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Canonical ideas team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $this->privateTeam = HrDepartment::create(['name' => 'Confidential other site team', 'is_active' => true]);
    $this->privateTeam->sites()->attach($this->outside->id);
    $this->staff = function (string $name, string $role = 'support_worker', ?Site $site = null): User {
        $person = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $person->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($person, $site ?? $this->site, ['position_title' => 'Public work role']);

        return $person;
    };
    $this->editor = ($this->staff)('Ideas editor', 'hr');
    $this->reader = ($this->staff)('Public idea author');
    $this->owner = ($this->staff)('Named work owner');
    $this->otherOwner = ($this->staff)('Next named work owner');
    $this->hidden = ($this->staff)('Private other site work owner', site: $this->outside);
    $this->community = app(HubCommunity::class);
    $this->payload = ['title' => 'A practical shared improvement', 'body' => 'Make everyday work easier for our colleagues.', 'category' => 'Better ways of working', 'department_id' => $this->team->id, 'actor_id' => $this->reader->id];
    $this->create = fn () => $this->community->createIdea($this->reader, $this->payload);
    $this->progress = fn (int $version = 1, array $extra = []) => ['action' => 'status', 'status' => 'in_progress', 'status_note' => 'We are trying the next practical step.',
        'owner_id' => $this->owner->id, 'version' => $version, 'actor_id' => $this->editor->id, ...$extra];
});

test('idea submission routes to a canonical team and exposes only authorised current work owner choices', function () {
    $this->actingAs($this->reader)->post('/company-hub/ideas', $this->payload)->assertRedirect()->assertSessionHasNoErrors();
    $idea = DB::table('hub_ideas')->sole();
    expect((int) $idea->department_id)->toBe($this->team->id)->and((int) $idea->user_id)->toBe($this->reader->id)->and($idea->owner_id)->toBeNull();
    $this->get('/company-hub?view=ideas')->assertOk()->assertInertia(fn (Assert $page) => $page->where('ideaProgressReady', true)->where('ideaActorId', $this->reader->id)
        ->has('ideaTeams', 1)->where('ideaTeams.0.id', $this->team->id)->has('ideaOwners', 0)
        ->where('ideas.0.team.name', $this->team->name)->where('ideas.0.owner', null)->where('ideas.0.can_update', false)->has('ideas.0.updates', 0));
    $options = $this->community->ideaOptions($this->editor);
    expect($options['ready'])->toBeTrue()->and($options['actor_id'])->toBe($this->editor->id)->and(collect($options['owners'])->pluck('id')->all())->toContain($this->owner->id)->not->toContain($this->hidden->id);
    expect(array_keys(collect($options['owners'])->firstWhere('id', $this->owner->id)))->toBe(['id', 'profile_id', 'name', 'position']);
    $this->assertDatabaseCount('hub_idea_updates', 0);
    Notification::assertNothingSent();
});

test('idea progress keeps every dated append and frozen named owner while updating the current status', function () {
    $id = ($this->create)();
    $url = '/company-hub/ideas/'.$id;
    $this->actingAs($this->editor)->post($url, ($this->progress)(1, ['status' => 'reviewing', 'status_note' => 'We are considering the first proposal.']))->assertRedirect()->assertSessionHasNoErrors();
    $original = (array) DB::table('hub_idea_updates')->where('hub_idea_id', $id)->sole();
    foreach (['planned', 'in_progress', 'in_progress', 'in_progress', 'delivered'] as $index => $stage) {
        $this->post($url, ($this->progress)($index + 2, ['status' => $stage, 'owner_id' => $this->otherOwner->id, 'status_note' => 'Staff progress update '.($index + 2)]))->assertRedirect()->assertSessionHasNoErrors();
    }
    expect((array) DB::table('hub_idea_updates')->where('id', $original['id'])->first())->toBe($original);
    $public = collect($this->community->ideas($this->reader))->sole();
    expect($public['status'])->toBe('delivered')->and($public['version'])->toBe(7)->and($public['status_note'])->toBe('Staff progress update 6')
        ->and($public['owner']['id'])->toBe($this->otherOwner->id)->and($public['updates'])->toHaveCount(6)
        ->and($public['updates'][0]['owner']['id'])->toBe($this->owner->id)->and($public['updates'][0]['author']['id'])->toBe($this->editor->id)
        ->and($public['updates'][0]['status'])->toBe('reviewing')->and($public['updates'][1]['status'])->toBe('planned')->and($public['updates'][2]['status'])->toBe('in_progress');
    foreach ($public['updates'] as $update) {
        expect($update['created_at'])->toMatch('/T.*Z$/')->and($update['legacy'])->toBeFalse()->and($update['team']['id'])->toBe($this->team->id);
    }
    $this->actingAs($this->reader)->get($public['owner']['url'])->assertOk();
    $this->assertDatabaseCount('hub_idea_updates', 6);
    $audit = DB::table('audit_logs')->where('action', 'company_hub.idea_status')->get()->pluck('meta')->implode(' ');
    expect($audit)->not->toContain('Staff progress update')->not->toContain('first proposal')->toContain('update_id');
    Notification::assertNothingSent();
});

test('idea legacy notes are preserved once with known date and no invented historical author or owner', function () {
    $id = ($this->create)();
    $oldDate = now()->subDays(4)->startOfSecond();
    DB::table('hub_ideas')->where('id', $id)->update(['status' => 'reviewing', 'status_note' => 'The surviving earlier public note.', 'updated_at' => $oldDate]);
    $legacy = collect($this->community->ideas($this->reader))->sole()['updates'][0];
    expect($legacy)->toMatchArray(['id' => 0, 'legacy' => true, 'author' => null, 'owner' => null, 'note' => 'The surviving earlier public note.'])->and($legacy['created_at'])->toBe($oldDate->toISOString());
    $this->actingAs($this->editor)->post('/company-hub/ideas/'.$id, ($this->progress)())->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/company-hub/ideas/'.$id, ($this->progress)(2, ['status_note' => 'A second newly dated staff update.']))->assertRedirect()->assertSessionHasNoErrors();
    $history = collect($this->community->ideas($this->reader))->sole()['updates'];
    expect($history)->toHaveCount(3)->and($history[0]['id'])->toBeGreaterThan(0)->and($history[0]['created_at'])->toBe($oldDate->toISOString())
        ->and($history[0]['legacy'])->toBeTrue()->and($history[0]['owner'])->toBeNull()->and($history[0]['author'])->toBeNull();
    expect(DB::table('hub_idea_updates')->where('hub_idea_id', $id)->where('legacy', true)->count())->toBe(1);
});

test('idea team and owner direct selections fail closed before persistence for unavailable canonical records', function () {
    $inactive = HrDepartment::create(['name' => 'Retired routing team', 'is_active' => false]);
    $this->actingAs($this->reader);
    foreach ([$this->privateTeam->id, $inactive->id, 999999] as $team) {
        $this->postJson('/company-hub/ideas', [...$this->payload, 'department_id' => $team])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }
    $this->assertDatabaseCount('hub_ideas', 0);
    $id = ($this->create)();
    $this->actingAs($this->editor);
    foreach ([$this->hidden->id, 999999] as $owner) {
        $this->postJson('/company-hub/ideas/'.$id, ($this->progress)(1, ['owner_id' => $owner]))->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    }
    $this->owner->hrEmployeeProfile()->update(['is_active' => false]);
    $this->postJson('/company-hub/ideas/'.$id, ($this->progress)())->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->owner->hrEmployeeProfile()->update(['is_active' => true]);
    $this->owner->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
    $this->postJson('/company-hub/ideas/'.$id, ($this->progress)())->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->owner->permissionOverrides()->detach();
    $this->editor->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->outside->id]]);
    // An owner visible via another approved site still must be able to read the routed team.
    $this->postJson('/company-hub/ideas/'.$id, ($this->progress)(1, ['owner_id' => $this->hidden->id]))->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->assertDatabaseCount('hub_idea_updates', 0);
    expect((int) DB::table('hub_ideas')->where('id', $id)->value('version'))->toBe(1);
    Notification::assertNothingSent();
});

test('idea progress actor version permission and provenance denials cannot append or overwrite history', function () {
    $id = ($this->create)();
    $url = '/company-hub/ideas/'.$id;
    $this->actingAs($this->reader)->postJson($url, ($this->progress)(1, ['actor_id' => $this->reader->id]))->assertForbidden();
    $this->actingAs($this->editor)->postJson($url, ($this->progress)(1, ['actor_id' => $this->owner->id]))->assertConflict();
    foreach (['user_id', 'author_id', 'posted_by_user_id', 'hub_idea_id', 'parent_id', 'update_id', 'created_at'] as $field) {
        $this->postJson($url, [...($this->progress)(), $field => $field === 'created_at' ? '2000-01-01' : $this->hidden->id])->assertUnprocessable();
    }
    $this->postJson($url, ($this->progress)(1, ['status' => 'unsupported']))->assertUnprocessable();
    $this->assertDatabaseCount('hub_idea_updates', 0);
    $this->post($url, ($this->progress)())->assertRedirect()->assertSessionHasNoErrors();
    $before = (array) DB::table('hub_ideas')->where('id', $id)->first();
    $this->postJson($url, ($this->progress)(1, ['status_note' => 'A stale replacement must never be saved.']))->assertConflict();
    $this->actingAs($this->owner)->postJson($url, ($this->progress)(2, ['actor_id' => $this->owner->id]))->assertForbidden();
    $this->actingAs($this->reader)->postJson('/company-hub/ideas', [...$this->payload, 'actor_id' => $this->editor->id])->assertConflict();
    $this->assertDatabaseCount('hub_ideas', 1);
    $this->assertDatabaseCount('hub_idea_updates', 1);
    expect((array) DB::table('hub_ideas')->where('id', $id)->first())->toBe($before);
});

test('idea JSON progress acknowledges canonical success and preserves conflict privacy and Inertia boundaries', function () {
    $id = ($this->create)();
    $url = '/company-hub/ideas/'.$id;
    $this->actingAs($this->editor)->postJson($url, ($this->progress)())->assertOk()->assertExactJson(['saved' => true, 'idea_id' => $id]);
    $before = (array) DB::table('hub_ideas')->where('id', $id)->first();
    expect((int) $before['version'])->toBe(2)->and($before['status'])->toBe('in_progress');
    $this->postJson($url, ($this->progress)(2, ['actor_id' => $this->owner->id]))->assertConflict()->assertJsonMissingPath('saved');
    $this->postJson($url, ($this->progress)(1, ['status_note' => 'The stale draft must remain unsaved.']))->assertConflict()->assertJsonMissingPath('saved');
    $this->postJson($url, ($this->progress)(2, ['owner_id' => $this->hidden->id]))->assertUnprocessable()->assertJsonValidationErrors('owner_id')->assertJsonMissingPath('saved');
    expect((array) DB::table('hub_ideas')->where('id', $id)->first())->toBe($before);
    $this->assertDatabaseCount('hub_idea_updates', 1);
    $this->withHeader('X-Inertia', 'true')->postJson($url, ($this->progress)(2, ['status' => 'delivered']))->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('hub_idea_updates', 2);
    Notification::assertNothingSent();
});

test('idea audience remains public while current owner team and update identities are redacted and withdrawn access is rechecked', function () {
    $id = ($this->create)();
    $this->community->updateIdea($this->editor, $id, ($this->progress)());
    $outside = collect($this->community->ideas($this->hidden))->sole();
    expect($outside['body'])->toBe($this->payload['body'])->and($outside['team'])->toBeNull()->and($outside['owner'])->toBeNull()
        ->and($outside['updates'][0]['note'])->toBe('We are trying the next practical step.')->and($outside['updates'][0]['author'])->toBeNull()
        ->and($outside['updates'][0]['owner'])->toBeNull()->and($outside['updates'][0]['team'])->toBeNull();
    expect($this->community->ideas($this->hidden, $this->team->name))->toBe([])->and($this->community->ideas($this->hidden, $this->owner->name))->toBe([]);
    expect($this->community->ideas($this->reader, $this->team->name))->toHaveCount(1)->and($this->community->ideas($this->reader, $this->owner->name))->toHaveCount(1);
    $ownerUrl = collect($this->community->ideas($this->reader))->sole()['owner']['url'];
    $this->owner->hrEmployeeProfile()->update(['is_active' => false]);
    $withdrawn = collect($this->community->ideas($this->reader))->sole();
    expect($withdrawn['owner'])->toBeNull()->and($withdrawn['updates'][0]['owner'])->toBeNull()->and($withdrawn['updates'])->toHaveCount(1);
    $this->actingAs($this->reader)->get($ownerUrl)->assertNotFound();
    $this->actingAs($this->editor)->postJson('/company-hub/ideas/'.$id, ($this->progress)(2))->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->team->update(['is_active' => false]);
    expect(collect($this->community->ideas($this->reader))->sole()['team'])->toBeNull();
    $this->postJson('/company-hub/ideas/'.$id, ($this->progress)(2, ['owner_id' => $this->otherOwner->id]))->assertUnprocessable()->assertJsonValidationErrors('department_id');
    $this->post('/company-hub/ideas/'.$id, ($this->progress)(2, ['owner_id' => $this->otherOwner->id, 'department_id' => null]))->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('hub_idea_updates', 2);
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->reader)->get('/company-hub?view=ideas')->assertForbidden();
});

test('idea support discussion and author removal keep their shared behavior and hidden parents expose no progress', function () {
    $id = ($this->create)();
    $this->community->updateIdea($this->editor, $id, ($this->progress)());
    $url = '/company-hub/ideas/'.$id;
    $this->actingAs($this->reader);
    $support = ['action' => 'support', 'active' => true, 'actor_id' => $this->reader->id, 'version' => 2];
    $this->post($url, $support)->assertRedirect();
    $this->post($url, $support)->assertRedirect();
    $this->assertDatabaseCount('hub_idea_supports', 1);
    $this->post($url, ['action' => 'comment', 'body' => 'A public practical discussion.', 'actor_id' => $this->reader->id, 'version' => 2])->assertRedirect();
    $comment = DB::table('hub_idea_comments')->sole();
    $other = ($this->create)();
    $this->postJson('/company-hub/ideas/'.$other, ['action' => 'remove_comment', 'comment_id' => $comment->id, 'actor_id' => $this->reader->id])->assertNotFound();
    $this->post($url, ['action' => 'remove_comment', 'comment_id' => $comment->id, 'actor_id' => $this->reader->id, 'version' => 2])->assertRedirect();
    expect(collect($this->community->ideas($this->reader))->firstWhere('id', $id)['comments'])->toBe([]);
    $this->postJson($url, [...$support, 'version' => 1])->assertConflict();
    $this->post($url, ['action' => 'remove', 'version' => 2, 'actor_id' => $this->reader->id])->assertRedirect();
    $this->actingAs($this->editor)->postJson($url, ($this->progress)(3))->assertNotFound();
    expect(collect($this->community->ideas($this->editor))->pluck('id')->all())->not->toContain($id);
    $this->assertDatabaseCount('hub_idea_updates', 1);
    Notification::assertNothingSent();
});

test('idea migration pending reads and legacy actions avoid progress storage and reject new metadata writes safely', function () {
    $pending = Mockery::mock(HubIdeas::class, [app(HubAccess::class), app(HrCurrentStaffService::class)])->makePartial();
    $pending->shouldReceive('ready')->andReturn(false);
    $id = $pending->create($this->reader, ['title' => 'Legacy public idea', 'body' => 'No new schema is assumed.', 'category' => 'Our people']);
    $pending->mutate($this->editor, $id, ['action' => 'status', 'status' => 'reviewing', 'status_note' => 'Legacy safe update.', 'version' => 1]);
    expect($pending->options($this->editor))->toBe(['ready' => false, 'actor_id' => $this->editor->id, 'teams' => [], 'owners' => []]);
    $projection = collect($pending->read($this->reader))->sole();
    expect($projection['owner'])->toBeNull()->and($projection['team'])->toBeNull()->and($projection['can_update'])->toBeFalse()->and($projection['updates'][0]['legacy'])->toBeTrue();
    expect(fn () => $pending->create($this->reader, $this->payload))->toThrow(ValidationException::class);
    expect(fn () => $pending->mutate($this->editor, $id, ($this->progress)(2)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('hub_idea_updates', 0);
    expect((int) DB::table('hub_ideas')->where('id', $id)->value('version'))->toBe(2);
});
