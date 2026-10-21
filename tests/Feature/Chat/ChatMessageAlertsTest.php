<?php

use App\Jobs\DeliverChatMessageNotifications;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();
    config(['chat.shared_experience' => true]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => []]);
    Bus::fake([DeliverChatMessageNotifications::class]);
    $site = Site::factory()->create();
    $this->sender = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $this->reader = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    foreach ([$this->sender, $this->reader] as $user) {
        ensureCanonicalHrStaffProfile($user, $site);
    }
    $this->conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$this->sender, $this->reader] as $user) {
        $this->conversation->participants()->create(['user_id' => $user->id, 'role' => 'member']);
    }
    $this->arrive = function () {
        return DB::transaction(function () {
            $message = $this->conversation->messages()->create(['sender_id' => $this->sender->id, 'content' => 'Private arrival text', 'sender_type' => 'staff']);
            app(ChatMessageDelivery::class)->enqueue($message, $this->conversation);

            return $message;
        });
    };
    $this->actingAs($this->reader)->withHeader('X-Chat-Actor', (string) $this->reader->id);
});

it('provides only fresh authorised arrival identities without replaying backlog or acknowledging messages', function () {
    ($this->arrive)();
    $baseline = $this->getJson(route('chat.message-alerts'))->assertOk()->assertJsonPath('alerts', [])
        ->assertJsonPath('actor_id', $this->reader->id)->assertHeader('Cache-Control', 'no-store, private')->json('cursor');
    $message = ($this->arrive)();
    $row = DB::table('chat_message_deliveries')->where('message_id', $message->id)->where('user_id', $this->reader->id)->first();
    $url = route('chat.message-alerts', ['after' => $baseline]);
    $this->getJson($url)->assertOk()->assertExactJson(['actor_id' => $this->reader->id, 'cursor' => $row->id,
        'alerts' => [['id' => $row->notification_id, 'conversation_id' => $this->conversation->id]], 'has_more' => false])
        ->assertDontSee('Private arrival text')->assertDontSee($this->sender->name);
    // Stable identity allows browser retries to deduplicate without modifying read or delivery state.
    $this->getJson($url)->assertOk()->assertJsonPath('alerts.0.id', $row->notification_id);
    expect($this->conversation->participants()->where('user_id', $this->reader->id)->value('last_read_message_id'))->toBeNull();
    expect(DB::table('chat_message_deliveries')->where('id', $row->id)->value('status'))->toBe('pending');
    expect($this->reader->notifications()->count())->toBe(0);
    $this->getJson(route('chat.message-alerts', ['after' => $row->id]))->assertOk()->assertJsonPath('alerts', []);
    $this->travel(31)->seconds();
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', [])->assertJsonPath('cursor', $row->id);
    $this->travelBack();
});

it('suppresses arrival sound candidates for current quiet mute read preference and membership decisions', function () {
    $message = ($this->arrive)();
    $url = route('chat.message-alerts', ['after' => 0]);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'alerts');
    foreach (['dnd', 'off_duty'] as $state) {
        DB::table('chat_preferences')->updateOrInsert(['user_id' => $this->reader->id], ['availability' => $state, 'sound_enabled' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    }
    DB::table('chat_preferences')->where('user_id', $this->reader->id)->update(['availability' => 'available', 'sound_enabled' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    DB::table('chat_preferences')->where('user_id', $this->reader->id)->update(['sound_enabled' => true]);
    DB::table('chat_personal_preferences')->insert(['user_id' => $this->reader->id, 'values' => json_encode(['quiet_enabled' => true]), 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    DB::table('chat_personal_preferences')->where('user_id', $this->reader->id)->update(['values' => '{}']);
    $member = $this->conversation->participants()->where('user_id', $this->reader->id)->firstOrFail();
    $member->update(['is_muted' => true]);
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    $member->update(['is_muted' => false, 'last_read_message_id' => $message->id]);
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    $member->update(['last_read_message_id' => null]);
    UserNotificationPreference::create(['user_id' => $this->reader->id, 'key' => 'chat.message.received', 'enabled' => true, 'channel_inapp' => false, 'channel_email' => false, 'channel_push' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    UserNotificationPreference::where('user_id', $this->reader->id)->delete();
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'alerts');
    $this->reader->update(['notification_sounds_enabled' => false]);
    $this->actingAs($this->reader->fresh())->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    $this->reader->update(['notification_sounds_enabled' => true]);
    $this->actingAs($this->reader->fresh());
    $message->delete();
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    $message->restore();
    $member->update(['history_after_id' => $message->id]);
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    $member->update(['history_after_id' => null]);
    $this->reader->update(['dnd_enabled' => true, 'dnd_until' => now()->addHour()]);
    $this->actingAs($this->reader->fresh())->getJson($url)->assertOk()->assertJsonPath('alerts', []);
    $this->reader->update(['dnd_enabled' => false, 'dnd_until' => null]);
    $this->actingAs($this->reader->fresh())->getJson($url)->assertOk()->assertJsonCount(1, 'alerts');
    $member->delete();
    $this->getJson($url)->assertOk()->assertJsonPath('alerts', []);
});

it('binds arrival candidates to the rendered account and bounds cursor input and page size', function () {
    for ($index = 0; $index < 102; $index++) {
        ($this->arrive)();
    }
    $this->flushHeaders()->getJson(route('chat.message-alerts'))->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    $this->withHeader('X-Chat-Actor', (string) $this->sender->id)->getJson(route('chat.message-alerts'))->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $this->reader->id);
    foreach (['-1', '1.5', '9007199254740992', 'bad'] as $after) {
        $this->getJson(route('chat.message-alerts', ['after' => $after]))->assertUnprocessable();
    }
    $first = $this->getJson(route('chat.message-alerts', ['after' => 0]))->assertOk()->assertJsonCount(100, 'alerts')->assertJsonPath('has_more', true)->json();
    $this->getJson(route('chat.message-alerts', ['after' => $first['cursor']]))->assertOk()->assertJsonCount(2, 'alerts')->assertJsonPath('has_more', false);
    $this->actingAs($this->sender)->getJson(route('chat.message-alerts', ['after' => 0]))->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $this->sender->id)->getJson(route('chat.message-alerts', ['after' => 0]))->assertOk()->assertJsonPath('alerts', []);
});
