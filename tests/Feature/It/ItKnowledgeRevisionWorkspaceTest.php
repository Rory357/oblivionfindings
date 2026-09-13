<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Domain\It\Services\ItKnowledgeDiagramSource;
use App\Domain\It\Services\ItKnowledgeFiles;
use App\Domain\It\Services\ItKnowledgeRaster;
use App\Domain\It\Services\ItKnowledgeRelationships;
use App\Domain\It\Services\ItKnowledgeResolutionSources;
use App\Domain\It\Services\ItKnowledgeWorkspace;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use App\Models\ItKbRevision;
use App\Models\ItKbWorkingCopy;
use App\Models\ItProblem;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Tasks\Providers\ItKnowledgeReviewTaskProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function revisionWorkspaceActor(Site $site, string $capability): User
{
    $actor = User::factory()->create(['name' => 'Synthetic Knowledge '.($capability === ItKbAccessService::AUTHOR ? 'author' : 'reviewer'), 'role' => 'support_worker', 'approved_at' => now()]);
    $role = Role::query()->create(['name' => 'kb-revision-fixture-'.str()->uuid(), 'label' => 'Synthetic Knowledge role', 'type' => 'custom', 'level' => 10]);
    $role->permissions()->attach(Permission::query()->where('key', $capability)->pluck('id'));
    $actor->roles()->sync([$role->id]);
    HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null]);

    return $actor;
}

function revisionWorkspaceDocument(User $author, Site $site, array $changes = []): ItKbArticle
{
    return app(ItKbLifecycleService::class)->create($author, array_replace([
        'title' => 'Synthetic recovery runbook', 'category' => 'network', 'body' => 'Approved synthetic recovery instructions.',
        'document_type' => 'runbook', 'structured_content' => revisionWorkspaceRunbookSections(),
        'audience' => 'specific_sites', 'site_scope' => [$site->id], 'owner_user_id' => $author->id,
        'review_due_at' => today()->addMonth()->toDateString(), 'related_records' => [],
    ], $changes));
}

function revisionWorkspaceRunbookSections(array $changes = []): array
{
    return array_replace([
        'symptoms' => 'Synthetic service cannot be reached.',
        'prerequisites' => 'Confirm the approved maintenance window.',
        'procedure' => 'Run the documented synthetic recovery action.',
        'verification' => 'Confirm the synthetic health check passes.',
        'rollback' => 'Restore the last verified configuration.',
        'support_contact' => 'Escalate to the named service owner.',
        'recovery_objectives' => 'Restore synthetic service within the recorded objective.',
    ], $changes);
}

function publishRevisionWorkspaceDocument(ItKbArticle $article, User $author, User $reviewer): ItKbArticle
{
    $service = app(ItKbLifecycleService::class);
    $service->submitForReview($article, $author, ['lock_version' => $article->fresh()->lock_version]);

    return $service->publish($article, $reviewer, ['lock_version' => $article->fresh()->lock_version]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->author = revisionWorkspaceActor($this->site, ItKbAccessService::AUTHOR);
    $this->reviewer = revisionWorkspaceActor($this->site, ItKbAccessService::REVIEW);
    expect(app(ItKbRevisionService::class)->ready())->toBeTrue();
});

test('a reviewed proposed revision replaces the publication only on approval and preserves encrypted history', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site), $this->author, $this->reviewer);
    $originalBody = $article->body;
    $originalVersion = $article->lock_version;
    $first = ItKbRevision::query()->where('article_id', $article->id)->sole();

    $this->actingAs($this->author)->patch("/it/kb/{$article->id}", [
        'actor_user_id' => $this->author->id, 'lock_version' => $originalVersion,
        'body' => 'Synthetic revised recovery instructions.', 'structured_content' => revisionWorkspaceRunbookSections(['recovery_objectives' => 'Revised synthetic objective.']),
    ])->assertRedirect()->assertSessionDoesntHaveErrors()->assertSessionMissing('error');
    expect($article->fresh()->body)->toBe($originalBody)->and($article->fresh()->status)->toBe('published')
        ->and($article->fresh()->lock_version)->toBeGreaterThan($originalVersion);
    $copy = ItKbWorkingCopy::query()->where('article_id', $article->id)->sole();
    expect($copy->snapshot['body'])->toBe('Synthetic revised recovery instructions.');
    expect(DB::table('it_kb_working_copies')->where('id', $copy->id)->value('snapshot'))->not->toContain('Synthetic revised recovery instructions.');

    $this->actingAs($this->author)->post("/it/kb/{$article->id}/submit-review", ['actor_user_id' => $this->author->id, 'lock_version' => $article->fresh()->lock_version])->assertSessionDoesntHaveErrors();
    $this->actingAs($this->author)->post("/it/kb/{$article->id}/publish", ['actor_user_id' => $this->author->id, 'lock_version' => $article->fresh()->lock_version])->assertForbidden();
    $this->actingAs($this->reviewer)->post("/it/kb/{$article->id}/publish", ['actor_user_id' => $this->reviewer->id, 'lock_version' => $article->fresh()->lock_version])->assertSessionDoesntHaveErrors()->assertSessionMissing('error');

    expect($article->fresh()->body)->toBe('Synthetic revised recovery instructions.')
        ->and($article->fresh()->reviewed_by_user_id)->toBe($this->reviewer->id)
        ->and(ItKbWorkingCopy::query()->where('article_id', $article->id)->exists())->toBeFalse()
        ->and($first->fresh()->snapshot['body'])->toBe($originalBody)
        ->and(ItKbRevision::query()->where('article_id', $article->id)->count())->toBe(2);
    expect(DB::table('it_kb_revisions')->where('id', $first->id)->value('snapshot'))->not->toContain($originalBody);
});

test('a stale editor cannot overwrite a newer document or append mutation audit evidence', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $version = $article->lock_version;
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $version, 'body' => 'Newer saved body.']);
    $audits = AuditLog::query()->where('auditable_type', $article->getMorphClass())->where('auditable_id', $article->id)->count();

    $this->actingAs($this->author)->patchJson("/it/kb/{$article->id}", [
        'actor_user_id' => $this->author->id, 'lock_version' => $version, 'body' => 'Stale body must never replace the saved work.',
    ])->assertStatus(409)->assertJsonPath('code', 'stale_knowledge')->assertJsonValidationErrors('lock_version');
    expect($article->fresh()->body)->toBe('Newer saved body.')
        ->and(AuditLog::query()->where('auditable_type', $article->getMorphClass())->where('auditable_id', $article->id)->count())->toBe($audits);
    $this->actingAs($this->author)->getJson("/it/knowledge/{$article->id}/editor-context?actor_user_id={$this->author->id}")
        ->assertOk()->assertJsonPath('article.body', 'Newer saved body.')->assertJsonPath('article.lock_version', $article->fresh()->lock_version)->assertJsonPath('editable', true);
});

test('editor and revision commands reject another originating account without changing the document', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $other = revisionWorkspaceActor($this->site, ItKbAccessService::AUTHOR);
    $this->actingAs($other)->getJson("/it/knowledge/{$article->id}/editor-context?actor_user_id={$this->author->id}")->assertForbidden();
    $this->actingAs($other)->patchJson("/it/kb/{$article->id}", ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'body' => 'Cross-account buffer.'])->assertForbidden();
    $this->actingAs($other)->postJson("/it/kb/{$article->id}/submit-review", ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version])->assertForbidden();
    expect($article->fresh()->status)->toBe('draft')->and($article->fresh()->body)->toBe($article->body);
});

