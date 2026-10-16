<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubDraft;
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
    $this->page = $this->publisher->create($this->editor, 'news', 'Current published story');
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft,
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [['id' => 'live', 'columns' => 1, 'blocks' => [
            ['id' => 'live-body', 'type' => 'text', 'title' => 'Published information', 'body' => 'The published reader content remains current.'],
        ]]],
    ], 1);
    $this->release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->published = $this->release->fresh()->content;
    Storage::disk('private')->put('company-hub/retained-unsubmitted.png', 'private saved image');
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/retained-unsubmitted.png', 'name' => 'Saved image.png', 'mime' => 'image/png', 'size' => 19]);
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->published, 'title' => 'Unsubmitted saved work',
        'sections' => [['id' => 'work', 'columns' => 1, 'blocks' => [
            ['id' => 'working-copy', 'type' => 'text', 'title' => 'Saved work', 'body' => 'SavedRestoreRetentionNeedle', 'tone' => 'soft', 'min_height' => 280],
            ['id' => 'hidden-work', 'type' => 'text', 'title' => 'Hidden saved work', 'body' => 'Keep the complete hidden authored detail.', 'hidden' => true],
            ['id' => 'saved-image', 'type' => 'image', 'title' => 'Saved photo', 'media_id' => $this->media->id, 'body' => 'The saved image caption.'],
        ]]],
    ], 2);
    $this->saved = $this->page->fresh()->draft;
    $this->base = '/company-hub/manage/'.$this->page->id;
    $this->restoreUrl = $this->base.'/revisions/'.$this->release->id.'/restore';
});

test('revision restore retention makes overwritten full saved work recoverable through immutable history without changing readers', function () {
    $this->actingAs($this->editor)->postJson($this->restoreUrl, ['version' => 3])->assertOk();
    $retained = $this->page->revisions()->where('draft_version', 3)->sole();
    expect($retained->state)->toBe('superseded')->and($retained->created_by)->toBe($this->editor->id)
        ->and($retained->fresh()->content)->toBe($this->saved)->and($retained->publish_at)->toBeNull()
        ->and($this->page->fresh()->draft_version)->toBe(4)->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id)
        ->and($this->release->fresh()->content)->toBe($this->published);
    $this->getJson($this->base.'/revisions')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', $retained->id)->assertJsonMissingPath('items.0.content');
    $this->getJson($this->base.'/revisions/'.$retained->id)->assertOk()->assertJsonPath('content.sections.0.blocks.1.hidden', true)
        ->assertJsonPath('content.sections.0.blocks.2.media_id', $this->media->id)->assertJsonPath('page_version', 4)->assertJsonPath('can_approve', false);
    $audit = AuditLog::where('action', 'company_hub.restored_to_draft')->where('auditable_id', $this->page->id)->sole();
    expect($audit->meta['preserved_draft_revision_id'])->toBe($retained->id)->and($audit->meta['preserved_draft_version'])->toBe(3);
    expect(fn () => $retained->update(['content' => [...$this->saved, 'title' => 'Attempted history mutation']]))->toThrow(LogicException::class);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$this->page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('selectedPage.content.title', 'Current published story')->where('selectedPage.revision_id', $this->release->id));
    $this->getJson($this->base.'/revisions/'.$retained->id)->assertNotFound();
    $this->get('/company-hub/media/'.$this->media->id)->assertNotFound();
    $this->getJson('/company-hub/search?query=SavedRestoreRetentionNeedle')->assertJsonCount(0, 'results');
    $this->actingAs($this->editor)->postJson($this->base.'/revisions/'.$retained->id.'/restore', ['version' => 4])->assertOk();
    expect($this->page->fresh()->draft)->toBe($this->saved)->and($this->page->fresh()->draft_version)->toBe(5)
        ->and($retained->fresh()->content)->toBe($this->saved)->and($this->release->fresh()->content)->toBe($this->published)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id);
    Storage::disk('private')->assertExists($this->media->path);
    Notification::assertNothingSent();
});

test('revision restore retention reuses complete submitted or scheduled snapshots rather than duplicating their saved version', function (string $state) {
    $existing = $state === 'submitted' ? $this->publisher->submit($this->editor, $this->page, 3)
        : $this->publisher->publish($this->editor, $this->page, 3, now()->addDay()->toIso8601String(), null);
    $snapshot = $existing->fresh()->content;
    $this->actingAs($this->editor)->postJson($this->restoreUrl, ['version' => 3])->assertConflict();
    $this->publisher->reviewDecision($this->editor, $this->page, $existing, $state === 'submitted' ? 'withdraw' : 'cancel_schedule', 3, null);
    $this->actingAs($this->editor)->postJson($this->restoreUrl, ['version' => 3])->assertOk();
    expect($this->page->revisions()->where('draft_version', 3)->count())->toBe(1)
        ->and($existing->fresh()->content)->toBe($snapshot)
        ->and($existing->fresh()->state)->toBe($state === 'submitted' ? 'withdrawn' : 'cancelled')
        ->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id)
        ->and($this->release->fresh()->content)->toBe($this->published);
    $audit = AuditLog::where('action', 'company_hub.restored_to_draft')->where('auditable_id', $this->page->id)->sole();
    expect($audit->meta['preserved_draft_revision_id'])->toBe($existing->id);
    $this->assertDatabaseCount('hub_revisions', 2);
    Notification::assertNothingSent();
})->with(['submitted', 'scheduled']);

