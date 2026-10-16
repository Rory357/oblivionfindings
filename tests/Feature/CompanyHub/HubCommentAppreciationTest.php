<?php

use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->moderator = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->moderator->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->moderator);
    $this->author = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->author->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->author, $this->site);
    $this->other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->other->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->other, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->moderator, 'news', 'Canonical appreciated discussion');
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'A complete company story for appreciation contracts.';
    $this->page->update(['draft' => $draft]);
    $this->page = $this->publisher->save($this->moderator, $this->page, [...$this->page->draft, 'reactions_enabled' => false], 1);
    $this->revision = $this->publisher->publish($this->moderator, $this->page, 2, null, null);
    $this->participation = app(HubParticipation::class);
    $this->comment = function (string $body, ?int $parent = null): int {
        $this->participation->write($this->author, $this->page, ['kind' => 'comment', 'body' => $body, 'parent_id' => $parent]);

        return (int) DB::table('hub_interactions')->where('hub_page_id', $this->page->id)->where('kind', 'comment')->max('id');
    };
    $this->version = fn (int $id): int => (int) DB::table('hub_interactions')->where('id', $id)->value('moderation_version');
    $this->base = '/company-hub/pages/'.$this->page->id;
    $this->appreciate = fn (int $id, bool $active = true) => $this->putJson($this->base.'/comments/'.$id.'/appreciation', [
        'actor_id' => auth()->id(), 'revision_id' => $this->revision->id, 'version' => ($this->version)($id), 'active' => $active,
    ]);
    $this->moderate = fn (int $id, bool $hidden) => $this->putJson($this->base.'/interactions/'.$id.'/moderation', [
        'actor_id' => $this->moderator->id, 'revision_id' => $this->revision->id, 'version' => ($this->version)($id), 'hidden' => $hidden,
    ]);
});

