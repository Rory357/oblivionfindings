<?php

use App\Models\Site;
use App\Models\User;

it('binds live configuration and signing to the rendered actor across an account switch', function () {
    config(['chat.shared_experience' => true, 'chat.realtime.enabled' => true,
        'broadcasting.connections.chat.key' => 'chat-test-public',
        'broadcasting.connections.chat.secret' => 'chat-test-secret-not-for-deployment',
        'broadcasting.connections.chat.app_id' => 'chat-tests']);
    $site = Site::factory()->create();
    $first = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $second = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($first, $site);
    ensureCanonicalHrStaffProfile($second, $site);
    $configuration = route('chat.realtime');
    $sign = route('chat.realtime.auth');
    $body = ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$first->id];
    $this->actingAs($first)->getJson($configuration)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $this->postJson($sign, $body)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $this->withHeader('X-Chat-Actor', (string) $first->id)->getJson($configuration)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('actor_id', $first->id)
        ->assertJsonPath('channel', $body['channel_name'])->assertDontSee('chat-test-secret');
    $this->postJson($sign, $body)->assertOk();
    $this->actingAs($second)->getJson($configuration)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $body['channel_name'] = 'private-chat.user.'.$second->id;
    $this->postJson($sign, $body)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $this->withHeader('X-Chat-Actor', (string) $second->id)->getJson($configuration)->assertOk()->assertJsonPath('actor_id', $second->id);
    $this->postJson($sign, $body)->assertOk();
    $this->postJson($sign, [...$body, 'channel_name' => 'private-chat.user.'.$first->id])->assertForbidden();
});
