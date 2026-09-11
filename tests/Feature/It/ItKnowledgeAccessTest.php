<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Models\AuditLog;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function knowledgeAccessActor(Site $site, array $permissions = ['it.request', 'it.view', 'it.manage', 'it.knowledge.author', 'it.knowledge.review']): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'knowledge-access-'.str()->uuid(),
        'label' => 'Knowledge access fixture',
        'level' => 40,
        'type' => 'custom',
    ]);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $actor->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $actor;
}

test('knowledge discovery filters draft bodies and published suggestions to current approved Sites', function () {
    $primary = Site::factory()->create();
    $secondary = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $agent = knowledgeAccessActor($primary);
    $agent->hrEmployeeProfile()->update(['secondary_site_ids' => [$secondary->id]]);
    $general = ItKbArticle::factory()->create(['title' => 'General draft']);
    $agents = ItKbArticle::factory()->published()->create(['audience' => 'it_agents']);
    $local = ItKbArticle::factory()->published()->create([
        'audience' => 'specific_sites', 'site_scope' => [$primary->id],
        'updated_at' => now()->subDay(),
    ]);
    $secondaryDraft = ItKbArticle::factory()->create([
        'audience' => 'specific_sites', 'site_scope' => [(string) $secondary->id],
    ]);
    $hiddenDraft = ItKbArticle::factory()->create([
        'title' => 'Hidden third-site draft', 'body' => 'Unapproved draft body must not leave the server.',
        'audience' => 'specific_sites', 'site_scope' => [$hiddenSite->id],
    ]);
    $hiddenPublished = ItKbArticle::factory()->published()->create([
        'title' => 'Hidden third-site publication', 'body' => 'Unapproved published body.',
        'audience' => 'specific_sites', 'site_scope' => [$hiddenSite->id],
    ]);

    $this->actingAs($agent)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('kbArticles', 4)
            ->where('kbArticles', fn ($articles) => collect($articles)->pluck('id')->sort()->values()->all()
                === collect([$general->id, $agents->id, $local->id, $secondaryDraft->id])->sort()->values()->all()))
        ->assertDontSee($hiddenDraft->body)
        ->assertDontSee($hiddenPublished->title);

    $ticket = ItTicket::factory()->create(['site_id' => $primary->id]);
    $this->actingAs($agent)->getJson(route('it.tickets.show', $ticket))->assertOk()
        ->assertJsonCount(2, 'kbSuggestions')
        ->assertJsonMissing(['title' => $hiddenPublished->title]);
    $this->actingAs($agent)->post("/it/kb/{$hiddenPublished->id}/view")->assertNotFound();
    $this->actingAs($agent)->post("/it/kb/{$hiddenPublished->id}/helpful", [])->assertNotFound();

    // Filtering happens in the query before a result cap, so newer hidden
    // publications cannot consume the visible result's slot.
    expect(app(ItKbAccessService::class)->applyViewScope(
        ItKbArticle::query()->where('audience', 'specific_sites'), $agent, publishedOnly: true,
    )->latest('updated_at')->limit(1)->pluck('id')->all())->toBe([$local->id]);

    $agent->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
    $this->actingAs($agent)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbArticles', 3));
    $this->actingAs($agent)->patch("/it/kb/{$secondaryDraft->id}", [
        'audience' => 'all_staff', 'body' => 'Stale editor attempts to widen the old audience.',
    ])->assertNotFound();
    expect($secondaryDraft->fresh()->audience)->toBe('specific_sites');
});

test('knowledge mutation routes conceal an unapproved current article before validation or audience changes', function (string $method, string $action, string $status, array $data) {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = knowledgeAccessActor($site);
    $article = ItKbArticle::factory()->create([
        'audience' => 'specific_sites', 'site_scope' => [$otherSite->id], 'status' => $status,
    ]);
    $before = $article->fresh()->getAttributes();

    $this->actingAs($actor)->{$method}("/it/kb/{$article->id}{$action}", $data)->assertNotFound();

    expect($article->fresh()->getAttributes())->toBe($before)
        ->and(AuditLog::query()->where('auditable_type', $article->getMorphClass())
            ->where('auditable_id', $article->id)->where('action', 'like', 'it.knowledge.%')->count())->toBe(0);
})->with([
    'reclassify hidden draft' => ['patch', '', 'draft', ['audience' => 'all_staff', 'body' => 'Forged publication.']],
    'invalid hidden edit' => ['patch', '', 'draft', ['title' => '']],
    'submit hidden review' => ['post', '/submit-review', 'draft', []],
    'publish hidden review' => ['post', '/publish', 'in_review', []],
    'retire hidden publication' => ['post', '/retire', 'published', []],
    'restore hidden retirement' => ['post', '/restore', 'retired', []],
    'delete hidden draft' => ['delete', '', 'draft', []],
]);