test('losing an original proposal Site conceals its editor and keeps its content out of history', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site, ['audience' => 'all_staff', 'site_scope' => []]), $this->author, $this->reviewer);
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'audience' => 'specific_sites', 'site_scope' => [$this->site->id], 'body' => 'Private proposal canary after Site removal.']);
    $otherSite = Site::factory()->create();
    HrEmployeeProfile::query()->where('user_id', $this->author->id)->firstOrFail()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);

    $this->actingAs($this->author->fresh())->getJson("/it/knowledge/{$article->id}/editor-context?actor_user_id={$this->author->id}")->assertNotFound();
    $this->actingAs($this->author->fresh())->getJson("/it/knowledge/{$article->id}/history?actor_user_id={$this->author->id}")
        ->assertOk()->assertJsonPath('working_copy', null)->assertDontSee('Private proposal canary after Site removal.');
    expect(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['body'])->toBe('Private proposal canary after Site removal.');
});

test('restoring a publication creates a proposal and retains every immutable revision', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site), $this->author, $this->reviewer);
    $original = ItKbRevision::query()->where('article_id', $article->id)->sole();
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'body' => 'Second publication.']);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $this->actingAs($this->author)->post("/it/knowledge/{$article->id}/restore-revision", ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'revision_id' => $original->id])->assertSessionDoesntHaveErrors();

    expect($article->fresh()->body)->toBe('Second publication.')
        ->and(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['body'])->toBe($original->snapshot['body'])
        ->and(ItKbRevision::query()->where('article_id', $article->id)->count())->toBe(2);
    expect(fn () => $original->update(['event' => 'rewritten']))->toThrow(LogicException::class);
    expect($original->fresh()->event)->toBe('published');
});

test('relationship search reaches later canonical pages and rejects references to inaccessible documents', function () {
    $service = ItService::factory()->create(['name' => 'Synthetic canonical pagination service 1']);
    foreach (range(2, 21) as $index) {
        $service->replicate()->forceFill(['key' => 'synthetic-knowledge-page-'.$index, 'name' => 'Synthetic canonical pagination service '.$index])->save();
    }
    $hiddenSite = Site::factory()->create();
    $hidden = ItKbArticle::factory()->create(['audience' => 'specific_sites', 'site_scope' => [$hiddenSite->id], 'title' => 'Hidden canonical document canary']);
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $this->actingAs($this->author)->getJson('/it/knowledge/record-options?type=service&q=Synthetic%20canonical%20pagination&page=1')->assertOk()->assertJsonCount(20, 'records')->assertJsonPath('has_more', true);
    $this->actingAs($this->author)->getJson('/it/knowledge/record-options?type=service&q=Synthetic%20canonical%20pagination&page=2')->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('has_more', false);
    $this->actingAs($this->author)->from('/it/knowledge')->patch("/it/kb/{$article->id}", ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'related_records' => [['type' => 'article', 'id' => $hidden->id, 'relation' => 'depends_on']]])
        ->assertSessionHasErrors('knowledge_relationship_access')->assertDontSee('Hidden canonical document canary');
    expect($article->fresh()->related_records)->toBe([]);
});

test('revision calendar dates are usable in editors and history without rewriting older snapshots', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site, ['review_due_at' => '2026-10-12']), $this->author, $this->reviewer);
    $first = ItKbRevision::query()->where('article_id', $article->id)->sole();
    expect($first->snapshot['review_due_at'])->toBe('2026-10-12');

    $legacyContent = [...$first->snapshot, 'review_due_at' => '2026-10-12T00:00:00.000000Z'];
    $legacy = ItKbRevision::query()->create([
        'article_id' => $article->id, 'revision_number' => 2, 'audience' => $article->audience,
        'site_scope' => $article->site_scope, 'snapshot' => $legacyContent, 'recorded_by_user_id' => $this->reviewer->id,
        'event' => 'published', 'published_at' => now(), 'created_at' => now(),
    ]);
    $copy = ItKbWorkingCopy::query()->create([
        'article_id' => $article->id, 'status' => 'draft', 'audience' => $article->audience, 'site_scope' => $article->site_scope,
        'snapshot' => $legacyContent, 'author_user_id' => $this->author->id, 'lock_version' => 1,
    ]);
    $originalCiphertext = DB::table('it_kb_revisions')->where('id', $legacy->id)->value('snapshot');
    $originalCopy = DB::table('it_kb_working_copies')->where('id', $copy->id)->value('snapshot');

    $this->actingAs($this->author)->getJson("/it/knowledge/{$article->id}/editor-context?actor_user_id={$this->author->id}")
        ->assertOk()->assertJsonPath('article.working_copy.content.review_due_at', '2026-10-12');
    $this->actingAs($this->reviewer)->getJson("/it/knowledge/{$article->id}/history?actor_user_id={$this->reviewer->id}")
        ->assertOk()->assertJsonPath('current_content.review_due_at', '2026-10-12')
        ->assertJsonPath('working_copy.content.review_due_at', '2026-10-12')
        ->assertJsonPath('revisions.0.content.review_due_at', '2026-10-12');
    expect(DB::table('it_kb_revisions')->where('id', $legacy->id)->value('snapshot'))->toBe($originalCiphertext)
        ->and(DB::table('it_kb_working_copies')->where('id', $copy->id)->value('snapshot'))->toBe($originalCopy);

    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'body' => 'Edited legacy proposal.']);
    expect($copy->fresh()->snapshot['review_due_at'])->toBe('2026-10-12')
        ->and(DB::table('it_kb_revisions')->where('id', $legacy->id)->value('snapshot'))->toBe($originalCiphertext);
});

test('library pagination retains filters without reopening the selected reader', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site);
    ItKbArticle::factory()->count(25)->create([
        'title' => 'Synthetic paginated document', 'category' => 'network', 'status' => 'draft',
        'audience' => 'specific_sites', 'site_scope' => [$this->site->id], 'owner_user_id' => $this->author->id,
    ]);
    $this->actingAs($this->author)->get("/it/knowledge?q=Synthetic&category=network&list_view=cards&article={$article->id}&page=2")
        ->assertRedirect('/it/knowledge/'.$article->id.'?'.http_build_query(['library' => 'q=Synthetic&category=network&list_view=cards&page=2']));
    $this->get('/it/knowledge?q=Synthetic&category=network&list_view=cards&page=2')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('knowledgePage.current_page', 2)
        ->where('knowledgePage.total', 26)
        ->where('knowledgePage.links', fn ($links) => collect($links)->filter(fn ($link) => $link['url'] !== null)->every(function ($link) {
            parse_str(parse_url($link['url'], PHP_URL_QUERY), $query);

            return ! array_key_exists('article', $query) && $query['q'] === 'Synthetic' && $query['category'] === 'network' && $query['list_view'] === 'cards';
        })));
});

