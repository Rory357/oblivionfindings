<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatMessageChanges;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatSettings;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatLockFixture(): array
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
    $message = $conversation->messages()->create(['sender_id' => $people[0]->id, 'sender_type' => 'user', 'content' => 'Shift swap?']);

    return [$conversation, $message, $people[0], $people[1]];
}

/** The lock clauses run against the conversation row while $work runs. */
function chatConversationLocks(callable $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'from `ops_conversations`'));
    DB::disableQueryLog();

    return [
        'shared' => $queries->filter(fn (string $sql) => str_contains($sql, 'lock in share mode'))->count(),
        'exclusive' => $queries->filter(fn (string $sql) => str_contains($sql, 'for update'))->count(),
    ];
}

it('lets readers share the conversation lock when marking read, saving preferences and syncing', function () {
    [$conversation, $message, , $reader] = chatLockFixture();
    $acting = fn () => test()->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id);

    expect(chatConversationLocks(fn () => $acting()->postJson(route('chat.read', $conversation), ['message_id' => $message->id])->assertNoContent()))
        ->toBe(['shared' => 1, 'exclusive' => 0]);
    expect(chatConversationLocks(fn () => $acting()->patchJson(route('chat.preferences', $conversation), ['is_muted' => true])->assertNoContent()))
        ->toBe(['shared' => 1, 'exclusive' => 0]);

    $cursor = app(ChatMessageChanges::class)->cursor($conversation, $reader, 0);
    expect(chatConversationLocks(fn () => app(ChatMessageChanges::class)->read($conversation, $reader, $cursor)))
        ->toBe(['shared' => 1, 'exclusive' => 0]);
});

it('delivers notices under a shared conversation lock', function () {
    [$conversation, $message] = chatLockFixture();
    app(ChatMessageDelivery::class)->enqueue($message, $conversation);

    $locks = chatConversationLocks(fn () => app(ChatMessageDelivery::class)->recover());
    expect($locks['shared'])->toBeGreaterThanOrEqual(1)->and($locks['exclusive'])->toBe(0);
});
