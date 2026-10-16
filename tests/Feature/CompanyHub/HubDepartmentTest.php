<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubDiscovery;
use App\Domain\CompanyHub\Services\HubPublishing;
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
    $this->team = HrDepartment::create(['name' => 'Learning team', 'is_active' => true]);
    $this->otherTeam = HrDepartment::create(['name' => 'Another team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id, 'work_email' => 'learning@company.test', 'bio' => 'Private HR detail']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->space = $this->publisher->create($this->editor, 'department', 'Learning together', 'team');
    $this->space = $this->publisher->save($this->editor, $this->space, [...$this->space->draft, 'team_ids' => [$this->team->id], 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]]], 1);
    $this->release = $this->publisher->publish($this->editor, $this->space, 2, null, null);
    $this->url = '/company-hub/read/'.$this->space->slug;
    $this->followUrl = '/company-hub/pages/'.$this->space->id.'/follow-teams';
});

test('department tabs use authorised published related pages and current canonical team colleagues', function () {
    $elsewhere = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $outside = User::factory()->create(['approved_at' => now()]);
    ensureCanonicalHrStaffProfile($outside, $elsewhere, ['department_id' => $this->team->id, 'work_email' => 'outside@company.test']);
    $other = User::factory()->create(['approved_at' => now()]);
    ensureCanonicalHrStaffProfile($other, $this->site, ['department_id' => $this->otherTeam->id]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$elsewhere->id], 'work_email' => 'learning@company.test']);
    $this->editor = $this->editor->fresh();
    foreach (['visible', 'draft', 'private', 'expired', 'unrelated'] as $status) {
        foreach (['news', 'knowledge'] as $kind) {
            $item = $this->publisher->create($this->editor, $kind, $status.' '.$kind);
            $item = $this->publisher->save($this->editor, $item, [...$item->draft, 'team_ids' => [$status === 'unrelated' ? $this->otherTeam->id : $this->team->id], 'audience' => $status === 'private' ? ['type' => 'sites', 'ids' => [$elsewhere->id]] : ['type' => 'all', 'ids' => []]], 1);
            if ($status !== 'draft') {
                $this->publisher->publish($this->editor, $item, 2, null, $status === 'expired' ? now()->addHour()->toIso8601String() : null);
            }
        }
    }
    $this->publisher->save($this->editor, $this->space, [...$this->space->draft, 'team_ids' => [$this->otherTeam->id]], 2);
    $this->travel(2)->hours();
    $this->actingAs($this->reader)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'teams')->where('department.version', 2)->where('department.teams.0.id', $this->team->id)
        ->has('department.updates', 1)->where('department.updates.0.title', 'visible news')
        ->has('department.resources', 1)->where('department.resources.0.title', 'visible knowledge')
        ->has('department.people', 2)->where('department.people', fn ($people) => collect($people)->pluck('user_id')->sort()->values()->all() === collect([$this->editor->id, $this->reader->id])->sort()->values()->all())
        ->where('department.owner.work_email', 'learning@company.test')->missing('department.owner.bio')->missing('department.owner.user_id')->missing('department.people.0.date_of_birth')
        ->where('department.can_create_update', false));
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->get($this->url)->assertInertia(fn (Assert $page) => $page->where('department.owner', null)->has('department.people', 1));
});

test('team update creation inherits only the current published teams and audience into an owned private draft', function () {
    $this->publisher->save($this->editor, $this->space, [...$this->space->draft, 'team_ids' => [$this->otherTeam->id], 'audience' => ['type' => 'all', 'ids' => []]], 2);
    $payload = ['title' => 'Our learning update', 'kind' => 'news', 'template' => 'leadership', 'source_page_id' => $this->space->id, 'source_revision_id' => $this->release->id];
    $this->actingAs($this->reader)->post('/company-hub/pages', $payload)->assertForbidden();
    $this->actingAs($this->editor)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('department.can_create_update', true));
    $response = $this->post('/company-hub/pages', $payload);
    $created = HubPage::where('kind', 'news')->sole();
    $response->assertRedirect('/company-hub/manage/'.$created->id.'/edit');
    expect($created->owner_id)->toBe($this->editor->id)->and($created->draft['team_ids'])->toBe([$this->team->id])
        ->and($created->draft['audience']['type'])->toBe('sites')->and($created->draft['audience']['ids'])->toBe([$this->site->id])
        ->and($created->draft['sections'][0]['id'])->toBe('priorities')->and($created->revisions()->count())->toBe(0);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$created->slug)->assertNotFound();
    $this->actingAs($this->editor)->postJson('/company-hub/pages', [...$payload, 'kind' => 'page'])->assertUnprocessable();
    $this->postJson('/company-hub/pages', [...$payload, 'source_revision_id' => $this->release->id + 999])->assertConflict();
    $this->postJson('/company-hub/pages', [...$payload, 'source_page_id' => $created->id])->assertNotFound();
    $this->assertDatabaseCount('hub_pages', 2);
    Notification::assertNothingSent();
});

test('team following preserves unrelated interests is private and rejects stale account page or preference context', function () {
    $discovery = app(HubDiscovery::class);
    $discovery->saveInterests($this->reader, ['actor_id' => $this->reader->id, 'version' => 1, 'topics' => ['Learning'], 'teams' => [$this->otherTeam->id]]);
    $payload = ['actor_id' => $this->reader->id, 'revision_id' => $this->release->id, 'version' => 2, 'following' => true];
    $this->actingAs($this->reader)->putJson($this->followUrl, $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('interests.topics', ['Learning'])->assertJsonPath('interests.teams', [$this->otherTeam->id, $this->team->id]);
    expect($discovery->preferences($this->reader)['teams'])->toContain($this->team->id)
        ->and($discovery->preferences($this->editor)['teams'])->toBe([]);
    $this->putJson($this->followUrl, [...$payload, 'following' => false])->assertConflict();
    $this->putJson($this->followUrl, [...$payload, 'version' => 3, 'actor_id' => $this->editor->id])->assertConflict();
    $this->putJson($this->followUrl, [...$payload, 'version' => 3, 'user_id' => $this->editor->id])->assertUnprocessable();
    $this->putJson($this->followUrl, [...$payload, 'version' => 3, 'following' => false])->assertOk()->assertJsonPath('interests.teams', [$this->otherTeam->id]);
    $this->space = $this->publisher->save($this->editor, $this->space, [...$this->space->draft, 'team_ids' => [$this->otherTeam->id]], 2);
    $this->publisher->publish($this->editor, $this->space, 3, null, null);
    $this->putJson($this->followUrl, [...$payload, 'version' => 4])->assertConflict();
});

test('department lifecycle and current site boundaries also protect direct team follow and creation', function () {
    $payload = ['actor_id' => $this->reader->id, 'revision_id' => $this->release->id, 'version' => 1, 'following' => true];
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id]);
    $this->actingAs($this->reader->fresh())->get($this->url)->assertNotFound();
    $this->putJson($this->followUrl, $payload)->assertNotFound();
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'end_date' => today()->subDay()]);
    $this->actingAs($this->reader->fresh())->putJson($this->followUrl, $payload)->assertNotFound();
    $this->space->update(['archived_at' => now()]);
    $this->actingAs($this->editor)->postJson('/company-hub/pages', ['title' => 'Unavailable update', 'kind' => 'news', 'template' => 'story', 'source_page_id' => $this->space->id, 'source_revision_id' => $this->release->id])->assertNotFound();
    $this->assertDatabaseCount('hub_pages', 1);
});