test('library metadata omits large document and proposal content until a permitted document is opened', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site, [
        'body' => str_repeat('Published body canary. ', 3000),
    ]), $this->author, $this->reviewer);
    app(ItKbLifecycleService::class)->update($article, $this->author, [
        'lock_version' => $article->fresh()->lock_version,
        'body' => str_repeat('Unpublished proposal canary. ', 3000),
    ]);
    $workspace = app(ItKnowledgeWorkspace::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $library = $workspace->forUser($this->author, Request::create('/it/knowledge'));
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $row = collect($library['kbArticles'])->firstWhere('id', $article->id);
    expect($row['body'])->toBeNull()
        ->and($row['structured_content'])->toBeNull()
        ->and($row['content_loaded'])->toBeFalse()
        ->and($row['working_copy'])->toBe(['status' => 'draft', 'content' => []])
        ->and($row['can']['edit'])->toBeTrue()
        ->and(strlen(json_encode($library, JSON_THROW_ON_ERROR)))->toBeLessThan(12000);
    $pageQuery = $queries->first(fn ($sql) => str_contains($sql, 'from `it_kb_articles`') && str_contains($sql, 'limit 24'));
    expect($pageQuery)->toBeString()->not->toContain('`body`', '`structured_content`', '`related_records`', 'select *');
    $copyQuery = $queries->first(fn ($sql) => str_contains($sql, 'from `it_kb_working_copies`') && ! str_contains($sql, 'exists'));
    expect($copyQuery)->toBeString()->not->toContain('`snapshot`', 'select *');

    $opened = $workspace->forUser($this->author, Request::create('/it/knowledge', 'GET', ['article' => $article->id]));
    expect($opened['selectedKbArticle']['content_loaded'])->toBeTrue()
        ->and($opened['selectedKbArticle']['body'])->toBe($article->fresh()->body);
    $this->actingAs($this->author)->getJson("/it/knowledge/{$article->id}/editor-context?actor_user_id={$this->author->id}")
        ->assertOk()->assertJsonPath('article.content_loaded', true)
        ->assertJsonPath('article.working_copy.content.body', str_repeat('Unpublished proposal canary. ', 3000));
});

test('full document pages keep native return context and deny an unapproved Site', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $context = 'q=network&category=network&page=2&list_view=cards';
    $this->actingAs($this->author)->get('/it/knowledge/'.$article->id.'?'.http_build_query(['library' => $context]))
        ->assertOk()->assertInertia(fn ($page) => $page->component('it/knowledge/show')
        ->where('article.id', $article->id)->where('article.body', $article->body)
        ->where('returnHref', '/it/knowledge?'.$context)->where('article.media_ready', true));
    $other = revisionWorkspaceActor(Site::factory()->create(), ItKbAccessService::AUTHOR);
    $this->actingAs($other)->get('/it/knowledge/'.$article->id)->assertNotFound();
    $this->actingAs($this->author)->get('/it/knowledge/'.$article->id.'?'.http_build_query(['library' => 'redirect=https://outside.invalid&title[]=bad&page=2']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('returnHref', '/it/knowledge?page=2'));
});

test('credential relationships expose only currently permitted masked metadata', function () {
    $this->author->roles()->first()->permissions()->attach(Permission::query()->where('key', 'credentials.view')->sole()->id);
    $actor = $this->author->fresh();
    $credential = SiteCredential::query()->create([
        'site_id' => $this->site->id, 'label' => 'Synthetic router credential', 'credential_type' => 'wifi',
        'username' => 'private-user-canary', 'notes' => 'private-notes-canary',
        'encrypted_value' => 'opaque-ciphertext-canary', 'iv' => 'opaque-iv',
        'totp_secret_encrypted' => 'opaque-totp-canary', 'house_staff_access' => false,
    ]);
    $relationships = app(ItKnowledgeRelationships::class);
    $reference = ['type' => 'credential', 'id' => $credential->id, 'relation' => 'depends_on'];
    $resolved = $relationships->resolve($actor, [$reference]);
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['label'])->toBe('Synthetic router credential')
        ->and($resolved[0]['href'])->toBe('/vendors?tab=credentials&credential_id='.$credential->id)
        ->and(json_encode($resolved))->not->toContain('private-user-canary', 'private-notes-canary', 'opaque-ciphertext-canary', 'opaque-totp-canary');
    $other = revisionWorkspaceActor(Site::factory()->create(), ItKbAccessService::AUTHOR);
    $other->roles()->first()->permissions()->attach(Permission::query()->where('key', 'credentials.view')->sole()->id);
    expect($relationships->resolve($other->fresh(), [$reference]))->toBeEmpty();
    $credential->update(['retired_at' => now()]);
    expect($relationships->resolve($actor, [$reference]))->toBeEmpty();
});

function knowledgeMediaDiagram(string $title = 'Recovery topology'): array
{
    $first = (string) str()->uuid();
    $second = (string) str()->uuid();

    return ['id' => (string) str()->uuid(), 'title' => $title,
        'nodes' => [['id' => $first, 'shape' => 'rectangle', 'text' => 'Router', 'x' => 20, 'y' => 40],
            ['id' => $second, 'shape' => 'ellipse', 'text' => 'Service', 'x' => 400, 'y' => 40]],
        'edges' => [['id' => (string) str()->uuid(), 'from' => $first, 'to' => $second, 'label' => 'Connects']]];
}

test('diagram edits retain saved source versions and reach readers only after publication', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site), $this->author, $this->reviewer);
    $initial = ItKbRevision::query()->where('article_id', $article->id)->sole();
    $diagram = knowledgeMediaDiagram();
    $this->actingAs($this->author)->patch('/it/kb/'.$article->id, ['lock_version' => $article->lock_version, 'diagrams' => [$diagram]])->assertSessionDoesntHaveErrors();
    expect($article->fresh()->diagrams)->toBeNull()
        ->and(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['diagrams'])->toBe([$diagram]);
    $diagram['nodes'][0]['text'] = 'Revised router';
    $this->patch('/it/kb/'.$article->id, ['lock_version' => $article->fresh()->lock_version, 'diagrams' => [$diagram]])->assertSessionDoesntHaveErrors();
    $drafts = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'media_draft_saved')->orderBy('id')->get();
    expect($drafts)->toHaveCount(2)->and($drafts[0]->snapshot['diagrams'][0]['nodes'][0]['text'])->toBe('Router');
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    expect($article->diagrams)->toEqual([$diagram]);
    app(ItKbLifecycleService::class)->restoreRevision($article, $this->author, $initial->id, $article->lock_version);
    expect(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['diagrams'])->toBeEmpty()
        ->and($article->fresh()->diagrams)->toEqual([$diagram]);
});

test('diagram validation rejects active content invalid geometry and forged connectors atomically', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $valid = knowledgeMediaDiagram();
    $invalid = $valid;
    $invalid['nodes'][0]['shape'] = '<svg onload=alert(1)>';
    $this->actingAs($this->author)->patchJson('/it/kb/'.$article->id, ['lock_version' => $article->lock_version, 'diagrams' => [$invalid]])->assertUnprocessable();
    $invalid = $valid;
    $invalid['nodes'][0]['x'] = 90000;
    $this->patchJson('/it/kb/'.$article->id, ['lock_version' => $article->lock_version, 'diagrams' => [$invalid]])->assertUnprocessable();
    $invalid = $valid;
    $invalid['edges'][0]['to'] = (string) str()->uuid();
    expect(fn () => app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'diagrams' => [$invalid]]))->toThrow(ValidationException::class);
    expect($article->fresh()->diagrams)->toBeNull()->and($article->fresh()->lock_version)->toBe($article->lock_version);
});

