<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubDocumentReads;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
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
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->freezeTime();
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
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Recent work documents', 'blank')->fresh();
    Storage::disk('private')->put('company-hub/recent-work.pdf', "%PDF-1.7\nWork instructions\n");
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/recent-work.pdf', 'name' => 'Work.pdf', 'mime' => 'application/pdf', 'size' => 28, 'version' => 1])->fresh();
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft,
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [['id' => 'files', 'columns' => 1, 'blocks' => [
            ['id' => 'work-file', 'type' => 'file', 'title' => 'Exact workplace document', 'media_id' => $this->media->id],
        ]]],
    ], 1);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->url = '/company-hub/documents/'.$this->media->id.'/read';
    $this->payload = ['actor_id' => $this->reader->id, 'media_id' => $this->media->id, 'revision_id' => $this->revision->id];
    $this->actingAs($this->reader);
});

test('document reading is explicit exact actor bound and repeatable without acknowledgement or page read side effects', function () {
    $this->get('/company-hub/documents/'.$this->media->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('document.actor_id', $this->reader->id)->where('document.revision_id', $this->revision->id));
    $this->get('/company-hub/media/'.$this->media->id.'/preview')->assertOk();
    $this->actingAs($this->editor)->get('/company-hub/documents/'.$this->media->id.'?edition=draft&actor_id='.$this->editor->id.'&page_version=2')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.revision_id', null));
    $this->assertDatabaseCount('hub_interactions', 0);
    $this->actingAs($this->reader)->postJson($this->url, $this->payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['saved' => true, ...$this->payload, 'document_id' => $this->media->id, 'read_at' => now()->toIso8601String()]);
    $first = DB::table('hub_interactions')->sole();
    $this->travel(2)->minutes();
    $this->postJson($this->url, $this->payload)->assertOk();
    $record = DB::table('hub_interactions')->sole();
    expect($record->id)->toBe($first->id)->and($record->created_at)->toBe($first->created_at)
        ->and($record->updated_at)->toBeGreaterThan($first->updated_at)->and($record->body)->toBe((string) $this->media->id)
        ->and($record->kind)->toBe('document_read');
    $this->media->update(['removed_at' => now(), 'removed_by' => $this->editor->id, 'version' => 2]);
    $this->postJson($this->url, $this->payload)->assertOk();
    $other = app(HubFeed::class)->forUser($this->editor);
    expect($other['recentResources'])->toBe([])->and($other['recentDocuments'])->toBe([]);
    $participation = app(HubParticipation::class)->read($this->editor, $this->page);
    expect($participation['comments'])->toBe([])->and($participation['helpful']['count'])->toBe(0);
    $this->assertDatabaseCount('hub_reads', 0);
    $this->assertDatabaseCount('hub_update_seen', 0);
    Notification::assertNothingSent();
});

test('document read receipts reject incomplete actor scope sibling media child identities and unrelated files', function () {
    $this->postJson($this->url, [])->assertUnprocessable()->assertJsonValidationErrors(['actor_id', 'media_id', 'revision_id']);
    $this->postJson($this->url, [...$this->payload, 'actor_id' => $this->editor->id])->assertConflict();
    $this->postJson($this->url, [...$this->payload, 'user_id' => $this->editor->id])->assertUnprocessable();
    $this->postJson($this->url, [...$this->payload, 'edition' => 'draft'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->payload, 'acknowledged' => true])->assertUnprocessable();
    $sibling = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Another.pdf', 'mime' => 'application/pdf', 'size' => 28, 'version' => 1]);
    $this->postJson($this->url, [...$this->payload, 'media_id' => $sibling->id])->assertConflict();
    $this->postJson('/company-hub/documents/'.$sibling->id.'/read', [...$this->payload, 'media_id' => $sibling->id])->assertNotFound();
    $child = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Unpublished-edition.pdf', 'mime' => 'application/pdf', 'size' => 28,
        'version' => 1, 'document_root_id' => $this->media->id, 'document_sequence' => 2]);
    $this->postJson('/company-hub/documents/'.$child->id.'/read', [...$this->payload, 'media_id' => $child->id])->assertNotFound();
    $this->postJson($this->url, [...$this->payload, 'revision_id' => $this->revision->id + 100000])->assertConflict();
    $this->assertDatabaseCount('hub_interactions', 0);
});

