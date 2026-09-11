<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\ItModuleNavigation;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Models\AuditLog;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;

function knowledgeCapabilityActor(Site $site, array $permissions): User
{
    $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'knowledge-capability-'.str()->uuid(), 'label' => 'Knowledge capability fixture',
        'level' => 10, 'type' => 'custom',
    ]);
    $role->permissions()->attach(Permission::query()->whereIn('key', $permissions)->pluck('id'));
    $actor->roles()->sync([$role->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null,
    ]);

    return $actor;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->knowledgeSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
});

test('fine knowledge and credential audit grants are admin-only defaults and migration is additive and idempotent', function () {
    $keys = ['it.knowledge.author', 'it.knowledge.review', 'credentials.audit'];
    expect(Role::query()->whereHas('permissions', fn ($query) => $query->whereIn('key', $keys))->pluck('name')->all())
        ->toBe(['admin']);
    $manager = knowledgeCapabilityActor($this->knowledgeSite, ['it.view', 'it.manage', 'credentials.reveal']);
    foreach ($keys as $key) {
        expect($manager->canDo($key))->toBeFalse();
    }
    $migration = require database_path('migrations/2026_09_09_000006_add_knowledge_and_credential_audit_permissions.php');
    $migration->up();
    $migration->up();
    expect(Permission::query()->whereIn('key', $keys)->count())->toBe(3)
        ->and(Role::query()->whereHas('permissions', fn ($query) => $query->whereIn('key', $keys))->pluck('name')->all())
        ->toBe(['admin']);
});

test('a knowledge-only author reaches scoped drafts and owner options without any ticket or provisioning rights', function () {
    $author = knowledgeCapabilityActor($this->knowledgeSite, [ItKbAccessService::AUTHOR]);
    $otherSite = Site::factory()->create();
    $visible = ItKbArticle::factory()->create(['audience' => 'specific_sites', 'site_scope' => [$this->knowledgeSite->id]]);
    $hidden = ItKbArticle::factory()->create(['audience' => 'specific_sites', 'site_scope' => [$otherSite->id]]);
    $ticket = ItTicket::factory()->create(['site_id' => $this->knowledgeSite->id, 'requester_user_id' => $author->id]);

    $this->actingAs($author)->get('/it?tab=knowledge')->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbArticles', 1)->where('kbArticles.0.id', $visible->id)
            ->where('kbArticles.0.can.author', true)->where('kbArticles.0.can.review', false)
            ->where('can.view', false)->where('can.manage', false)->where('can.request', false)
            ->where('can.knowledge_author', true)->where('can.knowledge_review', false)
            ->where('summary', null)->has('myTickets', 0)->has('catalogItems', 0)
            ->missing('requests')->missing('tickets')->missing('overview')->missing('assignees')
            ->where('kbOptions.owners.0.id', $author->id))
        ->assertDontSee($hidden->body);
    expect(collect(ItModuleNavigation::forUser($author))->pluck('items')->flatten(1)->pluck('href')->all())
        ->toBe(['/it?tab=knowledge']);
    $this->actingAs($author)->getJson("/it/tickets/{$ticket->id}")->assertForbidden();
    $this->actingAs($author)->postJson('/it/tickets', [])->assertForbidden();
    $this->actingAs($author)->getJson('/it/provisioning/export')->assertForbidden();
    $this->actingAs($author)->getJson('/it/reports/data')->assertForbidden();
    $this->actingAs($author)->get('/it/setup')->assertForbidden();

    $this->actingAs($author)->post('/it/kb', [
        'title' => 'Author without ticket management', 'category' => 'other', 'body' => 'Scoped draft body.',
        'audience' => 'specific_sites', 'site_scope' => [$this->knowledgeSite->id], 'owner_user_id' => $author->id,
    ])->assertRedirect()->assertSessionDoesntHaveErrors()->assertSessionMissing('error');
    $draft = ItKbArticle::query()->where('title', 'Author without ticket management')->firstOrFail();
    $this->actingAs($author)->patch("/it/kb/{$draft->id}", ['body' => 'Edited scoped draft.'])->assertRedirect();
    $this->actingAs($author)->post("/it/kb/{$draft->id}/submit-review")->assertRedirect();
    expect($draft->fresh()->status)->toBe('in_review')->and($draft->fresh()->body)->toBe('Edited scoped draft.');
    $this->actingAs($author)->post("/it/kb/{$draft->id}/publish")->assertForbidden();
    $this->actingAs($author)->post("/it/kb/{$draft->id}/retire", ['reason' => 'Cannot retire.'])->assertForbidden();
    $this->actingAs($author)->post("/it/kb/{$draft->id}/restore")->assertRedirect();
    expect($draft->fresh()->status)->toBe('draft');
});

