<?php

use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
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

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->moderator = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->moderator->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->moderator);
    $this->author = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->author->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->author, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->moderator, 'news', 'Moderated company story');
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'A complete company story for moderation contracts.';
    $this->page->update(['draft' => $draft]);
    $this->revision = $this->publisher->publish($this->moderator, $this->page, 1, null, null);
    $this->participation = app(HubParticipation::class);
    $this->context = ['actor_id' => $this->moderator->id, 'revision_id' => $this->revision->id];
    $this->base = '/company-hub/pages/'.$this->page->id;
    $this->comment = function (string $body, ?int $parent = null, ?User $author = null): int {
        $this->participation->write($author ?? $this->author, $this->page, ['kind' => 'comment', 'body' => $body, 'parent_id' => $parent]);

        return (int) DB::table('hub_interactions')->where('hub_page_id', $this->page->id)->where('kind', 'comment')->max('id');
    };
    $this->version = fn (int $id): int => (int) DB::table('hub_interactions')->where('id', $id)->value('moderation_version');
    $this->change = fn (int $id, bool $hidden) => $this->putJson($this->base.'/interactions/'.$id.'/moderation', [...$this->context, 'version' => ($this->version)($id), 'hidden' => $hidden]);
    $this->review = fn () => $this->getJson($this->base.'/moderation?'.http_build_query($this->context));
});

test('restoring a parent only reveals inherited replies while independent removal and hiding survive', function () {
    $parent = ($this->comment)('Root discussion');
    $inherited = ($this->comment)('Normal reply', $parent);
    $independent = ($this->comment)('Separately hidden reply', $parent);
    $removed = ($this->comment)('Author private removal', $parent);
    $legacy = ($this->comment)('Old unknown removal', $parent);
    $this->actingAs($this->moderator);
    ($this->change)($independent, true)->assertOk();
    $this->participation->remove($this->author, $this->page, $removed);
    DB::table('hub_interactions')->where('id', $legacy)->update(['hidden_at' => now(), 'legacy_hidden_at' => now()]);
    ($this->change)($parent, true)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(5, 'hidden_comments');
    $review = collect(($this->review)()->assertOk()->json('hidden_comments'))->keyBy('id');
    expect($review[$parent]['can_restore'])->toBeTrue()
        ->and($review[$inherited]['hidden_reason'])->toBe('parent_hidden')
        ->and($review[$inherited]['can_restore'])->toBeFalse()
        ->and($review[$independent]['hidden_reason'])->toBe('moderator_hidden')
        ->and($review[$independent]['parent_hidden'])->toBeTrue()
        ->and($review[$independent]['can_restore'])->toBeFalse()
        ->and($review[$removed]['body'])->toBeNull()
        ->and($review[$legacy]['body'])->toBeNull();
    ($this->change)($independent, false)->assertUnprocessable();
    ($this->change)($inherited, false)->assertUnprocessable();
    ($this->change)($parent, false)->assertOk()->assertJsonCount(3, 'hidden_comments');
    expect(array_column($this->participation->read($this->author, $this->page)['comments'], 'id'))->toBe([$parent, $inherited]);
    $this->assertDatabaseHas('hub_interactions', ['id' => $independent, 'parent_hidden_at' => null]);
    $this->assertDatabaseHas('hub_interactions', ['id' => $removed, 'parent_hidden_at' => null]);
    ($this->change)($removed, false)->assertUnprocessable();
    ($this->change)($legacy, false)->assertUnprocessable();
    ($this->change)($legacy, true)->assertUnprocessable();
    ($this->change)($independent, false)->assertOk()->assertJsonCount(2, 'hidden_comments');
    expect(array_column($this->participation->read($this->author, $this->page)['comments'], 'id'))->toBe([$parent, $inherited, $independent]);
    expect(AuditLog::where('action', 'company_hub.comment_restored')->count())->toBe(2);
    expect(AuditLog::where('action', 'like', 'company_hub.comment_%')->pluck('meta')->toJson())->not->toContain('Root discussion', 'Normal reply', 'Separately hidden reply', 'Author private removal', 'Old unknown removal');
});

