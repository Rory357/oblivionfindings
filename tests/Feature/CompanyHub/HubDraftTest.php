<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubDraft;
use App\Domain\CompanyHub\Services\HubFeed;
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
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'news', 'A useful shared story');
    Storage::disk('private')->put('company-hub/recoverable-cover.png', 'retained private media');
    $this->media = HubMedia::create([
        'hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/recoverable-cover.png', 'name' => 'Our story.png', 'mime' => 'image/png', 'size' => 22,
    ]);
    $this->page = $this->publisher->save($this->editor, $this->page, [
        ...$this->page->draft, 'cover_id' => $this->media->id,
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [['id' => 'story', 'columns' => 1, 'blocks' => [
            ['id' => 'body', 'type' => 'text', 'title' => 'Our story', 'body' => 'A saved unpublished detail.'],
        ]]],
    ], 1);
    $this->snapshot = $this->page->draft;
    $this->url = '/company-hub/manage/'.$this->page->id.'/draft';
    $this->payload = ['actor_id' => $this->editor->id, 'version' => $this->page->draft_version];
});

test('discarding an unpublished saved draft preserves a private recovery snapshot without recreating it on reads', function () {
    $this->actingAs($this->editor)->getJson($this->url)->assertOk()->assertJsonPath('has_saved_draft', true)->assertJsonPath('can_discard', true);
    $context = $this->deleteJson($this->url, $this->payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('has_saved_draft', false)->assertJsonPath('version', 3)->assertJsonPath('recovery.can_restore', true)->json();
    $this->assertDatabaseCount('hub_pages', 1);
    $this->assertDatabaseCount('hub_media', 1);
    $this->assertDatabaseCount('hub_draft_discards', 1);
    $this->assertDatabaseCount('hub_revisions', 0);
    Storage::disk('private')->assertExists($this->media->path);
    $discard = DB::table('hub_draft_discards')->sole();
    expect(json_decode($discard->content, true))->toEqual($this->snapshot)
        ->and($this->page->fresh()->draft_discarded_at)->not->toBeNull();
    $feed = app(HubFeed::class)->forUser($this->editor);
    expect(collect($feed['editablePages'])->pluck('id')->all())->not->toContain($this->page->id)
        ->and($feed['discardedDrafts'][0]['id'])->toBe($this->page->id)
        ->and($feed['discardedDrafts'][0]['draft_recovery']['recovery']['id'])->toBe($context['recovery']['id']);
    $this->get('/company-hub/manage/'.$this->page->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('draftRecovery.has_saved_draft', false)->where('page.draft_version', 3)->where('page.draft.sections', []));
    $this->getJson($this->url)->assertJsonPath('has_saved_draft', false)->assertJsonPath('version', 3);
    expect(DB::table('hub_draft_discards')->sole()->content)->toBe($discard->content);
    $this->actingAs($this->reader)->getJson($this->url)->assertNotFound();
    $readerFeed = app(HubFeed::class)->forUser($this->reader);
    expect($readerFeed['discardedDrafts'])->toBe([])->and($readerFeed['pages'])->toBe([]);
    $this->get('/company-hub/media/'.$this->media->id)->assertNotFound();
    Notification::assertNothingSent();
});

test('discard and recovery preserve the published reader release history media and page identity', function () {
    $release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $published = $release->content;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [
        ...$published, 'title' => 'An unpublished replacement',
        'sections' => [['id' => 'secret', 'columns' => 1, 'blocks' => [
            ['id' => 'private-body', 'type' => 'text', 'title' => 'Draft only', 'body' => 'Do not show this unfinished replacement.'],
        ]]],
    ], 2);
    $draft = $this->page->draft;
    $this->actingAs($this->editor)->getJson($this->url)->assertJsonPath('has_saved_draft', true);
    $id = $this->deleteJson($this->url, [...$this->payload, 'version' => 3])->assertOk()->assertJsonPath('version', 4)->json('recovery.id');
    expect($this->page->fresh()->draft)->toEqual($published)->and($this->page->fresh()->liveRevision()->id)->toBe($release->id);
    $feed = app(HubFeed::class)->forUser($this->editor);
    expect(collect($feed['editablePages'])->firstWhere('id', $this->page->id)['has_saved_draft'])->toBeFalse();
    $this->actingAs($this->reader)->get('/company-hub/read/'.$this->page->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedPage.content.title', 'A useful shared story')->where('selectedPage.content.sections.0.blocks.0.body', 'A saved unpublished detail.'));
    $this->actingAs($this->editor)->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'version' => 4, 'discard_id' => $id])
        ->assertOk()->assertJsonPath('has_saved_draft', true)->assertJsonPath('version', 5)->assertJsonPath('draft.title', 'An unpublished replacement')->assertJsonPath('recovery', null);
    expect($this->page->fresh()->draft)->toEqual($draft)->and($release->fresh()->content)->toEqual($published)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($release->id)
        ->and(json_decode(DB::table('hub_draft_discards')->sole()->content, true))->toEqual($draft);
    $this->assertDatabaseCount('hub_revisions', 1);
    $this->assertDatabaseCount('hub_media', 1);
    Storage::disk('private')->assertExists($this->media->path);
    Notification::assertNothingSent();
});