test('document reads preserve stable identity through private replacements and reject delayed receipts from previous releases', function () {
    $this->postJson($this->url, $this->payload)->assertOk();
    $first = DB::table('hub_interactions')->sole();
    $next = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Revised.pdf', 'mime' => 'application/pdf', 'size' => 28,
        'version' => 1, 'document_root_id' => $this->media->id, 'document_sequence' => 2]);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][0]['media_id'] = $next->id;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 2);
    $this->postJson($this->url, [...$this->payload, 'media_id' => $next->id])->assertConflict();
    $this->postJson($this->url, $this->payload)->assertOk();
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $before = (array) DB::table('hub_interactions')->sole();
    $this->postJson($this->url, $this->payload)->assertConflict();
    $this->postJson($this->url, [...$this->payload, 'media_id' => $next->id])->assertConflict();
    expect((array) DB::table('hub_interactions')->sole())->toEqual($before);
    $this->travel(2)->minutes();
    $this->postJson($this->url, [...$this->payload, 'media_id' => $next->id, 'revision_id' => $release->id])
        ->assertOk()->assertJsonPath('document_id', $this->media->id)->assertJsonPath('media_id', $next->id);
    expect(DB::table('hub_interactions')->sole()->id)->toBe($first->id)
        ->and(app(HubFeed::class)->forUser($this->reader)['recentDocuments'])->toBe([$this->media->id]);
});

test('document reads recheck retained actor staff permission and approved site access before recording', function () {
    $reads = app(HubDocumentReads::class);
    $actor = $this->reader->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    expect(fn () => $reads->record($actor, $this->media->id, $this->payload))->toThrow(HttpException::class);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'end_date' => today()->subDay()]);
    expect(fn () => $reads->record($actor, $this->media->id, $this->payload))->toThrow(HttpException::class);
    $this->reader->hrEmployeeProfile()->update(['end_date' => null]);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
    expect(fn () => $reads->record($actor, $this->media->id, $this->payload))->toThrow(HttpException::class);
    $this->reader->permissionOverrides()->detach();
    expect($reads->record($actor, $this->media->id, $this->payload)['actor_id'])->toBe($this->reader->id);
    $this->assertDatabaseCount('hub_interactions', 1);
});

test('document reads never accept draft only hidden missing expired or archived published references', function () {
    $private = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Private.pdf', 'mime' => 'application/pdf', 'size' => 28, 'version' => 1]);
    $this->actingAs($this->editor)->postJson('/company-hub/documents/'.$private->id.'/read',
        [...$this->payload, 'actor_id' => $this->editor->id, 'media_id' => $private->id])->assertNotFound();
    $this->actingAs($this->reader);
    Storage::disk('private')->delete($this->media->path);
    $this->postJson($this->url, $this->payload)->assertNotFound();
    Storage::disk('private')->put($this->media->path, '%PDF-1.7 restored fixture');
    $draft = $this->page->draft;
    $draft['sections'][0]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 2);
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->postJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertNotFound();
    $draft['sections'][0]['hidden'] = false;
    $draft['sections'][0]['blocks'][0]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 3);
    $release = $this->publisher->publish($this->editor, $this->page, 4, null, null);
    $this->postJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertNotFound();
    $draft['sections'][0]['blocks'][0]['hidden'] = false;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 4);
    $release = $this->publisher->publish($this->editor, $this->page, 5, null, now()->addMinute()->toIso8601String());
    $this->travel(2)->minutes();
    $this->postJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertNotFound();
    $this->publisher->archive($this->editor, $this->page->fresh(), 5);
    $this->postJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertNotFound();
    $this->assertDatabaseCount('hub_interactions', 0);
});

test('document history pruning is bounded to the current actor and kind while duplicate retries retain one root record', function () {
    $rows = [];
    for ($index = 0; $index < 50; $index++) {
        $rows[] = ['user_id' => $this->reader->id, 'hub_page_id' => $this->page->id, 'kind' => 'document_read',
            'body' => (string) (100000 + $index), 'created_at' => now()->subDays(51 - $index), 'updated_at' => now()->subDays(51 - $index)];
    }
    $rows[] = ['user_id' => $this->editor->id, 'hub_page_id' => $this->page->id, 'kind' => 'document_read',
        'body' => '200000', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()];
    $rows[] = ['user_id' => $this->reader->id, 'hub_page_id' => $this->page->id, 'kind' => 'checklist',
        'body' => 'private-checklist-key', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()];
    DB::table('hub_interactions')->insert($rows);
    $this->postJson($this->url, $this->payload)->assertOk();
    $this->postJson($this->url, $this->payload)->assertOk();
    expect(DB::table('hub_interactions')->where('user_id', $this->reader->id)->where('kind', 'document_read')->count())->toBe(50);
    $this->assertDatabaseMissing('hub_interactions', ['user_id' => $this->reader->id, 'kind' => 'document_read', 'body' => '100000']);
    $this->assertDatabaseHas('hub_interactions', ['user_id' => $this->editor->id, 'kind' => 'document_read', 'body' => '200000']);
    $this->assertDatabaseHas('hub_interactions', ['user_id' => $this->reader->id, 'kind' => 'checklist', 'body' => 'private-checklist-key']);
    expect(DB::table('hub_interactions')->where('user_id', $this->reader->id)->where('kind', 'document_read')->where('body', (string) $this->media->id)->count())->toBe(1);
});