test('author removal never becomes recoverable even for moderator authors or previously hidden comments', function () {
    $own = ($this->comment)('Moderator author removal', null, $this->moderator);
    $reply = ($this->comment)('Reply beneath removed author', $own);
    $other = ($this->comment)('Author withdraws previously moderated text');
    $this->actingAs($this->moderator);
    ($this->change)($other, true)->assertOk();
    $this->participation->remove($this->author, $this->page, $other);
    $this->delete($this->base.'/interactions/'.$own, [...$this->context, 'version' => 1])->assertRedirect();
    $review = collect(($this->review)()->assertOk()->json('hidden_comments'))->keyBy('id');
    expect($review[$own]['hidden_reason'])->toBe('author_removed')->and($review[$own]['body'])->toBeNull()->and($review[$own]['can_restore'])->toBeFalse()
        ->and($review[$other]['hidden_reason'])->toBe('author_removed')->and($review[$other]['body'])->toBeNull()
        ->and($review[$reply]['hidden_reason'])->toBe('parent_hidden');
    ($this->change)($own, false)->assertUnprocessable();
    ($this->change)($other, false)->assertUnprocessable();
    expect($this->participation->read($this->author, $this->page)['comments'])->toBe([]);
    // A moderator must explicitly choose Hide for their own comment to keep recovery available.
    $hiddenOwn = ($this->comment)('Moderator explicitly chooses hide', null, $this->moderator);
    ($this->change)($hiddenOwn, true)->assertOk();
    ($this->change)($hiddenOwn, false)->assertOk();
    $legacyDelete = ($this->comment)('Legacy moderator delete');
    $this->delete($this->base.'/interactions/'.$legacyDelete)->assertRedirect();
    ($this->change)($legacyDelete, false)->assertOk();
});

test('ordinary readers and page owners without moderation permission never receive hidden bodies', function () {
    $parent = ($this->comment)('Secret hidden parent');
    $reply = ($this->comment)('Secret inherited reply', $parent);
    $visible = ($this->comment)('Visible discussion');
    $this->actingAs($this->moderator);
    ($this->change)($parent, true)->assertOk();
    $this->actingAs($this->author);
    $this->get('/company-hub/read/'.$this->page->slug)->assertOk()->assertDontSee('Secret hidden parent')->assertDontSee('Secret inherited reply');
    $projection = $this->participation->read($this->author, $this->page);
    expect($projection['can_moderate'])->toBeFalse()->and($projection['hidden_count'])->toBe(0)
        ->and(array_column($projection['comments'], 'id'))->toBe([$visible]);
    $this->page->update(['owner_id' => $this->author->id]);
    ($this->review)()->assertForbidden()->assertDontSee('Secret hidden parent');
    ($this->change)($parent, false)->assertForbidden();
    $this->delete($this->base.'/interactions/'.$visible)->assertRedirect();
    // Defend the ordinary projection if inherited materialisation is ever inconsistent.
    DB::table('hub_interactions')->where('id', $reply)->update(['hidden_at' => null, 'parent_hidden_at' => null]);
    expect($this->participation->read($this->author, $this->page)['comments'])->toBe([]);
    $this->postJson($this->base.'/interactions', ['kind' => 'comment', 'body' => 'Cannot reply', 'parent_id' => $parent])->assertUnprocessable();
});

