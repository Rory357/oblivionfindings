<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->otherEditor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->otherEditor->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($this->otherEditor, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'An exact saved resource draft', 'blank')->fresh();
    $this->media = HubMedia::create([
        'hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/snapshot-reference.pdf', 'name' => 'Saved reference.pdf', 'mime' => 'application/pdf', 'size' => 20,
    ]);
    $this->input = [...$this->page->draft,
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [
            ['id' => 'main', 'columns' => 1, 'blocks' => [
                ['id' => 'intro', 'type' => 'text', 'title' => 'Saved instructions', 'body' => 'Canonical saved instructions.'],
                ['id' => 'question', 'type' => 'poll', 'title' => 'Choose a next step',
                    'question' => 'Which next step?', 'options' => [' Walk ', 'Lunch '],
                    'closes_at' => now()->addDays(2)->format('Y-m-d\TH:i:sP')],
            ]],
            ['id' => 'unfinished', 'columns' => 1, 'hidden' => true, 'blocks' => [
                ['id' => 'unfinished-text', 'type' => 'text', 'title' => null, 'body' => null, 'hidden' => false],
                ['id' => 'saved-file', 'type' => 'file', 'title' => 'A hidden reference', 'media_id' => $this->media->id, 'hidden' => true],
            ]],
        ],
    ];
    $this->page = $this->publisher->save($this->editor, $this->page, $this->input, $this->page->draft_version);
    $this->stored = $this->page->fresh()->draft;
    $this->context = ['actor_id' => $this->editor->id, 'version' => $this->page->draft_version];
    $this->url = '/company-hub/manage/'.$this->page->id.'/draft';
    $this->snapshotUrl = fn (array $change = []) => $this->url.'?'.http_build_query(['include_snapshot' => 1, ...$this->context, ...$change]);
    $this->actingAs($this->editor);
});

test('current saved draft snapshots return canonical normalised content and retained hidden authoring without creating a revision or write', function () {
    $attributes = $this->page->fresh()->getAttributes();
    $auditCount = AuditLog::count();
    $result = $this->getJson(($this->snapshotUrl)())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('page_id', $this->page->id)
        ->assertJsonPath('version', $this->context['version'])->assertJsonPath('has_saved_draft', true)
        ->assertJsonMissingPath('draft')->assertJsonMissingPath('content')->json('current_draft');

    expect($result)->toEqual([
        'source' => 'draft', 'actor_id' => $this->editor->id, 'page_id' => $this->page->id,
        'version' => $this->context['version'], 'saved_at' => $this->page->updated_at->toIso8601String(), 'content' => $this->stored,
    ])->not->toHaveKey('id')->not->toHaveKey('revision_id')->not->toHaveKey('author');
    expect($result['content']['sections'][0]['blocks'][1]['options'])->toBe(['Walk', 'Lunch'])
        ->and($result['content']['sections'][0]['blocks'][1]['closes_at'])->not->toBe($this->input['sections'][0]['blocks'][1]['closes_at'])
        ->and($result['content']['sections'][1]['hidden'])->toBeTrue()
        ->and($result['content']['sections'][1]['blocks'][0]['title'])->toBe('')
        ->and($result['content']['sections'][1]['blocks'][0]['hidden'])->toBeFalse()
        ->and($result['content']['sections'][1]['blocks'][1]['hidden'])->toBeTrue()
        ->and($result['content']['sections'][1]['blocks'][1]['media_id'])->toBe($this->media->id)
        ->and($this->page->fresh()->getAttributes())->toEqual($attributes)
        ->and(AuditLog::count())->toBe($auditCount);
    $this->getJson(($this->snapshotUrl)())->assertOk()->assertJsonPath('current_draft.content.title', $this->stored['title']);
    $this->assertDatabaseCount('hub_pages', 1);
    $this->assertDatabaseCount('hub_revisions', 0);
    $this->assertDatabaseCount('hub_draft_discards', 0);
    $this->assertDatabaseCount('hub_media', 1);
    Notification::assertNothingSent();
});

