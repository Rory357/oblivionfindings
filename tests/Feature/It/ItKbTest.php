<?php

use App\Models\ItKbArticle;
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

test('an agent creates a KB article with a tenant-unique slug', function () {
    $this->actingAs($this->hr)->post('/it/kb', [
        'title' => 'Reset your password',
        'category' => 'account',
        'body' => 'Go to the portal and click Forgot password.',
        'status' => 'published',
    ])->assertRedirect();

    $article = ItKbArticle::query()->firstWhere('title', 'Reset your password');
    expect($article)->not->toBeNull();
    expect($article->slug)->toBe('reset-your-password');
    expect($article->status)->toBe('published');
    expect($article->category)->toBe('account');
    expect((int) $article->author_user_id)->toBe($this->hr->id);
    expect((int) $article->tenant_id)->toBe(1);

    // A second article with the same title gets a de-duplicated slug.
    $this->actingAs($this->hr)->post('/it/kb', [
        'title' => 'Reset your password',
        'category' => 'account',
        'body' => 'Another way in.',
        'status' => 'draft',
    ])->assertRedirect();

    expect(
        ItKbArticle::query()->where('title', 'Reset your password')->pluck('slug')->sort()->values()->all()
    )->toBe(['reset-your-password', 'reset-your-password-2']);
});

test('agents edit articles and flip the publish state; the slug stays stable', function () {
    $article = ItKbArticle::factory()->create(['status' => 'draft']);
    $slug = $article->slug;

    // Status-only PATCH — the Publish toggle.
    $this->actingAs($this->hr)->patch("/it/kb/{$article->id}", ['status' => 'published'])->assertRedirect();
    expect($article->fresh()->status)->toBe('published');

    // Full edit (title/category/body/status together).
    $this->actingAs($this->hr)->patch("/it/kb/{$article->id}", [
        'title' => 'Edited title',
        'category' => 'network',
        'body' => 'Edited body.',
        'status' => 'draft',
    ])->assertRedirect();

    $article->refresh();
    expect($article->title)->toBe('Edited title');
    expect($article->category)->toBe('network');
    expect($article->status)->toBe('draft');
    expect($article->slug)->toBe($slug); // a title edit never churns the slug
});

test('KB authoring is agent-only and tenant-scoped', function () {
    $worker = kbUser('support_worker');
    $mine = ItKbArticle::factory()->create(['tenant_id' => 1]);
    $foreign = ItKbArticle::factory()->create(['tenant_id' => 2]);

    // Self-service requesters (no it.manage) cannot author, edit or delete.
    $this->actingAs($worker)->post('/it/kb', [
        'title' => 'Nope', 'category' => 'other', 'body' => 'x', 'status' => 'draft',
    ])->assertForbidden();
    $this->actingAs($worker)->patch("/it/kb/{$mine->id}", ['status' => 'published'])->assertForbidden();
    $this->actingAs($worker)->delete("/it/kb/{$mine->id}")->assertForbidden();

    // An agent cannot reach a foreign-tenant article — 404, not 403, so the
    // guard never leaks that the article exists in another organisation.
    $this->actingAs($this->hr)->patch("/it/kb/{$foreign->id}", ['status' => 'published'])->assertNotFound();
    $this->actingAs($this->hr)->delete("/it/kb/{$foreign->id}")->assertNotFound();
    expect($foreign->fresh()->status)->toBe('draft'); // untouched

    // The agent deletes their own tenant's article.
    $this->actingAs($this->hr)->delete("/it/kb/{$mine->id}")->assertRedirect();
    expect(ItKbArticle::query()->find($mine->id))->toBeNull();
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