test('moderation binds account published revision and comment version and cascade invalidates stale replies', function () {
    $parent = ($this->comment)('Current root');
    $reply = ($this->comment)('Current reply', $parent);
    $url = $this->base.'/interactions/'.$parent.'/moderation';
    $payload = [...$this->context, 'version' => 1, 'hidden' => true];
    $this->actingAs($this->moderator);
    $this->putJson($url, [...$payload, 'actor_id' => $this->author->id])->assertConflict();
    $this->putJson($url, [...$payload, 'revision_id' => $this->revision->id + 100])->assertConflict();
    $this->putJson($url, [...$payload, 'version' => 2])->assertConflict();
    $this->assertDatabaseHas('hub_interactions', ['id' => $parent, 'hidden_at' => null, 'moderation_version' => 1]);
    $this->putJson($url, $payload)->assertOk();
    $this->putJson($url, [...$payload, 'hidden' => false])->assertConflict();
    $this->putJson($this->base.'/interactions/'.$reply.'/moderation', [...$payload, 'hidden' => false])->assertConflict();
    $this->page = $this->publisher->save($this->moderator, $this->page, [...$this->page->draft, 'summary' => 'A fresh release'], 1);
    $this->publisher->publish($this->moderator, $this->page, 2, null, null);
    ($this->review)()->assertConflict()->assertDontSee('Current root');
    ($this->change)($parent, false)->assertConflict();
    expect(AuditLog::where('action', 'like', 'company_hub.comment_%')->count())->toBe(1);
});

test('author delete accepts legacy callers but validates supplied account revision and version', function () {
    $parent = ($this->comment)('Author context');
    $this->actingAs($this->author);
    $url = $this->base.'/interactions/'.$parent;
    $context = ['actor_id' => $this->author->id, 'revision_id' => $this->revision->id, 'version' => 1];
    $this->deleteJson($url, [...$context, 'actor_id' => $this->moderator->id])->assertConflict();
    $this->deleteJson($url, [...$context, 'revision_id' => $this->revision->id + 100])->assertConflict();
    $this->deleteJson($url, [...$context, 'version' => 2])->assertConflict();
    $this->deleteJson($url, [...$context, 'parent_id' => 999])->assertUnprocessable();
    $this->delete($url, $context)->assertRedirect();
    $this->assertDatabaseHas('hub_interactions', ['id' => $parent, 'moderation_version' => 2]);
    expect(DB::table('hub_interactions')->where('id', $parent)->value('author_removed_at'))->not->toBeNull();
    $this->delete($url)->assertRedirect();
    expect(AuditLog::where('action', 'company_hub.comment_removed')->count())->toBe(1);
});

test('review and restore require current staff current audience and unwithdrawn moderation permission', function () {
    $id = ($this->comment)('Restricted moderation text');
    $this->actingAs($this->moderator);
    ($this->change)($id, true)->assertOk();
    $permission = Permission::where('key', 'company_hub.moderate')->sole();
    $this->moderator->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    $this->moderator = $this->moderator->fresh();
    $this->actingAs($this->moderator);
    ($this->review)()->assertForbidden();
    ($this->change)($id, false)->assertForbidden();
    $this->moderator->permissionOverrides()->detach($permission->id);
    $this->moderator = $this->moderator->fresh();
    $this->actingAs($this->moderator);
    $this->moderator->hrEmployeeProfile->update(['is_active' => false]);
    ($this->review)()->assertNotFound();
    ($this->change)($id, false)->assertNotFound();
    $this->moderator->hrEmployeeProfile->update(['is_active' => true]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->moderator, $this->site, ['secondary_site_ids' => [$outside->id]]);
    $this->moderator = $this->moderator->fresh();
    $this->page = $this->publisher->save($this->moderator, $this->page, [...$this->page->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]], 1);
    $this->revision = $this->publisher->publish($this->moderator, $this->page, 2, null, null);
    $this->context['revision_id'] = $this->revision->id;
    ensureCanonicalHrStaffProfile($this->moderator, $this->site);
    $this->moderator = $this->moderator->fresh();
    $this->actingAs($this->moderator);
    ($this->review)()->assertNotFound()->assertDontSee('Restricted moderation text');
    ($this->change)($id, false)->assertNotFound();
    $this->assertDatabaseHas('hub_interactions', ['id' => $id, 'moderation_version' => 2]);
});