test('snapshot opt in requires bounded actor and version while legacy metadata reads keep their response shape', function () {
    $legacy = $this->getJson($this->url)->assertOk()->assertJsonMissingPath('current_draft')
        ->assertJsonMissingPath('draft')->assertJsonMissingPath('content')->json();
    expect(array_keys($legacy))->toBe(['actor_id', 'page_id', 'version', 'has_saved_draft', 'can_discard', 'blocked_reason', 'recovery']);
    $this->getJson($this->url.'?include_snapshot=0')->assertOk()->assertExactJson($legacy);
    foreach ([[], ['actor_id' => $this->editor->id], ['version' => $this->context['version']]] as $context) {
        $this->getJson($this->url.'?'.http_build_query(['include_snapshot' => 1, ...$context]))->assertUnprocessable();
    }
    foreach (['actor_id', 'version'] as $field) {
        foreach ([0, -1, 'not-an-id', '9007199254740992', [1]] as $value) {
            $this->getJson(($this->snapshotUrl)([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }
    foreach (['yes', 2, [1]] as $flag) {
        $this->getJson($this->url.'?'.http_build_query(['include_snapshot' => $flag, ...$this->context]))
            ->assertUnprocessable()->assertJsonValidationErrors('include_snapshot');
    }
    $this->assertDatabaseCount('hub_revisions', 0);
    expect($this->page->fresh()->draft)->toEqual($this->stored)->and($this->page->fresh()->draft_version)->toBe($this->context['version']);
});

test('snapshot reads bind the fresh current actor page and saved version before returning private content', function () {
    $this->getJson(($this->snapshotUrl)(['actor_id' => $this->otherEditor->id]))->assertConflict()->assertJsonMissingPath('current_draft');
    $this->actingAs($this->otherEditor)->getJson(($this->snapshotUrl)())->assertConflict()->assertJsonMissingPath('current_draft');
    $this->getJson(($this->snapshotUrl)(['actor_id' => $this->otherEditor->id]))->assertOk()
        ->assertJsonPath('current_draft.actor_id', $this->otherEditor->id);
    $this->actingAs($this->reader)->getJson(($this->snapshotUrl)(['actor_id' => $this->reader->id, 'version' => 999]))
        ->assertNotFound()->assertJsonMissingPath('current_draft');
    $this->actingAs($this->editor)->getJson(($this->snapshotUrl)(['version' => 1]))->assertConflict()->assertJsonMissingPath('current_draft');
    $stale = $this->page;
    $updated = $this->publisher->save($this->editor, $this->page, [...$this->stored, 'title' => 'A later canonical saved version'], $this->context['version']);
    $this->getJson(($this->snapshotUrl)())->assertConflict()->assertJsonMissingPath('current_draft');
    try {
        app(HubDraft::class)->read($this->editor, $stale, $this->context);
        $this->fail('A retained page model must not return an overwritten saved version.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    $this->getJson(($this->snapshotUrl)(['version' => $updated->draft_version]))->assertOk()
        ->assertJsonPath('current_draft.version', $updated->draft_version)
        ->assertJsonPath('current_draft.content.title', 'A later canonical saved version');
    $this->assertDatabaseCount('hub_revisions', 0);
    Notification::assertNothingSent();
});

test('snapshot reads recheck current staff permissions and approved sites on retained actor and page models', function () {
    $cached = $this->editor->load('hrEmployeeProfile');
    expect(app(HubAccess::class)->canEdit($cached, $this->page))->toBeTrue();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $permission = Permission::where('key', 'company_hub.view')->sole();
    $service = app(HubDraft::class);
    foreach (['former staff', 'permission', 'site'] as $change) {
        if ($change === 'former staff') {
            $this->editor->hrEmployeeProfile()->update(['is_active' => false]);
        } elseif ($change === 'permission') {
            $this->editor->hrEmployeeProfile()->update(['is_active' => true]);
            $this->editor->permissionOverrides()->attach($permission, ['allowed' => false]);
        } else {
            $this->editor->permissionOverrides()->detach($permission);
            $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
        }
        try {
            $service->read($cached, $this->page, $this->context);
            $this->fail('Revoked author access must never expose a saved snapshot.');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(404);
        }
        $this->actingAs($this->editor->fresh())->getJson(($this->snapshotUrl)())
            ->assertStatus($change === 'permission' ? 403 : 404)->assertJsonMissingPath('current_draft');
    }
    expect($this->page->fresh()->draft)->toEqual($this->stored)->and($this->page->fresh()->draft_version)->toBe($this->context['version']);
    $this->assertDatabaseCount('hub_revisions', 0);
});

test('current saved draft preview is null for a released or discarded baseline while later unpublished work is distinct from the immutable release', function () {
    $release = $this->publisher->publish($this->editor, $this->page, $this->context['version'], null, null);
    $published = $release->fresh()->content;
    $this->getJson(($this->snapshotUrl)())->assertOk()->assertJsonPath('has_saved_draft', false)->assertJsonPath('current_draft', null);
    $updated = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->stored, 'title' => 'Unpublished saved work'], $this->context['version']);
    $this->getJson(($this->snapshotUrl)(['version' => $updated->draft_version]))->assertOk()
        ->assertJsonPath('has_saved_draft', true)->assertJsonPath('current_draft.content.title', 'Unpublished saved work');
    $discarded = app(HubDraft::class)->discard($this->editor, $updated, ['actor_id' => $this->editor->id, 'version' => $updated->draft_version]);
    $this->getJson(($this->snapshotUrl)(['version' => $discarded['version']]))->assertOk()
        ->assertJsonPath('has_saved_draft', false)->assertJsonPath('current_draft', null)->assertJsonPath('recovery.can_restore', true);
    expect($release->fresh()->content)->toEqual($published)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($release->id)
        ->and($this->page->fresh()->draft)->toEqual($published);
    $this->assertDatabaseCount('hub_revisions', 1);
    $this->assertDatabaseCount('hub_draft_discards', 1);
    Notification::assertNothingSent();
});

test('reviewed and scheduled saved draft snapshots remain readable without unlocking or rewriting their immutable versions', function (string $state) {
    $revision = $this->publisher->submit($this->editor, $this->page, $this->context['version']);
    if ($state === 'schedule') {
        $revision = $this->publisher->approve($this->editor, $this->page, $revision, now()->addDay()->toIso8601String(), null);
    }
    $attributes = $revision->fresh()->getAttributes();
    $pageAttributes = $this->page->fresh()->getAttributes();
    $auditCount = AuditLog::count();
    $this->getJson(($this->snapshotUrl)())->assertOk()->assertJsonPath('blocked_reason', $state)->assertJsonPath('can_discard', false)
        ->assertJsonPath('current_draft.version', $this->context['version'])
        ->assertJsonPath('current_draft.content.sections.1.hidden', true)->assertJsonMissingPath('current_draft.revision_id');
    expect($revision->fresh()->getAttributes())->toEqual($attributes)
        ->and($this->page->fresh()->getAttributes())->toEqual($pageAttributes)
        ->and(AuditLog::count())->toBe($auditCount);
    $this->assertDatabaseCount('hub_revisions', 1);
    Notification::assertNothingSent();
})->with(['review', 'schedule']);

test('migration pending recovery does not prevent a read only canonical saved snapshot', function () {
    $schema = Schema::getFacadeRoot();
    Schema::partialMock()->shouldReceive('hasTable')->andReturnUsing(fn ($table) => $table === 'hub_draft_discards' ? false : $schema->hasTable($table));
    Schema::getFacadeRoot()->shouldReceive('hasColumn')->andReturnUsing(fn ($table, $column) => $table === 'hub_pages' && $column === 'draft_discarded_at' ? false : $schema->hasColumn($table, $column));
    $attributes = $this->page->fresh()->getAttributes();
    $result = $this->getJson(($this->snapshotUrl)())->assertOk()
        ->assertJsonPath('blocked_reason', 'unavailable')->assertJsonPath('can_discard', false)
        ->assertJsonPath('current_draft.source', 'draft')->json('current_draft.content');
    expect($result)->toEqual($this->stored)->and(HubPage::findOrFail($this->page->id)->getAttributes())->toEqual($attributes);
    $this->assertDatabaseCount('hub_revisions', 0);
    $this->assertDatabaseCount('hub_draft_discards', 0);
});