test('a separate knowledge reviewer can publish and retire within current audience but cannot author or restore', function () {
    $author = knowledgeCapabilityActor($this->knowledgeSite, [ItKbAccessService::AUTHOR]);
    $reviewer = knowledgeCapabilityActor($this->knowledgeSite, [ItKbAccessService::REVIEW]);
    $article = ItKbArticle::factory()->create([
        'status' => 'in_review', 'author_user_id' => $author->id, 'owner_user_id' => $author->id,
        'audience' => 'specific_sites', 'site_scope' => [$this->knowledgeSite->id],
    ]);
    $this->actingAs($reviewer)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page->where('kbArticles.0.can.author', false)->where('kbArticles.0.can.review', true));
    $this->actingAs($reviewer)->post('/it/kb', [])->assertForbidden();
    $this->actingAs($reviewer)->patch("/it/kb/{$article->id}", ['body' => 'Reviewer cannot rewrite.'])->assertForbidden();
    $this->actingAs($reviewer)->post("/it/kb/{$article->id}/restore")->assertForbidden();
    $this->actingAs($reviewer)->delete("/it/kb/{$article->id}", ['reason' => 'Cannot delete.'])->assertForbidden();
    $this->actingAs($reviewer)->post("/it/kb/{$article->id}/publish")->assertRedirect()->assertSessionMissing('error');
    expect($article->fresh()->status)->toBe('published')->and($article->fresh()->reviewed_by_user_id)->toBe($reviewer->id);
    $this->actingAs($reviewer)->post("/it/kb/{$article->id}/view")->assertRedirect();
    $this->actingAs($reviewer)->post("/it/kb/{$article->id}/helpful", ['helpful' => true])->assertRedirect();
    $this->actingAs($author)->patch("/it/kb/{$article->id}", ['body' => 'Cannot replace published guidance.'])
        ->assertRedirect()->assertSessionHas('error', 'Return this article to draft before editing its content.');
    expect($article->fresh()->body)->toBe($article->body);
    $this->actingAs($reviewer)->post("/it/kb/{$article->id}/retire", ['reason' => 'Superseded by reviewed guidance.'])
        ->assertRedirect()->assertSessionMissing('error');
    expect($article->fresh()->status)->toBe('retired')
        ->and(AuditLog::query()->where('action', 'it.knowledge.published')->where('meta->actor_id', $reviewer->id)->count())->toBe(1);
    $this->actingAs($reviewer)->post("/it/kb/{$article->id}/restore")->assertForbidden();
    $this->actingAs($author)->post("/it/kb/{$article->id}/restore")->assertRedirect();
    expect($article->fresh()->status)->toBe('draft')->and($article->fresh()->published_at)->toBeNull();
});

test('knowledge grants preserve full-audience mutation denial and capability revocation returns authorization failures', function () {
    $otherSite = Site::factory()->create();
    $actor = knowledgeCapabilityActor($this->knowledgeSite, [ItKbAccessService::AUTHOR, ItKbAccessService::REVIEW, 'it.view', 'it.manage']);
    $overlap = ItKbArticle::factory()->create(['status' => 'in_review', 'audience' => 'specific_sites', 'site_scope' => [$this->knowledgeSite->id, $otherSite->id]]);
    $hidden = ItKbArticle::factory()->create(['status' => 'in_review', 'audience' => 'specific_sites', 'site_scope' => [$otherSite->id]]);
    $this->actingAs($actor)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbArticles', 1)->where('kbArticles.0.id', $overlap->id)
            ->where('kbArticles.0.can.author', false)->where('kbArticles.0.can.review', false));
    foreach ([$overlap, $hidden] as $article) {
        $this->actingAs($actor)->post("/it/kb/{$article->id}/publish")->assertNotFound();
        $this->actingAs($actor)->patch("/it/kb/{$article->id}", ['audience' => 'all_staff', 'body' => 'Cannot widen.'])->assertNotFound();
    }
    $local = ItKbArticle::factory()->create(['status' => 'in_review']);
    $actor->permissionOverrides()->syncWithoutDetaching(Permission::query()
        ->whereIn('key', [ItKbAccessService::AUTHOR, ItKbAccessService::REVIEW])->pluck('id')
        ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    $actor = $actor->fresh();
    $this->actingAs($actor)->post('/it/kb', [])->assertForbidden();
    $this->actingAs($actor)->post("/it/kb/{$local->id}/publish")->assertForbidden();
    $this->actingAs($actor)->patch("/it/kb/{$local->id}", [])->assertForbidden();
    expect(fn () => app(ItKbLifecycleService::class)->publish($local, $actor))->toThrow(AuthorizationException::class);
    expect($local->fresh()->status)->toBe('in_review');
});
