<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatListStaff(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

function chatListGroups(User $viewer, array $others, int $count): void
{
    foreach (range(1, $count) as $index) {
        $conversation = OpsConversation::create(['conversation_type' => 'group', 'title' => 'Team '.$index]);
        $conversation->participants()->create(['user_id' => $viewer->id, 'role' => 'admin']);
        foreach ($others as $person) {
            $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
        }
        foreach ($others as $person) {
            $conversation->messages()->create(['sender_id' => $person->id, 'sender_type' => 'user', 'content' => 'Update '.$index]);
        }
    }
}

function chatListQueries(User $viewer): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = test()->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)->getJson(route('chat.conversations'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$count, $response];
}

it('adds a bounded number of queries for each conversation in the list', function () {
    $site = Site::factory()->create();
    $others = [chatListStaff($site), chatListStaff($site), chatListStaff($site)];
    [$few, $many] = [chatListStaff($site), chatListStaff($site)];
    chatListGroups($few, $others, 2);
    chatListGroups($many, $others, 6);

    chatListQueries($few); // warm caches shared by both requests
    [$fewQueries, $fewResponse] = chatListQueries($few);
    [$manyQueries, $manyResponse] = chatListQueries($many);
    $perConversation = ($manyQueries - $fewQueries) / 4;
    fwrite(STDERR, sprintf("\nChat list queries: 2 conversations = %d, 6 conversations = %d, %.1f per extra conversation\n", $fewQueries, $manyQueries, $perConversation));

    expect($fewResponse->json('conversations'))->toHaveCount(2)
        ->and($manyResponse->json('conversations'))->toHaveCount(6)
        ->and($perConversation)->toBeLessThanOrEqual(30);
});

it('leaves member-by-member checks out of list rows and keeps them for the open conversation', function () {
    $site = Site::factory()->create();
    $others = [chatListStaff($site), chatListStaff($site)];
    $viewer = chatListStaff($site);
    chatListGroups($viewer, $others, 1);

    [, $list] = chatListQueries($viewer);
    $row = $list->json('conversations.0');
    expect($row)->not->toHaveKeys(['mentionable_ids', 'management', 'can_manage_people'])
        ->and($row)->toHaveKeys(['id', 'title', 'unread', 'latest', 'can_send', 'policy']);

    test()->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)
        ->getJson(route('chat.messages', $row['id']))->assertOk()
        ->assertJsonPath('conversation.can_manage_people', true)
        ->assertJsonCount(2, 'conversation.mentionable_ids')
        ->assertJsonPath('conversation.management.owner_ids', [$viewer->id]);
});
