<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use App\Support\SecurityPolicy;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatReactionsFixture(): array
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
    $message = $conversation->messages()->create(['sender_id' => $people[0]->id, 'sender_type' => 'user', 'content' => 'Lunch is ready']);

    return [$conversation, $message, $people[0], $people[1]];
}

function chatReact(OpsMessage $message, User $user, string $emoji, bool $active = true)
{
    return test()->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id)
        ->postJson(route('chat.action', $message), ['action' => 'react', 'emoji' => $emoji, 'active' => $active]);
}

it('keeps several different emoji from one person and removes only the one asked for', function () {
    [, $message, $sender] = chatReactionsFixture();

    // 👍 and 😊 used to compare equal in the database, so the second was dropped.
    chatReact($message, $sender, '👍')->assertNoContent();
    chatReact($message, $sender, '😊')->assertNoContent();
    chatReact($message, $sender, '🙏')->assertNoContent();
    expect($message->reactions()->pluck('emoji')->sort()->values()->all())->toBe(collect(['👍', '😊', '🙏'])->sort()->values()->all());

    chatReact($message, $sender, '😊', false)->assertNoContent();
    expect($message->reactions()->pluck('emoji')->sort()->values()->all())->toBe(collect(['👍', '🙏'])->sort()->values()->all());
});

it('accepts any single emoji, including skin tones, flags and joined emoji', function () {
    [, $message, $sender] = chatReactionsFixture();

    foreach (['🫶', '👍🏽', '🇳🇿', '👨‍👩‍👧', '❤️', '#️⃣'] as $emoji) {
        chatReact($message, $sender, $emoji)->assertNoContent();
    }
    expect($message->reactions()->count())->toBe(6);
});

it('refuses text, several emoji or an empty reaction', function () {
    [, $message, $sender] = chatReactionsFixture();

    // Laravel trims input, so " 👍" arrives as a valid "👍"; a space between two emoji stays.
    foreach (['ok', '👍👍', '❤️❤️', '👍🏽👍', '👍 👍', 'a'] as $value) {
        chatReact($message, $sender, $value)->assertUnprocessable()->assertJsonValidationErrors('emoji');
    }
    test()->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)
        ->postJson(route('chat.action', $message), ['action' => 'react', 'active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('emoji');
    expect($message->reactions()->count())->toBe(0);
});

it('shows who reacted, naming the viewer as You', function () {
    [$conversation, $message, $sender, $other] = chatReactionsFixture();
    chatReact($message, $other, '👍')->assertNoContent();
    chatReact($message, $sender, '👍')->assertNoContent();

    test()->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)
        ->getJson(route('chat.messages', $conversation))
        ->assertOk()
        ->assertJsonPath('messages.0.reactions.0.emoji', '👍')
        ->assertJsonPath('messages.0.reactions.0.count', 2)
        ->assertJsonPath('messages.0.reactions.0.mine', true)
        ->assertJsonPath('messages.0.reactions.0.people', [$other->name, 'You']);
});