test('resource recents merge exact guide and sibling document chronology and intersect current authorised search results', function () {
    $sibling = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Second.pdf', 'mime' => 'application/pdf', 'size' => 28, 'version' => 1]);
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][] = ['id' => 'second', 'type' => 'file', 'title' => 'Second sibling document', 'media_id' => $sibling->id];
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 2);
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    DB::table('hub_reads')->insert(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id, 'first_read_at' => now()->subMinutes(4), 'last_read_at' => now()->subMinutes(4)]);
    $this->postJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertOk();
    $this->travel(2)->minutes();
    $this->postJson('/company-hub/documents/'.$sibling->id.'/read', [...$this->payload, 'media_id' => $sibling->id, 'revision_id' => $release->id])->assertOk();
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect($feed['recentResources'])->toBe(['media:'.$sibling->id, 'media:'.$this->media->id, 'page:'.$this->page->id])
        ->and($feed['recentDocuments'])->toBe([$sibling->id, $this->media->id])->and($feed['recentPages'])->toBe([$this->page->id]);
    $filtered = app(HubFeed::class)->forUser($this->reader, 'Second sibling document');
    expect($filtered['recentResources'])->toBe(['media:'.$sibling->id])->and($filtered['recentDocuments'])->toBe([$sibling->id]);
    $draft['sections'][0]['blocks'][0]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 3);
    $this->publisher->publish($this->editor, $this->page, 4, null, null);
    expect(app(HubFeed::class)->forUser($this->reader)['recentResources'])->toBe(['media:'.$sibling->id, 'page:'.$this->page->id]);
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->get('/company-hub')->assertForbidden();
});

test('late document read pruning failures roll back inserts and removals after actor and page locks', function () {
    $rows = [];
    for ($index = 0; $index < 50; $index++) {
        $rows[] = ['user_id' => $this->reader->id, 'hub_page_id' => $this->page->id, 'kind' => 'document_read',
            'body' => (string) (100000 + $index), 'created_at' => now()->subDays(51 - $index), 'updated_at' => now()->subDays(51 - $index)];
    }
    DB::table('hub_interactions')->insert($rows);
    $before = DB::table('hub_interactions')->orderBy('id')->get()->toArray();
    $queries = [];
    $fail = true;
    DB::listen(function (QueryExecuted $query) use (&$fail, &$queries): void {
        $queries[] = strtolower($query->sql);
        if ($fail && str_starts_with(strtolower($query->sql), 'delete from `hub_interactions`') && str_contains(strtolower($query->sql), 'not in')) {
            $fail = false;
            throw new RuntimeException('Synthetic private history pruning failure.');
        }
    });
    expect(fn () => app(HubDocumentReads::class)->record($this->reader, $this->media->id, $this->payload))
        ->toThrow(RuntimeException::class, 'Synthetic private history pruning failure.');
    expect(DB::table('hub_interactions')->orderBy('id')->get()->toArray())->toEqual($before);
    $userLock = array_find_key($queries, fn ($sql) => str_contains($sql, 'from `users`') && str_contains($sql, 'for update'));
    $pageLock = array_find_key($queries, fn ($sql) => str_contains($sql, 'from `hub_pages`') && str_contains($sql, 'for update'));
    $insert = array_find_key($queries, fn ($sql) => str_starts_with($sql, 'insert into `hub_interactions`'));
    expect($userLock)->toBeInt()->toBeLessThan($pageLock)->and($pageLock)->toBeInt()->toBeLessThan($insert);
    $this->assertDatabaseCount('hub_reads', 0);
    $this->assertDatabaseCount('hub_update_seen', 0);
    Notification::assertNothingSent();
});
