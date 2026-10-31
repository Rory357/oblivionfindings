<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DeliverChatMessageNotifications;
use App\Models\OpsConversation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatMembershipGaps;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Operations\OpsMessageVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Stephan, 31 Oct 2026: a leaver's private chats stay open for the others; the leaver loses all
// access; a client or family sign-in still closes the chat; a re-hire gets the chat back, but never
// the messages sent while they were away.

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    Bus::fake([DeliverChatMessageNotifications::class]);
});

function leaverStaff(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

function leaverChat(string $type, User ...$people): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => $type, 'title' => $type === 'group' ? 'Night team' : null]);
    foreach ($people as $index => $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => $index === 0 ? 'admin' : 'member']);
    }

    return $conversation;
}

/** The HR end date is the last working day. */
function leaverLeaves(User $user, string $lastDay): void
{
    HrEmployeeProfile::where('user_id', $user->id)->update(['end_date' => $lastDay]);
}

function leaverReturns(User $user, string $firstDay): void
{
    HrEmployeeProfile::where('user_id', $user->id)->update(['start_date' => $firstDay, 'end_date' => null, 'is_active' => true]);
}

function leaverAs(User $user)
{
    return test()->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id);
}

/** @return array<int, string> */
function leaverSees(User $viewer, OpsConversation $conversation): array
{
    return collect(leaverAs($viewer)->getJson(route('chat.messages', $conversation))->assertOk()->json('messages'))->pluck('content')->all();
}

it('keeps a private chat and its history for the others when a member leaves, with the leaver still named', function () {
    $site = Site::factory()->create();
    [$alex, $sam, $kim] = [leaverStaff($site), leaverStaff($site), leaverStaff($site)];
    $group = leaverChat('group', $alex, $sam, $kim);
    $direct = leaverChat('direct', $alex, $sam);
    $group->messages()->create(['sender_id' => $sam->id, 'content' => 'Handover is in the folder']);
    $direct->messages()->create(['sender_id' => $sam->id, 'content' => 'Thanks for covering']);

    leaverLeaves($sam, now('Pacific/Auckland')->subDays(2)->toDateString());

    expect(leaverSees($alex, $group))->toBe(['Handover is in the folder'])
        ->and(leaverSees($alex, $direct))->toBe(['Thanks for covering']);
    leaverAs($alex)->getJson(route('chat.messages', $group))->assertJsonFragment(['id' => $sam->id, 'name' => $sam->name]);
    expect(collect(leaverAs($alex)->getJson(route('chat.conversations'))->assertOk()->json('conversations'))->pluck('id')->all())
        ->toContain($group->id, $direct->id);
    leaverAs($kim)->postJson(route('chat.send', $group), ['mutation_id' => (string) Str::uuid(), 'content' => 'Still here'])->assertCreated();
    expect(app(OpsMessageVisibilityService::class)->unreadCount($alex))->toBe(3);
});

it('shuts the leaver out: no reading, posting, unread count, directory place or re-adding', function () {
    $site = Site::factory()->create();
    [$alex, $sam, $kim] = [leaverStaff($site), leaverStaff($site), leaverStaff($site)];
    $group = leaverChat('group', $alex, $sam, $kim);
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'Roster is out']);
    $other = leaverChat('group', $alex, $kim);
    expect(app(OpsMessageVisibilityService::class)->unreadCount($sam))->toBe(1);

    leaverLeaves($sam, now('Pacific/Auckland')->subDay()->toDateString());

    leaverAs($sam)->getJson(route('chat.messages', $group))->assertForbidden();
    leaverAs($sam)->postJson(route('chat.send', $group), ['mutation_id' => (string) Str::uuid(), 'content' => 'Still here?'])->assertForbidden();
    expect(app(OpsMessageVisibilityService::class)->unreadCount($sam))->toBe(0);

    expect(collect(leaverAs($alex)->getJson(route('chat.conversations'))->json('people'))->pluck('id')->all())->not->toContain($sam->id)
        ->and(collect(leaverAs($alex)->getJson(route('chat.people.options', $other))->assertOk()->json('people'))->pluck('id')->all())->not->toContain($sam->id);
    leaverAs($alex)->postJson(route('chat.people.store', $other), [
        'mutation_id' => (string) Str::uuid(), 'version' => $other->fresh()->chat_version, 'history_policy' => 'from_joining', 'user_ids' => [$sam->id],
    ])->assertForbidden();
    expect($other->participants()->where('user_id', $sam->id)->exists())->toBeFalse();
});

it('closes a staff chat for everyone once a member is a client or family sign-in', function (string $signIn) {
    $site = Site::factory()->create();
    [$alex, $sam, $kim] = [leaverStaff($site), leaverStaff($site), leaverStaff($site)];
    $group = leaverChat('group', $alex, $sam, $kim);
    $group->messages()->create(['sender_id' => $kim->id, 'content' => 'Shift swap?']);
    expect(leaverSees($alex, $group))->toBe(['Shift swap?']);

    if ($signIn === 'next_of_kin') {
        $sam->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system'])->id]);
    } else {
        $sam->update(['role' => 'client']);
    }

    foreach ([$alex, $kim] as $member) {
        leaverAs($member)->getJson(route('chat.messages', $group))->assertForbidden();
    }
    expect(app(OpsMessageVisibilityService::class)->unreadCount($alex))->toBe(0);
})->with(['next_of_kin', 'client']);

