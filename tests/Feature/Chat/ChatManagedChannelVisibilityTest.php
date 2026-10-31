<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatInvitations;
use App\Services\Chat\ChatSettings;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Support\SecurityPolicy;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatChannelStaff(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

function chatChannelWith(array $people, array $attributes): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => 'group', 'title' => 'Kōwhai House'] + $attributes);
    foreach ($people as $index => $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => $index === 0 ? 'admin' : 'member']);
    }

    return $conversation;
}

it('keeps a Site channel open to its Site when one member now works at another Site', function () {
    [$kowhai, $rata] = [Site::factory()->create(), Site::factory()->create()];
    [$viewer, $colleague] = [chatChannelStaff($kowhai), chatChannelStaff($kowhai)];
    $moved = chatChannelStaff($rata);
    $channel = chatChannelWith([$viewer, $colleague, $moved], ['is_managed_channel' => true, 'channel_site_id' => $kowhai->id]);
    $channel->messages()->create(['sender_id' => $colleague->id, 'sender_type' => 'user', 'content' => 'Fire drill moved to Thursday']);

    test()->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)
        ->getJson(route('chat.messages', $channel))->assertOk()->assertJsonCount(1, 'messages');
    expect(app(OpsMessageVisibilityService::class)->unreadCount($viewer))->toBe(1);

    // The member who moved no longer has the channel's Site, so the channel is closed to them.
    test()->actingAs($moved)->withHeader('X-Chat-Actor', (string) $moved->id)
        ->getJson(route('chat.messages', $channel))->assertForbidden();
});

it('keeps a private group, and the moved member\'s name, for everyone in it', function () {
    [$kowhai, $rata] = [Site::factory()->create(), Site::factory()->create()];
    [$viewer, $colleague] = [chatChannelStaff($kowhai), chatChannelStaff($kowhai)];
    $moved = chatChannelStaff($rata);
    $group = chatChannelWith([$viewer, $colleague, $moved], ['is_managed_channel' => false]);
    $group->messages()->create(['sender_id' => $moved->id, 'sender_type' => 'user', 'content' => 'I start at Rātā on Monday']);

    test()->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)
        ->getJson(route('chat.messages', $group))->assertOk()
        ->assertJsonPath('messages.0.sender_name', $moved->name)
        ->assertJsonFragment(['id' => $moved->id, 'name' => $moved->name]);
    expect(app(OpsMessageVisibilityService::class)->unreadCount($viewer))->toBe(1);

    // The person who moved keeps the group too.
    test()->actingAs($moved)->withHeader('X-Chat-Actor', (string) $moved->id)
        ->getJson(route('chat.messages', $group))->assertOk();
});

it('lets members add only people they can see, even when a member has moved Site', function () {
    [$kowhai, $rata] = [Site::factory()->create(), Site::factory()->create()];
    [$owner, $colleague, $newcomer] = [chatChannelStaff($kowhai), chatChannelStaff($kowhai), chatChannelStaff($kowhai)];
    [$moved, $stranger] = [chatChannelStaff($rata), chatChannelStaff($rata)];
    $group = chatChannelWith([$owner, $colleague, $moved], ['is_managed_channel' => false]);
    $invitations = app(ChatInvitations::class);

    expect($invitations->eligible($owner, $newcomer, $group))->toBeTrue()
        ->and($invitations->eligible($owner, $stranger, $group))->toBeFalse();
});