test('guessed historical file references require access to the original scope before saving', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site, ['audience' => 'all_staff', 'site_scope' => []]);
    $restrictedSite = Site::factory()->create();
    $file = ItKbFile::query()->create([
        'article_id' => $article->id, 'series_id' => (string) str()->uuid(), 'version' => 1,
        'name' => 'Restricted original.pdf', 'mime' => 'application/pdf', 'size' => 12,
        'sha256' => hash('sha256', 'synthetic'), 'path' => 'it_knowledge/'.str()->uuid(),
        'state' => 'ready', 'audience' => 'specific_sites', 'site_scope' => [$restrictedSite->id],
        'uploaded_by_user_id' => $this->author->id, 'created_at' => now(),
    ]);
    expect(fn () => app(ItKbLifecycleService::class)->update($article, $this->author, [
        'lock_version' => $article->lock_version, 'file_ids' => [$file->id],
    ]))->toThrow(DomainException::class);
    expect($article->fresh()->file_ids)->toBeNull()
        ->and($article->fresh()->lock_version)->toBe($article->lock_version)
        ->and(ItKbRevision::query()->where('article_id', $article->id)->count())->toBe(0);
    $this->actingAs($this->author)->get('/it/knowledge/'.$article->id.'/files/'.$file->id)->assertNotFound();
});

test('file history is paged after original scope filtering without decrypting revision bodies', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site, ['audience' => 'all_staff', 'site_scope' => []]);
    $restrictedSite = Site::factory()->create();
    $files = [];
    DB::transaction(function () use ($article, $restrictedSite, &$files): void {
        ItKbArticle::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
        foreach (range(1, 25) as $number) {
            $sites = [$number === 24 ? $restrictedSite->id : $this->site->id];
            $files[$number] = ItKbFile::query()->create([
                'article_id' => $article->id, 'series_id' => (string) str()->uuid(), 'version' => 1,
                'name' => 'Synthetic history '.$number.'.pdf', 'mime' => 'application/pdf', 'size' => 12,
                'sha256' => hash('sha256', 'synthetic-'.$number), 'path' => 'it_knowledge/'.str()->uuid(),
                'state' => 'ready', 'audience' => 'specific_sites', 'site_scope' => $sites,
                'uploaded_by_user_id' => $this->author->id, 'created_at' => now(),
            ]);
            $revisions = app(ItKbRevisionService::class);
            $revisions->recordSnapshot($article, $this->author, 'media_draft_saved', [
                ...$revisions->content($article), 'audience' => 'specific_sites', 'site_scope' => $sites,
                'file_ids' => [$files[$number]->id], 'body' => str_repeat('Large historical body. ', 3000),
            ]);
        }
        $article->update(['file_ids' => [$files[25]->id]]);
    });
    DB::enableQueryLog();
    try {
        $response = $this->actingAs($this->author)->getJson('/it/knowledge/'.$article->id.'/files/history')
            ->assertOk()->assertJsonCount(20, 'files')->assertJsonPath('next_before_id', $files[4]->id);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(array_column($response->json('files'), 'id'))->not->toContain($files[24]->id, $files[25]->id)
        ->and(strlen($response->getContent()))->toBeLessThan(10000);
    $historyQueries = $queries->filter(fn ($query) => str_contains($query, '`it_kb_revisions`'));
    expect($historyQueries)->not->toBeEmpty();
    foreach ($historyQueries as $query) {
        expect($query)->not->toContain('select *', '`snapshot`');
    }
    $this->getJson('/it/knowledge/'.$article->id.'/files/history?before_id='.$files[4]->id)
        ->assertOk()->assertJsonCount(3, 'files')->assertJsonPath('next_before_id', null);
    expect(app(ItKnowledgeFiles::class)->presentation($this->author, $article->fresh()))->toHaveCount(1);
});

test('private PDF versions retain history and enforce current document access on preview and originals', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-test'));
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $upload = fn () => UploadedFile::fake()->createWithContent('recovery.pdf', "%PDF-1.4\nSynthetic private test PDF\n%%EOF");
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'file' => $upload()])->assertSessionDoesntHaveErrors();
    $first = ItKbFile::query()->where('article_id', $article->id)->sole();
    expect($article->fresh()->file_ids)->toBe([$first->id]);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $this->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'replace_file_id' => $first->id, 'file' => $upload()])->assertSessionDoesntHaveErrors();
    $replacement = ItKbFile::query()->where('article_id', $article->id)->latest('id')->first();
    expect($replacement->series_id)->toBe($first->series_id)->and($replacement->version)->toBe(2)->and($article->fresh()->file_ids)->toBe([$first->id]);
    $base = '/it/knowledge/'.$article->id.'/files/';
    $this->get($base.$replacement->id)->assertOk()->assertInertia(fn ($page) => $page->component('it/knowledge/file')->where('file.pdf_href', $base.$replacement->id.'?inline=1'));
    $this->get($base.$replacement->id.'?'.http_build_query(['library' => 'q=recovery&page=2&list_view=cards&redirect=https://outside.invalid']))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('document.library_href', '/it/knowledge?q=recovery&page=2&list_view=cards')
        ->where('document.files_href', '/it/knowledge/'.$article->id.'?'.http_build_query(['library' => 'q=recovery&page=2&list_view=cards', 'section' => 'files'])));
    $this->get($base.$first->id.'?original=1')->assertOk()->assertDownload('recovery.pdf');
    $this->get($base.$first->id.'?inline=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $other = revisionWorkspaceActor(Site::factory()->create(), ItKbAccessService::AUTHOR);
    foreach (['', '?original=1', '?inline=1'] as $mode) {
        $this->actingAs($other)->get($base.$first->id.$mode)->assertNotFound();
    }
    $this->actingAs($this->reviewer)->getJson('/it/knowledge/'.$article->id.'/history')->assertOk()->assertJsonPath('working_copy.content.file_ids', [$replacement->id]);
});

test('Word uploads have a safe text preview and legacy Word has an explicit original fallback', function () {
    Storage::fake(ItAttachment::DISK);
    $disk = Storage::disk(ItAttachment::DISK);
    $disk->put('synthetic.docx', '');
    $zip = new ZipArchive;
    $zip->open($disk->path('synthetic.docx'), ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Safe Word preview &lt;script&gt; is text</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-test'));
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version,
        'file' => UploadedFile::fake()->createWithContent('recovery.docx', $disk->get('synthetic.docx'))])->assertSessionDoesntHaveErrors();
    $file = ItKbFile::query()->where('article_id', $article->id)->sole();
    $this->get('/it/knowledge/'.$article->id.'/files/'.$file->id)->assertOk()->assertInertia(fn ($page) => $page->where('file.text', 'Safe Word preview <script> is text')->where('file.pdf_href', null));
    $legacyUpload = UploadedFile::fake()->create('legacy.doc', 1, 'application/msword');
    // Laravel's sized fake reports 1 KiB but stores no bytes. Keep the actual
    // fixture consistent so the download's size/hash integrity guard is tested.
    file_put_contents($legacyUpload->getRealPath(), str_repeat("\0", 1024));
    $this->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->fresh()->lock_version,
        'file' => $legacyUpload])->assertSessionDoesntHaveErrors();
    $legacy = ItKbFile::query()->where('article_id', $article->id)->latest('id')->first();
    $this->get('/it/knowledge/'.$article->id.'/files/'.$legacy->id)->assertOk()->assertInertia(fn ($page) => $page->where('file.text', null)->where('file.pdf_href', null)->where('file.original_href', '/it/knowledge/'.$article->id.'/files/'.$legacy->id.'?original=1'));
});

