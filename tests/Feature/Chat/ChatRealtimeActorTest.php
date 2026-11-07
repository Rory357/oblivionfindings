<?php

use App\Models\Site;
use App\Models\User;
use App\Support\Signing\CompactToken;

it('binds live tickets to the rendered actor across an account switch', function () {
    config(['chat.shared_experience' => true, 'chat.live.enabled' => true, 'chat.live.secret' => 'chat-live-actor-test-secret-0123456789',
        'chat.live.key_id' => 'k-actor', 'chat.live.internal_url' => 'http://127.0.0.1:8092']);
    $site = Site::factory()->create();
    $first = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $second = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($first, $site);
    ensureCanonicalHrStaffProfile($second, $site);
    $ticket = route('chat.live.ticket');
    $subject = fn ($response) => CompactToken::verify($response->json('ticket'), ['k-actor' => 'chat-live-actor-test-secret-0123456789'], 'oblivion-live')['sub'];

    $this->actingAs($first)->postJson($ticket)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $issued = $this->withHeader('X-Chat-Actor', (string) $first->id)->postJson($ticket)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('chat-live-actor-test-secret');
    expect($subject($issued))->toBe($first->id);

    // A tab rendered for the first account cannot fetch a ticket after the account switches.
    $this->actingAs($second)->postJson($ticket)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $issued = $this->withHeader('X-Chat-Actor', (string) $second->id)->postJson($ticket)->assertOk();
    expect($subject($issued))->toBe($second->id);
});