test('knowledge service reauthorizes the canonical audience and preserves permitted authoring', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = knowledgeAccessActor($site);
    $article = ItKbArticle::factory()->create([
        'audience' => 'specific_sites', 'site_scope' => [$otherSite->id],
    ]);
    $article->site_scope = [$site->id];
    expect(fn () => app(ItKbLifecycleService::class)->update($article, $actor, ['audience' => 'all_staff']))
        ->toThrow(ModelNotFoundException::class);

    $sharedGuide = ItKbArticle::factory()->published()->create([
        'audience' => 'specific_sites', 'site_scope' => [$site->id, $otherSite->id],
    ]);
    expect(app(ItKbAccessService::class)->canReadPublished($actor, $sharedGuide))->toBeTrue()
        ->and(app(ItKbAccessService::class)->canManage($actor, $sharedGuide))->toBeFalse();
    $this->actingAs($actor)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbArticles', 1)
            ->where('kbArticles.0.id', $sharedGuide->id)->where('kbArticles.0.can.manage', false));
    $this->actingAs($actor)->patch("/it/kb/{$sharedGuide->id}", ['body' => 'Partial-site editor attempt.'])
        ->assertNotFound();

    $permitted = app(ItKbLifecycleService::class)->create($actor, [
        'title' => 'Approved Site guide', 'category' => 'network', 'body' => 'Safe recovery steps.',
        'audience' => 'specific_sites', 'site_scope' => [$site->id],
    ]);
    app(ItKbLifecycleService::class)->submitForReview($permitted, $actor);
    app(ItKbLifecycleService::class)->publish($permitted, $actor);
    expect($permitted->fresh()->status)->toBe('published');
    $this->actingAs($actor)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page->where('kbArticles', fn ($articles) => collect($articles)->firstWhere('id', $permitted->id)['can']['manage'] === true));

    $reader = knowledgeAccessActor($site, ['it.request']);
    $this->actingAs($reader)->get('/it')->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbPublished', 2)
            ->where('kbPublished', fn ($articles) => collect($articles)->pluck('id')->sort()->values()->all()
                === collect([$permitted->id, $sharedGuide->id])->sort()->values()->all()));
    $this->actingAs($reader)->post("/it/kb/{$permitted->id}/view")->assertRedirect();
    $this->actingAs($reader)->post("/it/kb/{$permitted->id}/helpful", ['helpful' => true])->assertRedirect();
    expect($permitted->fresh()->view_count)->toBe(1)
        ->and($permitted->fresh()->helpful_yes)->toBe(1);

    $actor->hrEmployeeProfile()->update(['end_date' => now()->subDay()->toDateString()]);
    expect(fn () => app(ItKbLifecycleService::class)->retire($permitted, $actor, 'Stale author attempt.'))
        ->toThrow(ModelNotFoundException::class);
    expect($permitted->fresh()->status)->toBe('published');
});

test('only the existing explicit all-Sites capability extends scoped knowledge governance', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = knowledgeAccessActor($site, ['it.view', 'it.manage', 'it.organisationWide', 'it.knowledge.author', 'it.knowledge.review']);
    $article = app(ItKbLifecycleService::class)->create($actor, [
        'title' => 'Explicitly governed all-Sites guide', 'category' => 'network', 'body' => 'Approved recovery.',
        'audience' => 'specific_sites', 'site_scope' => [$otherSite->id],
    ]);
    expect(app(ItKbAccessService::class)->canManage($actor, $article))->toBeTrue()
        ->and(app(ItKbAccessService::class)->applyViewScope(ItKbArticle::query(), $actor)->pluck('id')->all())
        ->toBe([$article->id]);
    app(ItKbLifecycleService::class)->submitForReview($article, $actor);
    app(ItKbLifecycleService::class)->publish($article, $actor);
    expect(app(ItKbAccessService::class)->canReadPublished($actor, $article))->toBeTrue();

    $actor->update(['approved_at' => null]);
    expect(app(ItKbAccessService::class)->canManage($actor, $article))->toBeFalse()
        ->and(app(ItKbAccessService::class)->canReadPublished($actor, $article))->toBeFalse();
});