test('removing a media draft archives it and restoration retains its files and immutable revisions', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-test'));
    $diagram = knowledgeMediaDiagram();
    $article = revisionWorkspaceDocument($this->author, $this->site, ['diagrams' => [$diagram]]);
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', [
        'actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version,
        'file' => UploadedFile::fake()->createWithContent('retained.pdf', "%PDF-1.4\nSynthetic retained file\n%%EOF"),
    ])->assertSessionDoesntHaveErrors();
    $article->refresh();
    $file = ItKbFile::query()->where('article_id', $article->id)->sole();
    $revisions = ItKbRevision::query()->where('article_id', $article->id)->orderBy('id')->get();
    $version = $article->lock_version;

    $this->delete('/it/kb/'.$article->id, ['actor_user_id' => $this->author->id, 'lock_version' => $version, 'reason' => 'Duplicate working draft.'])->assertSessionDoesntHaveErrors();
    expect($article->fresh()->status)->toBe('retired')
        ->and($article->fresh()->retirement_reason)->toBe('Duplicate working draft.')
        ->and($article->fresh()->lock_version)->toBe($version + 1)
        ->and($article->fresh()->file_ids)->toBe([$file->id])
        ->and($article->fresh()->diagrams)->toEqual([$diagram])
        ->and(ItKbRevision::query()->where('article_id', $article->id)->count())->toBe($revisions->count() + 1);
    Storage::disk(ItAttachment::DISK)->assertExists($file->path);
    foreach ($revisions as $revision) {
        expect($revision->fresh()->snapshot)->toBe($revision->snapshot);
    }
    $this->post('/it/kb/'.$article->id.'/restore', ['actor_user_id' => $this->author->id, 'lock_version' => $article->fresh()->lock_version])->assertSessionDoesntHaveErrors();
    expect($article->fresh()->status)->toBe('draft')->and($article->fresh()->file_ids)->toBe([$file->id]);
    $this->get('/it/knowledge/'.$article->id.'/files/'.$file->id.'?original=1')->assertOk()->assertDownload('retained.pdf');
});

test('an unfinished upload resumes once and replays without creating another file revision', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(
        new MalwareScanResult(MalwareScanDisposition::Unavailable, 'synthetic-test'),
        new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-test'),
    );
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $uuid = (string) str()->uuid();
    $upload = fn () => UploadedFile::fake()->createWithContent('retry.pdf', "%PDF-1.4\nSynthetic retry file\n%%EOF");
    $payload = ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'request_uuid' => $uuid];
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', [...$payload, 'file' => $upload()])->assertSessionHasErrors('file');
    $file = ItKbFile::query()->where('article_id', $article->id)->sole();
    $this->getJson('/it/knowledge/'.$article->id.'/files/unfinished')->assertOk()->assertJsonCount(1, 'files')->assertJsonPath('files.0.can_retry', true)->assertDontSee($file->path);
    $this->postJson('/it/knowledge/'.$article->id.'/files/'.$file->id.'/retry', $payload)->assertOk()->assertJsonPath('state', 'ready');
    $revisionCount = ItKbRevision::query()->where('article_id', $article->id)->count();
    $this->postJson('/it/knowledge/'.$article->id.'/files/'.$file->id.'/retry', $payload)->assertOk();
    $this->post('/it/knowledge/'.$article->id.'/files', [...$payload, 'file' => $upload()])->assertSessionDoesntHaveErrors();
    expect(ItKbFile::query()->where('article_id', $article->id)->count())->toBe(1)
        ->and($article->fresh()->file_ids)->toBe([$file->id])
        ->and(ItKbRevision::query()->where('article_id', $article->id)->count())->toBe($revisionCount)
        ->and(AuditLog::query()->where('action', 'it.knowledge.file.added')->where('auditable_id', $article->id)->count())->toBe(1);
    $this->getJson('/it/knowledge/'.$article->id.'/files/unfinished')->assertOk()->assertJsonCount(0, 'files');
    $this->postJson('/it/knowledge/'.$article->id.'/files', [...$payload, 'file' => UploadedFile::fake()->createWithContent('retry.pdf', "%PDF-1.4\nDifferent bytes\n%%EOF")])->assertConflict();
});

test('unfinished upload recovery rejects other authors revoked Sites stale drafts and damaged bytes', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'synthetic-test'));
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $payload = ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version];
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', [...$payload,
        'file' => UploadedFile::fake()->createWithContent('private-retry.pdf', "%PDF-1.4\nPrivate retry\n%%EOF")])->assertSessionHasErrors('file');
    $file = ItKbFile::query()->where('article_id', $article->id)->sole();
    $retry = '/it/knowledge/'.$article->id.'/files/'.$file->id.'/retry';
    $other = revisionWorkspaceActor($this->site, ItKbAccessService::AUTHOR);
    $this->actingAs($other)->getJson('/it/knowledge/'.$article->id.'/files/unfinished')->assertOk()->assertJsonCount(0, 'files');
    $this->postJson($retry, ['actor_user_id' => $other->id, 'lock_version' => $article->lock_version])->assertNotFound();
    $profile = HrEmployeeProfile::query()->where('user_id', $this->author->id)->sole();
    $profile->update(['primary_site_id' => Site::factory()->create()->id]);
    $this->actingAs($this->author->fresh())->postJson($retry, $payload)->assertNotFound();
    $profile->update(['primary_site_id' => $this->site->id]);
    $this->actingAs($this->author->fresh());
    Storage::disk(ItAttachment::DISK)->put($file->path, 'Damaged bytes');
    $this->postJson($retry, $payload)->assertUnprocessable()->assertJsonValidationErrors('file');
    expect($file->fresh()->state)->toBe('integrity_failed')->and($article->fresh()->file_ids)->toBeNull();
    $this->get('/it/knowledge/'.$article->id.'/files/'.$file->id.'?original=1')->assertNotFound();
    $dismiss = '/it/knowledge/'.$article->id.'/files/'.$file->id.'/dismiss';
    $this->postJson($dismiss, $payload)->assertOk();
    $this->postJson($dismiss, $payload)->assertOk();
    Storage::disk(ItAttachment::DISK)->assertExists($file->path);
    expect($file->fresh()->state)->toBe('abandoned');
    $this->postJson($retry, $payload)->assertUnprocessable();

    $stale = $file->replicate()->forceFill(['path' => 'it_knowledge/'.str()->uuid(), 'series_id' => (string) str()->uuid(), 'request_uuid' => null, 'state' => 'reserved', 'created_at' => now()]);
    $stale->save();
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'body' => 'Newer draft']);
    $this->getJson('/it/knowledge/'.$article->id.'/files/unfinished')->assertOk()->assertJsonPath('files.0.can_retry', false);
    $this->postJson('/it/knowledge/'.$article->id.'/files/'.$stale->id.'/retry', $payload)->assertUnprocessable();
    expect($article->fresh()->body)->toBe('Newer draft')->and($article->fresh()->file_ids)->toBeNull();
});