test('comment appreciation persists idempotent desired state on the exact comment without page reactions or private liker identities', function () {
    $parent = ($this->comment)('A helpful current comment');
    $child = ($this->comment)('A thoughtful reply', $parent);
    $this->actingAs($this->author);
    ($this->appreciate)($parent)->assertOk()->assertExactJson(['saved' => true, 'comment_id' => $parent, 'version' => 1, 'appreciation' => ['count' => 1, 'mine' => true]])
        ->assertHeader('Cache-Control', 'no-store, private');
    ($this->appreciate)($parent)->assertOk()->assertJsonPath('appreciation.count', 1);
    $this->actingAs($this->other);
    ($this->appreciate)($parent)->assertOk()->assertJsonPath('appreciation.count', 2);
    ($this->appreciate)($child)->assertOk();
    $read = $this->participation->read($this->moderator, $this->page);
    expect($read['reactions'])->toBe([])->and($read['comments'][0]['appreciation'])->toBe(['count' => 2, 'mine' => false])
        ->and($read['comments'][0]['can_appreciate'])->toBeTrue()->and($read['comments'][0]['version'])->toBe(1);
    expect(array_keys($read['comments'][0]['appreciation']))->toBe(['count', 'mine']);
    $this->actingAs($this->author);
    ($this->appreciate)($parent, false)->assertOk()->assertJsonPath('appreciation', ['count' => 1, 'mine' => false]);
    ($this->appreciate)($parent, false)->assertOk()->assertJsonPath('appreciation.count', 1);
    $this->assertDatabaseCount('hub_comment_appreciations', 2);
    expect(DB::table('hub_interactions')->where('kind', 'reaction')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('appreciation cannot access hidden parents or removed text and restoration preserves independent provenance', function () {
    $parent = ($this->comment)('Moderated parent');
    $child = ($this->comment)('Independently hidden child', $parent);
    $this->actingAs($this->other);
    ($this->appreciate)($parent)->assertOk();
    ($this->appreciate)($child)->assertOk();
    $this->actingAs($this->moderator);
    ($this->moderate)($child, true)->assertOk();
    ($this->moderate)($parent, true)->assertOk();
    foreach ([$parent, $child] as $id) {
        ($this->appreciate)($id)->assertNotFound();
    }
    expect($this->participation->read($this->author, $this->page)['comments'])->toBe([]);
    $this->getJson($this->base.'/moderation?'.http_build_query(['actor_id' => $this->moderator->id, 'revision_id' => $this->revision->id]))
        ->assertOk()->assertJsonMissingPath('hidden_comments.0.appreciation')->assertJsonMissingPath('hidden_comments.0.likers');
    $this->assertDatabaseCount('hub_comment_appreciations', 2);
    ($this->moderate)($parent, false)->assertOk();
    $read = $this->participation->read($this->other, $this->page);
    expect(array_column($read['comments'], 'id'))->toBe([$parent])->and($read['comments'][0]['appreciation'])->toBe(['count' => 1, 'mine' => true]);
    $this->actingAs($this->other)->putJson($this->base.'/comments/'.$parent.'/appreciation', [
        'actor_id' => $this->other->id, 'revision_id' => $this->revision->id, 'version' => 1, 'active' => false,
    ])->assertConflict();
    ($this->appreciate)($child)->assertNotFound();
    $this->participation->remove($this->author, $this->page, $parent);
    $this->assertDatabaseCount('hub_comment_appreciations', 0);
    $this->assertDatabaseHas('hub_interactions', ['id' => $child, 'moderator_hidden_by' => $this->moderator->id]);
    $this->actingAs($this->moderator);
    ($this->moderate)($parent, false)->assertUnprocessable();
    ($this->appreciate)($parent)->assertNotFound();
    $this->participation->remove($this->author, $this->page, $child);
    $this->getJson($this->base.'/moderation?'.http_build_query(['actor_id' => $this->moderator->id, 'revision_id' => $this->revision->id]))
        ->assertOk()->assertJsonPath('hidden_comments.0.hidden_reason', 'author_removed')->assertJsonPath('hidden_comments.0.body', null);
});

test('comment appreciation binds actor revision version page and enabled current audience and denies spoofed source identities', function () {
    $id = ($this->comment)('Canonical private target');
    $this->actingAs($this->author);
    $url = $this->base.'/comments/'.$id.'/appreciation';
    $data = ['actor_id' => $this->author->id, 'revision_id' => $this->revision->id, 'version' => 1, 'active' => true];
    foreach (['actor_id' => $this->other->id, 'revision_id' => $this->revision->id + 100, 'version' => 2] as $key => $value) {
        $this->putJson($url, [...$data, $key => $value])->assertConflict();
    }
    foreach (['user_id', 'author_id', 'hub_page_id', 'page_id', 'parent_id', 'body', 'reaction'] as $field) {
        $this->putJson($url, [...$data, $field => in_array($field, ['body', 'reaction']) ? 'heart' : $this->moderator->id])->assertUnprocessable();
    }
    $another = $this->publisher->create($this->moderator, 'news', 'Another appreciation boundary');
    $draft = $another->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'A complete company story for cross-page denial.';
    $another->update(['draft' => $draft]);
    $release = $this->publisher->publish($this->moderator, $another, 1, null, null);
    $this->putJson('/company-hub/pages/'.$another->id.'/comments/'.$id.'/appreciation', [...$data, 'revision_id' => $release->id])->assertNotFound();
    $reaction = DB::table('hub_interactions')->insertGetId(['hub_page_id' => $this->page->id, 'kind' => 'reaction', 'user_id' => $this->author->id, 'body' => 'heart', 'created_at' => now(), 'updated_at' => now()]);
    ($this->appreciate)($reaction)->assertNotFound();
    $this->author->hrEmployeeProfile()->update(['is_active' => false]);
    $this->putJson($url, $data)->assertNotFound();
    $this->author->hrEmployeeProfile()->update(['is_active' => true]);
    $permission = Permission::where('key', 'company_hub.view')->sole();
    $this->author->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    $this->actingAs($this->author->fresh())->putJson($url, $data)->assertForbidden();
    $this->author->permissionOverrides()->detach($permission->id);
    $this->actingAs($this->author->fresh());
    $this->page = $this->publisher->save($this->moderator, $this->page, [...$this->page->draft, 'comments_enabled' => false], 2);
    $this->revision = $this->publisher->publish($this->moderator, $this->page, 3, null, null);
    ($this->appreciate)($id)->assertForbidden();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->moderator, $this->site, ['secondary_site_ids' => [$outside->id]]);
    $this->moderator = $this->moderator->fresh();
    $this->page = $this->publisher->save($this->moderator, $this->page, [...$this->page->draft, 'comments_enabled' => true, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]], 3);
    $this->revision = $this->publisher->publish($this->moderator, $this->page, 4, null, null);
    ($this->appreciate)($id)->assertNotFound()->assertDontSee('Canonical private target');
    $this->page->update(['archived_at' => now()]);
    ($this->appreciate)($id)->assertNotFound();
    $this->assertDatabaseCount('hub_comment_appreciations', 0);
    // Migration pending reader projections use harmless counts and no action.
    $schema = Schema::getFacadeRoot();
    $pendingSchema = Mockery::mock(get_class($schema), [$schema->getConnection()])->makePartial();
    $pendingSchema->shouldReceive('hasTable')->with('hub_comment_appreciations')->andReturn(false);
    Schema::swap($pendingSchema);
    try {
        $this->page->update(['archived_at' => null]);
        $this->actingAs($this->moderator);
        $read = $this->participation->read($this->moderator, $this->page);
        expect($read['comments'][0]['can_appreciate'])->toBeFalse()->and($read['comments'][0]['appreciation'])->toBe(['count' => 0, 'mine' => false]);
        ($this->appreciate)($id)->assertUnprocessable()->assertJsonValidationErrors('comment');
    } finally {
        Schema::swap($schema);
    }
    Notification::assertNothingSent();
});
