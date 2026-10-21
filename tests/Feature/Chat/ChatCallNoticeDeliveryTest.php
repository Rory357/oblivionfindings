<?php

use App\Jobs\DeliverChatCallNotifications;
use App\Models\ChatCall;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Chat\ChatCallNoticeDelivery;
use App\Services\Chat\ChatMessageDelivery;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    config(['chat.shared_experience' => true]);
    Bus::fake([DeliverChatCallNotifications::class]);
    $site = Site::factory()->create();
    [$this->caller, $this->recipient] = collect(range(1, 2))->map(function () use ($site) {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
        ensureCanonicalHrStaffProfile($user, $site);

        return $user;
    })->all();
    $this->conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$this->caller, $this->recipient] as $user) {
        $this->conversation->participants()->create(['user_id' => $user->id, 'role' => 'member']);
    }
    $this->call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $this->conversation->id,
        'started_by' => $this->caller->id, 'host_id' => $this->caller->id, 'mutation_id' => (string) Str::uuid(),
        'status' => 'ringing', 'ring_until' => now()->addSeconds(30)]);
    $this->call->participants()->create(['user_id' => $this->caller->id, 'media_identity' => (string) Str::uuid(), 'status' => 'accepted']);
    $this->person = $this->call->participants()->create(['user_id' => $this->recipient->id, 'media_identity' => (string) Str::uuid(), 'status' => 'invited']);
    $this->finish = function () {
        DB::transaction(fn () => $this->call->update(['status' => 'missed', 'ended_at' => now()]));

        return DB::table('chat_call_notice_deliveries')->first();
    };
});

it('persists one generic missed notice and opens its authorised history without joining a call', function () {
    $row = ($this->finish)();
    $delivery = app(ChatCallNoticeDelivery::class);
    $delivery->enqueue($this->call->fresh());
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(1)
        ->and($row->user_id)->toBe($this->recipient->id)
        ->and($delivery->recover())->toBe(1)->and($delivery->recover())->toBe(0);
    $notice = $this->recipient->notifications()->sole();
    expect($notice->id)->toBe($row->notification_id)
        ->and($notice->data['title'])->toBe('Missed Chat call')
        ->and($notice->data['event_key'])->toBe('chat.call.missed')
        ->and(json_encode($notice->data))->not->toContain($this->caller->name, $this->call->id)
        ->and($this->caller->notifications()->count())->toBe(0)
        ->and(app(ChatMessageDelivery::class)->filterNotifications($this->recipient->notifications(), $this->recipient)->count())->toBe(1);
    $this->actingAs($this->caller)->get(route('chat.notifications.open', $row->notification_id))->assertNotFound();
    $this->actingAs($this->recipient)->get(route('chat.notifications.open', $row->notification_id))
        ->assertRedirect(route('chat.index', ['conversation' => $this->conversation->id, 'calls' => 'missed']));
    expect($notice->fresh()->read_at)->not->toBeNull()
        ->and($this->person->fresh()->joined_at)->toBeNull()
        ->and($this->conversation->participants()->where('user_id', $this->recipient->id)->value('last_read_at'))->toBeNull();
});

it('does not turn declined answered elsewhere revoked removed or connected calls into missed notices', function (string $status, bool $joined) {
    $this->person->update(['status' => $status, 'joined_at' => $joined ? now() : null]);
    ($this->finish)();
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(0)
        ->and(app(ChatCallNoticeDelivery::class)->recover())->toBe(0);
})->with([['declined', false], ['answered_elsewhere', false], ['revoked', false], ['removed', false], ['removing', false], ['connected', true]]);

it('captures an expired group invitation once and suppresses it when a new invitation replaces it', function () {
    $this->person->update(['status' => 'expired', 'left_at' => now()]);
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(1);
    $this->person->update(['status' => 'invited', 'left_at' => null, 'invitation_version' => 2, 'media_identity' => (string) Str::uuid()]);
    expect(app(ChatCallNoticeDelivery::class)->recover())->toBe(0)
        ->and(DB::table('chat_call_notice_deliveries')->value('status'))->toBe('suppressed');
    $this->person->update(['status' => 'expired', 'left_at' => now()]);
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(2)
        ->and(app(ChatCallNoticeDelivery::class)->recover())->toBe(1);
    ($this->finish)();
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(2);
});

it('rechecks current membership history and notification choices before delivery', function (string $change) {
    $row = ($this->finish)();
    $member = $this->conversation->participants()->where('user_id', $this->recipient->id)->first();
    match ($change) {
        'membership' => $member->delete(),
        'history' => DB::table('chat_history_call_exclusions')->insert(['participant_id' => $member->id, 'call_id' => $this->call->id]),
        'mute' => $member->update(['is_muted' => true]),
        'preference' => UserNotificationPreference::create(['user_id' => $this->recipient->id, 'key' => 'chat.call.missed', 'enabled' => false, 'channel_inapp' => true]),
    };
    expect(app(ChatCallNoticeDelivery::class)->recover())->toBe(0)
        ->and(DB::table('chat_call_notice_deliveries')->where('id', $row->id)->value('status'))->toBe('suppressed')
        ->and($this->recipient->notifications()->count())->toBe(0);
})->with(['membership', 'history', 'mute', 'preference']);

it('removes a delivered notice from authorised lists and links when history access changes', function () {
    $row = ($this->finish)();
    app(ChatCallNoticeDelivery::class)->recover();
    $member = $this->conversation->participants()->where('user_id', $this->recipient->id)->first();
    DB::table('chat_history_call_exclusions')->insert(['participant_id' => $member->id, 'call_id' => $this->call->id]);
    expect(app(ChatMessageDelivery::class)->filterNotifications($this->recipient->notifications(), $this->recipient)->count())->toBe(0);
    $this->actingAs($this->recipient)->get(route('chat.notifications.open', $row->notification_id))->assertNotFound();
    expect($this->recipient->notifications()->sole()->read_at)->toBeNull();
});

it('defers quiet time and recovers a failed persistence attempt without duplicating the notice', function () {
    ($this->finish)();
    $this->recipient->update(['dnd_enabled' => true, 'dnd_until' => now()->addMinute()]);
    $delivery = app(ChatCallNoticeDelivery::class);
    expect($delivery->recover())->toBe(0);
    $this->travel(61)->seconds();
    $cancel = true;
    Event::listen(NotificationSending::class, function () use (&$cancel) {
        return $cancel ? false : null;
    });
    expect($delivery->recover())->toBe(0)
        ->and(DB::table('chat_call_notice_deliveries')->value('attempts'))->toBe(1);
    $cancel = false;
    $this->travel(61)->seconds();
    expect($delivery->recover())->toBe(1)->and($delivery->recover())->toBe(0)
        ->and($this->recipient->notifications()->count())->toBe(1);
});

it('rolls notification work back with its call outcome and leaves disabled rollout untouched', function () {
    try {
        DB::transaction(function () {
            $this->call->update(['status' => 'missed', 'ended_at' => now()]);
            throw new RuntimeException('Rollback fixture');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(0)->and($this->call->fresh()->ended_at)->toBeNull();
    config(['chat.shared_experience' => false]);
    $this->call = $this->call->fresh();
    ($this->finish)();
    expect(DB::table('chat_call_notice_deliveries')->count())->toBe(0);
});
