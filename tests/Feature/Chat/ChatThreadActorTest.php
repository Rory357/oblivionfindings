<?php

use App\Jobs\DeliverChatMessageNotifications;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('binds thread reads and writes to the rendered actor without losing an exact send retry', function () {
    config(['chat.shared_experience' => true]);
    Bus::fake([DeliverChatMessageNotifications::class]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
    $site = Site::factory()->create();
    $first = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $second = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$first, $second] as $person) {
        ensureCanonicalHrStaffProfile($person, $site);
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    // Both accounts can read this conversation. Ownership alone cannot catch a stale tab.
    $message = $conversation->messages()->create(['sender_id' => $second->id, 'content' => 'Current account private content']);
    $mutation = (string) Str::uuid();
    $send = ['mutation_id' => $mutation, 'content' => 'Current account reply'];
    $edit = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Reviewed correction', 'mentions' => []];
    $requests = [
        ['GET', route('chat.messages', $conversation), []],
        ['POST', route('chat.changes', $conversation), ['cursor' => 'stale-view-cursor']],
        ['POST', route('chat.send', $conversation), $send],
        ['GET', route('chat.send.outcome', [$conversation, $mutation]), []],
        ['POST', route('chat.audience-review', $conversation), ['audience_token' => str_repeat('a', 64), 'understood' => true]],
        ['POST', route('chat.read', $conversation), ['message_id' => $message->id]],
        ['PATCH', route('chat.preferences', $conversation), ['is_favourite' => true]],
        ['POST', route('chat.action', $message), ['action' => 'save', 'active' => true]],
        ['PATCH', route('chat.messages.edit', $message), $edit],
    ];
    $beforeMember = $conversation->participants()->where('user_id', $second->id)->first()->getAttributes();
    $this->actingAs($second);
    foreach ([null, (string) $first->id] as $actor) {
        if ($actor !== null) {
            $this->withHeader('X-Chat-Actor', $actor);
        }
        foreach ($requests as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed')
                ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Current account private content');
        }
    }
    expect($conversation->messages()->count())->toBe(1);
    expect($message->fresh()->content)->toBe('Current account private content');
    expect($conversation->participants()->where('user_id', $second->id)->first()->getAttributes())->toBe($beforeMember);
    expect(DB::table('chat_saved_messages')->where('user_id', $second->id)->count())->toBe(0);
    expect(DB::table('chat_message_revisions')->where('message_id', $message->id)->count())->toBe(0);
    expect(DB::table('chat_audience_reviews')->where('conversation_id', $conversation->id)->count())->toBe(0);

    $this->withHeader('X-Chat-Actor', (string) $second->id);
    $history = $this->getJson(route('chat.messages', $conversation))->assertOk();
    $this->postJson(route('chat.changes', $conversation), ['cursor' => $history->json('sync_cursor')])->assertOk();
    $this->postJson(route('chat.read', $conversation), ['message_id' => $message->id])->assertNoContent();
    $this->patchJson(route('chat.preferences', $conversation), ['is_favourite' => true])->assertNoContent();
    $this->postJson(route('chat.action', $message), ['action' => 'save', 'active' => true])->assertNoContent();
    $this->patchJson(route('chat.messages.edit', $message), $edit)->assertOk()->assertJsonPath('message.content', 'Reviewed correction');
    $id = $this->postJson(route('chat.send', $conversation), $send)->assertCreated()->json('message.id');
    $this->postJson(route('chat.send', $conversation), $send)->assertCreated()->assertJsonPath('message.id', $id);
    $this->getJson(route('chat.send.outcome', [$conversation, $mutation]))->assertOk()->assertJsonPath('state', 'confirmed');
    expect($conversation->messages()->count())->toBe(2);
    // Matching the rendered actor never bypasses current membership authorization.
    $conversation->participants()->where('user_id', $second->id)->delete();
    $this->getJson(route('chat.messages', $conversation))->assertForbidden();
});
