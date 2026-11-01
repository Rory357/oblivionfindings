<?php

use App\Models\OpsConversation;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function chatPruneDelivery(int $messageId, int $userId, string $status, int $ageDays): int
{
    return DB::table('chat_message_deliveries')->insertGetId([
        'notification_id' => (string) Str::uuid(), 'message_id' => $messageId, 'user_id' => $userId,
        'event_key' => 'chat.message.received', 'status' => $status, 'available_at' => now()->subDays($ageDays),
        'created_at' => now()->subDays($ageDays), 'updated_at' => now()->subDays($ageDays),
    ]);
}

it('removes only finished delivery receipts past their keep period', function () {
    $sender = User::factory()->create();
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    $keep = [];
    $gone = [];
    foreach (['superseded', 'suppressed', 'failed', 'delivered', 'pending'] as $status) {
        // One user per row: deliveries are unique per message and person.
        $old = chatPruneDelivery($conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => $status])->id, User::factory()->create()->id, $status, 40);
        $recent = chatPruneDelivery($conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => $status.' recent'])->id, User::factory()->create()->id, $status, 3);
        in_array($status, ['delivered', 'pending'], true) ? $keep[] = $old : $gone[] = $old;
        $keep[] = $recent;
    }

    $this->artisan('chat:prune')->assertSuccessful();

    expect(DB::table('chat_message_deliveries')->whereIn('id', $gone)->count())->toBe(0)
        ->and(DB::table('chat_message_deliveries')->whereIn('id', $keep)->count())->toBe(count($keep));
});

it('removes settled push receipts after a week and keeps waiting ones', function () {
    $sender = User::factory()->create();
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    $delivery = chatPruneDelivery($conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Hello'])->id, User::factory()->create()->id, 'delivered', 1);
    $push = fn (string $status, int $ageDays, int $subscription) => DB::table('chat_browser_push_deliveries')->insertGetId([
        'delivery_id' => $delivery, 'subscription_id' => $subscription, 'subscription_fingerprint' => str_repeat('a', 64),
        'status' => $status, 'available_at' => now()->subDays($ageDays), 'expires_at' => now()->subDays($ageDays)->addDay(),
        'created_at' => now()->subDays($ageDays), 'updated_at' => now()->subDays($ageDays),
    ]);
    $accepted = $push('accepted', 10, 1);
    $recent = $push('accepted', 2, 2);
    $waiting = $push('pending', 10, 3);

    $this->artisan('chat:prune')->assertSuccessful();

    expect(DB::table('chat_browser_push_deliveries')->pluck('id')->sort()->values()->all())->toBe(collect([$recent, $waiting])->sort()->values()->all());
});

it('runs once a night in New Zealand time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'chat:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 2 * * *')
        ->and($event->timezone)->toBe('Pacific/Auckland')
        ->and($event->onOneServer)->toBeTrue();
});
