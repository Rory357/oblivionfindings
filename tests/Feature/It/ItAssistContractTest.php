<?php

use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->agent->roles()->syncWithoutDetaching(Role::where('name', 'hr')->pluck('id'));
    ensureCanonicalHrStaffProfile($this->agent, $this->site);
    $this->viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->viewer->roles()->syncWithoutDetaching(Role::where('name', 'support_worker')->pluck('id'));
    $this->viewer->permissionOverrides()->attach(Permission::where('key', 'it.view')->firstOrFail()->id, ['allowed' => true]);
    ensureCanonicalHrStaffProfile($this->viewer, $this->site);
    $this->requester = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->requester->roles()->syncWithoutDetaching(Role::where('name', 'support_worker')->pluck('id'));
    ensureCanonicalHrStaffProfile($this->requester, $this->site);
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->requester->id,
    ]);
    ItTicketComment::factory()->create(['ticket_id' => $this->ticket->id, 'is_internal' => false]);
    ItTicketComment::factory()->create(['ticket_id' => $this->ticket->id, 'is_internal' => true]);
});

test('the assist contract is typed, disabled by default and derived server-side for a working agent', function () {
    $response = $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}/assist")->assertOk();

    $response->assertJsonPath('record.type', 'it_ticket')
        ->assertJsonPath('record.reference', $this->ticket->reference)
        ->assertJsonPath('record.version', 1)
        ->assertJsonPath('audiences', ['public', 'internal'])
        ->assertJsonPath('proposal_schema.apply_via', 'existing authorized versioned commands only');

    $capabilities = collect($response->json('capabilities'));
    expect($capabilities->pluck('key')->all())->toBe(['ticket_summary', 'reply_draft', 'triage_suggestion'])
        ->and($capabilities->every(fn ($capability) => $capability['enabled'] === false))->toBeTrue()
        ->and($capabilities->pluck('reason')->unique()->all())->toBe(['assistance_disabled']);

    $sources = collect($response->json('sources'))->keyBy('key');
    expect($sources->get('public_conversation')['count'])->toBe(1)
        ->and($sources->get('internal_notes')['count'])->toBe(1)
        // Descriptors only — the contract never serializes content or secrets.
        ->and(str_contains(strtolower($response->getContent()), 'password'))->toBeFalse()
        ->and(collect($sources->keys())->contains(fn ($key) => str_contains((string) $key, 'credential')))->toBeFalse();
});

test('a participant-scope viewer gets public-only audiences and no internal descriptors while requesters get nothing', function () {
    $viewerContract = $this->actingAs($this->viewer)
        ->getJson("/it/tickets/{$this->ticket->id}/assist")->assertOk();
    expect($viewerContract->json('audiences'))->toBe(['public'])
        ->and(collect($viewerContract->json('sources'))->pluck('key')->all())
        ->not->toContain('internal_notes');

    $this->actingAs($this->requester)
        ->getJson("/it/tickets/{$this->ticket->id}/assist")->assertForbidden();
});

test('the documentation contract is author-only, disabled and cites permission-checked resolution sources', function () {
    $article = ItKbArticle::factory()->create([
        'status' => 'draft', 'audience' => 'all_staff', 'owner_user_id' => $this->agent->id,
    ]);
    $this->agent->permissionOverrides()->attach(
        Permission::where('key', 'it.knowledge.author')->firstOrFail()->id, ['allowed' => true],
    );

    $contract = $this->actingAs($this->agent->fresh())
        ->getJson("/it/knowledge/{$article->id}/assist")->assertOk();
    $contract->assertJsonPath('record.type', 'it_kb_article')
        ->assertJsonPath('record.reference', 'KB-'.$article->id)
        ->assertJsonPath('record.published_revision', null);
    $capabilities = collect($contract->json('capabilities'));
    expect($capabilities->pluck('key')->all())->toBe(['draft_from_resolution', 'summarise_document', 'completeness_review'])
        ->and($capabilities->every(fn ($capability) => $capability['enabled'] === false))->toBeTrue()
        ->and(collect($contract->json('sources'))->keyBy('key')->get('linked_resolutions')['count'])->toBe(0);

    // Readers without authoring rights get nothing, and so do requesters.
    $this->actingAs($this->viewer)->getJson("/it/knowledge/{$article->id}/assist")->assertForbidden();
    $this->actingAs($this->requester)->getJson("/it/knowledge/{$article->id}/assist")->assertForbidden();
});

test('even an enabled flag never enables execution — capabilities stay disabled with an explicit reason', function () {
    config(['it.assist.enabled' => true]);

    $capabilities = collect($this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}/assist")->assertOk()->json('capabilities'));
    expect($capabilities->every(fn ($capability) => $capability['enabled'] === false))->toBeTrue()
        ->and($capabilities->pluck('reason')->unique()->all())->toBe(['provider_not_integrated']);
});