test('unavailable malware scanning cannot expose a file or change the document draft', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'synthetic-test'));
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version,
        'file' => UploadedFile::fake()->createWithContent('recovery.pdf', "%PDF-1.4\nSynthetic blocked file\n%%EOF")])->assertSessionHasErrors('file');
    $file = ItKbFile::query()->where('article_id', $article->id)->sole();
    expect($file->state)->toBe('scan_unavailable')->and($article->fresh()->file_ids)->toBeNull()->and($article->fresh()->lock_version)->toBe($article->lock_version);
    $this->get('/it/knowledge/'.$article->id.'/files/'.$file->id.'?original=1')->assertNotFound();
});

test('typed documents retain incomplete drafts but cannot submit or publish missing required guidance', function () {
    $article = revisionWorkspaceDocument($this->author, $this->site, ['structured_content' => ['symptoms' => 'Incomplete synthetic runbook.']]);
    expect($article->status)->toBe('draft');
    $this->actingAs($this->author)->post("/it/kb/{$article->id}/submit-review", ['lock_version' => $article->lock_version])
        ->assertRedirect()->assertSessionHas('error');
    expect($article->fresh()->status)->toBe('draft');
    $row = app(ItKnowledgeWorkspace::class)->rows(new Collection([$article]), $this->author, true, true)[0];
    expect(collect($row['document_issues'])->pluck('field')->filter()->all())->toContain('verification', 'rollback', 'support_contact');
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'structured_content' => revisionWorkspaceRunbookSections()]);
    expect(publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer)->status)->toBe('published');
});

test('tags and structured searches use the visible publication and restored revisions retain their original tags', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site, [
        'tags' => [' Email ', 'email', 'Onboarding'],
        'structured_content' => revisionWorkspaceRunbookSections(['verification' => 'Unique structured needle ABCDEF.']),
    ]), $this->author, $this->reviewer);
    expect($article->tags)->toBe(['Email', 'Onboarding']);
    $original = ItKbRevision::query()->where('article_id', $article->id)->sole();
    $hiddenSite = Site::factory()->create();
    ItKbArticle::factory()->published()->create(['title' => 'Hidden tagged document canary', 'tags' => ['Email'], 'audience' => 'specific_sites', 'site_scope' => [$hiddenSite->id]]);
    $workspace = app(ItKnowledgeWorkspace::class);
    foreach ([['q' => 'ABCDEF'], ['tag' => 'Email']] as $filters) {
        $result = $workspace->forUser($this->author, Request::create('/it/knowledge', 'GET', $filters));
        expect($result['knowledgePage']['total'])->toBe(1)->and($result['kbArticles'][0]['id'])->toBe($article->id);
    }
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'tags' => ['Proposal-only canary']]);
    expect($workspace->forUser($this->author, Request::create('/it/knowledge', 'GET', ['q' => 'Proposal-only canary']))['knowledgePage']['total'])->toBe(0);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    app(ItKbLifecycleService::class)->restoreRevision($article, $this->author, $original->id, $article->lock_version);
    expect(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['tags'])->toBe(['Email', 'Onboarding'])
        ->and($article->fresh()->tags)->toBe(['Proposal-only canary']);
});

test('inactive permitted relationships remain labelled while pickers and new writes exclude them', function () {
    $service = ItService::factory()->create(['name' => 'Synthetic retired dependency', 'is_active' => true]);
    $article = revisionWorkspaceDocument($this->author, $this->site, ['related_records' => [['type' => 'service', 'id' => $service->id]]]);
    $service->update(['is_active' => false]);
    $relationships = app(ItKnowledgeRelationships::class);
    expect($relationships->resolve($this->author, $article->related_records)[0]['inactive'])->toBeTrue()
        ->and($relationships->options($this->author, 'service', 'Synthetic retired dependency')['records'])->toBe([]);
    expect(fn () => $relationships->normalise($this->author, $article->related_records))->toThrow(DomainException::class);
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'related_records' => []]);
    expect($article->fresh()->related_records)->toBe([]);
});

test('explicit solved feedback is distinct from helpful votes, idempotent and tied to the publication', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site), $this->author, $this->reviewer);
    $revision = ItKbRevision::query()->where('article_id', $article->id)->sole();
    $payload = ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'helpful' => true];
    $this->actingAs($this->author)->post("/it/kb/{$article->id}/helpful", $payload)->assertRedirect();
    expect($article->fresh()->helpful_yes)->toBe(1)->and($article->fresh()->deflection_count)->toBe(0)
        ->and($article->interactions()->where('event_type', 'solved')->count())->toBe(0);
    foreach (range(1, 2) as $attempt) {
        $this->actingAs($this->author)->post("/it/kb/{$article->id}/helpful", [...$payload, 'solved' => true])->assertRedirect();
    }
    $solved = $article->interactions()->where('event_type', 'solved')->sole();
    expect($solved->context['revision_id'])->toBe($revision->id)->and($article->fresh()->helpful_yes)->toBe(1);
    $row = app(ItKnowledgeWorkspace::class)->rows(new Collection([$article->fresh()]), $this->author, true, true)[0];
    expect($row['user_vote'])->toBeTrue()->and($row['user_solved'])->toBeTrue()->and($row['confirmed_solved'])->toBe(1)->and($row['deflections'])->toBe(0);
    $this->actingAs($this->reviewer)->postJson("/it/kb/{$article->id}/helpful", [...$payload, 'solved' => true])->assertForbidden();
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'body' => 'New proposal.']);
    $this->actingAs($this->reviewer)->postJson("/it/kb/{$article->id}/helpful", [...$payload, 'actor_user_id' => $this->reviewer->id, 'solved' => true])->assertStatus(409);
    expect($article->interactions()->where('event_type', 'solved')->count())->toBe(1);
});

test('document review reminders reach their owner and remain until the new review date is published', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site, ['review_due_at' => today()->subDay()->toDateString()]), $this->author, $this->reviewer);
    $provider = new ItKnowledgeReviewTaskProvider;
    expect($provider->authorizedTasks($this->author))->toHaveCount(1)->and($provider->authorizedTasks($this->reviewer))->toBe([]);
    app(ItKbLifecycleService::class)->recordView($article, $this->author);
    expect($provider->authorizedTasks($this->author))->toHaveCount(1);
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'review_due_at' => today()->addMonth()->toDateString()]);
    expect($provider->authorizedTasks($this->author))->toHaveCount(1);
    publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    expect($provider->authorizedTasks($this->author))->toBe([]);
    $article->refresh()->update(['review_due_at' => today()->subDay()]);
    HrEmployeeProfile::query()->where('user_id', $this->author->id)->update(['primary_site_id' => Site::factory()->create()->id, 'secondary_site_ids' => json_encode([])]);
    expect($provider->authorizedTasks($this->author->fresh()))->toBe([]);
});