test('moderation rejects forged author parent page body and noncomment identifiers and binds replies canonically', function () {
    $id = ($this->comment)('Canonical comment');
    $reply = ($this->comment)('One level reply', $id);
    $other = $this->publisher->create($this->moderator, 'news', 'Another company story');
    $draft = $other->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'A complete company story for cross-page denial.';
    $other->update(['draft' => $draft]);
    $release = $this->publisher->publish($this->moderator, $other, 1, null, null);
    $this->actingAs($this->moderator);
    foreach (['user_id', 'author_id', 'parent_id', 'hub_page_id', 'page_id', 'body'] as $field) {
        $this->putJson($this->base.'/interactions/'.$id.'/moderation', [...$this->context, 'version' => 1, 'hidden' => true, $field => $field === 'body' ? 'Spoofed content' : $this->author->id])->assertUnprocessable();
    }
    $this->putJson('/company-hub/pages/'.$other->id.'/interactions/'.$id.'/moderation', ['actor_id' => $this->moderator->id, 'revision_id' => $release->id, 'version' => 1, 'hidden' => true])->assertNotFound();
    $reaction = DB::table('hub_interactions')->insertGetId(['hub_page_id' => $this->page->id, 'user_id' => $this->author->id, 'kind' => 'reaction', 'body' => 'heart', 'created_at' => now(), 'updated_at' => now()]);
    ($this->change)($reaction, true)->assertNotFound();
    $this->postJson($this->base.'/interactions', ['kind' => 'comment', 'body' => 'Fake author', 'user_id' => $this->moderator->id])->assertUnprocessable();
    $this->postJson($this->base.'/interactions', ['kind' => 'comment', 'body' => 'Nested reply', 'parent_id' => $reply])->assertUnprocessable();
    $this->postJson('/company-hub/pages/'.$other->id.'/interactions', ['kind' => 'comment', 'body' => 'Wrong page reply', 'parent_id' => $id])->assertUnprocessable();
    $this->assertDatabaseHas('hub_interactions', ['id' => $id, 'moderation_version' => 1, 'body' => 'Canonical comment']);
    expect(AuditLog::where('action', 'like', 'company_hub.comment_%')->count())->toBe(0);
});

test('legacy hidden content remains unrecoverable through new parent hiding and comments closed still allow review', function () {
    $parent = ($this->comment)('Parent with old reply');
    $legacy = ($this->comment)('Private legacy text', $parent);
    // A legacy import without explicit provenance receives the same conservative protection.
    DB::table('hub_interactions')->where('id', $legacy)->update(['hidden_at' => now()]);
    $this->actingAs($this->moderator);
    ($this->change)($parent, true)->assertOk();
    ($this->change)($parent, false)->assertOk();
    ($this->review)()->assertOk()->assertJsonCount(1, 'hidden_comments')->assertJsonPath('hidden_comments.0.hidden_reason', 'legacy_hidden')->assertJsonPath('hidden_comments.0.body', null)->assertJsonPath('hidden_comments.0.can_restore', false)->assertDontSee('Private legacy text');
    ($this->change)($legacy, false)->assertUnprocessable();
    $this->page = $this->publisher->save($this->moderator, $this->page, [...$this->page->draft, 'comments_enabled' => false], 1);
    $this->revision = $this->publisher->publish($this->moderator, $this->page, 2, null, null);
    $this->context['revision_id'] = $this->revision->id;
    ($this->review)()->assertOk()->assertJsonPath('comments_enabled', false);
    ($this->change)($parent, true)->assertOk();
    ($this->change)($parent, false)->assertOk();
    expect($this->participation->read($this->moderator, $this->page)['hidden_count'])->toBe(1);
    $this->page->update(['archived_at' => now()]);
    ($this->review)()->assertNotFound();
    ($this->change)($parent, true)->assertNotFound();
});
