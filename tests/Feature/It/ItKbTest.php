<?php

use App\Models\AuditLog;
use App\Models\ItKbArticle;
use App\Models\ItKbInteraction;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function kbUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = kbUser('hr');
});

test('an agent creates a KB article with an application-unique slug', function () {
    $this->actingAs($this->hr)->post('/it/kb', [
        'title' => 'Reset your password',
        'category' => 'account',
        'body' => 'Go to the portal and click Forgot password.',
    ])->assertRedirect();

    $article = ItKbArticle::query()->firstWhere('title', 'Reset your password');
    expect($article)->not->toBeNull();
    expect($article->slug)->toBe('reset-your-password');
    expect($article->status)->toBe('draft');
    expect($article->category)->toBe('account');
    expect((int) $article->author_user_id)->toBe($this->hr->id);
    expect(AuditLog::query()
        ->where('action', 'it.knowledge.created')
        ->where('auditable_type', $article->getMorphClass())
        ->where('auditable_id', $article->id)
        ->count())->toBe(1);

    $this->actingAs($this->hr)->post("/it/kb/{$article->id}/submit-review")->assertRedirect();
    $this->actingAs($this->hr)->post("/it/kb/{$article->id}/publish")->assertRedirect();
    expect($article->fresh()->status)->toBe('published');

    // A second article with the same title gets a de-duplicated slug.
    $this->actingAs($this->hr)->post('/it/kb', [
        'title' => 'Reset your password',
        'category' => 'account',
        'body' => 'Another way in.',
    ])->assertRedirect();

    expect(
        ItKbArticle::query()->where('title', 'Reset your password')->pluck('slug')->sort()->values()->all()
    )->toBe(['reset-your-password', 'reset-your-password-2']);
});

test('agents edit articles and use the governed publish lifecycle while the slug stays stable', function () {
    $article = ItKbArticle::factory()->create(['status' => 'draft']);
    $slug = $article->slug;

    // Content edits never bypass the explicit review/publish lifecycle.
    $this->actingAs($this->hr)->patch("/it/kb/{$article->id}", [
        'title' => 'Edited title',
        'category' => 'network',
        'body' => 'Edited body.',
    ])->assertRedirect();

    $article->refresh();
    expect($article->title)->toBe('Edited title');
    expect($article->category)->toBe('network');
    expect($article->status)->toBe('draft');
    expect($article->slug)->toBe($slug); // a title edit never churns the slug

    $this->actingAs($this->hr)->post("/it/kb/{$article->id}/submit-review")->assertRedirect();
    $this->actingAs($this->hr)->post("/it/kb/{$article->id}/publish")->assertRedirect();
    expect($article->fresh()->status)->toBe('published');

    $this->actingAs($this->hr)->patch("/it/kb/{$article->id}", [
        'body' => 'Published guidance corrected without bypassing lifecycle state.',
    ])->assertRedirect();
    expect($article->fresh()->status)->toBe('published')
        ->and($article->fresh()->slug)->toBe($slug);
});