test('resolution sources copy no private text and link the first immutable publication back to the canonical ticket', function () {
    $ticket = ItTicket::factory()->resolved()->create([
        'requester_user_id' => $this->author->id, 'site_id' => $this->site->id, 'is_sensitive' => false,
        'title' => 'Private person title canary', 'description' => 'Private conversation canary',
        'resolution_summary' => 'Public summary with incidental personal details canary',
        'resolution_verification' => 'Internal verification password canary',
    ]);
    $sources = app(ItKnowledgeResolutionSources::class);
    $draft = $sources->prepare($this->author, $ticket);
    expect($draft['body'])->toBe('')->and(json_encode($draft))->not->toContain('canary');
    $this->actingAs($this->author)->get('/it/knowledge/from-resolution/'.$ticket->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.source_ticket_id', $ticket->id)->where('draft.body', ''))->assertDontSee('canary');
    $article = revisionWorkspaceDocument($this->author, $this->site, [...$draft, 'body' => 'Reviewed reusable procedure without personal details.']);
    expect($article->interactions()->where('event_type', 'resolution_source')->sole()->it_ticket_id)->toBe($ticket->id);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $first = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'published')->sole();
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'body' => 'A later approved procedure.']);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $linked = $sources->forTicket($this->author, $ticket)['records'];
    expect($linked)->toHaveCount(1)->and($linked[0]['href'])->toBe('/it/knowledge/'.$article->id.'?revision='.$first->id);
    $this->get($linked[0]['href'])->assertOk()->assertInertia(fn ($page) => $page
        ->where('article.body', 'Reviewed reusable procedure without personal details.')
        ->where('article.can.edit', false)->where('article.working_copy', null)->where('viewingRevision.id', $first->id));
    expect($sources->forArticle($this->reviewer, $article))->toBe([]);
});

test('stale sensitive or unavailable source tickets cannot create a linked knowledge draft', function () {
    $ticket = ItTicket::factory()->resolved()->create(['requester_user_id' => $this->author->id, 'site_id' => $this->site->id, 'is_sensitive' => false]);
    $sources = app(ItKnowledgeResolutionSources::class);
    $draft = $sources->prepare($this->author, $ticket);
    $count = ItKbArticle::query()->count();
    $ticket->forceFill(['lock_version' => $ticket->lock_version + 1])->save();
    expect(fn () => revisionWorkspaceDocument($this->author, $this->site, $draft))->toThrow(DomainException::class);
    expect(ItKbArticle::query()->count())->toBe($count);
    $ticket->update(['is_sensitive' => true]);
    $this->actingAs($this->author)->get('/it/knowledge/from-resolution/'.$ticket->id)->assertStatus(409);
    $this->actingAs($this->reviewer)->getJson('/it/knowledge/resolution-documents/'.$ticket->id)->assertNotFound();
});

test('immutable publication links enforce current and original audiences and reject draft revisions', function () {
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site), $this->author, $this->reviewer);
    $first = ItKbRevision::query()->where('article_id', $article->id)->sole();
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'audience' => 'all_staff', 'site_scope' => []]);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $other = revisionWorkspaceActor(Site::factory()->create(), ItKbAccessService::AUTHOR);
    $this->actingAs($other)->get('/it/knowledge/'.$article->id)->assertOk();
    $this->get('/it/knowledge/'.$article->id.'?revision='.$first->id)->assertNotFound();
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'diagrams' => [knowledgeMediaDiagram()]]);
    $proposal = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'media_draft_saved')->latest('id')->firstOrFail();
    $this->actingAs($this->author)->get('/it/knowledge/'.$article->id.'?revision='.$proposal->id)->assertNotFound();
});

test('historical publication files open only in their exact permitted revision and reject corruption', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-test'));
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $firstBytes = "%PDF-1.4\nFirst verified file\n%%EOF";
    $this->actingAs($this->author)->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version,
        'file' => UploadedFile::fake()->createWithContent('first.pdf', $firstBytes)])->assertSessionDoesntHaveErrors();
    $firstFile = ItKbFile::query()->where('article_id', $article->id)->sole();
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $firstRevision = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'published')->sole();
    $this->post('/it/knowledge/'.$article->id.'/files', ['actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'replace_file_id' => $firstFile->id,
        'file' => UploadedFile::fake()->createWithContent('second.pdf', "%PDF-1.4\nSecond verified file\n%%EOF")])->assertSessionDoesntHaveErrors();
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $secondRevision = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'published')->latest('id')->firstOrFail();
    $reader = revisionWorkspaceActor($this->site, 'it.request');
    $href = '/it/knowledge/'.$article->id.'/files/'.$firstFile->id;
    $this->actingAs($reader)->get($href)->assertNotFound();
    $this->get($href.'?revision='.$firstRevision->id)->assertOk()->assertInertia(fn ($page) => $page->where('file.original_href', $href.'?revision='.$firstRevision->id.'&original=1'));
    $this->get($href.'?revision='.$secondRevision->id.'&original=1')->assertNotFound();
    Storage::disk(ItAttachment::DISK)->put($firstFile->path, str_replace('First', 'Wrong', $firstBytes));
    $this->get($href.'?revision='.$firstRevision->id.'&original=1')->assertStatus(409);
    Storage::disk(ItAttachment::DISK)->put($firstFile->path, $firstBytes);
    $this->get($href.'?revision='.$firstRevision->id.'&original=1')->assertDownload('first.pdf');
});

test('problem relationships reuse the canonical problem and its existing ticket permission scope', function () {
    $actor = revisionWorkspaceActor($this->site, 'it.view');
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'work_type' => 'problem', 'is_sensitive' => false, 'title' => 'Permitted synthetic known error']);
    $problem = ItProblem::query()->create(['ticket_id' => $ticket->id]);
    $hiddenTicket = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id, 'work_type' => 'problem', 'is_sensitive' => false, 'title' => 'Hidden problem canary']);
    $hidden = ItProblem::query()->create(['ticket_id' => $hiddenTicket->id]);
    $relationships = app(ItKnowledgeRelationships::class);
    $rows = $relationships->options($actor, 'problem', 'synthetic')['records'];
    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($problem->id)->and($rows[0]['href'])->toBe('/it/problems/'.$problem->id);
    expect($relationships->resolve($actor, [['type' => 'problem', 'id' => $hidden->id]]))->toBe([])
        ->and($relationships->options($this->author, 'problem')['records'])->toBe([]);
});

function knowledgeRasterUpload(string $format = 'png', int $width = 128, int $height = 64): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    ($format === 'png' ? 'imagepng' : 'imagejpeg')($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return UploadedFile::fake()->createWithContent('synthetic-diagram.'.$format, $bytes);
}

function knowledgeV2ImageDiagram(int $fileId): array
{
    $root = base_path('tests/fixtures/it/knowledge-diagrams/');
    $diagram = json_decode(file_get_contents($root.'v2-minimal.json'), true, 512, JSON_THROW_ON_ERROR);
    $rich = json_decode(file_get_contents($root.'v2-rich.json'), true, 512, JSON_THROW_ON_ERROR);
    $image = collect($rich['pages'])->flatMap(fn ($page) => $page['nodes'])->firstWhere('type', 'image');
    $image['id'] = (string) str()->uuid();
    $image['parentId'] = null;
    $image['layerId'] = $diagram['pages'][0]['layers'][0]['id'];
    $image['imageFileId'] = $fileId;
    $diagram['pages'][0]['nodes'] = [$image];

    return $diagram;
}

test('the approved multipage drawing format survives publication, proposed changes and revision restoration', function () {
    $diagram = json_decode(file_get_contents(base_path('tests/fixtures/it/knowledge-diagrams/v2-template-catalogue.json')), true, 512, JSON_THROW_ON_ERROR);
    $article = publishRevisionWorkspaceDocument(revisionWorkspaceDocument($this->author, $this->site, ['diagrams' => [$diagram]]), $this->author, $this->reviewer);
    $publication = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'published')->sole();
    $this->actingAs($this->author)->get('/it/knowledge/'.$article->id)->assertOk();
    expect(app(ItKnowledgeDiagramSource::class)->issues($article->fresh()->diagrams))->toBe([])
        ->and($article->fresh()->diagrams)->toEqual([$diagram]);
    $changed = $diagram;
    $changed['pages'][0]['nodes'][0]['text'] = 'An explicitly saved proposed shape label';
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->lock_version, 'diagrams' => [$changed]]);
    expect($article->fresh()->diagrams)->toEqual([$diagram])
        ->and(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['diagrams'])->toEqual([$changed]);
    app(ItKbLifecycleService::class)->restoreRevision($article, $this->author, $publication->id, $article->fresh()->lock_version);
    expect(ItKbWorkingCopy::query()->where('article_id', $article->id)->sole()->snapshot['diagrams'])->toEqual([$diagram])
        ->and($publication->fresh()->snapshot['diagrams'])->toEqual([$diagram]);
});