it('keeps a managed Site channel following its Site when a member leaves', function () {
    [$home, $elsewhere] = [Site::factory()->create(), Site::factory()->create()];
    [$alex, $sam, $kim] = [leaverStaff($home), leaverStaff($home), leaverStaff($elsewhere)];
    $channel = OpsConversation::create(['conversation_type' => 'group', 'title' => 'Home team', 'is_managed_channel' => true, 'channel_site_id' => $home->id]);
    foreach ([$alex, $sam, $kim] as $person) {
        $channel->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $channel->messages()->create(['sender_id' => $alex->id, 'content' => 'Fire drill at 2']);

    leaverLeaves($sam, now('Pacific/Auckland')->subDay()->toDateString());

    expect(leaverSees($alex, $channel))->toBe(['Fire drill at 2']);
    leaverAs($kim)->getJson(route('chat.messages', $channel))->assertForbidden();
    leaverAs($sam)->getJson(route('chat.messages', $channel))->assertForbidden();
});

it('gives a re-hired member the chat back without the messages sent while they were away', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Pacific/Auckland')->utc());
    $site = Site::factory()->create();
    [$alex, $sam] = [leaverStaff($site), leaverStaff($site)];
    $group = leaverChat('group', $alex, $sam, leaverStaff($site));
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'Before they left']);
    $group->messages()->create(['sender_id' => $sam->id, 'content' => 'My last handover']);

    // Last working day 3 November: the gap starts at midnight, before the hourly check first sees it.
    leaverLeaves($sam, '2026-11-03');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'Pacific/Auckland')->utc());
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'The day after their last shift']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 02:07', 'Pacific/Auckland')->utc());
    $this->artisan('chat:sync-membership-gaps')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Pacific/Auckland')->utc());
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'Sent while away']);

    // Re-hired from 10 November: the gap ends at the start of the new employment.
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Pacific/Auckland')->utc());
    leaverReturns($sam, '2026-11-10');
    expect(app(ChatMembershipGaps::class)->sync())->toBe(1);
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'Welcome back']);

    expect(leaverSees($sam, $group))->toBe(['Before they left', 'My last handover', 'Welcome back'])
        ->and(leaverSees($alex, $group))->toBe(['Before they left', 'My last handover', 'The day after their last shift', 'Sent while away', 'Welcome back'])
        ->and(app(OpsMessageVisibilityService::class)->unreadCount($sam))->toBe(2);
    $search = fn (User $viewer) => collect(leaverAs($viewer)->getJson(route('chat.search', ['kind' => 'messages', 'q' => 'away']))->assertOk()->json('items'))->pluck('message.content')->all();
    expect($search($sam))->toBe([])->and($search($alex))->toBe(['Sent while away']);

    // A second absence is its own gap; the first stays hidden.
    leaverLeaves($sam, '2026-11-20');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00', 'Pacific/Auckland')->utc());
    app(ChatMembershipGaps::class)->sync();
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'Second time away']);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00', 'Pacific/Auckland')->utc());
    leaverReturns($sam, '2026-11-25');
    app(ChatMembershipGaps::class)->sync();

    expect(leaverSees($sam, $group))->toBe(['Before they left', 'My last handover', 'Welcome back'])
        ->and(DB::table('chat_membership_gaps')->where('participant_id', $group->participants()->where('user_id', $sam->id)->value('id'))->whereNotNull('returned_at')->count())->toBe(2);
});

it('never delivers notices from the gap when a member returns', function () {
    $site = Site::factory()->create();
    [$alex, $sam] = [leaverStaff($site), leaverStaff($site)];
    $direct = leaverChat('direct', $alex, $sam);
    $delivery = app(ChatMessageDelivery::class);

    leaverLeaves($sam, now('Pacific/Auckland')->subDays(2)->toDateString());
    app(ChatMembershipGaps::class)->sync();
    $this->travel(1)->hours();
    leaverAs($alex)->postJson(route('chat.send', $direct), ['mutation_id' => (string) Str::uuid(), 'content' => 'Are you still on tonight?'])->assertCreated();

    $this->travel(1)->days();
    leaverReturns($sam, now('Pacific/Auckland')->toDateString());
    app(ChatMembershipGaps::class)->sync();
    $delivery->recover();
    expect($sam->notifications()->count())->toBe(0);

    $this->travel(1)->minutes();
    leaverAs($alex)->postJson(route('chat.send', $direct), ['mutation_id' => (string) Str::uuid(), 'content' => 'Welcome back'])->assertCreated();
    $delivery->recover();
    expect($sam->notifications()->count())->toBe(1);
});
