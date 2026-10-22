<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Support\SecurityPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatPinStaff(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

function chatPinActing(User $user)
{
    return test()->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id);
}

it('records who pinned a message and when, and audits pin and unpin once each', function () {
    $site = Site::factory()->create();
    [$sender, $other] = [chatPinStaff($site), chatPinStaff($site)];
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$sender, $other] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Fire drill moved to Thursday']);

    chatPinActing($sender)->postJson(route('chat.action', $message), ['action' => 'pin', 'active' => true])->assertNoContent();
    chatPinActing($sender)->postJson(route('chat.action', $message), ['action' => 'pin', 'active' => true])->assertNoContent();
    $message->refresh();
    expect($message->is_pinned)->toBeTrue()
        ->and($message->pinned_by)->toBe($sender->id)
        ->and($message->pinned_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'chat.message.pinned')->count())->toBe(1);

    chatPinActing($other)->getJson(route('chat.messages', $conversation))->assertOk()
        ->assertJsonPath('messages.0.pinned', true)
        ->assertJsonPath('messages.0.pinned_by_name', $sender->name);
    chatPinActing($sender)->getJson(route('chat.messages', $conversation))->assertOk()
        ->assertJsonPath('messages.0.pinned_by_name', 'You');

    chatPinActing($other)->postJson(route('chat.action', $message), ['action' => 'pin', 'active' => false])->assertNoContent();
    $message->refresh();
    expect($message->is_pinned)->toBeFalse()
        ->and($message->pinned_by)->toBeNull()
        ->and($message->pinned_at)->toBeNull()
        ->and(AuditLog::where('action', 'chat.message.unpinned')->where('user_id', $other->id)->count())->toBe(1);
});

it('lets relatives save a message but not pin it in a family conversation', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $relative = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $relative->roles()->syncWithoutDetaching([$role->id]);
    $client->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);
    $conversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    $conversation->participants()->create(['user_id' => $relative->id, 'role' => 'family']);
    $message = $conversation->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'content' => 'Can we visit on Saturday?', 'client_id' => $client->id]);

    chatPinActing($relative)->postJson(route('chat.action', $message), ['action' => 'pin', 'active' => true])
        ->assertForbidden()
        ->assertJsonPath('message', 'Only the care team can pin messages in this conversation. Use Save for me instead.');
    expect($message->fresh()->is_pinned)->toBeFalse();

    chatPinActing($relative)->postJson(route('chat.action', $message), ['action' => 'save', 'active' => true])->assertNoContent();
});

it('applies the Site file-size limit when a file is first uploaded', function () {
    Storage::fake('local');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'))->byDefault();
    app()->instance(MalwareScanner::class, $scanner);

    $site = Site::factory()->create();
    [$sender, $other] = [chatPinStaff($site), chatPinStaff($site)];
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$sender, $other] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }

    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15, 'max_file_mb' => 1]]);
    chatPinActing($sender)->post(route('chat.uploads.store', [$conversation, (string) Str::uuid()]), [
        'file' => UploadedFile::fake()->create('plan.pdf', 2048, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15, 'max_file_mb' => 5]]);
    chatPinActing($sender)->post(route('chat.uploads.store', [$conversation, (string) Str::uuid()]), [
        'file' => UploadedFile::fake()->createWithContent('plan.txt', str_repeat('a', 1024 * 1024 + 10)),
    ], ['Accept' => 'application/json'])->assertOk();
});
