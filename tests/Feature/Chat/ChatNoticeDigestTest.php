<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ChatMessageNotice;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatSettings;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatDigestFixture(): array
{
    $site = Site::factory()->create();
    $people = collect(range(1, 2))->map(function () use ($site) {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);

        return $user;
    });
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ($people as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }

    return [$conversation, $people[0], $people[1]];
}

function chatDigestSend(OpsConversation $conversation, User $sender, string $text, ?User $mention = null): OpsMessage
{
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => $text]);
    if ($mention) {
        DB::table('chat_message_mentions')->insert(['message_id' => $message->id, 'user_id' => $mention->id, 'start' => 0,
            'length' => mb_strlen($mention->name) + 1, 'label' => '@'.$mention->name, 'created_at' => now()]);
    }
    app(ChatMessageDelivery::class)->enqueue($message, $conversation);

    return $message;
}

function chatDigestNotices(User $user)
{
    return $user->notifications()->where('type', ChatMessageNotice::class);
}

it('turns a backlog into one bell entry for the newest message', function () {
    [$conversation, $sender, $recipient] = chatDigestFixture();
    foreach (['One', 'Two', 'Three'] as $text) {
        chatDigestSend($conversation, $sender, $text);
    }

    app(ChatMessageDelivery::class)->recover();

    expect(chatDigestNotices($recipient)->count())->toBe(1)
        ->and(DB::table('chat_message_deliveries')->where('user_id', $recipient->id)->where('status', 'superseded')->count())->toBe(2)
        ->and(DB::table('chat_message_deliveries')->where('user_id', $recipient->id)->where('status', 'delivered')->count())->toBe(1);
});

it('keeps one unread entry per conversation as new messages arrive', function () {
    [$conversation, $sender, $recipient] = chatDigestFixture();
    chatDigestSend($conversation, $sender, 'First');
    app(ChatMessageDelivery::class)->recover();
    chatDigestSend($conversation, $sender, 'Second');
    app(ChatMessageDelivery::class)->recover();

    expect(chatDigestNotices($recipient)->count())->toBe(2)
        ->and(chatDigestNotices($recipient)->whereNull('read_at')->count())->toBe(1);
});

it('keeps an unread mention when a plain message follows it', function () {
    [$conversation, $sender, $recipient] = chatDigestFixture();
    chatDigestSend($conversation, $sender, 'Can you check the fridge?', $recipient);
    app(ChatMessageDelivery::class)->recover();
    chatDigestSend($conversation, $sender, 'Thanks');
    app(ChatMessageDelivery::class)->recover();

    $unread = DB::table('chat_message_deliveries')->join('notifications', 'notifications.id', '=', 'chat_message_deliveries.notification_id')
        ->where('chat_message_deliveries.user_id', $recipient->id)->whereNull('notifications.read_at')->pluck('chat_message_deliveries.event_key')->sort()->values()->all();
    expect($unread)->toBe(['chat.mentioned', 'chat.message.received']);
});

it('clears the bell entries when the conversation is read', function () {
    [$conversation, $sender, $recipient] = chatDigestFixture();
    chatDigestSend($conversation, $sender, 'Can you check the fridge?', $recipient);
    app(ChatMessageDelivery::class)->recover();
    $latest = chatDigestSend($conversation, $sender, 'Thanks');
    app(ChatMessageDelivery::class)->recover();

    test()->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)
        ->postJson(route('chat.read', $conversation), ['message_id' => $latest->id])->assertNoContent();

    expect(chatDigestNotices($recipient)->whereNull('read_at')->count())->toBe(0);
});

it('holds one entry per conversation while someone is off duty, then delivers the newest', function () {
    [$conversation, $sender, $recipient] = chatDigestFixture();
    DB::table('chat_preferences')->insert(['user_id' => $recipient->id, 'availability' => 'off_duty', 'created_at' => now(), 'updated_at' => now()]);
    foreach (['One', 'Two', 'Three'] as $text) {
        chatDigestSend($conversation, $sender, $text);
    }

    app(ChatMessageDelivery::class)->recover();
    expect(chatDigestNotices($recipient)->count())->toBe(0)
        ->and(DB::table('chat_message_deliveries')->where('user_id', $recipient->id)->where('status', 'pending')->count())->toBe(1);

    DB::table('chat_preferences')->where('user_id', $recipient->id)->update(['availability' => 'available']);
    DB::table('chat_message_deliveries')->where('status', 'pending')->update(['available_at' => now()->subSecond()]);
    app(ChatMessageDelivery::class)->recover();

    expect(chatDigestNotices($recipient)->count())->toBe(1);
});
