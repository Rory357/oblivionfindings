<?php

use App\Domain\CompanyHub\Services\HubDiscovery;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $staff = function (string $name, string $role = 'support_worker'): User {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($user, $this->site);

        return $user;
    };
    $this->reader = $staff('Interest owner A');
    $this->other = $staff('Interest owner B');
    $this->editor = $staff('Interest space author', 'hr');
    $this->team = HrDepartment::create(['name' => 'Current visible work team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $this->otherTeam = HrDepartment::create(['name' => 'Another visible work team', 'is_active' => true]);
    $this->otherTeam->sites()->attach($this->site->id);
    $this->discovery = app(HubDiscovery::class);
    $this->payload = ['actor_id' => $this->reader->id, 'version' => 1, 'topics' => ['Learning'], 'teams' => [$this->team->id]];
    $this->snapshot = fn () => DB::table('hub_preferences')->orderBy('user_id')->get()->toArray();
});

test('interest actor context and acknowledgements preserve exact selections and unrelated personal preferences', function () {
    DB::table('hub_preferences')->insert(['user_id' => $this->reader->id, 'interests_version' => 4,
        'interests' => '{"topics":["Company news"],"teams":[]}', 'notifications' => '{"news":false}',
        'shortcuts' => '[{"id":"retained","title":"My day","url":"/my-day"}]', 'followed_pages' => '[99]']);
    $before = DB::table('hub_preferences')->where('user_id', $this->reader->id)->first();
    $this->actingAs($this->reader)->getJson('/company-hub/interests')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('version', 4)->assertJsonPath('topics', ['Company news']);
    $topics = ['Company news', 'Learning'];
    $teams = [$this->otherTeam->id, $this->team->id];
    $this->putJson('/company-hub/interests', [...$this->payload, 'version' => 4, 'topics' => $topics, 'teams' => $teams])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('version', 5)
        ->assertJsonPath('topics', $topics)->assertJsonPath('teams', $teams);
    $after = DB::table('hub_preferences')->where('user_id', $this->reader->id)->first();
    expect([$after->notifications, $after->shortcuts, $after->followed_pages])->toBe([$before->notifications, $before->shortcuts, $before->followed_pages])
        ->and(app(HubFeed::class)->forUser($this->reader)['interests'])->toMatchArray(['actor_id' => $this->reader->id, 'version' => 5, 'topics' => $topics, 'teams' => $teams])
        ->and($this->discovery->preferences($this->other))->toMatchArray(['actor_id' => $this->other->id, 'version' => 1, 'topics' => [], 'teams' => []]);
    Notification::assertNothingSent();
});

test('interest mutations deny an old account tab even when the new account has the same preference version', function () {
    foreach ([$this->reader, $this->other] as $user) {
        $this->discovery->saveInterests($user, ['actor_id' => $user->id, 'version' => 1, 'topics' => ['Company news'], 'teams' => []]);
    }
    $oldTab = [...$this->payload, 'version' => 2];
    $before = ($this->snapshot)();
    $this->actingAs($this->other)->putJson('/company-hub/interests', $oldTab)->assertConflict()
        ->assertJsonPath('message', 'Your signed-in account changed. Reload your saved interests before continuing.');
    expect(fn () => $this->discovery->saveInterests($this->other, $oldTab))->toThrow(HttpException::class);
    expect(($this->snapshot)())->toEqual($before);
    $this->getJson('/company-hub/interests')->assertJsonPath('actor_id', $this->other->id)->assertJsonPath('version', 2)->assertJsonPath('topics', ['Company news']);
});

