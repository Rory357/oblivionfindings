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
    Bus::fake([DeliverChatMessageNotifications::class]);
});

function availabilityStaff(?Site $site = null): User
{
    $user = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($user, $site);

    return $user;
}

it('allows only current staff to choose off duty and keeps heartbeat updates from resetting it', function () {
    $staff = availabilityStaff();
    $this->actingAs($staff)->withHeader('X-Chat-Actor', (string) $staff->id);
    $this->getJson(route('chat.calls.status'))->assertOk()->assertJsonPath('can_set_off_duty', true);
    $this->patchJson(route('chat.availability'), ['availability' => 'off_duty'])->assertNoContent();
    $this->patchJson(route('chat.availability'), ['sound_enabled' => false])->assertNoContent();
    $this->patchJson(route('chat.availability'), [])->assertNoContent();
    $this->getJson(route('chat.calls.status'))->assertOk()->assertJsonPath('availability', 'off_duty')->assertJsonPath('sound_enabled', false);
    $this->withHeader('X-Chat-Actor', (string) ($staff->id + 10000))
        ->patchJson(route('chat.availability'), ['availability' => 'available'])->assertForbidden();
    expect(DB::table('chat_preferences')->where('user_id', $staff->id)->value('availability'))->toBe('off_duty');

    $family = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $this->actingAs($family)->withHeader('X-Chat-Actor', (string) $family->id);
    $this->getJson(route('chat.calls.status'))->assertOk()->assertJsonPath('can_set_off_duty', false);
    $this->patchJson(route('chat.availability'), ['availability' => 'off_duty'])->assertForbidden();
    $this->patchJson(route('chat.availability'), ['availability' => 'dnd'])->assertNoContent();
});

it('excludes explicit quiet states from new call routing even with a fresh heartbeat', function () {
    $staff = availabilityStaff();
    $this->actingAs($staff)->withHeader('X-Chat-Actor', (string) $staff->id);
    foreach (['off_duty', 'dnd', 'busy', 'unavailable', 'available'] as $state) {
        $this->patchJson(route('chat.availability'), ['availability' => $state])->assertNoContent();
        expect(app(ChatCallAudience::class)->availableIds(collect([$staff]))->contains($staff->id))->toBe($state === 'available');
    }
});

it('defers chat notices while quiet and delivers exactly once after returning to available', function (string $state) {
    $this->freezeTime();
    $site = Site::factory()->create();
    $sender = availabilityStaff($site);
    $recipient = availabilityStaff($site);
    // An old global DND end must not repeatedly reschedule work in the past.
    $recipient->update(['dnd_enabled' => true, 'dnd_until' => now()->subMinute()]);
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)
        ->patchJson(route('chat.availability'), ['availability' => $state])->assertNoContent();
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$sender, $recipient] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)
        ->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Please read when available'])->assertCreated();
    $delivery = app(ChatMessageDelivery::class);
    expect($delivery->recover())->toBe(0);
    expect(DB::table('chat_message_deliveries')->value('status'))->toBe('pending');
    expect(DB::table('chat_message_deliveries')->value('available_at'))->toBe(now()->addMinutes(5)->toDateTimeString());
    expect($recipient->notifications()->count())->toBe(0);
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)
        ->patchJson(route('chat.availability'), ['availability' => 'available'])->assertNoContent();
    $this->travel(6)->minutes();
    expect($delivery->recover())->toBe(1);
    expect($delivery->recover())->toBe(0);
    expect($recipient->notifications()->count())->toBe(1);
})->with(['off_duty', 'dnd']);
