<?php

use App\Jobs\DeliverChatMessageNotifications;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatCallAudience;
use App\Services\Chat\ChatMessageDelivery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
});

it('persists only the current actors preferences and preserves call availability', function () {
    $actor = User::factory()->create(['approved_at' => now(), 'role' => 'next_of_kin']);
    $other = User::factory()->create(['approved_at' => now()]);
    DB::table('chat_preferences')->insert(['user_id' => $actor->id, 'availability' => 'dnd', 'sound_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($actor)->withHeader('X-Chat-Actor', (string) $actor->id);
    $snapshot = $this->getJson(route('chat.preferences.show'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    $this->patchJson(route('chat.preferences.update'), ['version' => $snapshot['version'], 'values' => [
        'text_size' => 'extra_large', 'spacing' => 'compact', 'sending' => 'enter_newline', 'theme' => 'dark',
        'quiet_enabled' => true, 'show_previews' => false, 'sound_enabled' => false,
    ]])->assertOk()->assertJsonPath('values.text_size', 'extra_large')->assertJsonPath('values.quiet_enabled', true);
    expect($actor->fresh()->theme)->toBe('dark');
    expect(DB::table('chat_preferences')->where('user_id', $actor->id)->value('availability'))->toBe('dnd');
    expect((bool) DB::table('chat_preferences')->where('user_id', $actor->id)->value('sound_enabled'))->toBeFalse();
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $actor->id)
        ->getJson(route('chat.preferences.show'))->assertForbidden();
    $this->patchJson(route('chat.preferences.update'), ['version' => $snapshot['version'], 'values' => ['theme' => 'light']])->assertForbidden();
    expect(DB::table('chat_personal_preferences')->where('user_id', $other->id)->exists())->toBeFalse();
});

it('rejects stale versions and unsupported changes without overwriting the saved preference', function () {
    $actor = User::factory()->create(['approved_at' => now()]);
    $this->actingAs($actor)->withHeader('X-Chat-Actor', (string) $actor->id);
    $version = $this->getJson(route('chat.preferences.show'))->assertOk()->json('version');
    $this->patchJson(route('chat.preferences.update'), ['version' => $version, 'values' => ['text_size' => 'large']])->assertOk();
    $this->patchJson(route('chat.preferences.update'), ['version' => $version, 'values' => ['text_size' => 'normal']])->assertConflict();
    $current = $this->getJson(route('chat.preferences.show'))->assertOk()->assertJsonPath('values.text_size', 'large')->json('version');
    $this->patchJson(route('chat.preferences.update'), ['version' => $current, 'values' => ['text_size' => 'huge']])->assertUnprocessable();
    $this->patchJson(route('chat.preferences.update'), ['version' => $current, 'values' => ['user_id' => $actor->id + 1]])->assertUnprocessable();
    $this->getJson(route('chat.preferences.show'))->assertOk()->assertJsonPath('version', $current);
});

it('honours personal quiet time for call routing and deferred notices without changing operational DND', function () {
    $this->freezeTime();
    Bus::fake([DeliverChatMessageNotifications::class]);
    $actor = User::factory()->create(['role' => 'staff', 'approved_at' => now(), 'dnd_enabled' => false]);
    $sender = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $site = Site::factory()->create();
    ensureCanonicalHrStaffProfile($actor, $site);
    ensureCanonicalHrStaffProfile($sender, $site);
    DB::table('chat_preferences')->insert(['user_id' => $actor->id, 'availability' => 'available', 'last_seen_at' => now(), 'sound_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($actor)->withHeader('X-Chat-Actor', (string) $actor->id);
    $version = $this->getJson(route('chat.preferences.show'))->assertOk()->json('version');
    $next = $this->patchJson(route('chat.preferences.update'), ['version' => $version, 'values' => ['quiet_enabled' => true]])->assertOk()->json('version');
    expect(app(ChatCallAudience::class)->availableIds(collect([$actor])))->toBeEmpty();
    expect($actor->fresh()->dnd_enabled)->toBeFalse();
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$actor, $sender] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)
        ->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Read after quiet time'])->assertCreated();
    expect(app(ChatMessageDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_message_deliveries')->value('status'))->toBe('pending');
    $this->actingAs($actor)->withHeader('X-Chat-Actor', (string) $actor->id)
        ->patchJson(route('chat.preferences.update'), ['version' => $next, 'values' => ['quiet_enabled' => false]])->assertOk();
    expect(app(ChatCallAudience::class)->availableIds(collect([$actor]))->all())->toBe([$actor->id]);
    $this->travel(6)->minutes();
    expect(app(ChatMessageDelivery::class)->recover())->toBe(1);
    expect(app(ChatMessageDelivery::class)->recover())->toBe(0);
    expect($actor->notifications()->count())->toBe(1);
});
