<?php

use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('binds every search and collection kind to the rendered actor even when both accounts can read the conversation', function () {
    config(['chat.shared_experience' => true]);
    $site = Site::factory()->create();
    $first = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $second = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($first, $site);
    ensureCanonicalHrStaffProfile($second, $site);
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$first, $second] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $message = $conversation->messages()->create(['sender_id' => $first->id, 'content' => 'Current permitted discovery text', 'is_pinned' => true]);
    foreach ([$first, $second] as $person) {
        DB::table('chat_saved_messages')->insert(['user_id' => $person->id, 'message_id' => $message->id, 'created_at' => now()]);
    }
    $routes = collect(['messages', 'files', 'people', 'conversations'])
        ->map(fn ($kind) => route('chat.search', ['kind' => $kind, 'conversation' => $conversation->id]))
        ->concat(collect(['saved', 'pinned', 'media'])->map(fn ($kind) => route('chat.collections', ['kind' => $kind, 'conversation' => $conversation->id])));
    $this->actingAs($first);
    foreach ($routes as $route) {
        $this->getJson($route)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed')->assertDontSee('Current permitted discovery text');
    }
    $this->withHeader('X-Chat-Actor', (string) $first->id);
    foreach ($routes as $route) {
        $this->getJson($route)->assertOk();
    }
    $this->actingAs($second);
    foreach ($routes as $route) {
        $this->getJson($route)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed')->assertDontSee('Current permitted discovery text');
    }
    $this->withHeader('X-Chat-Actor', (string) $second->id);
    foreach ($routes as $route) {
        $this->getJson($route)->assertOk();
    }
});