test('interest mutations deny missing provenance stale versions and invalid canonical choices without changing preferences', function () {
    $this->actingAs($this->reader)->putJson('/company-hub/interests', $this->payload)->assertOk();
    $before = ($this->snapshot)();
    $this->putJson('/company-hub/interests', $this->payload)->assertConflict();
    $current = [...$this->payload, 'version' => 2];
    $this->putJson('/company-hub/interests', array_diff_key($current, ['actor_id' => true]))->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    $this->putJson('/company-hub/interests', [...$current, 'user_id' => $this->other->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->putJson('/company-hub/interests', [...$current, 'actor_id' => 'unknown'])->assertUnprocessable();
    $this->putJson('/company-hub/interests', [...$current, 'topics' => ['Fictional new topic']])->assertUnprocessable();
    expect(($this->snapshot)())->toEqual($before);
});

test('interest actor saves recheck withdrawn team visibility from a previously opened context', function () {
    $this->actingAs($this->reader)->putJson('/company-hub/interests', $this->payload)->assertOk();
    $opened = $this->discovery->preferences($this->reader);
    expect(array_column($opened['available_teams'], 'id'))->toContain($this->team->id);
    $before = ($this->snapshot)();
    $current = [...$this->payload, 'version' => 2];
    $this->team->sites()->sync([$this->outside->id]);
    $this->putJson('/company-hub/interests', $current)->assertUnprocessable();
    $this->getJson('/company-hub/interests')->assertJsonPath('teams', []);
    $this->team->sites()->sync([$this->site->id]);
    $this->team->update(['is_active' => false]);
    $this->putJson('/company-hub/interests', $current)->assertUnprocessable();
    $this->team->delete();
    $this->putJson('/company-hub/interests', $current)->assertUnprocessable();
    expect(($this->snapshot)())->toEqual($before);
    $this->putJson('/company-hub/interests', [...$current, 'teams' => []])->assertOk()
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('version', 3)->assertJsonPath('teams', []);
});

test('interest actor reads and writes recheck current staff sites and permissions without reusing a cached viewer', function () {
    $this->actingAs($this->reader)->putJson('/company-hub/interests', $this->payload)->assertOk();
    $this->discovery->preferences($this->reader);
    $before = ($this->snapshot)();
    $current = [...$this->payload, 'version' => 2];
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->getJson('/company-hub/interests')->assertForbidden();
    $this->putJson('/company-hub/interests', $current)->assertForbidden();
    expect(fn () => $this->discovery->saveInterests($this->reader, $current))->toThrow(HttpException::class);
    $this->reader->hrEmployeeProfile()->update(['is_active' => true]);
    $this->site->update(['is_active' => false]);
    $this->getJson('/company-hub/interests')->assertForbidden();
    $this->putJson('/company-hub/interests', $current)->assertForbidden();
    $this->site->update(['is_active' => true]);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    $this->getJson('/company-hub/interests')->assertForbidden();
    $this->putJson('/company-hub/interests', $current)->assertForbidden();
    expect(($this->snapshot)())->toEqual($before);
});

test('interest actor saves roll back persisted choices if the transaction cannot complete', function () {
    $this->discovery->saveInterests($this->reader, $this->payload);
    $before = ($this->snapshot)();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'update `hub_preferences`')) {
            throw new RuntimeException('Synthetic interest persistence failure.');
        }
    });
    expect(fn () => $this->discovery->saveInterests($this->reader, [...$this->payload, 'version' => 2, 'topics' => ['Company news'], 'teams' => []]))
        ->toThrow(RuntimeException::class, 'Synthetic interest persistence failure.');
    expect(($this->snapshot)())->toEqual($before);
});

test('interest actor context remains intact through the canonical team-space follow workflow', function () {
    $this->discovery->saveInterests($this->reader, [...$this->payload, 'teams' => [$this->otherTeam->id]]);
    $publisher = app(HubPublishing::class);
    $page = $publisher->create($this->editor, 'department', 'Canonical team following', 'team');
    $content = [...$page->draft, 'team_ids' => [$this->team->id], 'audience' => ['type' => 'all', 'ids' => []],
        'sections' => [['id' => 'main', 'columns' => 1, 'blocks' => [['id' => 'intro', 'type' => 'text', 'title' => 'Our team', 'body' => 'A complete published team space for canonical interest following.']]]]];
    $page = $publisher->save($this->editor, $page, $content, 1);
    $revision = $publisher->publish($this->editor, $page, 2, null, null);
    $payload = ['actor_id' => $this->reader->id, 'revision_id' => $revision->id, 'version' => 2, 'following' => true];
    $this->actingAs($this->reader)->putJson('/company-hub/pages/'.$page->id.'/follow-teams', $payload)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('actor_id', $this->reader->id)
        ->assertJsonPath('interests.actor_id', $this->reader->id)->assertJsonPath('interests.version', 3)
        ->assertJsonPath('interests.topics', ['Learning'])->assertJsonPath('interests.teams', [$this->otherTeam->id, $this->team->id]);
    $before = ($this->snapshot)();
    $this->putJson('/company-hub/pages/'.$page->id.'/follow-teams', [...$payload, 'version' => 3, 'actor_id' => $this->other->id])->assertConflict();
    expect(($this->snapshot)())->toEqual($before);
    Notification::assertNothingSent();
});