test('discard never unarchives or resurrects an expired release and keeps the newest historic baseline', function () {
    $first = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft, 'title' => 'Newest historical release'], 2);
    $latest = $this->publisher->publish($this->editor, $this->page, 3, null, now()->addDay()->toIso8601String());
    $latest->update(['expires_at' => now()->subSecond()]);
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft, 'title' => 'Private work after expiry'], 3);
    $this->publisher->archive($this->editor, $this->page, 4);
    $archived = $this->page->fresh()->archived_at->toIso8601String();
    $this->actingAs($this->editor)->deleteJson($this->url, [...$this->payload, 'version' => 5])->assertOk()->assertJsonPath('has_saved_draft', false);
    $this->page = $this->page->fresh();
    expect($this->page->draft['title'])->toBe('Newest historical release')->and($this->page->liveRevision())->toBeNull()
        ->and($this->page->archived_at->toIso8601String())->toBe($archived)
        ->and($first->fresh()->state)->toBe('approved')->and($latest->fresh()->state)->toBe('approved');
    $this->actingAs($this->reader)->get('/company-hub/read/'.$this->page->slug)->assertNotFound();
    $this->assertDatabaseCount('hub_pages', 1);
    $this->assertDatabaseCount('hub_revisions', 2);
    Notification::assertNothingSent();
});

test('saved draft discard and recovery reject wrong actors stale versions private objects and failed audit writes', function () {
    $this->actingAs($this->reader)->deleteJson($this->url, $this->payload)->assertNotFound();
    $this->actingAs($this->editor)->deleteJson($this->url, [...$this->payload, 'actor_id' => $this->reader->id])->assertConflict();
    $this->deleteJson($this->url, [...$this->payload, 'version' => 1])->assertConflict();
    $this->deleteJson($this->url, [...$this->payload, 'user_id' => $this->reader->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->deleteJson($this->url, [...$this->payload, 'content' => ['title' => 'Injected draft']])->assertUnprocessable()->assertJsonValidationErrors('content');
    $failAudit = true;
    DB::listen(function ($query) use (&$failAudit): void {
        if ($failAudit && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')) {
            $failAudit = false;
            throw new RuntimeException('Isolated draft discard audit failure');
        }
    });
    expect(fn () => app(HubDraft::class)->discard($this->editor, $this->page, $this->payload))->toThrow(RuntimeException::class, 'Isolated draft discard audit failure');
    $this->assertDatabaseCount('hub_draft_discards', 0);
    expect($this->page->fresh()->draft)->toEqual($this->snapshot)->and($this->page->fresh()->draft_version)->toBe(2)->and($this->page->fresh()->draft_discarded_at)->toBeNull();
    $id = $this->deleteJson($this->url, $this->payload)->assertOk()->json('recovery.id');
    $this->deleteJson($this->url, $this->payload)->assertConflict();
    $this->postJson($this->url.'/restore', ['actor_id' => $this->reader->id, 'version' => 3, 'discard_id' => $id])->assertConflict();
    $this->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'version' => 2, 'discard_id' => $id])->assertConflict();
    $other = $this->publisher->create($this->editor, 'news', 'Another private draft');
    $otherId = $this->deleteJson('/company-hub/manage/'.$other->id.'/draft', ['actor_id' => $this->editor->id, 'version' => 1])->assertOk()->json('recovery.id');
    $this->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'version' => 3, 'discard_id' => $otherId])->assertNotFound();
    $this->editor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'company_hub.write')->sole()->id => ['allowed' => false], Permission::where('key', 'company_hub.edit_all')->sole()->id => ['allowed' => false]]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertNotFound();
    $this->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'version' => 3, 'discard_id' => $id])->assertNotFound();
    Notification::assertNothingSent();
});

