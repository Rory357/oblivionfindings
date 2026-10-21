<?php

use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;

it('binds the conversation list to the rendered account and still rechecks current membership', function () {
    config(['chat.shared_experience' => true]);
    $site = Site::factory()->create();
    $first = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $second = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$first, $second] as $person) {
        ensureCanonicalHrStaffProfile($person, $site);
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $conversation->messages()->create(['sender_id' => $first->id, 'content' => 'Private workspace preview']);
    $url = route('chat.conversations');
    $this->actingAs($first)->getJson($url)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $this->withHeader('X-Chat-Actor', (string) $first->id)->getJson($url)->assertOk()->assertJsonPath('conversations.0.id', $conversation->id);
    $this->actingAs($second)->getJson($url)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed')->assertDontSee('Private workspace preview');
    $this->withHeader('X-Chat-Actor', (string) $second->id)->getJson($url)->assertOk()->assertJsonPath('conversations.0.id', $conversation->id);
    $conversation->participants()->where('user_id', $second->id)->delete();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'conversations')->assertDontSee('Private workspace preview');
});