test('diagram images use scanned private files with immutable replacement and historical access', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->twice()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-raster-test'));
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $this->actingAs($this->author)->postJson('/it/knowledge/'.$article->id.'/files', [
        'actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version,
        'request_uuid' => (string) str()->uuid(), 'diagram_image' => true, 'file' => knowledgeRasterUpload(),
    ])->assertOk()->assertJsonPath('actor_user_id', $this->author->id)->assertJsonPath('file.mime', 'image/png')
        ->assertJsonPath('file.width', 128)->assertJsonPath('file.height', 64);
    $png = ItKbFile::query()->where('article_id', $article->id)->sole();
    $diagram = knowledgeV2ImageDiagram($png->id);
    app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $article->fresh()->lock_version, 'diagrams' => [$diagram]]);
    $article = publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $publication = ItKbRevision::query()->where('article_id', $article->id)->where('event', 'published')->sole();
    $reader = revisionWorkspaceActor($this->site, 'it.request');
    $imageHref = '/it/knowledge/'.$article->id.'/files/'.$png->id;
    $this->actingAs($reader)->get($imageHref.'?raster=1&actor_user_id='.$reader->id)->assertOk()
        ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Knowledge-File-Id', (string) $png->id)
        ->assertHeader('X-Image-Width', '128')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->getJson($imageHref.'?raster=1&actor_user_id='.$this->author->id)->assertUnprocessable();
    $outsider = revisionWorkspaceActor(Site::factory()->create(), 'it.request');
    $this->actingAs($outsider)->get($imageHref.'?raster=1&actor_user_id='.$outsider->id)->assertNotFound();

    $version = $article->fresh()->lock_version;
    expect(fn () => app(ItKbLifecycleService::class)->update($article, $this->author, ['lock_version' => $version, 'file_ids' => []]))->toThrow(DomainException::class);
    expect($article->fresh()->lock_version)->toBe($version);
    $this->actingAs($this->author)->postJson('/it/knowledge/'.$article->id.'/files', [
        'actor_user_id' => $this->author->id, 'lock_version' => $version,
        'replace_file_id' => $png->id, 'diagram_image' => true, 'file' => knowledgeRasterUpload('jpeg'),
    ])->assertOk()->assertJsonPath('file.mime', 'image/jpeg');
    $jpeg = ItKbFile::query()->where('article_id', $article->id)->latest('id')->first();
    $copy = ItKbWorkingCopy::query()->where('article_id', $article->id)->sole();
    expect($copy->snapshot['diagrams'][0]['pages'][0]['nodes'][0]['imageFileId'])->toBe($jpeg->id)
        ->and($article->fresh()->diagrams[0]['pages'][0]['nodes'][0]['imageFileId'])->toBe($png->id);
    $this->actingAs($reader)->get('/it/knowledge/'.$article->id.'/files/'.$jpeg->id.'?raster=1&actor_user_id='.$reader->id)->assertNotFound();
    publishRevisionWorkspaceDocument($article, $this->author, $this->reviewer);
    $this->actingAs($reader)->get($imageHref.'?raster=1&actor_user_id='.$reader->id)->assertNotFound();
    $historicalHref = $imageHref.'?revision='.$publication->id.'&raster=1&actor_user_id='.$reader->id;
    $this->get($historicalHref)->assertOk();
    Storage::disk(ItAttachment::DISK)->append($png->path, 'synthetic corruption');
    $this->get($historicalHref)->assertStatus(409);
    expect(app(ItKnowledgeDiagramSource::class)->issues($publication->fresh()->snapshot['diagrams']))->toBe([])
        ->and($publication->fresh()->snapshot['diagrams'])->toEqual([$diagram]);
});

test('image uploads reject SVG, mismatched formats and excessive dimensions without reserving files', function () {
    Storage::fake(ItAttachment::DISK);
    $this->mock(MalwareScanner::class)->shouldNotReceive('scanPath');
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $uploads = [
        UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        knowledgeRasterUpload('png', ItKnowledgeRaster::MAX_DIMENSION + 1, 1),
        UploadedFile::fake()->createWithContent('wrong.png', 'This is ordinary text, not an image.'),
    ];
    foreach ($uploads as $upload) {
        $this->actingAs($this->author)->postJson('/it/knowledge/'.$article->id.'/files', [
            'actor_user_id' => $this->author->id, 'lock_version' => $article->lock_version, 'diagram_image' => true, 'file' => $upload,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }
    expect(ItKbFile::query()->where('article_id', $article->id)->count())->toBe(0)
        ->and($article->fresh()->lock_version)->toBe($article->lock_version);
});

test('diagram images reject wrong-article pending non-raster missing and out-of-scope files atomically', function () {
    Storage::fake(ItAttachment::DISK);
    $article = revisionWorkspaceDocument($this->author, $this->site);
    $other = revisionWorkspaceDocument($this->author, $this->site, ['title' => 'Another synthetic document']);
    $restrictedSite = Site::factory()->create();
    $upload = knowledgeRasterUpload();
    $path = 'it_knowledge/'.str()->uuid();
    Storage::disk(ItAttachment::DISK)->put($path, file_get_contents($upload->getRealPath()));
    $metadata = [
        'article_id' => $article->id, 'version' => 1, 'name' => 'Synthetic image.png', 'mime' => 'image/png',
        'size' => $upload->getSize(), 'sha256' => hash_file('sha256', $upload->getRealPath()), 'path' => $path,
        'state' => 'ready', 'audience' => 'specific_sites', 'site_scope' => [$this->site->id],
        'uploaded_by_user_id' => $this->author->id, 'created_at' => now(),
    ];
    $ids = [];
    foreach ([['article_id' => $other->id], ['state' => 'reserved'], ['mime' => 'application/pdf'], ['site_scope' => [$restrictedSite->id]]] as $changes) {
        $fixturePath = 'it_knowledge/'.str()->uuid();
        Storage::disk(ItAttachment::DISK)->put($fixturePath, file_get_contents($upload->getRealPath()));
        $ids[] = ItKbFile::query()->create(array_replace($metadata, ['series_id' => (string) str()->uuid(), 'path' => $fixturePath], $changes))->id;
    }
    $ids[] = max($ids) + 1000;
    $originalArticle = $article->fresh()->getAttributes();
    foreach ($ids as $fileId) {
        expect(fn () => app(ItKbLifecycleService::class)->update($article, $this->author, [
            'lock_version' => $article->lock_version, 'diagrams' => [knowledgeV2ImageDiagram($fileId)], 'file_ids' => [$fileId],
        ]))->toThrow(DomainException::class);
        expect($article->fresh()->getAttributes())->toBe($originalArticle);
    }
});