test('KB authoring is agent-only while deletion is reasoned and draft-only', function () {
    $worker = kbUser('support_worker');
    $first = ItKbArticle::factory()->create();
    $second = ItKbArticle::factory()->create();

    // Self-service requesters (no it.manage) cannot author, edit or delete.
    $this->actingAs($worker)->post('/it/kb', [
        'title' => 'Nope', 'category' => 'other', 'body' => 'x',
    ])->assertForbidden();
    $this->actingAs($worker)->patch("/it/kb/{$first->id}", ['title' => 'Nope'])->assertForbidden();
    $this->actingAs($worker)->delete("/it/kb/{$first->id}")->assertForbidden();

    // One authorised agent governs the whole application library, but review
    // and published history cannot be destroyed through the draft action.
    $this->actingAs($this->hr)->patch("/it/kb/{$second->id}", ['title' => 'Shared guidance'])->assertRedirect();
    $this->actingAs($this->hr)->post("/it/kb/{$second->id}/submit-review")->assertRedirect();
    expect($second->fresh()->status)->toBe('in_review');
    $this->actingAs($this->hr)
        ->delete("/it/kb/{$second->id}", ['reason' => 'This review is obsolete.'])
        ->assertRedirect()
        ->assertSessionHas('error', 'Return this article to draft before deleting it.');
    expect(ItKbArticle::query()->find($second->id))->not->toBeNull();

    $this->actingAs($this->hr)->post("/it/kb/{$second->id}/publish")->assertRedirect();
    $this->actingAs($this->hr)
        ->delete("/it/kb/{$second->id}", ['reason' => 'This published guide is obsolete.'])
        ->assertRedirect()
        ->assertSessionHas('error', 'Retire published knowledge so its history remains available.');
    expect(ItKbArticle::query()->find($second->id))->not->toBeNull();

    // A draft needs an operational reason and leaves an immutable audit.
    $this->actingAs($this->hr)
        ->delete("/it/kb/{$first->id}")
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->hr)
        ->delete("/it/kb/{$first->id}", ['reason' => 'Duplicate draft created during authoring.'])
        ->assertRedirect();
    expect(ItKbArticle::query()->find($first->id))->toBeNull();
    expect(AuditLog::query()
        ->where('action', 'it.knowledge.draft.deleted')
        ->where('auditable_type', $first->getMorphClass())
        ->where('auditable_id', $first->id)
        ->where('meta->reason', 'Duplicate draft created during authoring.')
        ->count())->toBe(1);
});

test('the knowledge catalogue reaches agents but never a self-service payload', function () {
    ItKbArticle::factory()->create(['status' => 'published']);

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbArticles', 1));

    // A self-service requester never receives the agent Knowledge catalogue.
    $worker = kbUser('support_worker');
    $this->actingAs($worker)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('kbArticles'));
});

test('pure requesters browse only published articles; agents get the full catalogue instead', function () {
    ItKbArticle::factory()->published()->create(['title' => 'Reset your password']);
    ItKbArticle::factory()->create(['title' => 'Secret draft']); // draft — never browsed

    $worker = kbUser('support_worker');
    $this->actingAs($worker)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbPublished', 1)->missing('kbArticles'));

    // Agents get the management catalogue (both), not the requester browse list.
    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('kbArticles', 2)->has('kbPublished', 0));
});

test('a requester read is recorded while helpful feedback is one canonical vote per user', function () {
    $article = ItKbArticle::factory()->published()->create();
    $worker = kbUser('support_worker');

    $this->actingAs($worker)->post("/it/kb/{$article->id}/view")->assertRedirect();
    expect($article->fresh()->view_count)->toBe(1);

    $this->actingAs($worker)->post("/it/kb/{$article->id}/helpful", ['helpful' => true])->assertRedirect();
    $this->actingAs($worker)->post("/it/kb/{$article->id}/helpful", ['helpful' => true])->assertRedirect();
    $this->actingAs($worker)->post("/it/kb/{$article->id}/helpful", ['helpful' => false])->assertRedirect();
    $article->refresh();
    expect($article->helpful_yes)->toBe(1);
    expect($article->helpful_no)->toBe(0);
    expect($article->deflection_count)->toBe(1);
    expect(ItKbInteraction::query()
        ->where('it_kb_article_id', $article->id)
        ->where('user_id', $worker->id)
        ->whereIn('event_type', ['helpful', 'not_helpful'])
        ->count())->toBe(1);

    $otherWorker = kbUser('support_worker');
    $this->actingAs($otherWorker)
        ->post("/it/kb/{$article->id}/helpful", ['helpful' => false])
        ->assertRedirect();
    expect($article->fresh()->helpful_no)->toBe(1);

    $this->actingAs($worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page->where('kbPublished.0.user_vote', true));
});

test('browse endpoints never touch a draft article', function () {
    $draft = ItKbArticle::factory()->create(); // draft
    $worker = kbUser('support_worker');

    $this->actingAs($worker)->post("/it/kb/{$draft->id}/view")->assertNotFound();
    $this->actingAs($worker)->post("/it/kb/{$draft->id}/helpful", ['helpful' => true])->assertNotFound();
    expect($draft->fresh()->view_count)->toBe(0);
    expect($draft->fresh()->helpful_yes)->toBe(0);
});