test('revision restore retention rejects stale and unauthorised or foreign snapshots before retaining or overwriting work', function () {
    $this->actingAs($this->reader)->postJson($this->restoreUrl, ['version' => 3])->assertNotFound();
    $this->actingAs($this->editor)->postJson($this->restoreUrl, ['version' => 2])->assertConflict();
    $other = $this->publisher->create($this->editor, 'news', 'Another page');
    $draft = $other->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'A complete foreign published page.';
    $other->update(['draft' => $draft]);
    $foreign = $this->publisher->publish($this->editor, $other, 1, null, null);
    $this->postJson($this->base.'/revisions/'.$foreign->id.'/restore', ['version' => 3])->assertNotFound();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $forbidden = $this->page->revisions()->create(['draft_version' => 1, 'state' => 'superseded', 'created_by' => $this->editor->id,
        'content' => [...$this->published, 'title' => 'Forbidden original site snapshot', 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]]);
    $this->postJson($this->base.'/revisions/'.$forbidden->id.'/restore', ['version' => 3])->assertNotFound();
    expect($this->page->revisions()->where('draft_version', 3)->count())->toBe(0)
        ->and($this->page->fresh()->draft)->toBe($this->saved)->and($this->page->fresh()->draft_version)->toBe(3)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id)->and($this->release->fresh()->content)->toBe($this->published);
});

test('revision restore retention rechecks cached actor permissions current sites and staff eligibility', function (string $change) {
    // Earlier create/save calls deliberately populated this retained actor's work access.
    if ($change === 'permissions') {
        $this->editor->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'company_hub.write')->sole()->id => ['allowed' => false],
            Permission::where('key', 'company_hub.edit_all')->sole()->id => ['allowed' => false],
        ]);
    } elseif ($change === 'site') {
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    } else {
        $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    }
    try {
        $this->publisher->restore($this->editor, $this->page, $this->release, 3);
        $this->fail('Restore must reject current access loss.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(404);
    }
    expect($this->page->revisions()->where('draft_version', 3)->count())->toBe(0)
        ->and($this->page->fresh()->draft)->toBe($this->saved)->and($this->page->fresh()->draft_version)->toBe(3)
        ->and($this->release->fresh()->content)->toBe($this->published);
})->with(['permissions', 'site', 'former']);

test('revision restore retention and the replacement draft roll back together when audit fails', function () {
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')) {
            $fail = false;
            throw new RuntimeException('Isolated revision restore audit failure');
        }
    });
    expect(fn () => $this->publisher->restore($this->editor, $this->page, $this->release, 3))->toThrow(RuntimeException::class, 'Isolated revision restore audit failure');
    expect($this->page->revisions()->count())->toBe(1)->and($this->page->fresh()->draft)->toBe($this->saved)
        ->and($this->page->fresh()->draft_version)->toBe(3)->and($this->release->fresh()->content)->toBe($this->published)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id);
    $this->actingAs($this->editor)->postJson($this->restoreUrl, ['version' => 3])->assertOk();
    expect($this->page->revisions()->where('draft_version', 3)->count())->toBe(1);
});

test('revision restore retention does not duplicate published baselines or discarded drafts already kept in recovery', function () {
    $baseline = $this->publisher->create($this->editor, 'news', 'A published baseline');
    $draft = $baseline->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'A complete published baseline.';
    $baseline->update(['draft' => $draft]);
    $release = $this->publisher->publish($this->editor, $baseline, 1, null, null);
    $this->publisher->restore($this->editor, $baseline, $release, 1);
    expect($baseline->revisions()->count())->toBe(1);
    $context = app(HubDraft::class)->discard($this->editor, $this->page, ['actor_id' => $this->editor->id, 'version' => 3]);
    $discard = DB::table('hub_draft_discards')->where('id', $context['recovery']['id'])->sole();
    $this->publisher->restore($this->editor, $this->page->fresh(), $this->release, 4);
    expect($this->page->revisions()->count())->toBe(1)->and($this->page->fresh()->draft_discarded_at)->toBeNull()
        ->and(DB::table('hub_draft_discards')->where('id', $discard->id)->value('content'))->toBe($discard->content)
        ->and($this->release->fresh()->content)->toBe($this->published);
});

test('revision restore retention works through existing history when the separate draft recovery migration is unavailable', function () {
    $schema = Schema::getFacadeRoot();
    Schema::partialMock()->shouldReceive('hasTable')->andReturnUsing(fn ($table) => $table === 'hub_draft_discards' ? false : $schema->hasTable($table));
    Schema::getFacadeRoot()->shouldReceive('hasColumn')->andReturnUsing(fn ($table, $column) => $table === 'hub_pages' && $column === 'draft_discarded_at' ? false : $schema->hasColumn($table, $column));
    $this->actingAs($this->editor)->postJson($this->restoreUrl, ['version' => 3])->assertOk();
    $retained = $this->page->revisions()->where('draft_version', 3)->sole();
    $this->getJson($this->base.'/revisions/'.$retained->id)->assertOk()->assertJsonPath('content.title', 'Unsubmitted saved work');
    expect($retained->fresh()->content)->toBe($this->saved)->and($this->release->fresh()->content)->toBe($this->published);
    $this->assertDatabaseCount('hub_draft_discards', 0);
    Notification::assertNothingSent();
});