test('active review and future schedule must be explicitly withdrawn or cancelled before saved draft discard', function () {
    $revision = $this->publisher->submit($this->editor, $this->page, 2);
    $this->actingAs($this->editor)->getJson($this->url)->assertJsonPath('can_discard', false)->assertJsonPath('blocked_reason', 'review');
    $this->deleteJson($this->url, $this->payload)->assertConflict();
    expect($revision->fresh()->state)->toBe('submitted');
    $this->publisher->reviewDecision($this->editor, $this->page, $revision, 'withdraw', 2, null);
    $scheduled = $this->publisher->submit($this->editor, $this->page, 2);
    $this->publisher->approve($this->editor, $this->page, $scheduled, now()->addDay()->toIso8601String(), null);
    $this->getJson($this->url)->assertJsonPath('can_discard', false)->assertJsonPath('blocked_reason', 'schedule');
    $this->deleteJson($this->url, $this->payload)->assertConflict();
    expect($scheduled->fresh()->state)->toBe('approved');
    $this->publisher->reviewDecision($this->editor, $this->page, $scheduled, 'cancel_schedule', 2, null);
    $this->deleteJson($this->url, $this->payload)->assertOk()->assertJsonPath('has_saved_draft', false);
    expect($revision->fresh()->state)->toBe('withdrawn')->and($scheduled->fresh()->state)->toBe('cancelled');
    Notification::assertNothingSent();
});

test('normal save and authorised history restore clear discarded state while submitting its untouched baseline is blocked', function () {
    $release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->actingAs($this->editor)->getJson($this->url)->assertJsonPath('has_saved_draft', false)->assertJsonPath('can_discard', false);
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft, 'title' => 'A new saved draft'], 2);
    $this->deleteJson($this->url, [...$this->payload, 'version' => 3])->assertOk();
    $record = DB::table('hub_draft_discards')->sole();
    $this->postJson('/company-hub/manage/'.$this->page->id.'/submit', ['version' => 4])->assertConflict();
    $this->postJson('/company-hub/manage/'.$this->page->id.'/publish', ['version' => 4])->assertConflict();
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->fresh()->draft, 'title' => 'A deliberate new draft'], 4);
    $this->getJson($this->url)->assertJsonPath('has_saved_draft', true)->assertJsonPath('recovery', null);
    expect($this->page->draft_discarded_at)->toBeNull()->and(DB::table('hub_draft_discards')->sole()->content)->toBe($record->content);
    $this->deleteJson($this->url, [...$this->payload, 'version' => 5])->assertOk();
    $this->publisher->restore($this->editor, $this->page->fresh(), $release, 6);
    $this->getJson($this->url)->assertJsonPath('has_saved_draft', true)->assertJsonPath('version', 7)->assertJsonPath('recovery', null);
    expect($this->page->fresh()->draft_discarded_at)->toBeNull()->and($this->page->fresh()->liveRevision()->id)->toBe($release->id);
    Notification::assertNothingSent();
});

test('draft recovery rechecks current staff audience and referenced media without exposing inaccessible snapshots', function () {
    $this->actingAs($this->editor)->deleteJson($this->url, $this->payload)->assertOk();
    $id = DB::table('hub_draft_discards')->sole()->id;
    $drafts = app(HubDraft::class);
    expect($drafts->read($this->editor, $this->page)['recovery']['id'])->toBe($id);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    expect(fn () => $drafts->read($this->editor, $this->page))->toThrow(HttpException::class);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertNotFound();
    $this->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'version' => 3, 'discard_id' => $id])->assertNotFound();
    expect(app(HubFeed::class)->forUser($this->editor->fresh())['discardedDrafts'])->toBe([]);
    $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->media->delete();
    $this->actingAs($this->editor->fresh())->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'version' => 3, 'discard_id' => $id])
        ->assertUnprocessable()->assertJsonValidationErrors('cover_id');
    expect($this->page->fresh()->draft_discarded_at)->not->toBeNull()->and($this->page->fresh()->draft_version)->toBe(3)
        ->and(DB::table('hub_draft_discards')->sole()->restored_at)->toBeNull();
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertNotFound();
    Notification::assertNothingSent();
});

test('missing recovery migration disables draft discard safely and ordinary saving remains available', function () {
    $schema = Schema::getFacadeRoot();
    Schema::partialMock()->shouldReceive('hasTable')->andReturnUsing(fn ($table) => $table === 'hub_draft_discards' ? false : $schema->hasTable($table));
    Schema::getFacadeRoot()->shouldReceive('hasColumn')->andReturnUsing(fn ($table, $column) => $table === 'hub_pages' && $column === 'draft_discarded_at' ? false : $schema->hasColumn($table, $column));
    $this->actingAs($this->editor)->getJson($this->url)->assertOk()->assertJsonPath('has_saved_draft', true)->assertJsonPath('can_discard', false)->assertJsonPath('blocked_reason', 'unavailable');
    $this->deleteJson($this->url, $this->payload)->assertStatus(503);
    $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'title' => 'Normal save without recovery'], 2);
    expect($this->page->fresh()->draft_version)->toBe(3);
    $this->assertDatabaseCount('hub_draft_discards', 0);
    Notification::assertNothingSent();
});
