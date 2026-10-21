<?php

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Operations\MessageController;
use App\Jobs\DeliverChatFamilyWorkNotifications;
use App\Jobs\DeliverChatMessageNotifications;
use App\Jobs\WakeChatClients;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\ChatUpload;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientPhoto;
use App\Models\ConsentType;
use App\Models\FamilyNote;
use App\Models\NextOfKin;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\ChatMessageNotice;
use App\Services\Chat\ChatAttachmentPublication;
use App\Services\Chat\ChatCallReconciler;
use App\Services\Chat\ChatFamilyWork;
use App\Services\Chat\ChatFamilyWorkDelivery;
use App\Services\Chat\ChatMessageChanges;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatMessageEditing;
use App\Services\Chat\ChatRealtime;
use App\Services\Chat\ChatSettings;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Support\SecurityPolicy;
use Carbon\Carbon;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    // SecurityPolicy keeps a static copy; a timeout set by one test must not log out the next.
    SecurityPolicy::flushCache();
    Bus::fake([DeliverChatMessageNotifications::class, DeliverChatFamilyWorkNotifications::class]);
    // Explicit test policy; production remains unconfigured until an administrator decides.
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
    // Isolated scanner verdicts; this suite never installs or runs an external scanner.
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'))->byDefault();
    app()->instance(MalwareScanner::class, $scanner);
});

function sharedChatCallbackFixture(): array
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    [$relative, $other] = [sharedChatRelative($client), sharedChatRelative($client)];
    [$worker, $cover] = [sharedChatStaff($site), sharedChatStaff($site)];
    $role = Role::create(['name' => 'callback_cover_'.Str::random(8), 'label' => 'Care team', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'family_portal.manage'], ['description' => 'Manage family portal', 'group' => 'operations', 'module' => 'Operations']);
    $role->permissions()->attach($permission->id);
    foreach ([$worker, $cover] as $person) {
        $person->roles()->attach($role->id);
    }
    $conversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    $conversation->participants()->create(['user_id' => $relative->id, 'role' => 'family']);

    return [$client, $conversation, $relative, $other, $worker->fresh(), $cover->fresh()];
}

function sharedChatFollowupFixture(): array
{
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $role = Role::create(['name' => 'followup_profile_'.Str::random(8), 'label' => 'Client profile', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'clients.viewAny'], ['description' => 'View clients', 'group' => 'clients', 'module' => 'Clients']);
    $role->permissions()->attach($permission->id);
    foreach ([$worker, $cover] as $person) {
        $person->roles()->attach($role->id);
    }
    foreach ([$relative, $other] as $person) {
        NextOfKin::create(['client_id' => $client->id, 'user_id' => $person->id, 'relationship' => 'guardian']);
    }
    $portalPermission = Permission::firstOrCreate(['key' => 'clients.viewPortal'], ['description' => 'View portal', 'group' => 'clients', 'module' => 'Clients']);
    Role::where('name', 'next_of_kin')->firstOrFail()->permissions()->syncWithoutDetaching([$portalPermission->id]);
    $consent = AuthoritativeConsentFixture::manualSelf($client, ConsentType::factory()->create(['name' => 'Information Sharing with Whānau / Family', 'category' => 'communication']), $worker);

    return [$client, $conversation, $relative->fresh(), $other->fresh(), $worker->fresh(), $cover->fresh(), $consent, $role];
}

function sharedChatAttachment(OpsMessage $message, string $name = 'visit.png', string $mime = 'image/png', ?string $bytes = null): int
{
    $bytes ??= base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jAvsAAAAASUVORK5CYII=');
    $path = 'chat/'.$message->conversation_id.'/'.Str::uuid();
    Storage::disk('local')->put($path, $bytes);

    return DB::table('chat_attachments')->insertGetId(['message_id' => $message->id, 'storage_path' => $path,
        'name' => $name, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'created_at' => now(), 'updated_at' => now()]);
}

it('blocks the complete file send when any attachment fails scanning without publishing partial content', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->twice()->andReturn(
        new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'),
        new MalwareScanResult(MalwareScanDisposition::Infected, 'test-scanner'),
    );
    app()->instance(MalwareScanner::class, $scanner);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Unpublished message',
        'files' => [UploadedFile::fake()->createWithContent('first.txt', 'First file'), UploadedFile::fake()->createWithContent('second.txt', 'Blocked test file')],
    ])->assertUnprocessable()->assertJsonPath('code', 'chat_file_blocked')->assertJsonValidationErrors('files.1');
    expect($conversation->messages()->count())->toBe(0);
    expect(DB::table('chat_attachments')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('chat'))->toBe([]);
    Bus::assertNotDispatched(DeliverChatMessageNotifications::class);
});

it('fails file sending closed without a scanner but allows text and later exact recovery without rescanning accepted files', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    config(['chat.files.scanner' => []]);
    app()->instance(MalwareScanner::class, new MalwareScanner);
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => 'Retain these files', 'files' => [UploadedFile::fake()->createWithContent('visit.txt', 'Fictional visit')]];
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), $payload)->assertStatus(503)->assertJsonPath('code', 'chat_file_unavailable');
    expect($conversation->messages()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('chat'))->toBe([]);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Text remains available'])->assertCreated();
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'));
    app()->instance(MalwareScanner::class, $scanner);
    $messageId = $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->json('message.id');
    app()->instance(MalwareScanner::class, new MalwareScanner);
    $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.id', $messageId);
    $this->getJson(route('chat.send.outcome', [$conversation, $payload['mutation_id']]))->assertOk()->assertJsonPath('message_id', $messageId);
    expect(DB::table('chat_attachments')->count())->toBe(1);
});

it('rechecks history when another send commits between the initial lookup and locked acknowledgement', function (bool $restrictHistory) {
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $reference = (string) Str::uuid();
    $connection = DB::connection();
    $originalEvents = $connection->getEventDispatcher();
    $events = clone $originalEvents;
    $connection->setEventDispatcher($events);
    $interleaved = false;
    $message = null;
    $events->listen(QueryExecuted::class, function ($query) use (&$interleaved, &$message, $reference, $conversation, $sender, $restrictHistory) {
        // QueryExecuted runs after the first lookup has returned its empty result.
        if ($interleaved || ! str_starts_with(strtolower($query->sql), 'select')
            || ! str_contains($query->sql, 'ops_messages') || ! str_contains($query->sql, 'mutation_id')
            || ! in_array($reference, $query->bindings, true)) {
            return;
        }
        $interleaved = true;
        $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Previously accepted private message', 'mutation_id' => $reference]);
        if ($restrictHistory) {
            $conversation->participants()->where('user_id', $sender->id)->update(['history_after_id' => $message->id]);
        } else {
            $conversation->update(['is_archived' => true]);
        }
    });
    try {
        $result = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => $reference, 'content' => 'Retry contents must not replace the accepted message']);
        expect($interleaved)->toBeTrue();
        if ($restrictHistory) {
            $result->assertForbidden();
        } else {
            $result->assertCreated()->assertJsonPath('message.id', $message->id)->assertJsonPath('message.content', 'Previously accepted private message');
        }
        expect($conversation->messages()->count())->toBe(1);
    } finally {
        $connection->setEventDispatcher($originalEvents);
    }
})->with([true, false]);

it('checks a send outcome without resending content and retains current authority and history boundaries', function () {
    $site = Site::factory()->create();
    [$sender, $reader, $outside] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $reference = (string) Str::uuid();
    $url = route('chat.send.outcome', [$conversation, $reference]);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson($url)->assertOk()->assertExactJson([
        'state' => 'unconfirmed', 'conversation_id' => $conversation->id, 'mutation_id' => $reference,
        'message_id' => null, 'removed' => false, 'can_retry' => true,
    ]);
    expect($conversation->messages()->count())->toBe(0);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Private content', 'mutation_id' => $reference]);
    $result = $this->getJson($url)->assertOk()->assertExactJson([
        'state' => 'confirmed', 'conversation_id' => $conversation->id, 'mutation_id' => $reference,
        'message_id' => $message->id, 'removed' => false, 'can_retry' => true,
    ]);
    expect($result->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson($url)->assertOk()->assertJsonPath('state', 'unconfirmed')->assertJsonPath('message_id', null);
    $this->actingAs($outside)->withHeader('X-Chat-Actor', (string) $outside->id)->getJson($url)->assertForbidden();
    $elsewhere = sharedChatConversation($sender, $reader);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.send.outcome', [$elsewhere, $reference]))->assertOk()->assertJsonPath('state', 'unconfirmed');
    $conversation->update(['is_archived' => true]);
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('can_retry', false);
    $conversation->update(['is_archived' => false]);
    $conversation->participants()->where('user_id', $sender->id)->update(['is_archived_for_user' => true]);
    $this->getJson($url)->assertOk()->assertJsonPath('can_retry', false);
    $conversation->participants()->where('user_id', $sender->id)->update(['is_archived_for_user' => false]);
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['enabled' => false])]);
    $message->delete();
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('removed', true)->assertJsonPath('can_retry', false);
    $conversation->participants()->where('user_id', $sender->id)->update(['history_after_id' => $message->id]);
    $this->getJson($url)->assertForbidden();
    $conversation->participants()->where('user_id', $sender->id)->update(['history_after_id' => null]);
    HrEmployeeProfile::where('user_id', $sender->id)->update(['is_active' => false]);
    $this->actingAs($sender->fresh())->withHeader('X-Chat-Actor', (string) $sender->id)->getJson($url)->assertForbidden();
});

it('denies send outcomes with noncanonical client provenance or revoked family membership', function () {
    [$client, $conversation, $relative, $other] = sharedChatCallbackFixture();
    $reference = (string) Str::uuid();
    $message = $conversation->messages()->create(['sender_id' => $relative->id, 'client_id' => $client->id, 'content' => 'Family update', 'mutation_id' => $reference]);
    $url = route('chat.send.outcome', [$conversation, $reference]);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson($url)->assertOk()->assertJsonPath('state', 'confirmed');
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson($url)->assertForbidden();
    $message->update(['client_id' => null]);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson($url)->assertForbidden();
    $message->update(['client_id' => $client->id]);
    $conversation->participants()->where('user_id', $relative->id)->delete();
    $this->getJson($url)->assertForbidden();
});

it('keeps each uploaded caption with its file and preserves captions on exact send retries', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$sender, $reader, $outside] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id);
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => 'Visit files',
        'files' => [UploadedFile::fake()->create('first.txt', 1, 'text/plain'), UploadedFile::fake()->create('second.txt', 1, 'text/plain')],
        'captions' => ['  First file caption  ', '0']];
    $sent = $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.attachments.0.caption', 'First file caption')
        ->assertJsonPath('message.attachments.1.caption', '0');
    $messageId = $sent->json('message.id');
    $fileId = $sent->json('message.attachments.0.id');
    $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.id', $messageId);
    expect(DB::table('chat_attachments')->where('message_id', $messageId)->count())->toBe(2);
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['enabled' => false, 'files_enabled' => false, 'max_files' => 1])]);
    $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.id', $messageId);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.attachment.metadata', $fileId))->assertOk()->assertJsonPath('caption', 'First file caption');
    $this->actingAs($outside)->withHeader('X-Chat-Actor', (string) $outside->id)->getJson(route('chat.attachment.metadata', $fileId))->assertForbidden();
    OpsMessage::findOrFail($messageId)->delete();
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.messages', $conversation))->assertOk()->assertJsonPath('messages.0.attachments', []);
    $this->getJson(route('chat.attachment.metadata', $fileId))->assertNotFound();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.deleted', true);
    HrEmployeeProfile::where('user_id', $sender->id)->update(['is_active' => false]);
    $this->actingAs($sender->fresh())->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), $payload)->assertForbidden();
});

it('rejects orphan and oversized attachment captions before saving files or a message', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id);
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => 'Keep this draft'];
    $this->postJson(route('chat.send', $conversation), $payload + ['captions' => ['Orphan']])->assertUnprocessable();
    $file = UploadedFile::fake()->create('plan.txt', 1, 'text/plain');
    $this->postJson(route('chat.send', $conversation), $payload + ['files' => [$file], 'captions' => [str_repeat('x', 1001)]])->assertUnprocessable();
    $this->postJson(route('chat.send', $conversation), $payload + ['files' => [$file], 'captions' => [2 => 'Wrong file index']])->assertUnprocessable();
    expect($conversation->messages()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('chat'))->toBe([]);
});

function sharedChatPublicationFixture(): array
{
    Storage::fake('local');
    [$client, $conversation, $relative, $other, $worker, $cover, $consent, $role] = sharedChatFollowupFixture();
    $permission = Permission::firstOrCreate(['key' => 'clients.update'], ['description' => 'Edit client records', 'group' => 'clients', 'module' => 'Clients']);
    $role->permissions()->attach($permission->id);
    $message = $conversation->messages()->create(['sender_id' => $relative->id, 'client_id' => $client->id, 'content' => 'Please keep this visit photo']);
    $attachment = sharedChatAttachment($message);

    return [$client, $conversation, $relative, $other, $worker->fresh(), $attachment, $message, $consent];
}

it('binds private files and publication to the rendered account before reading bytes or writing records', function () {
    [$client, $conversation, $relative, $other, $worker, $attachment] = sharedChatPublicationFixture();
    $second = sharedChatStaff($client->site);
    $second->roles()->attach($worker->roles()->pluck('roles.id')->all());
    $upload = (string) Str::uuid();
    $selection = ['client_id' => $client->id, 'destination' => 'documents', 'family_visible' => false];
    $requests = [
        ['GET', route('chat.uploads.index', $conversation), []],
        ['GET', route('chat.uploads.show', [$conversation, $upload]), []],
        ['POST', route('chat.uploads.store', [$conversation, $upload]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Private file')]],
        ['POST', route('chat.uploads.retry', [$conversation, $upload]), []],
        ['DELETE', route('chat.uploads.destroy', [$conversation, $upload]), []],
        ['GET', route('chat.attachment.metadata', $attachment), []],
        ['GET', route('chat.attachment.publication.options', $attachment), []],
        ['POST', route('chat.attachment.publication.review', $attachment), $selection],
        ['POST', route('chat.attachment.publication.store', $attachment), $selection],
    ];
    $audits = AuditLog::where('action', 'chat.attachment.published')->count();
    $this->actingAs($second->fresh())->flushHeaders();
    foreach ([null, (string) $worker->id] as $actor) {
        if ($actor !== null) {
            $this->withHeader('X-Chat-Actor', $actor);
        }
        foreach ($requests as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        }
        foreach (['chat.attachment', 'chat.attachment.preview'] as $route) {
            $this->get(route($route, $attachment))->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        }
    }
    expect(ChatUpload::find($upload))->toBeNull();
    expect(ClientDocument::count())->toBe(0);
    expect(ClientPhoto::count())->toBe(0);
    expect(DB::table('chat_attachment_publications')->count())->toBe(0);
    expect(AuditLog::where('action', 'chat.attachment.published')->count())->toBe($audits);

    $this->withHeader('X-Chat-Actor', (string) $second->id);
    $this->getJson(route('chat.attachment.metadata', $attachment))->assertOk()->assertJsonPath('can_publish', true);
    $this->getJson(route('chat.attachment.publication.options', $attachment))->assertOk();
    $this->postJson(route('chat.uploads.store', [$conversation, $upload]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Private file')])->assertOk()->assertJsonPath('state', 'ready');
    $this->getJson(route('chat.uploads.index', $conversation))->assertOk()->assertJsonCount(1, 'uploads');
    $this->getJson(route('chat.uploads.show', [$conversation, $upload]))->assertOk()->assertJsonPath('state', 'ready');
    $this->postJson(route('chat.uploads.retry', [$conversation, $upload]))->assertOk()->assertJsonPath('state', 'ready');
    $review = $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->json();
    $payload = $selection + ['audience_token' => $review['audience_token'], 'audience_confirmed' => true, 'mutation_id' => (string) Str::uuid()];
    $record = $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertCreated()->json('record_id');
    $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertOk()->assertJsonPath('record_id', $record)->assertJsonPath('already_created', true);
    expect(ClientDocument::count())->toBe(1);
    expect(AuditLog::where('action', 'chat.attachment.published')->latest('id')->first()->user_id)->toBe($second->id);

    // Native file links carry the original account; a matching query is not permission.
    $this->flushHeaders();
    foreach (['chat.attachment', 'chat.attachment.preview'] as $route) {
        $this->get(route($route, ['attachment' => $attachment, '_chat_actor' => $worker->id]))->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        $this->get(route($route, ['attachment' => $attachment, '_chat_actor' => $second->id]))->assertOk();
    }
    foreach (['chat.uploads.preview', 'chat.uploads.download'] as $route) {
        $url = route($route, [$conversation, $upload]);
        $this->get($url)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        $this->get($url.'?_chat_actor='.$worker->id)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    }
    $download = route('chat.uploads.download', [$conversation, $upload]);
    $this->get($download.'?_chat_actor='.$second->id)->assertOk();
    $this->actingAs($worker)->get($download.'?_chat_actor='.$worker->id)->assertForbidden();
    $this->actingAs($other)->get(route('chat.attachment', ['attachment' => $attachment, '_chat_actor' => $other->id]))->assertForbidden();
    $this->actingAs($second->fresh())->withHeader('X-Chat-Actor', (string) $second->id);
    $this->deleteJson(route('chat.uploads.destroy', [$conversation, $upload]))->assertOk()->assertJsonPath('state', 'discarded');
    $this->deleteJson(route('chat.uploads.destroy', [$conversation, $upload]))->assertOk()->assertJsonPath('state', 'discarded');
});

it('publishes a reviewed private chat copy with explicit audience and exact retry receipts', function () {
    [$client, $conversation, $relative, $other, $worker, $attachment, $message] = sharedChatPublicationFixture();
    $options = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.attachment.publication.options', $attachment))->assertOk()
        ->assertJsonPath('locked_client_id', $client->id)->assertJsonCount(1, 'clients')->assertJsonPath('destinations', ['documents', 'photos']);
    expect($options->headers->get('Cache-Control'))->toContain('no-store');
    $selection = ['client_id' => $client->id, 'destination' => 'documents', 'family_visible' => false];
    $review = $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->assertJsonCount(0, 'family_readers')->json();
    $payload = $selection + ['audience_token' => $review['audience_token'], 'audience_confirmed' => true, 'mutation_id' => (string) Str::uuid()];
    $this->postJson(route('chat.attachment.publication.store', $attachment), [...$payload, 'audience_confirmed' => false])->assertUnprocessable();
    expect(ClientDocument::count())->toBe(0);
    $id = $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertCreated()->json('record_id');
    $record = ClientDocument::findOrFail($id);
    $source = DB::table('chat_attachments')->find($attachment);
    expect($record->portal_visible)->toBeFalse();
    expect($record->storage_path)->not->toBe($source->storage_path);
    expect(Storage::disk('local')->get($record->storage_path))->toBe(Storage::disk('local')->get($source->storage_path));
    expect(DB::table('chat_attachment_publications')->count())->toBe(1);
    expect(AuditLog::where('action', 'chat.attachment.published')->count())->toBe(1);
    $message->delete();
    Storage::disk('local')->delete($source->storage_path);
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['files_enabled' => false])]);
    $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertOk()->assertJsonPath('already_created', true)->assertJsonPath('record_id', $id);
    $this->postJson(route('chat.attachment.publication.store', $attachment), [...$payload, 'family_visible' => true])->assertConflict();
    $record->delete();
    $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertOk()->assertJsonPath('href', null);
    expect(ClientDocument::count())->toBe(0);
    HrEmployeeProfile::where('user_id', $worker->id)->update(['is_active' => false]);
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertForbidden();
});

it('requires a new publication review after changes to content or approved family readers', function () {
    [$client, $conversation, $relative, $other, $worker, $attachment, $message, $consent] = sharedChatPublicationFixture();
    $selection = ['client_id' => $client->id, 'destination' => 'photos', 'family_visible' => true];
    $review = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->assertJsonCount(2, 'family_readers')->json();
    $payload = $selection + ['audience_token' => $review['audience_token'], 'audience_confirmed' => true, 'mutation_id' => (string) Str::uuid()];
    $other->update(['name' => 'Updated approved relative']);
    $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertConflict();
    $latest = $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->json();
    $consent->delete();
    $this->postJson(route('chat.attachment.publication.store', $attachment), [...$payload, 'audience_token' => $latest['audience_token']])->assertConflict();
    $latest = $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->assertJsonCount(0, 'family_readers')->json();
    $path = DB::table('chat_attachments')->find($attachment)->storage_path;
    Storage::disk('local')->put($path, Storage::disk('local')->get($path).'changed');
    $this->postJson(route('chat.attachment.publication.store', $attachment), [...$payload, 'audience_token' => $latest['audience_token']])->assertConflict();
    expect(ClientPhoto::count())->toBe(0);
    $latest = $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->json();
    $id = $this->postJson(route('chat.attachment.publication.store', $attachment), [...$payload, 'audience_token' => $latest['audience_token']])->assertCreated()->json('record_id');
    $photo = ClientPhoto::findOrFail($id);
    expect($photo->visibility)->toBe('family')->and($photo->status)->toBe('approved')->and($photo->uploaded_by_user_id)->toBe($worker->id);
    Storage::disk('local')->assertExists($photo->storage_path);
    expect($message->fresh()->client_id)->toBe($client->id);
});

it('keeps publication behind source history and destination client authority', function () {
    [$client, $conversation, $relative, $other, $worker, $attachment, $message] = sharedChatPublicationFixture();
    $selection = ['client_id' => $client->id, 'destination' => 'documents', 'family_visible' => false];
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.attachment.metadata', $attachment))->assertOk()->assertJsonPath('can_publish', false);
    $this->getJson(route('chat.attachment.publication.options', $attachment))->assertForbidden();
    $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertForbidden();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.attachment.metadata', $attachment))->assertOk()->assertJsonPath('can_publish', true);
    $otherClient = Client::factory()->create(['site_id' => $client->site_id]);
    $this->postJson(route('chat.attachment.publication.review', $attachment), [...$selection, 'client_id' => $otherClient->id])->assertForbidden();
    $conversation->participants()->create(['user_id' => $worker->id, 'role' => 'member', 'history_after_id' => $message->id]);
    $this->getJson(route('chat.attachment.publication.options', $attachment))->assertForbidden();
    $conversation->participants()->where('user_id', $worker->id)->delete();
    $message->update(['client_id' => $otherClient->id]);
    $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertForbidden();
    $message->update(['client_id' => $client->id]);
    // Remove the worker's current site authority without bypassing client commencement rules.
    HrEmployeeProfile::where('user_id', $worker->id)->update(['primary_site_id' => Site::factory()->create()->id, 'secondary_site_ids' => '[]']);
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)
        ->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertForbidden();
});

it('limits publication choices and cleans independent copies after a failed record write', function () {
    [$client, $family, $relative, $other, $worker] = sharedChatPublicationFixture();
    $conversation = sharedChatConversation($worker, sharedChatStaff($client->site));
    $message = $conversation->messages()->create(['sender_id' => $worker->id, 'content' => 'Staff file']);
    $attachment = sharedChatAttachment($message);
    $hidden = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.attachment.publication.options', $attachment))->assertOk()->assertJsonPath('locked_client_id', null)->assertJsonCount(1, 'clients');
    $selection = ['client_id' => $client->id, 'destination' => 'documents', 'family_visible' => false];
    $this->postJson(route('chat.attachment.publication.review', $attachment), [...$selection, 'client_id' => $hidden->id])->assertForbidden();
    $review = $this->postJson(route('chat.attachment.publication.review', $attachment), $selection)->assertOk()->json();
    $payload = $selection + ['audience_token' => $review['audience_token'], 'audience_confirmed' => true, 'mutation_id' => (string) Str::uuid()];
    $dispatcher = ClientDocument::getEventDispatcher();
    $failing = clone $dispatcher;
    $failing->listen('eloquent.creating: '.ClientDocument::class, fn () => throw new RuntimeException('Simulated destination failure'));
    ClientDocument::setEventDispatcher($failing);
    try {
        app(ChatAttachmentPublication::class)->publish($worker, $attachment, $payload);
        $this->fail('The simulated write should fail.');
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('Simulated destination failure');
    } finally {
        ClientDocument::setEventDispatcher($dispatcher);
    }
    expect(Storage::disk('local')->allFiles('client_documents/'.$client->id))->toBe([]);
    expect(DB::table('chat_attachment_publications')->count())->toBe(0);
    $this->postJson(route('chat.attachment.publication.store', $attachment), $payload)->assertCreated();
    $unsupported = sharedChatAttachment($message, 'script.html', 'text/html', '<!doctype html><html><body>Not a document</body></html>');
    $this->getJson(route('chat.attachment.publication.options', $unsupported))->assertOk()->assertJsonPath('destinations', []);
    $this->postJson(route('chat.attachment.publication.review', $unsupported), $selection)->assertUnprocessable();
});

it('previews private chat media with current canonical access and safe serving headers', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$sender, $reader, $outside] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Visit photo']);
    $attachment = sharedChatAttachment($message);
    $this->actingAs($outside)->withHeader('X-Chat-Actor', (string) $outside->id)->getJson(route('chat.attachment.metadata', $attachment))->assertForbidden();
    $this->get(route('chat.attachment.preview', $attachment))->assertForbidden();
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.attachment.metadata', $attachment))->assertOk()
        ->assertJsonPath('message_id', $message->id)->assertJsonPath('conversation_id', $conversation->id)
        ->assertJsonPath('name', 'visit.png')->assertDontSee('storage_path')->assertJsonPath('preview_url', '/chat/api/attachments/'.$attachment.'/preview');
    $preview = $this->get(route('chat.attachment.preview', $attachment))->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    expect($preview->headers->get('Content-Disposition'))->toStartWith('attachment;');
    expect($preview->headers->get('Cache-Control'))->toContain('no-store');
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['enabled' => false, 'files_enabled' => false])]);
    $this->get(route('chat.attachment.preview', $attachment))->assertOk();
    $conversation->participants()->where('user_id', $reader->id)->update(['history_after_id' => $message->id]);
    $this->getJson(route('chat.attachment.metadata', $attachment))->assertForbidden();
    $this->get(route('chat.attachment.preview', $attachment))->assertForbidden();
    $conversation->participants()->where('user_id', $reader->id)->update(['history_after_id' => null]);
    $conversation->participants()->where('user_id', $reader->id)->delete();
    $this->get(route('chat.attachment.preview', $attachment))->assertForbidden();
    $message->delete();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.attachment.metadata', $attachment))->assertNotFound();
    $this->get(route('chat.attachment.preview', $attachment))->assertNotFound();
});

it('keeps family media within the approved conversation and rejects forged client provenance', function () {
    Storage::fake('local');
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $message = $conversation->messages()->create(['sender_id' => $relative->id, 'client_id' => $client->id, 'content' => 'Family photo']);
    $attachment = sharedChatAttachment($message);
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson(route('chat.attachment.metadata', $attachment))->assertForbidden();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.attachment.metadata', $attachment))->assertOk();
    $this->get(route('chat.attachment.preview', $attachment))->assertOk();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->get(route('chat.attachment.preview', $attachment))->assertOk();
    $message->update(['client_id' => Client::factory()->create(['site_id' => $client->site_id])->id]);
    $this->getJson(route('chat.attachment.metadata', $attachment))->assertForbidden();
    $this->get(route('chat.attachment.preview', $attachment))->assertForbidden();
    $message->update(['client_id' => $client->id]);
    $client->portalUsers()->detach($relative->id);
    $this->actingAs($relative->fresh())->withHeader('X-Chat-Actor', (string) $relative->id)->get(route('chat.attachment.preview', $attachment))->assertForbidden();
});

it('refuses unsafe or oversized previews while retaining authorised original downloads', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Documents']);
    $text = sharedChatAttachment($message, 'readme.txt', 'text/plain', 'Original text');
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.attachment.metadata', $text))->assertOk()->assertJsonPath('preview_url', null);
    $this->get(route('chat.attachment.preview', $text))->assertStatus(415);
    $this->get(route('chat.attachment', $text))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    $forged = sharedChatAttachment($message, 'forged.png', 'image/png', '<html>Not an image</html>');
    $this->get(route('chat.attachment.preview', $forged))->assertStatus(415);
    $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jAvsAAAAASUVORK5CYII=');
    $imageBytes = substr_replace($imageBytes, pack('NN', 10000, 10000), 16, 8);
    $hugeImage = sharedChatAttachment($message, 'too-many-pixels.png', 'image/png', $imageBytes);
    $this->get(route('chat.attachment.preview', $hugeImage))->assertStatus(413);
    $large = sharedChatAttachment($message, 'large.pdf', 'application/pdf', '%PDF-1.4'.str_repeat(' ', 25 * 1024 * 1024));
    $this->get(route('chat.attachment.preview', $large))->assertStatus(413);
    $pdf = sharedChatAttachment($message, 'visit.pdf', 'application/pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    $this->get(route('chat.attachment.preview', $pdf))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Storage::disk('local')->delete(DB::table('chat_attachments')->where('id', $pdf)->value('storage_path'));
    $this->getJson(route('chat.attachment.metadata', $pdf))->assertNotFound();
    $this->get(route('chat.attachment.preview', $pdf))->assertNotFound();
});

it('binds family queues coordination and follow-ups to the rendered worker before reads or writes', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatFollowupFixture();
    $conversation->refresh();
    $before = $conversation->getAttributes();
    $note = ['mutation_id' => (string) Str::uuid(), 'action' => 'note', 'note' => 'Private staff coordination'];
    $followup = ['mutation_id' => (string) Str::uuid(), 'action' => 'Review the family visit', 'assigned_to' => $cover->id,
        'due_date' => '2026-10-23', 'audience_token' => str_repeat('a', 64), 'audience_confirmed' => true];
    $requests = [
        ['GET', route('chat.family-inbox'), []],
        ['GET', route('chat.family-work.index', $conversation), []],
        ['PATCH', route('chat.family-work.update', $conversation), $note],
        ['PATCH', route('chat.queue', $conversation), $note],
        ['GET', route('chat.followups.review', $conversation), []],
        ['POST', route('chat.followups.store', $conversation), $followup],
    ];
    $this->actingAs($cover);
    foreach ([null, (string) $worker->id] as $actor) {
        if ($actor !== null) {
            $this->withHeader('X-Chat-Actor', $actor);
        }
        foreach ($requests as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed')
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }
    expect($conversation->fresh()->getAttributes())->toBe($before);
    expect(DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)->count())->toBe(0);
    expect(FamilyNote::where('client_id', $client->id)->count())->toBe(0);
    $this->withHeader('X-Chat-Actor', (string) $cover->id);
    $this->getJson(route('chat.family-inbox'))->assertOk();
    $this->getJson(route('chat.family-work.index', $conversation))->assertOk();
    $this->patchJson(route('chat.family-work.update', $conversation), $note)->assertNoContent();
    $this->patchJson(route('chat.queue', $conversation), $note)->assertNoContent();
    expect(DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)->count())->toBe(1);
    $review = $this->getJson(route('chat.followups.review', $conversation))->assertOk()->json();
    $followup['audience_token'] = $review['audience_token'];
    $id = $this->postJson(route('chat.followups.store', $conversation), $followup)->assertCreated()->json('family_note_id');
    $this->postJson(route('chat.followups.store', $conversation), $followup)->assertOk()->assertJsonPath('family_note_id', $id);
    expect(FamilyNote::where('client_id', $client->id)->count())->toBe(1);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $this->getJson(route('chat.family-work.index', $conversation))->assertForbidden();
    $this->patchJson(route('chat.family-work.update', $conversation), $note)->assertForbidden();
});

it('creates a reviewed shared family follow-up with a real owner without changing chat reply status', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatFollowupFixture();
    $conversation->update(['queue_status' => 'waiting']);
    $conversation->refresh(); // Include database defaults in the unchanged-version assertion.
    $review = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.followups.review', $conversation))->assertOk()->assertJsonCount(2, 'audience')->json();
    expect(collect($review['audience'])->pluck('id')->all())->toContain($other->id);
    expect($conversation->participants()->where('user_id', $other->id)->exists())->toBeFalse();
    $payload = ['mutation_id' => (string) Str::uuid(), 'action' => 'Confirm the Friday visit with the family.', 'assigned_to' => $cover->id,
        'due_date' => '2026-10-23', 'audience_token' => $review['audience_token'], 'audience_confirmed' => true];
    $this->postJson(route('chat.followups.store', $conversation), [...$payload, 'audience_confirmed' => false])->assertUnprocessable();
    expect(FamilyNote::count())->toBe(0);
    $id = $this->postJson(route('chat.followups.store', $conversation), $payload)->assertCreated()->json('family_note_id');
    $note = FamilyNote::findOrFail($id);
    expect($note->assigned_to_user_id)->toBe($cover->id);
    expect($note->description)->toBe($payload['action']);
    expect($note->due_date->toDateString())->toBe('2026-10-23');
    expect($note->note_type)->toBe('todo');
    expect($note->visibility)->toBe('portal');
    expect($conversation->fresh()->queue_status)->toBe('waiting');
    expect($conversation->fresh()->chat_version)->toBe($conversation->chat_version);
    expect(OpsMessage::count())->toBe(0);
    $note->update(['status' => 'completed']);
    $this->postJson(route('chat.followups.store', $conversation), $payload)->assertOk()->assertJsonPath('already_created', true)->assertJsonPath('family_note_id', $id);
    expect($note->fresh()->status)->toBe('completed');
    $this->postJson(route('chat.followups.store', $conversation), [...$payload, 'action' => 'Changed action'])->assertConflict();
    expect(FamilyNote::count())->toBe(1);
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->get(route('portal.clients.family-notes', $client))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/family-notes')->where('notes.0.id', $id)->where('notes.0.assigned_user_name', $cover->name));
    $this->getJson(route('chat.messages', $conversation))->assertForbidden();
});

it('requires a fresh follow-up audience review and rejects unavailable assignees', function () {
    [$client, $conversation, $relative, $other, $worker, $cover, $consent] = sharedChatFollowupFixture();
    $review = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.followups.review', $conversation))->assertOk()->json();
    $payload = ['mutation_id' => (string) Str::uuid(), 'action' => 'Arrange the visit', 'assigned_to' => $cover->id,
        'due_date' => '2026-10-23', 'audience_token' => $review['audience_token'], 'audience_confirmed' => true];
    $other->update(['name' => 'Updated family name']);
    $this->postJson(route('chat.followups.store', $conversation), $payload)->assertConflict();
    expect(FamilyNote::count())->toBe(0);
    $latest = $this->getJson(route('chat.followups.review', $conversation))->assertOk()->json();
    expect($latest['audience_token'])->not->toBe($review['audience_token']);
    $this->postJson(route('chat.followups.store', $conversation), [...$payload, 'audience_token' => $latest['audience_token'], 'assigned_to' => $relative->id])->assertUnprocessable();
    HrEmployeeProfile::where('user_id', $cover->id)->update(['is_active' => false]);
    $this->postJson(route('chat.followups.store', $conversation), [...$payload, 'audience_token' => $latest['audience_token']])->assertUnprocessable();
    $consent->delete();
    $this->getJson(route('chat.followups.review', $conversation))->assertOk()->assertJsonCount(0, 'audience');
    $this->postJson(route('chat.followups.store', $conversation), [...$payload, 'audience_token' => $latest['audience_token'], 'assigned_to' => $worker->id])->assertConflict();
    expect(FamilyNote::count())->toBe(0);
});

it('enforces canonical Family Notes authority and does not recreate removed follow-ups on retry', function () {
    [$client, $conversation, $relative, $other, $worker, $cover, $consent, $role] = sharedChatFollowupFixture();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.followups.review', $conversation))->assertForbidden();
    $review = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.followups.review', $conversation))->assertOk()->json();
    $payload = ['mutation_id' => (string) Str::uuid(), 'action' => 'Arrange the visit', 'assigned_to' => $worker->id,
        'due_date' => '2026-10-23', 'audience_token' => $review['audience_token'], 'audience_confirmed' => true];
    $id = $this->postJson(route('chat.followups.store', $conversation), $payload)->assertCreated()->json('family_note_id');
    FamilyNote::findOrFail($id)->delete();
    $conversation->update(['is_archived' => true]);
    $this->postJson(route('chat.followups.store', $conversation), $payload)->assertOk()->assertJsonPath('href', null)->assertJsonPath('already_created', true);
    $this->postJson(route('chat.followups.store', $conversation), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertConflict();
    expect(FamilyNote::withTrashed()->count())->toBe(1);
    $conversation->update(['is_archived' => false]);
    $worker->roles()->detach($role->id);
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.followups.review', $conversation))->assertForbidden();
    $this->postJson(route('chat.followups.store', $conversation), $payload)->assertForbidden();
});

it('keeps the first unanswered family response clock through sends retries and assignment', function () {
    AppSetting::updateOrCreate(['key' => 'session_timeout_minutes'], ['value' => 30]);
    SecurityPolicy::flushCache();
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $schedule = ['timezone' => 'Pacific/Auckland', 'response_minutes' => 60, 'weekly' => [['day' => 1, 'start' => '08:00', 'end' => '18:00']], 'holidays' => []];
    app(ChatSettings::class)->save($worker, [...ChatSettings::DEFAULTS, 'version' => 0, 'response_schedule' => $schedule]);
    $this->travelTo(Carbon::parse('2026-10-18 19:00:00', 'UTC')->setTimezone(date_default_timezone_get()));
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $audience = sharedChatAudience($this, $conversation);
    $payload = [...$audience, 'mutation_id' => (string) Str::uuid(), 'content' => 'Please follow up'];
    $id = $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->json('message.id');
    $this->travel(30)->minutes();
    $this->postJson(route('chat.send', $conversation), [...$payload, 'mutation_id' => (string) Str::uuid(), 'content' => 'More information'])->assertCreated();
    expect($conversation->fresh()->family_response_message_id)->toBe($id);
    $work = route('chat.family-work.index', $conversation);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($work)->assertOk()->assertJsonPath('response_clock.state', 'due')->assertJsonPath('response_clock.due_at', '2026-10-18T20:00:00.000000Z');
    $this->patchJson(route('chat.family-work.update', $conversation), ['action' => 'claim', 'mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    $this->travel(31)->minutes();
    $this->getJson(route('chat.family-inbox'))->assertUnauthorized()->assertJsonPath('code', 'chat_session_expired');
    // The response deadline outlives the browser session; sign back in to inspect it.
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)
        ->withSession(['last_activity_at' => now()->timestamp])
        ->getJson(route('chat.family-inbox'))->assertOk()->assertJsonPath('items.0.response_clock.state', 'overdue');
    $replyAudience = sharedChatAudience($this, $conversation);
    $this->postJson(route('chat.send', $conversation), [...$replyAudience, 'mutation_id' => (string) Str::uuid(), 'content' => 'We can help with this'])->assertCreated();
    expect($conversation->fresh()->family_response_started_at)->toBeNull();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.send', $conversation), $payload)->assertCreated();
    expect($conversation->fresh()->family_response_started_at)->toBeNull();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($work)->assertOk()->assertJsonPath('response_clock.state', 'not_pending');
    $this->travelBack();
});

it('hides response deadlines outside membership history and clears resolved work', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $message = $conversation->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id, 'content' => 'Earlier request', 'message_type' => 'text']);
    $conversation->update(['family_response_message_id' => $message->id, 'family_response_started_at' => now()]);
    $conversation->participants()->create(['user_id' => $worker->id, 'role' => 'member', 'history_after_id' => $message->id]);
    $schedule = ['timezone' => 'Pacific/Auckland', 'response_minutes' => 60, 'weekly' => [['day' => 1, 'start' => '08:00', 'end' => '18:00']], 'holidays' => []];
    app(ChatSettings::class)->save($cover, [...ChatSettings::DEFAULTS, 'version' => 0, 'response_schedule' => $schedule]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.family-work.index', $conversation))->assertOk()->assertJsonPath('response_clock.state', 'history_restricted')->assertJsonPath('response_clock.due_at', null);
    $this->getJson(route('chat.family-inbox'))->assertOk()->assertJsonPath('items.0.response_clock.due_at', null);
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.family-work.index', $conversation))->assertOk()->assertJsonPath('response_clock.state', 'due');
    $this->patchJson(route('chat.family-work.update', $conversation), ['action' => 'status', 'status' => 'resolved', 'mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    expect($conversation->fresh()->family_response_message_id)->toBeNull();
    $this->getJson(route('chat.family-work.index', $conversation))->assertOk()->assertJsonPath('response_clock.state', 'not_pending');
});

it('validates response schedules and distinguishes inherited from explicit no-promise sites', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $role = Role::create(['name' => 'chat_admin_'.Str::random(8), 'label' => 'Chat administrator', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Manage settings', 'group' => 'settings', 'module' => 'Settings']);
    $role->permissions()->attach($permission->id);
    $worker->roles()->attach($role->id);
    $schedule = ['timezone' => 'Pacific/Auckland', 'response_minutes' => 240, 'weekly' => [['day' => 1, 'start' => '08:00', 'end' => '18:00']], 'holidays' => ['2026-12-25']];
    $payload = [...ChatSettings::DEFAULTS, 'version' => 0, 'response_schedule' => $schedule, 'site_overrides' => [$client->site_id => ['response_schedule' => null]]];
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->putJson(route('settings.chat.update'), $payload)->assertForbidden();
    $this->getJson(route('settings.chat.configuration'))->assertForbidden();
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->putJson(route('settings.chat.update'), [...$payload, 'response_schedule' => [...$schedule, 'weekly' => []]])->assertUnprocessable();
    $this->putJson(route('settings.chat.update'), $payload)->assertOk()->assertJsonPath('settings.version', 1);
    expect(app(ChatSettings::class)->read()['response_schedule']['response_minutes'])->toBe(240);
    expect(app(ChatSettings::class)->read($client->site_id)['response_schedule'])->toBeNull();
    $this->putJson(route('settings.chat.update'), $payload)->assertConflict();
    $this->putJson(route('settings.chat.update'), [...$payload, 'version' => 1, 'site_overrides' => []])->assertOk();
    expect(app(ChatSettings::class)->read($client->site_id)['response_schedule']['response_minutes'])->toBe(240);
    $this->getJson(route('settings.chat.configuration'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('settings.version', 2);
    $this->putJson(route('settings.chat.update'), [...$payload, 'version' => 2, 'response_expectation' => 'Organisation wording', 'site_overrides' => [$client->site_id => ['response_expectation' => '']]])->assertOk();
    expect(app(ChatSettings::class)->read($client->site_id)['response_expectation'])->toBe('');
});

it('projects family home only for the selected linked client and permitted conversation history', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $client->supportWorkers()->attach($worker->id);
    $otherConversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true, 'title' => 'Other relative private topic']);
    $otherConversation->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    $ownCallback = DB::table('chat_callbacks')->insertGetId(['conversation_id' => $conversation->id, 'requested_by' => $relative->id, 'status' => 'requested', 'preferred_time' => 'After lunch', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('chat_callbacks')->insert(['conversation_id' => $otherConversation->id, 'requested_by' => $other->id, 'status' => 'requested', 'preferred_time' => 'Private relative request', 'created_at' => now(), 'updated_at' => now()]);
    $route = route('chat.family-home', $client);
    $headers = ['X-Chat-Actor' => (string) $relative->id];
    $this->actingAs($relative)->getJson($route, $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $relative->id)->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('care_team.conversation_id', null)->assertJsonPath('care_team.calling_enabled', false)
        ->assertJsonPath('care_team.available', false)->assertJsonPath('response.state', 'unconfigured')
        ->assertJsonPath('contacts.0.id', $worker->id)->assertJsonCount(1, 'contacts')
        ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $conversation->id)
        ->assertJsonCount(1, 'callbacks')->assertJsonPath('callbacks.0.id', $ownCallback)
        ->assertJsonMissing(['preferred_time' => 'Private relative request']);
    $conversation->participants()->where('user_id', $relative->id)->update(['history_after_id' => 999, 'callback_history_after_id' => $ownCallback]);
    $this->getJson($route, $headers)->assertOk()->assertJsonCount(0, 'callbacks');
    $this->getJson($route, ['X-Chat-Actor' => (string) $other->id])->assertForbidden();
    $this->getJson(route('chat.family-home', Client::factory()->create()), $headers)->assertForbidden();
    $client->portalUsers()->detach($relative->id);
    $this->getJson($route, $headers)->assertForbidden();
});

it('reports family home calling availability from current service policy and eligible fresh responders', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $client->supportWorkers()->attach($worker->id);
    config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'https://voice.example.test', 'chat.calling.api_key' => 'chat-test', 'chat.calling.api_secret' => str_repeat('test', 12), 'chat.calling.verified' => true, 'chat.calling.authorization_gateway_verified' => true]);
    app(ChatSettings::class)->save($worker, [...ChatSettings::DEFAULTS, 'version' => 0, 'calls_enabled' => true, 'family_calls_enabled' => true]);
    DB::table('chat_preferences')->updateOrInsert(['user_id' => $worker->id], ['availability' => 'available', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $route = route('chat.family-home', $client);
    $headers = ['X-Chat-Actor' => (string) $relative->id];
    $this->actingAs($relative)->getJson($route, $headers)->assertOk()->assertJsonPath('care_team.available', true)->assertJsonPath('contacts.0.available', true);
    DB::table('chat_preferences')->where('user_id', $worker->id)->update(['last_seen_at' => now()->subMinute()]);
    $this->getJson($route, $headers)->assertOk()->assertJsonPath('care_team.available', false)->assertJsonPath('contacts.0.available', false);
    DB::table('chat_preferences')->where('user_id', $worker->id)->update(['last_seen_at' => now()]);
    $worker->update(['dnd_enabled' => true]);
    $this->getJson($route, $headers)->assertOk()->assertJsonPath('care_team.available', false);
    HrEmployeeProfile::where('user_id', $worker->id)->update(['end_date' => today()->subDay()]);
    $this->getJson($route, $headers)->assertOk()->assertJsonCount(0, 'contacts');
});

it('checks family home action metadata against the current actor client and membership without returning messages', function () {
    [$client, $conversation, $relative, $other] = sharedChatCallbackFixture();
    $conversation->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id, 'content' => 'Private message content', 'message_type' => 'text']);
    $route = route('chat.family-home.conversation', [$client, $conversation]);
    $this->actingAs($relative)->getJson($route, ['X-Chat-Actor' => (string) $relative->id])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('actor_id', $relative->id)
        ->assertJsonPath('client_id', $client->id)->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonMissingPath('conversation.latest')->assertJsonMissingPath('messages');
    $this->getJson($route, ['X-Chat-Actor' => (string) $other->id])->assertForbidden();
    $this->actingAs($other)->getJson($route, ['X-Chat-Actor' => (string) $other->id])->assertForbidden();
    $otherClient = Client::factory()->create();
    $otherClient->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);
    $this->actingAs($relative)->getJson(route('chat.family-home.conversation', [$otherClient, $conversation]), ['X-Chat-Actor' => (string) $relative->id])->assertForbidden();
});

it('keeps deliberate family topics separate from private contact shortcuts', function () {
    [$client, $legacy, $relative] = sharedChatCallbackFixture();
    $payload = ['client_id' => $client->id, 'participant_ids' => [], 'title' => 'Friday visit'];
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $topic = $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertCreated()->json('conversation.id');
    $second = $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertCreated()->json('conversation.id');
    expect($second)->not->toBe($topic);
    expect(OpsConversation::findOrFail($topic)->family_contact_kind)->toBeNull();
    $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid(), 'reuse_contact' => true])->assertUnprocessable();
    $shortcut = $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid(), 'reuse_contact' => true, 'title' => null])->assertCreated()->json('conversation.id');
    expect($shortcut)->not->toBe($topic)->not->toBe($second);
    $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid(), 'reuse_contact' => true, 'title' => null])->assertCreated()->assertJsonPath('conversation.id', $shortcut);
});

it('reuses a private family contact by original recipient without mixing relatives or current work owners', function () {
    [$client, $legacy, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $create = fn (array $people = []) => $this->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'reuse_contact' => true, 'participant_ids' => $people])->assertCreated()->json('conversation.id');
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $team = $create();
    expect($team)->not->toBe($legacy->id);
    expect($create())->toBe($team);
    $named = $create([$worker->id]);
    expect($named)->not->toBe($team);
    OpsConversation::findOrFail($named)->update(['assigned_to' => $cover->id, 'title' => 'Renamed conversation']);
    expect($create([$worker->id]))->toBe($named);
    expect($create([$cover->id]))->not->toBe($named);
    expect(OpsConversation::findOrFail($named)->family_contact_user_id)->toBe($worker->id);
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id);
    expect($create())->not->toBe($team);
    expect($create([$worker->id]))->not->toBe($named);
});

it('does not reuse family contacts after audience expansion archiving or membership removal', function () {
    [$client, $legacy, $relative, $other, $worker] = sharedChatCallbackFixture();
    $create = fn () => $this->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'reuse_contact' => true, 'participant_ids' => []])->assertCreated()->json('conversation.id');
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $shared = OpsConversation::findOrFail($create());
    $shared->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    $private = OpsConversation::findOrFail($create());
    expect($private->id)->not->toBe($shared->id);
    // Revoking the other relative does not turn the old shared audience into a private shortcut.
    $client->portalUsers()->detach($other->id);
    expect($create())->toBe($private->id);
    $private->participants()->where('user_id', $relative->id)->update(['is_archived_for_user' => true]);
    $next = OpsConversation::findOrFail($create());
    expect($next->id)->not->toBe($private->id)->not->toBe($shared->id);
    $next->update(['is_archived' => true]);
    $last = OpsConversation::findOrFail($create());
    expect($last->id)->not->toBe($next->id);
    $last->participants()->where('user_id', $relative->id)->delete();
    expect($create())->not->toBe($last->id);
    expect($shared->fresh()->participants()->where('user_id', $other->id)->exists())->toBeTrue();
});

it('keeps family contact reuse receipts exact and rejects a newly ineligible named worker', function () {
    [$client, $legacy, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $payload = ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'reuse_contact' => true, 'participant_ids' => [$worker->id]];
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $id = $this->postJson(route('chat.create'), $payload)->assertCreated()->json('conversation.id');
    $reuse = [...$payload, 'mutation_id' => (string) Str::uuid()];
    $this->postJson(route('chat.create'), $reuse)->assertCreated()->assertJsonPath('conversation.id', $id);
    $this->postJson(route('chat.create'), $reuse)->assertCreated()->assertJsonPath('conversation.id', $id);
    $this->postJson(route('chat.create'), [...$reuse, 'participant_ids' => [$cover->id]])->assertConflict();
    expect(DB::table('chat_creation_requests')->where('conversation_id', $id)->count())->toBe(2);
    expect(AuditLog::where('action', 'chat.conversation.created')->where('client_id', $client->id)->count())->toBe(1);
    $worker->roles()->detach();
    HrEmployeeProfile::where('user_id', $worker->id)->update(['end_date' => today()->subDay()]);
    $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertForbidden();
    expect(OpsConversation::where('family_contact_user_id', $worker->id)->count())->toBe(1);
});

it('publishes only a reviewed urgent contact for the current authorised client site', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $route = route('chat.urgent-contact', $client);
    $headers = ['X-Chat-Actor' => (string) $relative->id];
    $contact = ['label' => 'Service urgent help', 'phone' => '0800 555 0100', 'instructions' => 'For urgent service support.', 'confirmed' => true];
    $siteContact = [...$contact, 'label' => 'Site urgent help', 'phone' => '+64 9 555 0200'];
    $client->site->update(['phone' => '0800 555 9999']);
    $this->actingAs($relative)->getJson($route, $headers)->assertOk()->assertJsonPath('contact', null);
    app(ChatSettings::class)->save($worker, [...ChatSettings::DEFAULTS, 'version' => 0, 'urgent_contact' => $contact, 'site_overrides' => [$client->site_id => ['urgent_contact' => $siteContact]]]);
    $this->getJson($route, $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['actor_id' => $relative->id, 'client_id' => $client->id, 'contact' => ['label' => 'Site urgent help', 'phone' => '+64 9 555 0200', 'instructions' => 'For urgent service support.', 'phone_uri' => 'tel:+6495550200']]);
    $this->getJson($route, ['X-Chat-Actor' => (string) $other->id])->assertForbidden();
    $this->getJson(route('chat.urgent-contact', Client::factory()->create()), $headers)->assertForbidden();
    // Establish a completed canonical transfer without invoking the separate
    // service-commencement workflow in this Chat access fixture.
    DB::table('clients')->where('id', $client->id)->update(['site_id' => Site::factory()->create()->id]);
    $this->getJson($route, $headers)->assertOk()->assertJsonPath('contact.label', 'Service urgent help');
    $client->portalUsers()->detach($relative->id);
    $this->getJson($route, $headers)->assertForbidden();
    $this->actingAs($worker)->getJson($route, ['X-Chat-Actor' => (string) $worker->id])->assertOk();
});

it('validates and audits urgent contact settings with explicit site removal and version checks', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $role = Role::create(['name' => 'urgent_admin_'.Str::random(8), 'label' => 'Chat administrator', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Manage settings', 'group' => 'settings', 'module' => 'Settings']);
    $role->permissions()->attach($permission->id);
    $worker->roles()->attach($role->id);
    $contact = ['label' => ' Service urgent help ', 'phone' => ' 0800 555 0100 ', 'instructions' => '', 'confirmed' => true];
    $payload = [...ChatSettings::DEFAULTS, 'version' => 0, 'urgent_contact' => $contact, 'site_overrides' => [$client->site_id => ['urgent_contact' => null]]];
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->putJson(route('settings.chat.update'), $payload)->assertForbidden();
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->putJson(route('settings.chat.update'), [...$payload, 'urgent_contact' => [...$contact, 'confirmed' => false]])->assertUnprocessable()->assertJsonValidationErrors('urgent_contact.confirmed');
    $this->putJson(route('settings.chat.update'), [...$payload, 'site_overrides' => [$client->site_id => ['urgent_contact' => [...$contact, 'phone' => 'javascript:alert(1)']]]])->assertUnprocessable()->assertJsonValidationErrors('site_overrides.'.$client->site_id.'.urgent_contact.phone');
    expect(app(ChatSettings::class)->read()['version'])->toBe(0);
    $this->putJson(route('settings.chat.update'), $payload)->assertOk()->assertJsonPath('settings.version', 1)->assertJsonPath('settings.urgent_contact.label', 'Service urgent help');
    expect(app(ChatSettings::class)->read($client->site_id)['urgent_contact'])->toBeNull();
    $this->putJson(route('settings.chat.update'), $payload)->assertConflict();
    $this->putJson(route('settings.chat.update'), [...$payload, 'version' => 1, 'site_overrides' => []])->assertOk()->assertJsonPath('settings.version', 2);
    expect(app(ChatSettings::class)->read($client->site_id)['urgent_contact']['phone'])->toBe('0800 555 0100');
    expect(AuditLog::where('action', 'chat.settings.updated')->count())->toBe(2);
});

it('enforces canonical site policy for managed channels while preserving private staff defaults', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    [$sender, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $channel = sharedChatConversation($sender, $receiver);
    $channel->update(['is_managed_channel' => true, 'channel_site_id' => $site->id]);
    $private = sharedChatConversation($sender, $receiver);
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => [...ChatSettings::DEFAULTS, 'site_overrides' => [$site->id => ['enabled' => false, 'files_enabled' => false, 'message_change_window_minutes' => null]]]]);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.messages', $channel))->assertOk()->assertJsonPath('conversation.can_send', false)->assertJsonPath('conversation.policy.files_enabled', false);
    $this->postJson(route('chat.send', $channel), ['mutation_id' => (string) Str::uuid(), 'content' => 'Blocked'])->assertForbidden();
    $this->getJson(route('chat.messages', $private))->assertOk()->assertJsonPath('conversation.can_send', true)->assertJsonPath('conversation.policy.files_enabled', true);
    $this->postJson(route('chat.send', $private), ['mutation_id' => (string) Str::uuid(), 'content' => 'Private defaults'])->assertCreated();
    $cursor = $this->getJson(route('chat.messages', $private))->json('sync_cursor');
    expect(app(ChatSettings::class)->read($otherSite->id)['enabled'])->toBeTrue();
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => [...ChatSettings::DEFAULTS, 'enabled' => false, 'site_overrides' => [$site->id => ['enabled' => true, 'files_enabled' => false]]]]);
    $this->getJson(route('chat.messages', $channel))->assertOk()->assertJsonPath('conversation.can_send', true);
    $this->postJson(route('chat.send', $channel), ['mutation_id' => (string) Str::uuid(), 'content' => 'Enabled site'])->assertCreated();
    $this->postJson(route('chat.send', $channel), ['mutation_id' => (string) Str::uuid(), 'content' => 'Attachment blocked', 'files' => [UploadedFile::fake()->create('note.txt', 1, 'text/plain')]])->assertForbidden();
    $this->getJson(route('chat.messages', $private))->assertOk()->assertJsonPath('conversation.can_send', false);
    $this->postJson(route('chat.changes', $private), ['cursor' => $cursor])->assertOk()->assertJsonPath('conversation.can_send', false);
    expect(DB::table('chat_attachments')->count())->toBe(0);
});

it('validates all effective site settings and preserves unrelated overrides on settings save', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $role = Role::create(['name' => 'scope_admin_'.Str::random(8), 'label' => 'Chat administrator', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Manage settings', 'group' => 'settings', 'module' => 'Settings']);
    $role->permissions()->attach($permission->id);
    $worker->roles()->attach($role->id);
    $otherSite = Site::factory()->create();
    $payload = [...ChatSettings::DEFAULTS, 'version' => 0, 'site_overrides' => [$client->site_id => ['files_enabled' => false, 'max_file_mb' => 4, 'message_change_window_minutes' => null], $otherSite->id => ['max_files' => 2]]];
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->putJson(route('settings.chat.update'), [...$payload, 'site_overrides' => [$client->site_id => ['max_files' => 99]]])->assertUnprocessable();
    $this->putJson(route('settings.chat.update'), [...$payload, 'site_overrides' => [$client->site_id => ['calls_enabled' => true]]])->assertUnprocessable();
    $this->putJson(route('settings.chat.update'), [...$payload, 'site_overrides' => [$client->site_id => ['can_view_private' => true]]])->assertUnprocessable();
    $this->putJson(route('settings.chat.update'), $payload)->assertOk()->assertJsonPath('settings.version', 1);
    $policy = app(ChatSettings::class)->read($client->site_id);
    expect($policy['files_enabled'])->toBeFalse();
    expect($policy['max_file_mb'])->toBe(4);
    expect($policy['message_change_window_minutes'])->toBeNull();
    expect(app(ChatSettings::class)->read($otherSite->id)['files_enabled'])->toBeTrue();
    $this->putJson(route('settings.chat.update'), [...$payload, 'version' => 1, 'site_overrides' => [$otherSite->id => ['max_files' => 2]]])->assertOk();
    expect(app(ChatSettings::class)->read($client->site_id)['max_file_mb'])->toBe(20);
    expect(app(ChatSettings::class)->read($otherSite->id)['max_files'])->toBe(2);
    expect(AuditLog::where('action', 'chat.settings.updated')->count())->toBe(2);
});

it('lets an active call finish after policy disablement while rejecting new calls and revoked participants', function () {
    config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'https://voice.example.test', 'chat.calling.api_key' => 'chat-test', 'chat.calling.api_secret' => str_repeat('test', 12), 'chat.calling.verified' => true, 'chat.calling.authorization_gateway_verified' => true]);
    Http::fake(['https://voice.example.test/*' => Http::response([], 200)]);
    $site = Site::factory()->create();
    [$caller, $receiver, $third] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver, $third);
    $conversation->update(['is_managed_channel' => true, 'channel_site_id' => $site->id]);
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => ['calls_enabled' => false, 'site_overrides' => [$site->id => ['calls_enabled' => true, 'max_call_participants' => 2]]]]);
    DB::table('chat_preferences')->insert(['user_id' => $receiver->id, 'availability' => 'available', 'last_seen_at' => now()]);
    $callerDevice = (string) Str::uuid();
    $receiverDevice = (string) Str::uuid();
    $payload = ['mutation_id' => (string) Str::uuid(), 'device_id' => $callerDevice, 'participant_ids' => [$receiver->id]];
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->getJson(route('chat.messages', $conversation))->assertOk()->assertJsonPath('conversation.can_call', true);
    $this->withHeader('X-Chat-Actor', (string) $caller->id)->postJson(route('chat.calls.start', $conversation), [...$payload, 'participant_ids' => [$receiver->id, $third->id]])->assertUnprocessable();
    $id = $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->postJson(route('chat.calls.start', $conversation), $payload)->assertCreated()->json('call.id');
    $token = $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id)->postJson(route('chat.calls.join', $id), ['device_id' => $receiverDevice])->assertOk()->json('connection.token');
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => ['enabled' => false, 'calls_enabled' => false]]);
    $this->postJson(route('chat.calls.join', $id), ['device_id' => $receiverDevice])->assertOk();
    $this->withHeader('X-Chat-Access-Token', $token)->get(route('chat.calling.authorize'))->assertNoContent();
    app(ChatCallReconciler::class)->reconcile(ChatCall::findOrFail($id));
    expect(ChatCall::findOrFail($id)->ended_at)->toBeNull();
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->postJson(route('chat.calls.start', $conversation), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertStatus(503);
    $conversation->participants()->where('user_id', $receiver->id)->delete();
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id)->postJson(route('chat.calls.join', $id), ['device_id' => $receiverDevice])->assertForbidden();
    $this->get(route('chat.calling.authorize'))->assertForbidden();
    app(ChatCallReconciler::class)->reconcile(ChatCall::findOrFail($id));
    expect(ChatCall::findOrFail($id)->ended_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/RemoveParticipant'));
});

it('counts only authorised family inbox work and refreshes ownership filters', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $waiting = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true, 'queue_status' => 'waiting', 'assigned_to' => $worker->id]);
    $waiting->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => false]);
    $base = route('chat.family-inbox');
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($base)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $conversation->id)->assertJsonPath('items.0.title', $relative->name)
        ->assertJsonPath('counts.open', 1)->assertJsonPath('counts.waiting', 1)->assertJsonPath('counts.mine', 1)->assertJsonPath('items.0.response_expectation', '');
    $this->getJson($base.'?filter=mine')->assertOk()->assertJsonPath('items.0.id', $waiting->id);
    $this->patchJson(route('chat.family-work.update', $waiting), ['mutation_id' => (string) Str::uuid(), 'action' => 'status', 'status' => 'resolved', 'version' => 1])->assertNoContent();
    $this->getJson($base.'?filter=mine&selected='.$waiting->id)->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('counts.mine', 0)->assertJsonPath('counts.resolved', 1)->assertJsonPath('selected_available', true);
    $waiting->update(['care_team_cover' => false]);
    $this->getJson($base.'?selected='.$waiting->id)->assertOk()->assertJsonPath('selected_available', false)->assertJsonPath('counts.resolved', 0);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson($base.'?client='.$client->id)->assertForbidden();
});

it('does not reveal restricted callback history through family inbox counts', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $id = DB::table('chat_callbacks')->insertGetId(['conversation_id' => $conversation->id, 'requested_by' => $relative->id, 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
    $conversation->participants()->create(['user_id' => $worker->id, 'role' => 'member', 'history_after_id' => 0, 'callback_history_after_id' => $id]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.family-inbox').'?filter=callbacks')->assertOk()->assertJsonPath('counts.callbacks', 0)->assertJsonCount(0, 'items');
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.family-inbox').'?filter=callbacks')->assertOk()->assertJsonPath('counts.callbacks', 1)->assertJsonPath('items.0.callback_count', 1);
    DB::table('client_portal_users')->where('client_id', $client->id)->where('user_id', $relative->id)->delete();
    $this->getJson(route('chat.family-inbox'))->assertOk()->assertJsonPath('items.0.title', 'Family conversation');
    $outside = sharedChatStaff(Site::factory()->create());
    $this->actingAs($outside)->withHeader('X-Chat-Actor', (string) $outside->id)->getJson(route('chat.family-inbox'))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('counts.open', 0);
    $this->getJson(route('chat.family-inbox').'?client='.$client->id)->assertForbidden();
});

it('paginates family inbox rows while keeping full authorised queue counts', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    for ($i = 0; $i < 31; $i++) {
        OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    }
    $base = route('chat.family-inbox').'?client='.$client->id;
    $page = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($base)->assertOk()->assertJsonCount(30, 'items')->assertJsonPath('counts.open', 32)->json();
    $this->getJson($base.'&before='.$page['next_before'])->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('counts.open', 32);
    $this->getJson($base.'&through='.$page['scan_floor'])->assertOk()->assertJsonCount(30, 'items')->assertJsonPath('older_before', $page['scan_floor']);
});

it('hands family work over with private encrypted notes and exact mutation retries', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $path = route('chat.family-work.update', $conversation);
    $claim = ['mutation_id' => (string) Str::uuid(), 'action' => 'claim', 'version' => 1, 'note' => 'Check Friday arrangements'];
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($path, $claim)->assertNoContent();
    $this->patchJson($path, $claim)->assertNoContent();
    expect($conversation->fresh()->chat_version)->toBe(2);
    expect(DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)->count())->toBe(1);
    expect(DB::table('chat_family_work_events')->first()->encrypted_note)->not->toContain('Friday');
    $this->patchJson($path, [...$claim, 'note' => 'Changed retry'])->assertConflict();
    $handoff = ['mutation_id' => (string) Str::uuid(), 'action' => 'handoff', 'version' => 2, 'assigned_to' => $cover->id, 'note' => 'Please confirm with family'];
    $this->patchJson($path, $handoff)->assertNoContent();
    $this->getJson(route('chat.family-work.index', $conversation))->assertOk()->assertJsonPath('owner_name', $cover->name)->assertJsonPath('events.0.note', 'Please confirm with family');
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'assign', 'version' => 3, 'assigned_to' => null])->assertNoContent();
    expect($conversation->fresh()->assigned_to)->toBeNull();
    $this->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'status', 'version' => 4, 'status' => 'waiting'])->assertNoContent();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($path, $handoff)->assertNoContent();
    expect($conversation->fresh()->assigned_to)->toBeNull();
    expect($conversation->fresh()->queue_status)->toBe('waiting');
    expect(OpsMessage::where('conversation_id', $conversation->id)->count())->toBe(0);
    expect(AuditLog::where('action', 'like', 'chat.family_work.%')->get()->toJson())->not->toContain('Friday')->not->toContain('Please confirm');
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.family-work.index', $conversation))->assertForbidden();
    $this->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'note', 'note' => 'Not staff'])->assertForbidden();
});

it('rejects stale family ownership and ineligible workers without changing work', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $path = route('chat.family-work.update', $conversation);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'claim', 'version' => 1])->assertNoContent();
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'claim', 'version' => 1])->assertConflict();
    $this->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'claim', 'version' => 2])->assertConflict();
    $this->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'assign', 'version' => 2, 'assigned_to' => $relative->id])->assertUnprocessable();
    $outside = sharedChatStaff(Site::factory()->create());
    $this->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'assign', 'version' => 2, 'assigned_to' => $outside->id])->assertUnprocessable();
    $this->patchJson(route('chat.queue', $conversation), ['version' => 2, 'status' => 'resolved', 'claim' => false])->assertUnprocessable();
    expect($conversation->fresh()->chat_version)->toBe(2);
    $conversation->update(['care_team_cover' => false]);
    $this->getJson(route('chat.family-work.index', $conversation))->assertForbidden();
    $this->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'note', 'note' => 'No longer authorised'])->assertForbidden();
});

it('limits private coordination history by membership id and paginates without family exposure', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $path = route('chat.family-work.update', $conversation);
    $payload = ['mutation_id' => (string) Str::uuid(), 'action' => 'note', 'note' => 'Earlier staff context'];
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($path, $payload)->assertNoContent();
    $boundary = DB::table('chat_family_work_events')->max('id');
    $conversation->participants()->create(['user_id' => $cover->id, 'role' => 'member', 'history_after_id' => 0, 'family_work_history_after_id' => $boundary]);
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.family-work.index', $conversation))->assertOk()->assertJsonCount(0, 'events');
    for ($i = 0; $i < 32; $i++) {
        $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($path, ['mutation_id' => (string) Str::uuid(), 'action' => 'note', 'note' => 'Coordination '.$i])->assertNoContent();
    }
    $page = $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.family-work.index', $conversation))->assertOk()->assertJsonCount(30, 'events')->json();
    $this->getJson(route('chat.family-work.index', $conversation).'?before='.$page['next_before'])->assertOk()->assertJsonCount(2, 'events')->assertJsonPath('events.1.note', 'Coordination 0');
    $this->getJson(route('chat.family-work.index', $conversation).'?through='.$page['scan_floor'])->assertOk()->assertJsonPath('older_before', $page['scan_floor']);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($path, $payload)->assertNoContent();
    expect(DB::table('chat_family_work_events')->count())->toBe(33);
    expect($conversation->fresh()->chat_version)->toBe(1);
});

it('keeps preferred and agreed callback times separate and makes outcome retries exact', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $request = ['mutation_id' => (string) Str::uuid(), 'preferred_time' => 'After 2 pm', 'request_note' => 'Please discuss the visit'];
    $id = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.callbacks.store', $conversation), $request)->assertCreated()->json('id');
    $this->getJson(route('chat.callbacks.index', $conversation))->assertOk()->assertJsonPath('callbacks.0.preferred_time', 'After 2 pm')->assertJsonPath('callbacks.0.agreed_time', null)->assertJsonCount(0, 'owners');
    $this->postJson(route('chat.callbacks.store', $conversation), [...$request, 'mutation_id' => (string) Str::uuid(), 'preferred_time' => 'After 3 pm'])->assertOk()->assertJsonPath('id', $id);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'action' => 'assign', 'assigned_to' => $worker->id])->assertNoContent();
    $this->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 2, 'action' => 'schedule', 'agreed_time' => 'Friday 16 October at 3 pm'])->assertNoContent();
    $attempt = ['mutation_id' => (string) Str::uuid(), 'version' => 3, 'action' => 'attempt'];
    $this->patchJson(route('chat.callbacks.update', $id), $attempt)->assertNoContent();
    $this->patchJson(route('chat.callbacks.update', $id), $attempt)->assertNoContent();
    $this->getJson(route('chat.callbacks.index', $conversation))->assertOk()->assertJsonPath('callbacks.0.attempts', 1)->assertJsonCount(1, 'callbacks.0.attempt_history')->assertJsonPath('callbacks.0.agreed_time', 'Friday 16 October at 3 pm');
    $this->patchJson(route('chat.callbacks.update', $id), [...$attempt, 'action' => 'complete'])->assertConflict();
    $complete = ['mutation_id' => (string) Str::uuid(), 'version' => 4, 'action' => 'complete'];
    $this->patchJson(route('chat.callbacks.update', $id), $complete)->assertNoContent();
    $this->patchJson(route('chat.callbacks.update', $id), $complete)->assertNoContent();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.callbacks.store', $conversation), $request)->assertOk()->assertJsonPath('id', $id);
    expect(DB::table('chat_callbacks')->count())->toBe(1);
    expect(DB::table('chat_callback_events')->where('action', 'attempt')->count())->toBe(1);
    expect(AuditLog::where('action', 'chat.callback.request')->value('meta'))->not->toHaveKey('request_note');
});

it('requires eligible callback owners and explicit conflict review and keeps family audiences separate', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $id = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.callbacks.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'preferred_time' => 'Tomorrow morning'])->assertCreated()->json('id');
    $assign = ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'action' => 'assign', 'assigned_to' => $worker->id];
    $this->patchJson(route('chat.callbacks.update', $id), $assign)->assertForbidden();
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson(route('chat.callbacks.index', $conversation))->assertForbidden();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson(route('chat.callbacks.update', $id), [...$assign, 'assigned_to' => $other->id])->assertUnprocessable();
    $this->patchJson(route('chat.callbacks.update', $id), $assign)->assertNoContent();
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->patchJson(route('chat.callbacks.update', $id), [...$assign, 'mutation_id' => (string) Str::uuid(), 'assigned_to' => $cover->id])->assertConflict();
    $this->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 2, 'action' => 'attempt'])->assertConflict();
    $this->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 2, 'action' => 'assign', 'assigned_to' => null])->assertNoContent();
    $this->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 3, 'action' => 'schedule', 'agreed_time' => 'Tomorrow at 10 am'])->assertConflict();
    $client->portalUsers()->detach($relative->id);
    $this->actingAs($relative->fresh())->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.callbacks.index', $conversation))->assertForbidden();
});

it('applies callback history boundaries before list metadata and direct updates even within the same second', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $this->freezeTime();
    $id = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.callbacks.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'preferred_time' => 'Private earlier request'])->assertCreated()->json('id');
    $conversation->participants()->create(['user_id' => $other->id, 'role' => 'family', 'history_after_id' => 0, 'history_joined_at' => now(), 'callback_history_after_id' => $id]);
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson(route('chat.callbacks.index', $conversation))->assertOk()->assertJsonCount(0, 'callbacks')->assertJsonPath('next_before', null);
    $this->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'action' => 'cancel'])->assertNotFound();
    $newId = $this->postJson(route('chat.callbacks.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'preferred_time' => 'New visible request'])->assertCreated()->json('id');
    $this->getJson(route('chat.callbacks.index', $conversation))->assertOk()->assertJsonCount(1, 'callbacks')->assertJsonPath('callbacks.0.id', $newId);
    $this->getJson(route('chat.callbacks.index', ['conversation' => $conversation, 'through' => $newId]))->assertOk()->assertJsonPath('older_before', null);
    $this->travelBack();
});

it('paginates callback history and refreshes only authorised loaded ranges', function () {
    [$client, $conversation, $relative] = sharedChatCallbackFixture();
    for ($i = 0; $i < 32; $i++) {
        DB::table('chat_callbacks')->insert(['conversation_id' => $conversation->id, 'requested_by' => $relative->id, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    }
    $page = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.callbacks.index', $conversation))->assertOk()->assertJsonCount(30, 'callbacks');
    $this->getJson(route('chat.callbacks.index', ['conversation' => $conversation, 'before' => $page->json('next_before')]))->assertOk()->assertJsonCount(2, 'callbacks')->assertJsonPath('next_before', null);
    $this->getJson(route('chat.callbacks.index', ['conversation' => $conversation, 'through' => $page->json('scan_floor')]))->assertOk()->assertJsonCount(30, 'callbacks')->assertJsonPath('next_before', null)->assertJsonPath('older_before', $page->json('scan_floor'));
    $first = DB::table('chat_callbacks')->where('conversation_id', $conversation->id)->min('id');
    DB::table('chat_callbacks')->where('id', $first)->update(['status' => 'requested']);
    $this->getJson(route('chat.callbacks.index', $conversation))->assertOk()->assertJsonCount(30, 'callbacks')->assertJsonPath('current_callback.id', $first)->assertJsonPath('can_request', false);
});

it('excludes revoked relatives from search people and prevents assignment to excluded callback history', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $conversation->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    $client->portalUsers()->detach($other->id);
    $result = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.search', ['kind' => 'people', 'client' => $client->id]))->assertOk();
    expect(array_column($result->json('options.people'), 'id'))->not->toContain($other->id);
    expect(array_column($result->json('items'), 'id'))->not->toContain($other->id);
    $id = $this->postJson(route('chat.callbacks.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'preferred_time' => 'After 2 pm'])->assertCreated()->json('id');
    $conversation->participants()->create(['user_id' => $cover->id, 'role' => 'member', 'history_after_id' => 0, 'callback_history_after_id' => $id, 'history_joined_at' => now()]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson(route('chat.callbacks.update', $id), ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'action' => 'assign', 'assigned_to' => $cover->id])->assertUnprocessable();
    $result = $this->getJson(route('chat.callbacks.index', $conversation))->assertOk();
    expect($result->json('callbacks.0.eligible_owner_ids'))->not->toContain($cover->id);
});

it('searches only current authorised history across conversations and all four result kinds', function () {
    $site = Site::factory()->create();
    [$viewer, $member, $stranger] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($viewer, $member);
    $conversation->update(['title' => 'Visible care team']);
    $private = sharedChatConversation($member, $stranger);
    $private->update(['title' => 'Secret team']);
    $private->messages()->create(['sender_id' => $stranger->id, 'content' => 'Secret message']);
    $excluded = $conversation->messages()->create(['sender_id' => $member->id, 'content' => 'Earlier excluded']);
    $granted = $conversation->messages()->create(['sender_id' => $member->id, 'content' => 'Explicitly shared']);
    $membership = $conversation->participants()->where('user_id', $viewer->id)->first();
    $membership->update(['history_after_id' => $granted->id]);
    DB::table('chat_history_grants')->insert(['participant_id' => $membership->id, 'message_id' => $granted->id, 'created_at' => now()]);
    $file = $conversation->messages()->create(['sender_id' => $member->id, 'content' => 'Current message', 'attachments' => [['name' => 'Care plan.pdf']]]);
    $conversation->messages()->create(['sender_id' => $member->id, 'client_id' => Client::factory()->create(['site_id' => $site->id])->id, 'content' => 'Wrong provenance']);
    $search = fn ($params) => $this->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)->getJson(route('chat.search', $params));
    $result = $search(['kind' => 'messages'])->assertOk()->assertJsonCount(2, 'items')->assertDontSee('Secret')->assertDontSee('Earlier excluded')->assertDontSee('Wrong provenance');
    expect(array_column($result->json('options.people'), 'id'))->not->toContain($stranger->id);
    $search(['kind' => 'files', 'q' => 'plan.pdf'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $file->id);
    $search(['kind' => 'messages', 'q' => 'plan.pdf'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $file->id);
    $search(['kind' => 'people', 'q' => $member->name])->assertOk()->assertJsonPath('items.0.id', $member->id);
    $search(['kind' => 'conversations', 'q' => 'Visible'])->assertOk()->assertJsonPath('items.0.id', $conversation->id);
    $search(['kind' => 'messages', 'conversation' => $private->id])->assertForbidden();
    $search(['kind' => 'messages', 'through' => $granted->id])->assertOk()->assertJsonPath('older_before', null);
    $file->delete();
    $search(['kind' => 'files'])->assertOk()->assertJsonCount(0, 'items');
    $membership->delete();
    $search(['kind' => 'messages'])->assertOk()->assertJsonCount(0, 'items')->assertJsonCount(0, 'options.conversations');
});

it('paginates search and applies literal words sender and Auckland day boundaries', function () {
    $this->travelTo(Carbon::parse('2026-10-15T12:00:00Z'));
    $site = Site::factory()->create();
    [$viewer, $member] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($viewer, $member);
    $ids = [];
    for ($i = 0; $i < 53; $i++) {
        $ids[] = $conversation->messages()->create(['sender_id' => $member->id, 'content' => 'Match '.$i])->id;
    }
    $first = $this->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)->getJson(route('chat.search', ['kind' => 'messages', 'q' => 'Match']))->assertOk()->assertJsonCount(50, 'items');
    $this->getJson(route('chat.search', ['kind' => 'messages', 'q' => 'Match', 'before' => $first->json('next_before')]))->assertOk()->assertJsonCount(3, 'items')->assertJsonPath('next_before', null);
    $this->getJson(route('chat.search', ['kind' => 'messages', 'q' => 'Match', 'through' => $ids[3]]))->assertOk()->assertJsonCount(50, 'items')->assertJsonPath('older_before', $ids[3])->assertJsonPath('next_before', null);
    $today = $conversation->messages()->create(['sender_id' => $member->id, 'content' => '100%_care']);
    $today->forceFill(['created_at' => '2026-10-15 11:00:00'])->save();
    $yesterday = $conversation->messages()->create(['sender_id' => $member->id, 'content' => '100%_care']);
    $yesterday->forceFill(['created_at' => '2026-10-15 10:59:59'])->save();
    $tomorrow = $conversation->messages()->create(['sender_id' => $member->id, 'content' => '100%_care']);
    $tomorrow->forceFill(['created_at' => '2026-10-16 11:00:00'])->save();
    $conversation->messages()->create(['sender_id' => $viewer->id, 'content' => '100%_care']);
    $conversation->messages()->create(['sender_id' => $member->id, 'content' => '100XXcare']);
    $this->getJson(route('chat.search', ['kind' => 'messages', 'q' => '%_', 'date' => 'today', 'sender' => $member->id]))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $today->id);
    $this->travelBack();
});

it('isolates family search audiences client context and revoked relationships', function () {
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    [$viewer, $relative] = [sharedChatRelative($client), sharedChatRelative($client)];
    foreach ([$viewer, $relative] as $person) {
        $conversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'title' => 'Family '.$person->id]);
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
        $conversation->messages()->create(['sender_id' => $person->id, 'client_id' => $client->id, 'content' => 'Private '.$person->id]);
    }
    $this->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)->getJson(route('chat.search', ['kind' => 'messages']))->assertForbidden();
    foreach (['messages', 'conversations', 'people'] as $kind) {
        $this->getJson(route('chat.search', ['kind' => $kind, 'client' => $client->id]))->assertOk()->assertJsonCount(1, 'items')->assertJsonCount(1, 'options.conversations')->assertJsonCount(1, 'options.people')->assertDontSee('Private '.$relative->id);
    }
    $this->getJson(route('chat.search', ['kind' => 'messages', 'client' => $client->id, 'conversation' => $conversation->id]))->assertForbidden();
    $client->portalUsers()->detach($viewer->id);
    $this->actingAs($viewer->fresh())->withHeader('X-Chat-Actor', (string) $viewer->id)->getJson(route('chat.search', ['kind' => 'messages', 'client' => $client->id]))->assertForbidden();
});

it('removes only the reviewed message version and resolves an exact retry without another event', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Original']);
    $remove = ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => 0];
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.action', $message), ['action' => 'delete'])->assertUnprocessable();
    $edit = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Reviewed correction', 'mentions' => []];
    $this->patchJson(route('chat.messages.edit', $message), $edit)->assertOk();
    $this->postJson(route('chat.action', $message), $remove)->assertConflict()->assertJsonPath('code', 'chat_remove_conflict');
    expect($message->fresh()->content)->toBe('Reviewed correction');
    $remove = [...$remove, 'mutation_id' => (string) Str::uuid(), 'version' => 1];
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->postJson(route('chat.action', $message), $remove)->assertForbidden();
    $cursor = $this->getJson(route('chat.messages', $conversation))->json('sync_cursor');
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.action', $message), $remove)->assertNoContent();
    $removed = OpsMessage::withTrashed()->findOrFail($message->id);
    expect($removed->trashed())->toBeTrue();
    expect($removed->chat_edit_version)->toBe(2);
    $revision = DB::table('chat_message_revisions')->where('version', 2)->sole();
    expect($revision->previous_state)->not->toContain('Reviewed correction');
    expect(json_decode(Crypt::decryptString($revision->previous_state), true)['content'])->toBe('Reviewed correction');
    $eventCount = DB::table('chat_message_changes')->count();
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['enabled' => false, 'message_change_window_minutes' => 0])]);
    $this->postJson(route('chat.action', $message), $remove)->assertNoContent();
    expect(DB::table('chat_message_changes')->count())->toBe($eventCount);
    expect(DB::table('chat_message_revisions')->count())->toBe(2);
    expect(AuditLog::where('action', 'chat.message.removed')->count())->toBe(1);
    $this->postJson(route('chat.action', $message), [...$remove, 'version' => 0])->assertConflict();
    $this->postJson(route('chat.action', $message), ['action' => 'pin', 'active' => true])->assertNotFound();
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertOk()->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.content', '')->assertDontSee('previous_state');
    $conversation->participants()->where('user_id', $sender->id)->delete();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.action', $message), $remove)->assertForbidden();
});

it('isolates removal references from edits and other messages and rolls back the full removal', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Retain this']);
    $other = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Other message']);
    $remove = ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => 0];
    try {
        DB::transaction(function () use ($sender, $message, $remove) {
            app(ChatMessageEditing::class)->remove($sender, $message, $remove);
            throw new RuntimeException('Rollback removal');
        });
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('Rollback removal');
    }
    expect($message->fresh()->content)->toBe('Retain this');
    expect(DB::table('chat_message_revisions')->count())->toBe(0);
    expect(AuditLog::where('action', 'chat.message.removed')->count())->toBe(0);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.action', $message), $remove)->assertNoContent();
    $this->postJson(route('chat.action', $other), $remove)->assertConflict();
    $this->patchJson(route('chat.messages.edit', $other), ['mutation_id' => $remove['mutation_id'], 'version' => 0, 'content' => 'Changed', 'mentions' => []])->assertConflict();
    expect($other->fresh()->content)->toBe('Other message');
    $other->update(['client_id' => Client::factory()->create(['site_id' => $site->id])->id]);
    $this->postJson(route('chat.action', $other), [...$remove, 'mutation_id' => (string) Str::uuid()])->assertForbidden();
});

it('edits only own text with encrypted revisions and exact retry receipts', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Original private text']);
    $body = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Corrected text', 'mentions' => []];
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->patchJson(route('chat.messages.edit', $message), $body)->assertForbidden();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), $body)->assertOk()->assertJsonPath('message.edit_version', 1)->assertJsonPath('message.content', 'Corrected text');
    $row = DB::table('chat_message_revisions')->sole();
    expect($row->previous_state)->not->toContain('Original private text');
    expect(json_decode(Crypt::decryptString($row->previous_state), true)['content'])->toBe('Original private text');
    expect(AuditLog::where('action', 'chat.message.edited')->value('meta'))->not->toHaveKey('content');
    // Retry still resolves after policy expiry, without another revision or changing timestamps.
    $message->forceFill(['created_at' => now()->subHour()])->save();
    $this->patchJson(route('chat.messages.edit', $message), $body)->assertOk()->assertJsonPath('message.edit_version', 1);
    expect(DB::table('chat_message_revisions')->count())->toBe(1);
    $this->patchJson(route('chat.messages.edit', $message), [...$body, 'content' => 'Different retry'])->assertConflict();
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.messages', $conversation))->assertOk()->assertDontSee('Original private text')->assertJsonPath('messages.0.can_edit', false);
});

it('rejects stale edits and synchronises revised Unicode text and mentions without exposing revisions', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Original']);
    $cursor = $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.messages', $conversation))->json('sync_cursor');
    $label = '@'.$reader->name;
    $body = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => '  🙂 '.$label.' revised', 'mentions' => [['user_id' => $reader->id, 'start' => 4, 'length' => mb_strlen($label)]]];
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), $body)->assertOk()->assertJsonPath('message.mentions.0.start', 2);
    $this->patchJson(route('chat.messages.edit', $message), [...$body, 'mutation_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'chat_edit_conflict');
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertOk()->assertJsonPath('messages.0.content', trim($body['content']))->assertJsonCount(0, 'new_message_ids')->assertDontSee('previous_state');
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'content' => 'No mention now', 'mentions' => []])->assertOk();
    expect(DB::table('chat_message_mentions')->where('message_id', $message->id)->exists())->toBeFalse();
    expect(DB::table('chat_message_revisions')->count())->toBe(2);
});

it('enforces the configured edit and removal window and preserves attachments and original provenance', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Text', 'attachments' => [['name' => 'retained.txt']], 'created_at' => now()->subHour()]);
    $message->forceFill(['created_at' => now()->subHour()])->save();
    $body = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => '', 'mentions' => []];
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), $body)->assertConflict();
    $this->postJson(route('chat.action', $message), ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => (int) $message->fresh()->chat_edit_version])->assertConflict();
    $message->forceFill(['created_at' => now()])->save();
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['message_change_window_minutes' => null])]);
    $this->patchJson(route('chat.messages.edit', $message), $body)->assertConflict();
    app(ChatSettings::class)->save($sender, [...ChatSettings::DEFAULTS, 'version' => 0, 'message_change_window_minutes' => 15]);
    $this->patchJson(route('chat.messages.edit', $message), [...$body, 'reply_to_id' => 123])->assertUnprocessable();
    $this->patchJson(route('chat.messages.edit', $message), $body)->assertOk();
    expect($message->fresh()->attachments)->toBe([['name' => 'retained.txt']]);
    expect($message->fresh()->sender_id)->toBe($sender->id);
    $this->postJson(route('chat.action', $message), ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => (int) $message->fresh()->chat_edit_version])->assertNoContent();
    $this->patchJson(route('chat.messages.edit', $message), $body)->assertNotFound();
    // Both the edit and the subsequent removal retain a restricted revision.
    expect(DB::table('chat_message_revisions')->where('message_id', $message->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('rolls revision history back with an edit and denies noncanonical message provenance', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Keep original']);
    $body = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Rolled back edit', 'mentions' => []];
    try {
        DB::transaction(function () use ($sender, $message, $body) {
            app(ChatMessageEditing::class)->edit($sender, $message, $body);
            throw new RuntimeException('Rollback fixture');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Rollback fixture');
    }
    expect($message->fresh()->content)->toBe('Keep original');
    expect(DB::table('chat_message_revisions')->count())->toBe(0);
    expect(DB::table('chat_message_changes')->count())->toBe(0);
    $message->forceFill(['client_id' => Client::factory()->create(['site_id' => $site->id])->id])->save();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), $body)->assertForbidden();
    config(['chat.shared_experience' => false]);
    $this->patchJson(route('chat.messages.edit', $message), $body)->assertNotFound();
});

it('denies edits after family relationship revocation and isolates separate relatives', function () {
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    [$sender, $other] = [sharedChatRelative($client), sharedChatRelative($client)];
    $conversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id]);
    $conversation->participants()->create(['user_id' => $sender->id, 'role' => 'member']);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'client_id' => $client->id, 'content' => 'Family original']);
    $body = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Family correction', 'mentions' => []];
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->patchJson(route('chat.messages.edit', $message), $body)->assertForbidden();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), $body)->assertOk();
    $client->portalUsers()->detach($sender->id);
    $this->actingAs($sender->fresh())->withHeader('X-Chat-Actor', (string) $sender->id)->patchJson(route('chat.messages.edit', $message), $body)->assertForbidden();
    expect(DB::table('chat_message_revisions')->count())->toBe(1);
});

function enableSharedChatRealtime(): void
{
    config(['chat.realtime.enabled' => true, 'broadcasting.connections.chat.key' => 'chat-test-public',
        'broadcasting.connections.chat.secret' => 'chat-test-secret-not-for-deployment', 'broadcasting.connections.chat.app_id' => 'chat-tests']);
}

it('authorises only the signed-in eligible viewer live channel and exposes no server secret', function () {
    $site = Site::factory()->create();
    [$viewer, $other] = [sharedChatStaff($site), sharedChatStaff($site)];
    $this->actingAs($viewer)->withHeader('X-Chat-Actor', (string) $viewer->id)->getJson(route('chat.realtime'))->assertOk()->assertExactJson(['actor_id' => $viewer->id, 'enabled' => false]);
    enableSharedChatRealtime();
    $config = $this->getJson(route('chat.realtime'))->assertOk()->assertJsonPath('channel', 'private-chat.user.'.$viewer->id)->assertJsonPath('path', '/chat-live');
    expect($config->getContent())->not->toContain('chat-test-secret');
    $channel = 'private-chat.user.'.$viewer->id;
    $this->postJson(route('chat.realtime.auth'), ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk()->assertJsonPath('auth', 'chat-test-public:'.hash_hmac('sha256', '123.456:'.$channel, 'chat-test-secret-not-for-deployment'));
    $this->postJson(route('chat.realtime.auth'), ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$other->id])->assertForbidden();
    $this->postJson(route('chat.realtime.auth'), ['socket_id' => '123.456', 'channel_name' => 'chat.public'])->assertForbidden();
    $this->postJson(route('chat.realtime.auth'), ['socket_id' => 'not-a-socket', 'channel_name' => $channel])->assertUnprocessable();
    $viewer->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($viewer->fresh())->getJson(route('chat.realtime'))->assertForbidden();
    $this->postJson(route('chat.realtime.auth'), ['socket_id' => '123.456', 'channel_name' => $channel])->assertForbidden();
});

it('rechecks family portal eligibility for live-channel renewal', function () {
    enableSharedChatRealtime();
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $relative = sharedChatRelative($client);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.realtime'))->assertOk()->assertJsonPath('enabled', true);
    $client->portalUsers()->detach($relative->id);
    $this->actingAs($relative->fresh())->postJson(route('chat.realtime.auth'), ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$relative->id])->assertForbidden();
});

it('publishes content-free live hints only to currently authorised recipients', function () {
    enableSharedChatRealtime();
    $site = Site::factory()->create();
    [$sender, $recipient, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $sent = [];
    $transport = Mockery::mock(Broadcaster::class);
    $transport->shouldReceive('broadcast')->andReturnUsing(function ($channels, $event, $data) use (&$sent) {
        $sent[] = ['channels' => array_map(fn ($channel) => (string) $channel, $channels), 'event' => $event, 'data' => $data];
    });
    Broadcast::shouldReceive('connection')->with('chat')->andReturn($transport);
    $service = app(ChatRealtime::class);
    $service->publish($conversation->id);
    expect($sent[0]['channels'])->toEqualCanonicalizing(['private-chat.user.'.$sender->id, 'private-chat.user.'.$recipient->id]);
    expect($sent[0]['data'])->toBe(['refresh' => true]);
    expect($sent[0]['event'])->toBe('chat.changed');
    $service->publish($conversation->id, $recipient->id);
    expect($sent[1]['channels'])->toBe(['private-chat.user.'.$recipient->id]);
    $conversation->participants()->where('user_id', $recipient->id)->delete();
    $service->publish($conversation->id, $recipient->id);
    expect($sent)->toHaveCount(2);
    $service->publish($conversation->id, $outsider->id);
    expect($sent)->toHaveCount(2);
});

it('wakes call participants on state changes without broadcasting polling heartbeats', function () {
    enableSharedChatRealtime();
    Bus::fake([WakeChatClients::class]);
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $sender->id, 'host_id' => $sender->id, 'mutation_id' => (string) Str::uuid(), 'status' => 'ringing', 'ring_until' => now()->addMinute()]);
    $participant = $call->participants()->create(['user_id' => $recipient->id, 'media_identity' => (string) Str::uuid(), 'status' => 'invited']);
    Bus::assertDispatchedTimes(WakeChatClients::class, 2);
    $this->travel(1)->seconds();
    $participant->touch();
    $call->touch();
    Bus::assertDispatchedTimes(WakeChatClients::class, 2);
    $participant->update(['status' => 'accepted']);
    $call->update(['status' => 'active']);
    Bus::assertDispatchedTimes(WakeChatClients::class, 4);
    Bus::assertDispatched(WakeChatClients::class, fn ($job) => $job->conversationId === $conversation->id);
});

it('queues live hints only after commit and tolerates unavailable dispatch after a committed write', function () {
    enableSharedChatRealtime();
    Bus::fake([WakeChatClients::class]);
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    DB::transaction(function () use ($conversation) {
        app(ChatRealtime::class)->queueConversation($conversation->id);
        Bus::assertNotDispatched(WakeChatClients::class);
    });
    Bus::assertDispatchedTimes(WakeChatClients::class, 1);
    try {
        DB::transaction(function () use ($conversation) {
            app(ChatRealtime::class)->queueConversation($conversation->id);
            throw new RuntimeException('Rollback fixture');
        });
    } catch (RuntimeException) {
    }
    Bus::assertDispatchedTimes(WakeChatClients::class, 1);
    Bus::swap(Mockery::mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Unavailable queue'))->getMock());
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Durable when live transport is down'])->assertCreated();
    expect(DB::table('chat_message_changes')->count())->toBe(1);
});

it('recovers every message after a long disconnect with bounded ordered pages and a stable retry cursor', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $cursor = $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.messages', $conversation))->assertOk()->json('sync_cursor');
    $ids = DB::transaction(function () use ($conversation, $sender) {
        OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

        return collect(range(1, 110))->map(function ($index) use ($conversation, $sender) {
            $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Recovered '.$index]);
            app(ChatMessageChanges::class)->record($message, created: true);

            return $message->id;
        });
    });
    $first = $this->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertOk()->assertJsonCount(100, 'messages')->assertJsonPath('has_more_changes', true);
    expect($first->json('new_message_ids'))->toBe($ids->take(100)->all());
    $retry = $this->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertOk();
    expect($retry->json('messages'))->toBe($first->json('messages'));
    $last = $this->postJson(route('chat.changes', $conversation), ['cursor' => $first->json('sync_cursor')])->assertOk()->assertJsonCount(10, 'messages')->assertJsonPath('has_more_changes', false);
    expect($last->json('new_message_ids'))->toBe($ids->slice(100)->values()->all());
    $this->postJson(route('chat.changes', $conversation), ['cursor' => $last->json('sync_cursor')])->assertOk()->assertJsonCount(0, 'messages');
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Canonical send'])->assertCreated();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.changes', $conversation), ['cursor' => $last->json('sync_cursor')])->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.content', 'Canonical send');
});

it('synchronises old removals and reactions while keeping saved-message events private', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $old = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Old care detail']);
    $other = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Keep this message']);
    $senderCursor = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.messages', $conversation))->json('sync_cursor');
    $recipientCursor = $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.messages', $conversation))->json('sync_cursor');
    $this->postJson(route('chat.action', $other), ['action' => 'save', 'active' => true])->assertNoContent();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.changes', $conversation), ['cursor' => $senderCursor])->assertOk()->assertJsonCount(0, 'messages');
    $saved = $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.changes', $conversation), ['cursor' => $recipientCursor])->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.saved', true)->assertJsonCount(0, 'new_message_ids');
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.action', $old), ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => 0])->assertNoContent();
    $this->postJson(route('chat.action', $other), ['action' => 'react', 'active' => true, 'emoji' => '👍'])->assertNoContent();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.changes', $conversation), ['cursor' => $saved->json('sync_cursor')])->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.content', '')->assertJsonPath('messages.1.reactions.0.count', 1);
});

it('binds recovery cursors to the current viewer conversation and membership history', function () {
    $site = Site::factory()->create();
    [$sender, $recipient, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $other = sharedChatConversation($sender, $recipient);
    $cursor = $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.messages', $conversation))->json('sync_cursor');
    $this->postJson(route('chat.changes', $conversation), ['cursor' => 'tampered'])->assertUnprocessable();
    $this->postJson(route('chat.changes', $other), ['cursor' => $cursor])->assertUnprocessable();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertUnprocessable();
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertForbidden();
    $conversation->participants()->where('user_id', $recipient->id)->delete();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertForbidden();
    $conversation->participants()->create(['user_id' => $recipient->id, 'role' => 'member', 'history_after_id' => 123]);
    $this->postJson(route('chat.changes', $conversation), ['cursor' => $cursor])->assertConflict()->assertJsonPath('code', 'chat_sync_reset');
    $fresh = $this->getJson(route('chat.messages', $conversation))->json('sync_cursor');
    $this->postJson(route('chat.changes', $conversation), ['cursor' => $fresh])->assertOk()->assertJsonCount(0, 'messages');
    config(['chat.shared_experience' => false]);
    $this->postJson(route('chat.changes', $conversation), ['cursor' => $fresh])->assertNotFound();
});

it('rolls back the recovery stream with failed message work', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    try {
        DB::transaction(function () use ($conversation, $sender) {
            OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Never committed']);
            app(ChatMessageChanges::class)->record($message, created: true);
            throw new RuntimeException('Rollback fixture');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('chat_message_changes')->count())->toBe(0);
    expect(OpsMessage::count())->toBe(0);
});

it('commits notification work with the message and delivers one private notice despite send and worker retries', function () {
    $site = Site::factory()->create();
    [$sender, $recipient, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => 'Sensitive care detail'];
    $messageId = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), $payload)->assertCreated()->json('message.id');
    $this->postJson(route('chat.send', $conversation), $payload)->assertCreated();
    expect(DB::table('chat_message_deliveries')->count())->toBe(1);
    $delivery = app(ChatMessageDelivery::class);
    expect($delivery->recover())->toBe(1);
    expect($delivery->recover())->toBe(0);
    $notice = $recipient->notifications()->sole();
    expect($notice->data['title'])->toBe('New Chat message');
    expect(json_encode($notice->data))->not->toContain('Sensitive care detail')->not->toContain($sender->name)->not->toContain('conversation_id');
    expect($sender->notifications()->count())->toBe(0);
    expect($outsider->notifications()->count())->toBe(0);
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->get(route('chat.notifications.open', $notice->id))->assertNotFound();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->get(route('chat.notifications.open', $notice->id))->assertRedirect(route('chat.index', ['conversation' => $conversation->id, 'message' => $messageId]));
    expect($conversation->participants()->where('user_id', $recipient->id)->value('last_read_message_id'))->toBeNull();
    $visible = app(BoardPackAccessService::class);
    expect($visible->visibleNotificationQuery($recipient)->whereKey($notice->id)->exists())->toBeTrue();
    $conversation->participants()->where('user_id', $recipient->id)->delete();
    expect($visible->visibleNotificationQuery($recipient)->whereKey($notice->id)->exists())->toBeFalse();
    $this->get(route('chat.notifications.open', $notice->id))->assertNotFound();
});

it('rechecks mute, read state and channel preferences before delayed Chat delivery', function () {
    $site = Site::factory()->create();
    [$sender, $muted, $disabled, $reader, $revoked] = collect(range(1, 5))->map(fn () => sharedChatStaff($site))->all();
    $conversations = [];
    $messages = [];
    foreach ([$muted, $disabled, $reader, $revoked] as $person) {
        $conversations[$person->id] = sharedChatConversation($sender, $person);
        $messages[$person->id] = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversations[$person->id]), ['mutation_id' => (string) Str::uuid(), 'content' => 'New update'])->assertCreated()->json('message.id');
    }
    $conversations[$muted->id]->participants()->where('user_id', $muted->id)->update(['is_muted' => true]);
    UserNotificationPreference::create(['user_id' => $disabled->id, 'key' => 'chat.message.received', 'enabled' => true, 'channel_inapp' => false]);
    $conversations[$reader->id]->participants()->where('user_id', $reader->id)->update(['last_read_message_id' => $messages[$reader->id]]);
    HrEmployeeProfile::where('user_id', $revoked->id)->update(['is_active' => false]);
    expect(app(ChatMessageDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_message_deliveries')->where('status', 'suppressed')->count())->toBe(4);
    expect(DB::table('notifications')->count())->toBe(0);
});

it('defers quiet-time delivery and retries a failed database notification without losing or duplicating it', function (bool $cancel) {
    $this->freezeTime();
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $recipient->update(['dnd_enabled' => true, 'dnd_until' => now()->addMinutes(5)]);
    $conversation = sharedChatConversation($sender, $recipient);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Wait for quiet time'])->assertCreated();
    $service = app(ChatMessageDelivery::class);
    expect($service->recover())->toBe(0);
    expect(DB::table('chat_message_deliveries')->value('status'))->toBe('pending');
    $this->travel(6)->minutes();
    $fail = true;
    Event::listen(NotificationSending::class, function ($event) use (&$fail, $cancel) {
        if ($fail && $event->notification instanceof ChatMessageNotice) {
            if ($cancel) {
                return false;
            }
            throw new RuntimeException('Simulated notification failure');
        }
    });
    expect($service->recover())->toBe(0);
    expect($recipient->notifications()->count())->toBe(0);
    expect(DB::table('chat_message_deliveries')->value('attempts'))->toBe(1);
    expect(DB::table('chat_message_deliveries')->value('status'))->toBe('pending');
    expect(DB::table('chat_message_deliveries')->value('delivered_at'))->toBeNull();
    $notificationId = DB::table('chat_message_deliveries')->value('notification_id');
    $fail = false;
    $this->travel(2)->minutes();
    expect($service->recover())->toBe(1);
    expect($service->recover())->toBe(0);
    expect($recipient->notifications()->count())->toBe(1);
    expect($recipient->notifications()->sole()->id)->toBe($notificationId);
    expect(DB::table('chat_message_deliveries')->value('status'))->toBe('delivered');
})->with(['listener throws' => false, 'listener cancels' => true]);

it('keeps family replies within the invited audience and distinguishes explicit mentions', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    [$relative, $other] = [sharedChatRelative($client), sharedChatRelative($client)];
    $worker = sharedChatStaff($site);
    $role = Role::create(['name' => 'chat_delivery_cover', 'label' => 'Care team', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'family_portal.manage'], ['description' => 'Manage family portal', 'group' => 'operations', 'module' => 'Operations']);
    $role->permissions()->attach($permission->id);
    $worker->roles()->attach($role->id);
    $conversation = OpsConversation::findOrFail($this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => []])->assertCreated()->json('conversation.id'));
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id);
    $this->postJson(route('chat.send', $conversation), [...sharedChatAudience($this, $conversation), 'mutation_id' => (string) Str::uuid(), 'content' => 'Private care-team reply'])->assertCreated();
    $service = app(ChatMessageDelivery::class);
    expect($service->recover())->toBe(1);
    expect($relative->notifications()->sole()->data['event_key'])->toBe('chat.family.reply');
    expect($other->notifications()->count())->toBe(0);
    $client->portalUsers()->detach($relative->id);
    expect(app(BoardPackAccessService::class)->visibleNotificationQuery($relative->fresh())->count())->toBe(0);
    $peer = sharedChatStaff($site);
    $peer->update(['name' => 'Rohan']);
    $direct = sharedChatConversation($worker, $peer);
    $this->postJson(route('chat.send', $direct), ['mutation_id' => (string) Str::uuid(), 'content' => '@Rohan please check', 'mentions' => [['user_id' => $peer->id, 'start' => 0, 'length' => 6]]])->assertCreated();
    expect($service->recover())->toBe(1);
    expect($peer->notifications()->sole()->data['event_key'])->toBe('chat.mentioned');
});

it('rolls notification work back with its message and recovers it when the queue is unavailable', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $service = app(ChatMessageDelivery::class);
    try {
        DB::transaction(function () use ($service, $sender, $conversation): void {
            $message = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Rolled back', 'message_type' => 'text']);
            $service->enqueue($message, $conversation);
            throw new RuntimeException('Rollback fixture');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('chat_message_deliveries')->count())->toBe(0);
    expect(OpsMessage::count())->toBe(0);
    Bus::swap(Mockery::mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable'))->getMock());
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Committed despite queue failure'])->assertCreated();
    expect(DB::table('chat_message_deliveries')->where('status', 'pending')->count())->toBe(1);
    expect($service->recover())->toBe(1);
});

function sharedChatStaff(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

function sharedChatConversation(User ...$people): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => count($people) > 2 ? 'group' : 'direct']);
    foreach ($people as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }

    return $conversation;
}

function sharedChatRelative(Client $client): User
{
    $user = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $user->roles()->syncWithoutDetaching([$role->id]);
    $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

    return $user;
}

function sharedChatAudience(TestCase $test, OpsConversation $conversation): array
{
    $token = $test->getJson(route('chat.messages', $conversation))->assertOk()->json('conversation.audience_review_token');
    $test->postJson(route('chat.audience-review', $conversation), ['audience_token' => $token, 'understood' => true])->assertOk()->assertJsonPath('conversation.audience_review_required', false);

    return ['audience_token' => $token];
}

it('requires a reviewed current family audience without sending on acknowledgement or duplicating an already accepted send', function () {
    Storage::fake('local');
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    [$sender, $relative, $outsider] = [sharedChatRelative($client), sharedChatRelative($client), sharedChatRelative($client)];
    $created = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => []])->assertCreated();
    $conversation = OpsConversation::findOrFail($created->json('conversation.id'));
    $token = $created->json('conversation.audience_review_token');
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => 'Private family update', 'audience_token' => $token];
    $this->postJson(route('chat.send', $conversation), [...$payload, 'files' => [UploadedFile::fake()->create('family.txt', 1, 'text/plain')]])->assertConflict()->assertJsonPath('code', 'chat_audience_review_required');
    expect(OpsMessage::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->postJson(route('chat.audience-review', $conversation), ['audience_token' => $token, 'understood' => false])->assertUnprocessable();
    $review = sharedChatAudience($this, $conversation);
    sharedChatAudience($this, $conversation);
    expect(OpsMessage::count())->toBe(0);
    expect(AuditLog::where('action', 'chat.audience.reviewed')->count())->toBe(1);
    $sent = $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->json('message.id');
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->postJson(route('chat.audience-review', $conversation), ['audience_token' => $token, 'understood' => true])->assertForbidden();
    $conversation->participants()->create(['user_id' => $relative->id, 'role' => 'family']);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.messages', $conversation))->assertJsonPath('conversation.audience_review_required', true);
    $this->postJson(route('chat.audience-review', $conversation), [...$review, 'understood' => true])->assertConflict();
    $this->postJson(route('chat.send', $conversation), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.id', $sent);
    expect(OpsMessage::count())->toBe(1);
    $fresh = sharedChatAudience($this, $conversation);
    $this->postJson(route('chat.send', $conversation), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson(route('chat.send', $conversation), [...$payload, ...$fresh, 'mutation_id' => (string) Str::uuid()])->assertCreated();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.send', $conversation), [...$payload, ...$fresh, 'mutation_id' => (string) Str::uuid()])->assertConflict();
    $conversation->participants()->where('user_id', $sender->id)->delete();
    $conversation->participants()->create(['user_id' => $sender->id, 'role' => 'family']);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.messages', $conversation))->assertJsonPath('conversation.audience_review_required', true);
    $client->portalUsers()->detach($sender->id);
    $this->actingAs($sender->fresh())->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.audience-review', $conversation), [...$fresh, 'understood' => true])->assertForbidden();
});

it('requires current staff care-team cover to review family audiences too', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $relative = sharedChatRelative($client);
    $worker = sharedChatStaff($site);
    $role = Role::create(['name' => 'chat_cover_review', 'label' => 'Care team', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'family_portal.manage'], ['description' => 'Manage family portal', 'group' => 'operations', 'module' => 'Operations']);
    $role->permissions()->attach($permission->id);
    $worker->roles()->attach($role->id);
    $created = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => []])->assertCreated();
    $conversation = OpsConversation::findOrFail($created->json('conversation.id'));
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Care-team reply'])->assertConflict();
    $review = sharedChatAudience($this, $conversation);
    $this->postJson(route('chat.send', $conversation), [...$review, 'mutation_id' => (string) Str::uuid(), 'content' => 'Care-team reply'])->assertCreated();
    HrEmployeeProfile::where('user_id', $worker->id)->update(['is_active' => false]);
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->postJson(route('chat.audience-review', $conversation), [...$review, 'understood' => true])->assertForbidden();
});

it('keeps explicit two-person groups distinct and recovers the original request after later group changes', function () {
    $site = Site::factory()->create();
    [$owner, $colleague, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $direct = sharedChatConversation($owner, $colleague);
    $payload = ['mutation_id' => (string) Str::uuid(), 'conversation_kind' => 'group', 'title' => 'Evening support', 'participant_ids' => [$colleague->id]];
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->postJson(route('chat.create'), [...$payload, 'title' => ''])->assertUnprocessable();
    $id = $this->postJson(route('chat.create'), $payload)->assertCreated()->json('conversation.id');
    $group = OpsConversation::findOrFail($id);
    expect($id)->not->toBe($direct->id);
    expect($group->conversation_type)->toBe('group');
    expect($group->participants()->count())->toBe(2);
    $this->postJson(route('chat.create'), [...$payload, 'participant_ids' => [$other->id]])->assertConflict();
    $this->postJson(route('chat.create'), [...$payload, 'title' => 'Different name'])->assertConflict();
    $group->update(['title' => 'Renamed later']);
    $group->participants()->where('user_id', $colleague->id)->delete();
    $this->postJson(route('chat.create'), $payload)->assertCreated()->assertJsonPath('conversation.id', $id)->assertJsonPath('conversation.title', 'Renamed later');
    expect($group->participants()->count())->toBe(1);
    expect(OpsConversation::count())->toBe(2);
    $group->participants()->where('user_id', $owner->id)->delete();
    $this->postJson(route('chat.create'), $payload)->assertForbidden();
});

it('records reused direct chat requests and never recreates a deleted result or accepts a changed selection', function () {
    $site = Site::factory()->create();
    [$owner, $colleague, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $direct = sharedChatConversation($owner, $colleague);
    $payload = ['mutation_id' => (string) Str::uuid(), 'conversation_kind' => 'direct', 'participant_ids' => [$colleague->id]];
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->postJson(route('chat.create'), $payload)->assertCreated()->assertJsonPath('conversation.id', $direct->id);
    $this->postJson(route('chat.create'), [...$payload, 'participant_ids' => [$other->id]])->assertConflict();
    $this->postJson(route('chat.create'), [...$payload, 'conversation_kind' => 'group', 'title' => 'Different kind'])->assertConflict();
    $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('conversation.id', $direct->id);
    $this->postJson(route('chat.create'), [...$payload, 'mutation_id' => (string) Str::uuid(), 'participant_ids' => [$colleague->id, $other->id]])->assertUnprocessable();
    expect(OpsConversation::count())->toBe(1);
    expect(DB::table('chat_creation_requests')->count())->toBe(2);
    $direct->delete();
    $this->postJson(route('chat.create'), $payload)->assertGone();
    expect(OpsConversation::count())->toBe(0);
});

it('requires a private group name and enforces capacity across creation and repeated invitations', function () {
    $site = Site::factory()->create();
    $owner = sharedChatStaff($site);
    $people = collect(range(1, 20))->map(fn () => sharedChatStaff($site));
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'participant_ids' => $people->take(2)->pluck('id')->all()])->assertUnprocessable();
    $this->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'title' => 'Too many', 'participant_ids' => $people->pluck('id')->all()])->assertUnprocessable();
    expect(OpsConversation::count())->toBe(0);
    $conversation = OpsConversation::findOrFail($this->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'title' => 'Care team staff', 'participant_ids' => $people->take(18)->pluck('id')->all()])->assertCreated()->json('conversation.id'));
    $this->getJson(route('chat.people.options', $conversation))->assertJsonPath('max_additional', 1);
    $payload = ['mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'history_policy' => 'from_joining', 'user_ids' => [$people[18]->id, $people[19]->id]];
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertUnprocessable();
    $this->postJson(route('chat.people.store', $conversation), [...$payload, 'user_ids' => [$people[18]->id]])->assertCreated();
    $this->getJson(route('chat.people.options', $conversation))->assertJsonPath('max_additional', 0);
    $this->postJson(route('chat.people.store', $conversation), [...$payload, 'mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'user_ids' => [$people[19]->id]])->assertUnprocessable();
    expect($conversation->participants()->count())->toBe(20);
});

it('expires family invitations and requires a newly reviewed invitation without restoring earlier selected history', function () {
    $this->freezeTime();
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    [$sender, $recipient, $outsider] = [sharedChatRelative($client), sharedChatRelative($client), sharedChatRelative($client)];
    $conversation = OpsConversation::findOrFail($this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => []])->assertCreated()->json('conversation.id'));
    $earlier = $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), ...sharedChatAudience($this, $conversation), 'content' => 'Previously selected history'])->assertCreated()->json('message.id');
    $payload = ['mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'user_ids' => [$recipient->id], 'history_policy' => 'selected', 'history_confirmed' => true, 'message_ids' => [$earlier]];
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertCreated();
    $invite = DB::table('chat_invitations')->first();
    $this->getJson(route('chat.invitations.history', $conversation))->assertJsonPath('invitations.0.status', 'pending')->assertJsonPath('invitations.0.can_cancel', true);
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->getJson(route('chat.invitations.history', $conversation))->assertForbidden();
    $this->travel(7)->days();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version])->assertStatus(410);
    $this->getJson(route('chat.invitations', ['client' => $client->id]))->assertJsonCount(0, 'invitations');
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.invitations.history', $conversation))->assertJsonPath('invitations.0.status', 'expired')->assertJsonPath('invitations.0.can_reinvite', true);
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertConflict();
    $this->postJson(route('chat.people.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'user_ids' => [$recipient->id], 'history_policy' => 'from_joining'])->assertCreated();
    $fresh = DB::table('chat_invitations')->where('id', '!=', $invite->id)->first();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.invitations.respond', $fresh->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    $this->getJson(route('chat.messages', $conversation))->assertJsonCount(0, 'messages');
    expect(DB::table('chat_history_grants')->count())->toBe(0);
});

it('keeps a staff invitation into a relative private conversation hidden until a relative there agrees', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $third = sharedChatRelative($client);
    $headers = fn (User $person) => ['X-Chat-Actor' => (string) $person->id];
    $payload = ['mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'user_ids' => [$other->id], 'history_policy' => 'from_joining'];
    $this->actingAs($worker)->postJson(route('chat.people.store', $conversation), $payload, $headers($worker))->assertCreated();
    $invite = DB::table('chat_invitations')->sole();
    expect((bool) $invite->consent_required)->toBeTrue()->and($invite->consented_by)->toBeNull();
    $this->getJson(route('chat.invitations.history', $conversation), $headers($worker))->assertJsonPath('invitations.0.status', 'awaiting_family')->assertJsonPath('invitations.0.can_cancel', true);
    // The invited relative cannot see or accept it yet; another linked relative outside the thread cannot decide.
    $this->actingAs($other)->getJson(route('chat.invitations', ['client' => $client->id]), $headers($other))->assertJsonCount(0, 'invitations')->assertJsonCount(0, 'consent_requests');
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version], $headers($other))->assertNotFound();
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'consent'], $headers($other))->assertForbidden();
    $this->actingAs($third)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'consent'], $headers($third))->assertForbidden();
    $this->actingAs($worker)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'consent'], $headers($worker))->assertForbidden();
    // The relative in the conversation sees the request and agrees; repeating it is harmless.
    $this->actingAs($relative)->getJson(route('chat.invitations', ['client' => $client->id]), $headers($relative))
        ->assertJsonCount(1, 'consent_requests')->assertJsonPath('consent_requests.0.person', $other->name)->assertJsonPath('consent_requests.0.from', $worker->name);
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'consent'], $headers($relative))->assertNoContent();
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'consent'], $headers($relative))->assertNoContent();
    expect(AuditLog::where('action', 'chat.invitation.family_agreed')->count())->toBe(1);
    $this->actingAs($other)->getJson(route('chat.invitations', ['client' => $client->id]), $headers($other))->assertJsonCount(1, 'invitations');
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version], $headers($other))->assertNoContent();
    expect($conversation->members()->where('user_id', $other->id)->exists())->toBeTrue();
});

it('lets a relative refuse a staff invitation into their private conversation', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $headers = fn (User $person) => ['X-Chat-Actor' => (string) $person->id];
    $this->actingAs($worker)->postJson(route('chat.people.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'user_ids' => [$other->id], 'history_policy' => 'from_joining'], $headers($worker))->assertCreated();
    $invite = DB::table('chat_invitations')->sole();
    $this->actingAs($relative)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'refuse'], $headers($relative))->assertNoContent();
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'consent'], $headers($relative))->assertConflict();
    $this->actingAs($worker)->getJson(route('chat.invitations.history', $conversation), $headers($worker))->assertJsonPath('invitations.0.status', 'refused')->assertJsonPath('invitations.0.can_cancel', false);
    $this->actingAs($other)->getJson(route('chat.invitations', ['client' => $client->id]), $headers($other))->assertJsonCount(0, 'invitations');
    expect($conversation->members()->where('user_id', $other->id)->exists())->toBeFalse();
});

it('removes invited relatives only with current authority and revokes files and active calls without removing the portal relationship', function () {
    Storage::fake('local');
    config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'https://voice.example.test', 'chat.calling.api_key' => 'test', 'chat.calling.api_secret' => str_repeat('test', 12)]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true, 'family_calls_enabled' => true]]);
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    [$sender, $recipient, $other] = [sharedChatRelative($client), sharedChatRelative($client), sharedChatRelative($client)];
    $conversation = OpsConversation::findOrFail($this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => []])->assertCreated()->json('conversation.id'));
    foreach ([$recipient, $other] as $person) {
        $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.people.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'version' => $conversation->fresh()->chat_version, 'user_ids' => [$person->id], 'history_policy' => 'from_joining'])->assertCreated();
        $invite = DB::table('chat_invitations')->where('user_id', $person->id)->first();
        $this->actingAs($person)->withHeader('X-Chat-Actor', (string) $person->id)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    }
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->patchJson(route('chat.management', $conversation), ['action' => 'remove', 'user_id' => $recipient->id, 'version' => $conversation->fresh()->chat_version])->assertForbidden();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), ...sharedChatAudience($this, $conversation), 'files' => [UploadedFile::fake()->create('private.txt', 1, 'text/plain')]])->assertCreated();
    $attachment = DB::table('chat_attachments')->first();
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $sender->id, 'host_id' => $sender->id, 'mutation_id' => (string) Str::uuid(), 'status' => 'connected', 'ring_until' => now()->addMinute(), 'connected_at' => now()]);
    foreach ([$sender, $recipient, $other] as $person) {
        $call->participants()->create(['user_id' => $person->id, 'media_identity' => (string) Str::uuid(), 'status' => 'connected', 'joined_at' => now()]);
    }
    Http::fake(['https://voice.example.test/*' => Http::sequence()->push([], 503)->push([], 200)->push([], 200)->push([], 200)]);
    $payload = ['action' => 'remove', 'user_id' => $recipient->id, 'version' => $conversation->fresh()->chat_version];
    $this->patchJson(route('chat.management', $conversation), $payload)->assertServerError();
    expect($conversation->participants()->where('user_id', $recipient->id)->exists())->toBeTrue()
        ->and(DB::table('chat_invitations')->where('user_id', $recipient->id)->value('status'))->toBe('accepted');
    $this->patchJson(route('chat.management', $conversation), $payload)->assertNoContent();
    expect($call->participants()->where('user_id', $recipient->id)->value('status'))->toBe('revoked')
        ->and($recipient->fresh()->canAccessClientPortal($client))->toBeTrue()
        ->and(DB::table('chat_invitations')->where('user_id', $recipient->id)->value('status'))->toBe('removed');
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
    $this->getJson(route('chat.attachment', $attachment->id))->assertForbidden();
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson(route('chat.messages', $conversation))->assertOk();
    $this->patchJson(route('chat.management', $conversation), ['action' => 'leave', 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    expect(DB::table('chat_invitations')->where('user_id', $other->id)->value('status'))->toBe('removed');
});

it('keeps private staff history and files inaccessible to nonparticipants', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$sender, $recipient, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), [
        'mutation_id' => (string) Str::uuid(), 'content' => 'Private attachment',
        'files' => [UploadedFile::fake()->create('care-summary.pdf', 10, 'application/pdf')],
    ])->assertCreated();
    $file = DB::table('chat_attachments')->first();
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
    $this->getJson(route('chat.attachment', $file->id))->assertForbidden();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->get(route('chat.attachment', $file->id))->assertOk();
    expect(ClientDocument::where('original_name', 'care-summary.pdf')->exists())->toBeFalse();
});

it('accepts a retried send only once and retains its authoritative identifier', function () {
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => 'Please check in'];
    $first = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), $payload)->assertCreated();
    $second = $this->postJson(route('chat.send', $conversation), $payload)->assertCreated();
    expect($second->json('message.id'))->toBe($first->json('message.id'))
        ->and($conversation->messages()->count())->toBe(1);
});

it('keeps archive and unread reminders personal without rewinding receipts or accepting old acknowledgements', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Read this']);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->postJson(route('chat.read', $conversation), ['message_id' => $message->id])->assertNoContent();
    $this->patchJson(route('chat.preferences', $conversation), ['mark_unread' => true, 'is_archived_for_user' => true])->assertNoContent();
    $member = $conversation->participants()->where('user_id', $reader->id)->first();
    $marker = $member->unread_marker;
    expect((int) $member->last_read_message_id)->toBe($message->id)->and($marker)->not->toBeNull()
        ->and($conversation->fresh()->is_archived)->toBeFalse();
    $this->getJson(route('chat.messages', $conversation))->assertJsonPath('conversation.archived', false)->assertJsonPath('conversation.archived_for_me', true)->assertJsonPath('conversation.unread', 1)->assertJsonPath('conversation.can_send', false);
    expect(app(OpsMessageVisibilityService::class)->unreadCount($reader))->toBe(1);
    $this->postJson(route('chat.read', $conversation), ['message_id' => $message->id])->assertNoContent();
    expect($member->fresh()->unread_marker)->toBe($marker);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Wait'])->assertConflict();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.messages', $conversation))->assertJsonPath('conversation.archived_for_me', false)->assertJsonPath('conversation.can_send', true)->assertJsonPath('conversation.unread_marker', null);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->patchJson(route('chat.preferences', $conversation), ['is_archived_for_user' => false, 'mark_unread' => true])->assertNoContent();
    $newMarker = $member->fresh()->unread_marker;
    $this->postJson(route('chat.read', $conversation), ['message_id' => $message->id, 'unread_marker' => $marker])->assertNoContent();
    expect($member->fresh()->unread_marker)->toBe($newMarker);
    $this->postJson(route('chat.read', $conversation), ['message_id' => $message->id, 'unread_marker' => $newMarker])->assertNoContent();
    expect($member->fresh()->unread_marker)->toBeNull()->and(app(OpsMessageVisibilityService::class)->unreadCount($reader))->toBe(0);
});

it('requires current ownership and a reviewed version for group changes and atomically transfers before leaving', function () {
    $site = Site::factory()->create();
    [$owner, $member, $other, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $version = $conversation->fresh()->chat_version;
    $this->actingAs($member)->withHeader('X-Chat-Actor', (string) $member->id)->patchJson(route('chat.management', $conversation), ['action' => 'rename', 'title' => 'Forged', 'version' => $version])->assertForbidden();
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->patchJson(route('chat.management', $conversation), ['action' => 'rename', 'title' => 'Afternoon team', 'version' => $version])->assertNoContent();
    $this->patchJson(route('chat.management', $conversation), ['action' => 'remove', 'user_id' => $member->id, 'version' => $version])->assertConflict();
    $version = $conversation->fresh()->chat_version;
    $this->patchJson(route('chat.management', $conversation), ['action' => 'leave', 'version' => $version])->assertUnprocessable();
    $this->patchJson(route('chat.management', $conversation), ['action' => 'transfer', 'user_id' => $outsider->id, 'version' => $version])->assertForbidden();
    $this->patchJson(route('chat.management', $conversation), ['action' => 'leave', 'user_id' => $member->id, 'version' => $version])->assertNoContent();
    expect($conversation->participants()->where('user_id', $owner->id)->exists())->toBeFalse()
        ->and($conversation->participants()->where('user_id', $member->id)->value('role'))->toBe('admin')
        ->and($conversation->fresh()->title)->toBe('Afternoon team');
    $this->getJson(route('chat.messages', $conversation))->assertForbidden();
    $this->actingAs($member)->withHeader('X-Chat-Actor', (string) $member->id)->getJson(route('chat.messages', $conversation))->assertOk()->assertJsonPath('conversation.management.can_rename', true);
    expect(DB::table('audit_logs')->where('action', 'chat.conversation.leave')->count())->toBe(1);
});

it('removes group access to history and files while keeping remaining members and closed channels safe', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$owner, $member, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Private', 'files' => [UploadedFile::fake()->create('note.txt', 1, 'text/plain')]])->assertCreated();
    $file = DB::table('chat_attachments')->first();
    $this->patchJson(route('chat.management', $conversation), ['action' => 'remove', 'user_id' => $member->id, 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    $this->actingAs($member)->withHeader('X-Chat-Actor', (string) $member->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
    $this->getJson(route('chat.attachment', $file->id))->assertForbidden();
    $this->patchJson(route('chat.preferences', $conversation), ['is_archived_for_user' => true])->assertForbidden();
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson(route('chat.messages', $conversation))->assertOk();
    $conversation->update(['is_archived' => true, 'is_managed_channel' => true]);
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->patchJson(route('chat.preferences', $conversation), ['is_archived_for_user' => false])->assertNoContent();
    $this->getJson(route('chat.messages', $conversation))->assertJsonPath('conversation.archived', true)->assertJsonPath('conversation.can_send', false)->assertJsonPath('conversation.management.can_rename', false);
    $this->patchJson(route('chat.management', $conversation), ['action' => 'rename', 'title' => 'Reopen', 'version' => $conversation->fresh()->chat_version])->assertForbidden();
});

it('keeps saved messages personal, idempotent and subject to current conversation access', function () {
    $site = Site::factory()->create();
    [$sender, $reader, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'message_type' => 'text', 'content' => 'Saved private text']);
    $payload = ['action' => 'save', 'active' => true];
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->postJson(route('chat.action', $message), $payload)->assertNoContent();
    $this->postJson(route('chat.action', $message), $payload)->assertNoContent();
    expect(DB::table('chat_saved_messages')->count())->toBe(1);
    $this->getJson(route('chat.collections', ['kind' => 'saved']))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.message.saved', true);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.collections', ['kind' => 'saved']))->assertOk()->assertJsonCount(0, 'items');
    $this->getJson(route('chat.messages', $conversation))->assertJsonPath('messages.0.saved', false);
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->postJson(route('chat.action', $message), $payload)->assertForbidden();
    $conversation->participants()->where('user_id', $reader->id)->delete();
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.collections', ['kind' => 'saved']))->assertOk()->assertJsonCount(0, 'items');
    $this->getJson(route('chat.collections', ['kind' => 'saved', 'conversation' => $conversation->id]))->assertForbidden();
});

it('does not let a channel owner bypass current channel-management permission', function () {
    $site = Site::factory()->create();
    [$owner, $member, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->update(['is_managed_channel' => true, 'channel_site_id' => $site->id]);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    expect($owner->canDo('settings.access.manage'))->toBeFalse();
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->getJson(route('chat.messages', $conversation))->assertOk()
        ->assertJsonPath('conversation.management.can_rename', false)
        ->assertJsonPath('conversation.management.can_remove', false)
        ->assertJsonPath('conversation.management.can_transfer', false);
    foreach (['rename', 'remove', 'transfer'] as $action) {
        $this->patchJson(route('chat.management', $conversation), ['action' => $action, 'title' => 'No authority', 'user_id' => $member->id, 'version' => $conversation->fresh()->chat_version])->assertForbidden();
    }
});

it('revokes active audio during member removal and does not claim success if the media server refuses', function () {
    config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'https://voice.example.test',
        'chat.calling.api_key' => 'chat-test', 'chat.calling.api_secret' => str_repeat('test', 12)]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true]]);
    $site = Site::factory()->create();
    [$owner, $member, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $owner->id, 'host_id' => $owner->id,
        'mutation_id' => (string) Str::uuid(), 'status' => 'connected', 'ring_until' => now()->addMinute(), 'connected_at' => now()]);
    foreach ([$owner, $member, $other] as $person) {
        $call->participants()->create(['user_id' => $person->id, 'media_identity' => (string) Str::uuid(), 'status' => 'connected', 'joined_at' => now()]);
    }
    $payload = ['action' => 'remove', 'user_id' => $member->id, 'version' => $conversation->fresh()->chat_version];
    Http::fake(['https://voice.example.test/*' => Http::sequence()->push([], 503)->push([], 200)]);
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->patchJson(route('chat.management', $conversation), $payload)->assertServerError();
    expect($conversation->participants()->where('user_id', $member->id)->exists())->toBeTrue()
        ->and($call->participants()->where('user_id', $member->id)->value('status'))->toBe('connected');
    $this->patchJson(route('chat.management', $conversation), $payload)->assertNoContent();
    expect($conversation->participants()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and($call->participants()->where('user_id', $member->id)->value('status'))->toBe('revoked')
        ->and($call->fresh()->ended_at)->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/RemoveParticipant'));
});

it('paginates saved items and excludes removed and mismatched canonical messages', function () {
    $site = Site::factory()->create();
    $reader = sharedChatStaff($site);
    $conversation = sharedChatConversation($reader, sharedChatStaff($site));
    $ids = [];
    for ($i = 0; $i < 54; $i++) {
        $message = $conversation->messages()->create(['sender_id' => $reader->id, 'sender_type' => 'user', 'message_type' => 'text', 'content' => 'Item '.$i]);
        $ids[] = $message->id;
        DB::table('chat_saved_messages')->insert(['user_id' => $reader->id, 'message_id' => $message->id, 'created_at' => now()]);
    }
    $removed = OpsMessage::findOrFail(array_pop($ids));
    $removed->delete();
    $mismatch = OpsMessage::findOrFail(array_pop($ids));
    $mismatch->update(['client_id' => Client::factory()->create(['site_id' => $site->id])->id]);
    $first = $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.collections', ['kind' => 'saved']))->assertOk()->assertJsonCount(50, 'items');
    expect($first->json('next_before'))->not->toBeNull();
    $second = $this->getJson(route('chat.collections', ['kind' => 'saved', 'before' => $first->json('next_before')]))->assertOk()->assertJsonCount(2, 'items');
    expect(array_intersect(array_column(array_column($first->json('items'), 'message'), 'id'), array_column(array_column($second->json('items'), 'message'), 'id')))->toBe([]);
    expect($second->json('next_before'))->toBeNull();
    $this->postJson(route('chat.action', $ids[0]), ['action' => 'save', 'active' => false])->assertNoContent();
    $this->getJson(route('chat.collections', ['kind' => 'saved', 'before' => $first->json('next_before')]))->assertJsonCount(1, 'items');
});

it('refreshes a loaded collection range without skipping older items after new pins arrive', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $messages = collect();
    for ($i = 0; $i < 54; $i++) {
        $messages->push($conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Pinned '.$i, 'is_pinned' => true]));
    }
    $scope = ['kind' => 'pinned', 'conversation' => $conversation->id];
    $first = $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.collections', $scope))->assertOk()->assertJsonCount(50, 'items');
    $floor = $first->json('scan_floor');
    expect($floor)->toBe($messages[4]->id)->and($first->json('next_before'))->toBe($floor);
    $messages[53]->update(['is_pinned' => false]);
    $messages[52]->update(['content' => 'Corrected pinned text', 'edited_at' => now()]);
    for ($i = 0; $i < 3; $i++) {
        $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'New pin '.$i, 'is_pinned' => true]);
    }
    $refresh = $this->getJson(route('chat.collections', [...$scope, 'through' => $floor]))->assertOk()->assertJsonCount(50, 'items')->assertJsonPath('older_before', $floor);
    $tail = $this->getJson(route('chat.collections', [...$scope, 'through' => $floor, 'before' => $refresh->json('next_before')]))->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('next_before', null)->assertJsonPath('scan_floor', $floor)->assertJsonPath('older_before', $floor);
    $items = collect([...$refresh->json('items'), ...$tail->json('items')]);
    expect($items->pluck('message.id')->unique()->count())->toBe(52)
        ->and($items->pluck('message.id'))->not->toContain($messages[53]->id)
        ->and($items->firstWhere('message.id', $messages[52]->id)['message']['content'])->toBe('Corrected pinned text');
    $this->getJson(route('chat.collections', [...$scope, 'before' => $tail->json('older_before')]))->assertOk()->assertJsonCount(4, 'items');
    $conversation->participants()->where('user_id', $reader->id)->delete();
    $this->getJson(route('chat.collections', [...$scope, 'through' => $floor]))->assertForbidden();
});

it('does not expose excluded history or removed media while refreshing a collection range', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $secret = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Earlier private pin', 'is_pinned' => true]);
    $visible = $conversation->messages()->create(['sender_id' => $sender->id, 'content' => 'Current photo', 'is_pinned' => true]);
    $conversation->participants()->where('user_id', $reader->id)->update(['history_after_id' => $secret->id]);
    DB::table('chat_attachments')->insert(['message_id' => $visible->id, 'name' => 'photo.jpg', 'storage_path' => 'private/photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 20, 'created_at' => now(), 'updated_at' => now()]);
    $scope = ['conversation' => $conversation->id, 'through' => $visible->id];
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.collections', [...$scope, 'kind' => 'pinned']))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('older_before', null);
    $this->getJson(route('chat.collections', [...$scope, 'kind' => 'media']))->assertOk()->assertJsonCount(1, 'items');
    $visible->delete();
    $this->getJson(route('chat.collections', [...$scope, 'kind' => 'media']))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('next_before', null)->assertJsonPath('older_before', null);
    $this->getJson(route('chat.collections', [...$scope, 'kind' => 'pinned']))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('scan_floor', null)->assertJsonPath('older_before', null);
    $this->getJson(route('chat.collections', [...$scope, 'kind' => 'pinned', 'through' => -1]))->assertUnprocessable();
});

it('opens pinned and shared media only within an authorised conversation', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'message_type' => 'text', 'content' => 'Pinned photo', 'is_pinned' => true]);
    DB::table('chat_attachments')->insert(['message_id' => $message->id, 'name' => 'photo.jpg', 'storage_path' => 'private/photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 20, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['pinned', 'media'] as $kind) {
        $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.collections', ['kind' => $kind, 'conversation' => $conversation->id]))->assertOk()->assertJsonPath('items.0.message.id', $message->id);
        $this->getJson(route('chat.collections', ['kind' => $kind]))->assertUnprocessable();
    }
});

it('does not mark history read on fetch and never advances beyond an observed message', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $first = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'message_type' => 'text', 'content' => 'One']);
    $second = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'message_type' => 'text', 'content' => 'Two']);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.messages', $conversation))->assertOk();
    expect($conversation->participants()->where('user_id', $reader->id)->first()->last_read_at)->toBeNull();
    $this->postJson(route('chat.read', $conversation), ['message_id' => $first->id])->assertNoContent();
    $this->getJson(route('chat.conversations'))->assertJsonPath('conversations.0.unread', 1);
    $this->postJson(route('chat.read', $conversation), ['message_id' => $second->id])->assertNoContent();
    $this->postJson(route('chat.read', $conversation), ['message_id' => $first->id])->assertNoContent();
    expect($conversation->participants()->where('user_id', $reader->id)->first()->last_read_message_id)->toBe($second->id);
});

it('persists explicit Unicode mentions once and removes deleted messages from the mentions view', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $recipient->update(['name' => 'Rohan']);
    $conversation = sharedChatConversation($sender, $recipient);
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => '  🙂 @Rohan check this', 'mentions' => [['user_id' => $recipient->id, 'start' => 4, 'length' => 6]]];
    $first = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.mentions.0.start', 2)->assertJsonPath('message.mentions.0.label', '@Rohan');
    $this->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.id', $first->json('message.id'));
    expect(DB::table('chat_message_mentions')->count())->toBe(1);
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.conversations'))->assertOk()->assertJsonPath('conversations.0.latest_mention_id', $first->json('message.id'));
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->getJson(route('chat.conversations'))->assertJsonPath('conversations.0.latest_mention_id', null);
    $this->postJson(route('chat.action', $first->json('message.id')), ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => 0])->assertNoContent();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.conversations'))->assertJsonPath('conversations.0.latest_mention_id', null);
    $this->getJson(route('chat.messages', $conversation))->assertJsonPath('messages.0.mentions', []);
});

it('rejects forged, overlapping or renamed mentions without publishing a partial message', function () {
    $site = Site::factory()->create();
    [$sender, $recipient, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $recipient->update(['name' => 'Rohan']);
    $outsider->update(['name' => 'Rohan']);
    $conversation = sharedChatConversation($sender, $recipient);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id);
    foreach ([
        [['user_id' => $outsider->id, 'start' => 0, 'length' => 6]],
        [['user_id' => $recipient->id, 'start' => 1, 'length' => 6]],
        [['user_id' => $recipient->id, 'start' => 0, 'length' => 6], ['user_id' => $recipient->id, 'start' => 0, 'length' => 6]],
    ] as $mentions) {
        $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => '@Rohan check this', 'mentions' => $mentions])->assertUnprocessable();
    }
    $recipient->update(['name' => 'New name']);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => '@Rohan check this', 'mentions' => [['user_id' => $recipient->id, 'start' => 0, 'length' => 6]]])->assertUnprocessable();
    expect($conversation->messages()->count())->toBe(0)->and(DB::table('chat_message_mentions')->count())->toBe(0);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => '@Rohan as ordinary text'])->assertCreated()->assertJsonPath('message.mentions', []);
});

it('rejects cross-conversation replies and forged read cursors', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    $other = sharedChatConversation($sender, $recipient);
    $message = $other->messages()->create(['sender_id' => $sender->id, 'content' => 'Other context', 'sender_type' => 'user', 'message_type' => 'text']);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Reply', 'reply_to_id' => $message->id])->assertUnprocessable();
    $this->postJson(route('chat.read', $conversation), ['message_id' => $message->id])->assertNotFound();
});

it('redacts removed message content and refuses removed attachment access', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $response = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Remove this', 'files' => [UploadedFile::fake()->create('note.txt', 1, 'text/plain')]])->assertCreated();
    $message = OpsMessage::findOrFail($response->json('message.id'));
    $attachment = DB::table('chat_attachments')->where('message_id', $message->id)->first();
    $this->postJson(route('chat.action', $message), ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => (int) $message->fresh()->chat_edit_version])->assertNoContent();
    $this->getJson(route('chat.messages', $conversation))->assertJsonPath('messages.0.content', '')->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.attachments', []);
    $this->getJson(route('chat.attachment', $attachment->id))->assertNotFound();
});

it('denies a former staff member even when historical membership remains', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $recipient);
    HrEmployeeProfile::where('user_id', $recipient->id)->update(['is_active' => false]);
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'No access'])->assertForbidden();
});

it('keeps channel history and unread counts behind current site access', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    [$sender, $reader] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($sender, $reader);
    $conversation->update(['is_managed_channel' => true, 'channel_site_id' => $otherSite->id]);
    $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Site-restricted message']);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
    expect(app(OpsMessageVisibilityService::class)->unreadCount($reader))->toBe(0);
});

it('makes repeated pin and reaction requests idempotent', function () {
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'A message']);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id);
    foreach (range(1, 2) as $attempt) {
        $this->postJson(route('chat.action', $message), ['action' => 'pin', 'active' => true])->assertNoContent();
        $this->postJson(route('chat.action', $message), ['action' => 'react', 'emoji' => '👍', 'active' => true])->assertNoContent();
    }
    expect($message->fresh()->is_pinned)->toBeTrue()->and($message->reactions()->count())->toBe(1);
});

it('requires the shared rollout and rejects the old write path once enabled', function () {
    $site = Site::factory()->create();
    $sender = sharedChatStaff($site);
    $conversation = sharedChatConversation($sender, sharedChatStaff($site));
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id);
    config(['chat.shared_experience' => false]);
    $this->getJson(route('chat.messages', $conversation))->assertNotFound();
    config(['chat.shared_experience' => true]);
    $request = Request::create('/', 'POST', ['content' => 'Old client']);
    $request->setUserResolver(fn () => $sender);
    try {
        app(MessageController::class)->store($request, $conversation->id);
        $this->fail('Legacy send must not bypass the shared command.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect($conversation->messages()->count())->toBe(0);
});

it('keeps each relative separate until they are explicitly added', function () {
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $relatives = collect(range(1, 2))->map(function () use ($client) {
        $user = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
        $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

        return $user;
    });
    $response = $this->actingAs($relatives[0])->withHeader('X-Chat-Actor', (string) $relatives[0]->id)->postJson(route('chat.create'), [
        'mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => [],
    ])->assertCreated();
    $conversation = OpsConversation::findOrFail($response->json('conversation.id'));
    expect($conversation->participants()->pluck('user_id')->all())->toBe([$relatives[0]->id]);
    $relatives[1]->update(['name' => 'Rohan']);
    $this->getJson(route('chat.messages', $conversation))->assertJsonPath('conversation.mentionable_ids', []);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), ...sharedChatAudience($this, $conversation), 'content' => '@Rohan check this', 'mentions' => [['user_id' => $relatives[1]->id, 'start' => 0, 'length' => 6]]])->assertUnprocessable();
    $this->actingAs($relatives[1])->withHeader('X-Chat-Actor', (string) $relatives[1]->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
});

function sharedChatCallingPolicy(): void
{
    config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'https://voice.example.test',
        'chat.calling.api_key' => 'chat-test', 'chat.calling.api_secret' => str_repeat('test', 12),
        'chat.calling.verified' => true, 'chat.calling.authorization_gateway_verified' => true]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true, 'family_calls_enabled' => true, 'max_call_participants' => 2]]);
    Http::fake(['https://voice.example.test/*' => Http::response([], 200)]);
}

it('recovers the exact outgoing call attempt after mutable routing policy and outcome changes', function () {
    sharedChatCallingPolicy();
    $site = Site::factory()->create();
    [$caller, $receiver, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver, $other);
    sharedChatAvailable($receiver, $other);
    $data = ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'participant_ids' => [$receiver->id]];
    $outcome = route('chat.calls.start-outcome', [$conversation, $data['mutation_id']]).'?device_id='.$data['device_id'];
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->getJson($outcome)->assertOk()->assertJsonPath('attempt_state', 'unconfirmed');
    $id = $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->assertJsonPath('can_join', true)->json('call.id');
    $call = ChatCall::findOrFail($id);
    $call->update(['routing_mode' => 'care_team']);
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => ['calls_enabled' => false]]);
    $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->assertJsonPath('call.id', $id);
    $this->postJson(route('chat.calls.start', $conversation), [...$data, 'participant_ids' => [$other->id]])->assertConflict();
    $this->postJson(route('chat.calls.start', $conversation), [...$data, 'device_id' => (string) Str::uuid()])->assertConflict();
    $this->getJson($outcome)->assertOk()->assertJsonPath('attempt_state', 'started')->assertJsonPath('call.id', $id);
    $call->update(['status' => 'ended', 'ended_at' => now()]);
    $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->assertJsonPath('attempt_state', 'finished')->assertJsonPath('can_join', false);
    expect(ChatCall::where('started_by', $caller->id)->count())->toBe(1);
    DB::table('chat_history_call_exclusions')->insert(['participant_id' => $conversation->participants()->where('user_id', $caller->id)->value('id'), 'call_id' => $id]);
    $this->getJson($outcome)->assertOk()->assertJsonPath('attempt_state', 'finished')->assertJsonPath('call', null);
    $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->assertJsonPath('call', null)->assertJsonPath('can_join', false);
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id)->getJson($outcome)->assertOk()->assertJsonPath('attempt_state', 'unconfirmed')->assertJsonPath('call', null);
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $receiver->id)->getJson($outcome)->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $caller->id);
    $conversation->participants()->where('user_id', $caller->id)->delete();
    $this->getJson($outcome)->assertForbidden();
});

it('cancels an outgoing call attempt before creation and prevents a late start from ringing anyone', function () {
    sharedChatCallingPolicy();
    $site = Site::factory()->create();
    [$caller, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver);
    sharedChatAvailable($receiver);
    $data = ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'participant_ids' => [$receiver->id]];
    $cancel = route('chat.calls.cancel-start', [$conversation, $data['mutation_id']]);
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->postJson($cancel, $data)->assertOk()->assertJsonPath('attempt_state', 'cancelled');
    $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->assertJsonPath('attempt_state', 'cancelled')->assertJsonPath('call', null);
    $this->postJson($cancel, $data)->assertOk();
    $this->postJson($cancel, [...$data, 'device_id' => (string) Str::uuid()])->assertConflict();
    $this->getJson(route('chat.calls.start-outcome', [$conversation, $data['mutation_id']]).'?device_id='.(string) Str::uuid())->assertConflict();
    expect(ChatCall::where('started_by', $caller->id)->count())->toBe(0);
    expect(DB::table('chat_call_start_commands')->where('actor_id', $caller->id)->count())->toBe(1);
});

it('keeps a cancelled outgoing call attempt denied through media failure without cancelling a later invitation', function () {
    sharedChatCallingPolicy();
    $site = Site::factory()->create();
    [$caller, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver);
    sharedChatAvailable($receiver);
    $data = ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'participant_ids' => [$receiver->id]];
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id);
    $id = $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->json('call.id');
    $call = ChatCall::findOrFail($id);
    Http::swap(new Factory);
    Http::fake(['https://voice.example.test/*' => Http::response([], 503)]);
    $cancel = route('chat.calls.cancel-start', [$conversation, $data['mutation_id']]);
    $this->postJson($cancel, $data)->assertOk()->assertJsonPath('attempt_state', 'cancelled');
    expect($call->participants()->where('user_id', $caller->id)->value('status'))->toBe('removing');
    $this->postJson(route('chat.calls.join', $call), ['device_id' => $data['device_id']])->assertForbidden();
    $this->postJson(route('chat.calls.start', $conversation), $data)->assertCreated()->assertJsonPath('can_join', false);
    $newIdentity = (string) Str::uuid();
    $call->update(['status' => 'active', 'host_id' => $receiver->id]);
    $call->participants()->where('user_id', $caller->id)->update(['status' => 'invited', 'device_id' => null, 'media_identity' => $newIdentity, 'invitation_version' => 2]);
    $call->participants()->where('user_id', $receiver->id)->update(['status' => 'connected', 'device_id' => (string) Str::uuid(), 'updated_at' => now()]);
    Http::swap(new Factory);
    Http::fake();
    $this->postJson($cancel, $data)->assertOk();
    Http::assertNothingSent();
    expect($call->participants()->where('user_id', $caller->id)->value('status'))->toBe('invited');
    expect($call->participants()->where('user_id', $caller->id)->value('media_identity'))->toBe($newIdentity);
});

function sharedChatAvailable(User ...$people): void
{
    foreach ($people as $person) {
        DB::table('chat_preferences')->updateOrInsert(['user_id' => $person->id], ['availability' => 'available', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('projects confirmed call outcomes only for the current participant invitation and visible history', function () {
    [$call, $conversation, $host, $member, $outsider] = sharedChatManagedCall();
    $person = $call->participants()->where('user_id', $member->id)->firstOrFail();
    $params = ['device_id' => $person->device_id, 'invitation_id' => $person->media_identity];
    $url = fn (array $query = []) => route('chat.calls.outcome', $call).'?'.http_build_query($query ?: $params);
    $this->actingAs($member)->withHeader('X-Chat-Actor', (string) $member->id)->getJson($url())->assertOk()->assertJsonPath('call.outcome', 'ongoing')->assertJsonPath('followup.family', false)->assertJsonPath('followup.callbacks', false);
    $this->getJson($url([...$params, 'device_id' => (string) Str::uuid()]))->assertOk()->assertJsonPath('call.outcome', 'answered_on_another_device');
    $this->getJson($url([...$params, 'invitation_id' => (string) Str::uuid()]))->assertConflict();
    $person->update(['status' => 'answered_elsewhere', 'device_id' => null]);
    $this->getJson($url())->assertOk()->assertJsonPath('call.outcome', 'answered_elsewhere');
    $person->update(['status' => 'invited', 'joined_at' => null, 'invitation_expires_at' => now()->subSecond()]);
    $this->getJson($url())->assertOk()->assertJsonPath('call.outcome', 'missed');
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => ['enabled' => false, 'calls_enabled' => false]]);
    $this->getJson($url())->assertOk()->assertJsonPath('followup.conversation.can_send', false)->assertJsonPath('followup.conversation.can_call', false);
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->getJson($url())->assertNotFound();
    $this->actingAs($member)->withHeader('X-Chat-Actor', (string) $member->id);
    DB::table('chat_history_call_exclusions')->insert(['participant_id' => $conversation->participants()->where('user_id', $member->id)->value('id'), 'call_id' => $call->id]);
    $this->getJson($url())->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $host->id)->getJson($url())->assertForbidden();
});

it('reports a cancelled ringing call and an all-declined call without calling either connected', function () {
    sharedChatCallingPolicy();
    $site = Site::factory()->create();
    [$caller, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver);
    sharedChatAvailable($receiver);
    $device = (string) Str::uuid();
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id);
    $id = $this->postJson(route('chat.calls.start', $conversation), ['mutation_id' => (string) Str::uuid(), 'device_id' => $device, 'participant_ids' => [$receiver->id]])->assertCreated()->json('call.id');
    $this->postJson(route('chat.calls.leave', $id), ['device_id' => $device])->assertNoContent();
    $this->getJson(route('chat.calls.outcome', $id).'?device_id='.$device)->assertOk()->assertJsonPath('call.outcome', 'cancelled')->assertJsonPath('call.connected_at', null);
    $call = ChatCall::findOrFail($id);
    $call->update(['status' => 'ended']);
    $call->participants()->where('user_id', $receiver->id)->update(['status' => 'declined']);
    $this->getJson(route('chat.calls.outcome', $id).'?device_id='.$device)->assertOk()->assertJsonPath('call.outcome', 'declined_by_others');
});

function sharedChatManagedCall(): array
{
    sharedChatCallingPolicy();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true, 'max_call_participants' => 4]]);
    $site = Site::factory()->create();
    [$host, $member, $invitee, $fourth] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($host, $member, $invitee, $fourth);
    $call = sharedChatPresentationCall($conversation, $host, $member);
    $call->update(['status' => 'active', 'connected_at' => now()]);
    foreach ($call->participants as $person) {
        $person->update(['status' => 'connected', 'device_id' => (string) Str::uuid(), 'joined_at' => now()]);
    }
    sharedChatAvailable($invitee, $fourth);

    return [$call, $conversation, $host, $member, $invitee, $fourth];
}

function sharedChatPeopleCommand(ChatCall $call, User $actor): array
{
    $person = $call->participants()->where('user_id', $actor->id)->firstOrFail();

    return ['mutation_id' => (string) Str::uuid(), 'device_id' => $person->device_id, 'actor_invitation_id' => $person->media_identity];
}

it('manages live call invitations and removal without replaying old commands against a new invitation', function () {
    [$call, $conversation, $host, $member, $invitee, $fourth] = sharedChatManagedCall();
    $invite = [...sharedChatPeopleCommand($call, $host), 'participant_ids' => [$invitee->id]];
    $this->actingAs($host)->withHeader('X-Chat-Actor', (string) $host->id);
    $this->getJson(route('chat.calls.people', [$call, 'device_id' => $invite['device_id']]))->assertOk()->assertJsonPath('reserved', 2)->assertJsonCount(2, 'people');
    $this->postJson(route('chat.calls.invite', $call), $invite)->assertNoContent();
    $first = $call->participants()->where('user_id', $invitee->id)->firstOrFail();
    expect($first->invited_by)->toBe($host->id)->and($first->invitation_version)->toBe(1);
    $this->postJson(route('chat.calls.invite', $call), $invite)->assertNoContent();
    $this->postJson(route('chat.calls.invite', $call), [...$invite, 'participant_ids' => [$fourth->id]])->assertConflict();
    expect($call->participants()->count())->toBe(3);
    $device = (string) Str::uuid();
    $token = $this->actingAs($invitee)->withHeader('X-Chat-Actor', (string) $invitee->id)
        ->postJson(route('chat.calls.join', $call), ['device_id' => $device, 'invitation_id' => $first->media_identity])->assertOk()->json('connection.token');
    $remove = [...sharedChatPeopleCommand($call, $host), 'participant_id' => $invitee->id, 'invitation_id' => $first->media_identity];
    $this->actingAs($member)->withHeader('X-Chat-Actor', (string) $member->id)
        ->postJson(route('chat.calls.remove', $call), [...sharedChatPeopleCommand($call, $member), 'participant_id' => $invitee->id, 'invitation_id' => $first->media_identity])->assertForbidden();
    Http::swap(new Factory);
    $removalChecked = false;
    Http::fake(['https://voice.example.test/*' => function () use ($first, &$removalChecked) {
        expect($first->fresh()->status)->toBe('removing');
        $removalChecked = true;

        return Http::response([], 200);
    }]);
    $this->actingAs($host)->withHeader('X-Chat-Actor', (string) $host->id)->postJson(route('chat.calls.remove', $call), $remove)->assertNoContent();
    expect($first->fresh()->status)->toBe('removed');
    expect($removalChecked)->toBeTrue();
    $this->withHeader('X-Chat-Access-Token', $token)->get(route('chat.calling.authorize'))->assertForbidden();
    $this->postJson(route('chat.calls.invite', $call), $invite)->assertNoContent();
    expect($first->fresh()->status)->toBe('removed');
    $this->postJson(route('chat.calls.invite', $call), [...$invite, 'mutation_id' => (string) Str::uuid()])->assertNoContent();
    $second = $first->fresh();
    expect($second->media_identity)->not->toBe($first->media_identity)->and($second->invitation_version)->toBe(2);
    $this->postJson(route('chat.calls.remove', $call), $remove)->assertNoContent();
    $this->postJson(route('chat.calls.remove', $call), [...$remove, 'mutation_id' => (string) Str::uuid()])->assertConflict();
    $this->actingAs($invitee)->withHeader('X-Chat-Actor', (string) $invitee->id);
    foreach (['join', 'leave', 'connected'] as $action) {
        $result = $this->postJson(route('chat.calls.'.$action, $call), ['device_id' => $device, 'invitation_id' => $first->media_identity]);
        expect(in_array($result->status(), [404, 409], true))->toBeTrue();
    }
    $this->postJson(route('chat.calls.join', $call), ['device_id' => $device])->assertConflict();
    $this->postJson(route('chat.calls.join', $call), ['device_id' => $device, 'invitation_id' => $second->media_identity])->assertOk();
    $this->postJson(route('chat.calls.leave', $call), ['device_id' => $device, 'invitation_id' => $first->media_identity])->assertConflict();
    expect($second->fresh()->status)->toBe('accepted');
    Http::assertSentCount(1);
});

it('keeps failed media removal denied and completes the same command safely after reconciliation', function () {
    [$call, $conversation, $host, $member] = sharedChatManagedCall();
    $person = $call->participants()->where('user_id', $member->id)->firstOrFail();
    $command = [...sharedChatPeopleCommand($call, $host), 'participant_id' => $member->id, 'invitation_id' => $person->media_identity];
    Http::swap(new Factory);
    Http::fake(['https://voice.example.test/*' => Http::response([], 503)]);
    $this->actingAs($host)->withHeader('X-Chat-Actor', (string) $host->id)->postJson(route('chat.calls.remove', $call), $command)->assertServerError();
    expect($person->fresh()->status)->toBe('removing');
    expect(DB::table('chat_call_people_commands')->where('mutation_id', $command['mutation_id'])->value('completed_at'))->toBeNull();
    $this->postJson(route('chat.calls.invite', $call), [...sharedChatPeopleCommand($call, $host), 'participant_ids' => [$member->id]])->assertConflict();
    Http::swap(new Factory);
    Http::fake(['https://voice.example.test/*' => Http::response([], 404)]);
    app(ChatCallReconciler::class)->reconcile($call);
    expect($person->fresh()->status)->toBe('removed')->and($call->fresh()->ended_at)->not->toBeNull();
    $this->postJson(route('chat.calls.remove', $call), $command)->assertNoContent();
    expect(DB::table('chat_call_people_commands')->where('mutation_id', $command['mutation_id'])->value('completed_at'))->not->toBeNull();
});

it('checks current membership availability device and capacity before live invitations and does not grant outsiders call options', function () {
    [$call, $conversation, $host, $member, $invitee, $fourth] = sharedChatManagedCall();
    $command = [...sharedChatPeopleCommand($call, $host), 'participant_ids' => [$invitee->id, $fourth->id]];
    $this->actingAs($host)->withHeader('X-Chat-Actor', (string) $host->id);
    $this->postJson(route('chat.calls.invite', $call), [...$command, 'device_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson(route('chat.calls.invite', $call), [...$command, 'actor_invitation_id' => (string) Str::uuid()])->assertConflict();
    $fourth->update(['dnd_enabled' => true]);
    $this->postJson(route('chat.calls.invite', $call), $command)->assertConflict();
    $fourth->update(['dnd_enabled' => false]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true, 'max_call_participants' => 3]]);
    $this->postJson(route('chat.calls.invite', $call), $command)->assertConflict();
    $conversation->participants()->where('user_id', $invitee->id)->delete();
    $this->postJson(route('chat.calls.invite', $call), [...$command, 'participant_ids' => [$invitee->id]])->assertForbidden();
    $this->actingAs($invitee)->withHeader('X-Chat-Actor', (string) $invitee->id)
        ->getJson(route('chat.calls.people', [$call, 'device_id' => $command['device_id']]))->assertForbidden();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => false, 'max_call_participants' => 4]]);
    $this->actingAs($host)->withHeader('X-Chat-Actor', (string) $host->id)
        ->postJson(route('chat.calls.invite', $call), [...$command, 'participant_ids' => [$fourth->id]])->assertForbidden();
    expect(DB::table('chat_call_people_commands')->count())->toBe(0)->and($call->participants()->count())->toBe(2);
    Http::assertNothingSent();
});

it('converts an answered care-team call to an explicitly invited group without reopening closed cover invitations', function () {
    sharedChatCallingPolicy();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true, 'family_calls_enabled' => true, 'max_call_participants' => 4]]);
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $conversation->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    $call = sharedChatPresentationCall($conversation, $relative, $worker, $cover);
    $call->update(['status' => 'active', 'host_id' => $worker->id, 'routing_mode' => 'care_team', 'answered_by' => $worker->id]);
    foreach ($call->participants as $person) {
        $person->update(['status' => $person->user_id === $cover->id ? 'answered_elsewhere' : 'connected', 'device_id' => (string) Str::uuid()]);
    }
    sharedChatAvailable($other);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)
        ->postJson(route('chat.calls.invite', $call), [...sharedChatPeopleCommand($call, $worker), 'participant_ids' => [$other->id]])->assertNoContent();
    expect($call->fresh()->routing_mode)->toBe('invited')->and($call->participants()->where('user_id', $cover->id)->value('status'))->toBe('answered_elsewhere');
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->getJson(route('chat.calls.status'))
        ->assertOk()->assertJsonPath('calls.0.presentation.caller_name', $worker->name);
    $this->postJson(route('chat.calls.join', $call), ['device_id' => (string) Str::uuid()])->assertOk();
});

it('passes group call hosting to current eligible staff before an earlier family participant', function () {
    sharedChatCallingPolicy();
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $conversation->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    $call = sharedChatPresentationCall($conversation, $relative, $other, $worker, $cover);
    $call->update(['status' => 'active', 'connected_at' => now()]);
    foreach ($call->participants as $person) {
        $person->update(['status' => 'connected', 'joined_at' => now(), 'device_id' => (string) Str::uuid()]);
    }
    $relativeDevice = $call->participants->firstWhere('user_id', $relative->id)->device_id;
    $workerDevice = $call->participants->firstWhere('user_id', $worker->id)->device_id;
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)
        ->postJson(route('chat.calls.leave', $call), ['device_id' => $relativeDevice])->assertNoContent();
    expect($call->fresh()->host_id)->toBe($worker->id)->and($call->fresh()->ended_at)->toBeNull();
    // A retry from the former host cannot end the survivors' call.
    $this->postJson(route('chat.calls.leave', $call), ['device_id' => $relativeDevice, 'end_for_everyone' => true])->assertNoContent();
    expect($call->fresh()->ended_at)->toBeNull();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)
        ->postJson(route('chat.calls.leave', $call), ['device_id' => $workerDevice])->assertNoContent();
    expect($call->fresh()->host_id)->toBe($cover->id)->and($call->fresh()->ended_at)->toBeNull();
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'DeleteRoom'));
});

it('reconciles a lost group host using current authority and falls back to approved family', function () {
    sharedChatCallingPolicy();
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $conversation->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    $call = sharedChatPresentationCall($conversation, $worker, $relative, $other, $cover);
    $call->update(['status' => 'active', 'connected_at' => now()]);
    $call->participants()->update(['status' => 'connected', 'joined_at' => now()]);
    $call->participants()->where('user_id', $worker->id)->update(['updated_at' => now()->subMinutes(2)]);
    app(ChatCallReconciler::class)->reconcile($call);
    expect($call->fresh()->host_id)->toBe($cover->id)->and($call->fresh()->ended_at)->toBeNull();
    // Removing cover authority must prevent retaining or awarding the host role.
    $conversation->update(['care_team_cover' => false]);
    app(ChatCallReconciler::class)->reconcile($call);
    expect($call->fresh()->host_id)->toBe($relative->id)->and($call->fresh()->ended_at)->toBeNull();
    expect($call->participants()->where('user_id', $cover->id)->value('status'))->toBe('revoked');
    Http::assertSentCount(2);
});

it('expires unanswered group invitations while preserving the people already connected', function (bool $legacy) {
    $this->freezeTime();
    sharedChatCallingPolicy();
    $site = Site::factory()->create();
    [$caller, $receiver, $unanswered] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver, $unanswered);
    $call = sharedChatPresentationCall($conversation, $caller, $receiver, $unanswered);
    $call->update(['status' => 'active', 'connected_at' => now(), 'ring_until' => $legacy ? now() : now()->addSeconds(40)]);
    $call->participants()->whereIn('user_id', [$caller->id, $receiver->id])->update(['status' => 'connected', 'joined_at' => now()]);
    $call->participants()->where('user_id', $unanswered->id)->update(['invitation_expires_at' => $legacy ? null : now()]);
    $device = (string) Str::uuid();
    $this->actingAs($unanswered)->withHeader('X-Chat-Actor', (string) $unanswered->id)
        ->postJson(route('chat.calls.join', $call), ['device_id' => $device])->assertConflict();
    $this->getJson(route('chat.calls.status'))->assertOk()->assertJsonCount(0, 'calls');
    expect($call->participants()->where('user_id', $unanswered->id)->value('status'))->toBe('expired');
    expect($call->fresh()->ended_at)->toBeNull();
    expect($call->participants()->where('status', 'connected')->count())->toBe(2);
    $this->postJson(route('chat.calls.leave', $call), ['device_id' => $device])->assertNoContent();
    $this->getJson(route('chat.calls.history', ['conversation' => $conversation, 'filter' => 'missed']))
        ->assertOk()->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.outcome', 'missed');
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->getJson(route('chat.calls.status'))->assertOk()->assertJsonCount(1, 'calls');
    Http::assertNothingSent();
})->with([false, true]);

it('lets background chat calls expire the authenticated session without returning a sign-in page as success', function () {
    sharedChatCallingPolicy();
    $user = sharedChatStaff(Site::factory()->create());
    AppSetting::updateOrCreate(['key' => 'session_timeout_minutes'], ['value' => 30]);
    SecurityPolicy::flushCache();
    try {
        $activity = now()->subMinutes(20)->timestamp;
        $this->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id)->withSession(['last_activity_at' => $activity]);
        $this->getJson(route('chat.calls.status'))->assertOk();
        $this->patchJson(route('chat.availability'), [])->assertNoContent();
        expect(session('last_activity_at'))->toBe($activity);
        $this->travel(11)->minutes();
        $this->getJson(route('chat.calls.status'))->assertUnauthorized()->assertJsonPath('code', 'chat_session_expired');
        $this->assertGuest();
    } finally {
        SecurityPolicy::flushCache();
    }
});

it('routes a care-team call to eligible responders and gives only the first acceptance media access', function () {
    sharedChatCallingPolicy();
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $conversation->participants()->create(['user_id' => $other->id, 'role' => 'family']);
    sharedChatAvailable($relative, $other, $worker, $cover);
    $request = ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'routing_mode' => 'care_team', 'participant_ids' => []];
    $created = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.calls.start', $conversation), $request)
        ->assertCreated()->assertJsonPath('call.routing_mode', 'care_team')->assertJsonCount(3, 'call.participants');
    expect($created->json('call.presentation.audience'))->toContain('First responder answers');
    $call = ChatCall::findOrFail($created->json('call.id'));
    expect($call->participants()->where('user_id', $other->id)->exists())->toBeFalse();
    $this->postJson(route('chat.calls.start', $conversation), $request)->assertCreated()->assertJsonPath('call.id', $call->id);
    $this->postJson(route('chat.calls.start', $conversation), [...$request, 'routing_mode' => 'invited', 'participant_ids' => [$worker->id]])->assertConflict();
    $device = (string) Str::uuid();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->postJson(route('chat.calls.join', $call), ['device_id' => $device])
        ->assertOk()->assertJsonPath('call.host_id', $worker->id)->assertJsonStructure(['connection' => ['token']]);
    expect($call->fresh()->answered_by)->toBe($worker->id);
    expect($call->participants()->where('user_id', $cover->id)->value('status'))->toBe('answered_elsewhere');
    expect($call->participants()->whereIn('status', ['accepted', 'connected'])->count())->toBe(2);
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->postJson(route('chat.calls.join', $call), ['device_id' => (string) Str::uuid()])->assertConflict();
    $this->getJson(route('chat.calls.status'))->assertJsonCount(0, 'calls');
    $this->postJson(route('chat.calls.leave', $call), ['device_id' => (string) Str::uuid()])->assertNoContent();
    expect($call->fresh()->ended_at)->toBeNull();
    $this->getJson(route('chat.calls.history', $conversation))->assertOk()->assertJsonPath('calls.0.outcome', 'answered_elsewhere');
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.calls.leave', $call), ['device_id' => $request['device_id']])->assertNoContent();
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.calls.history', ['conversation' => $conversation, 'filter' => 'missed']))->assertJsonCount(0, 'calls');
});

it('does not turn unavailable or private family conversations into care-team conferences', function () {
    sharedChatCallingPolicy();
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    sharedChatAvailable($worker, $cover);
    $worker->update(['dnd_enabled' => true, 'dnd_until' => now()->addHour()]);
    DB::table('chat_preferences')->where('user_id', $cover->id)->update(['last_seen_at' => now()->subMinutes(2)]);
    $request = ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'routing_mode' => 'care_team', 'participant_ids' => []];
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.calls.options', $conversation))
        ->assertOk()->assertJsonPath('care_team.allowed', true)->assertJsonPath('care_team.available', false);
    $this->postJson(route('chat.calls.start', $conversation), $request)->assertConflict();
    $this->postJson(route('chat.calls.start', $conversation), [...$request, 'participant_ids' => [$worker->id]])->assertUnprocessable();
    $this->postJson(route('chat.calls.start', $conversation), [...$request, 'routing_mode' => 'invited', 'participant_ids' => [$worker->id]])->assertConflict();
    $conversation->update(['care_team_cover' => false]);
    $this->postJson(route('chat.calls.start', $conversation), $request)->assertUnprocessable();
    expect(ChatCall::count())->toBe(0);
    Http::assertNothingSent();
});

it('rechecks care-team responder availability and current access before claiming', function () {
    sharedChatCallingPolicy();
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    sharedChatAvailable($worker, $cover);
    $created = $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.calls.start', $conversation),
        ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'routing_mode' => 'care_team', 'participant_ids' => []])->assertCreated();
    $call = ChatCall::findOrFail($created->json('call.id'));
    $worker->update(['dnd_enabled' => true, 'dnd_until' => null]);
    $this->actingAs($worker->fresh())->withHeader('X-Chat-Actor', (string) $worker->id)->postJson(route('chat.calls.join', $call), ['device_id' => (string) Str::uuid()])->assertConflict();
    expect($call->fresh()->answered_by)->toBeNull();
    $conversation->update(['care_team_cover' => false]);
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->postJson(route('chat.calls.join', $call), ['device_id' => (string) Str::uuid()])->assertForbidden();
    expect($call->fresh()->answered_by)->toBeNull();
});

function sharedChatPresentationCall(OpsConversation $conversation, User $caller, User ...$invitees): ChatCall
{
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id,
        'started_by' => $caller->id, 'host_id' => $caller->id, 'mutation_id' => (string) Str::uuid(), 'ring_until' => now()->addSeconds(40)]);
    foreach ([$caller, ...$invitees] as $person) {
        $call->participants()->create(['user_id' => $person->id, 'media_identity' => (string) Str::uuid(),
            'status' => $person->id === $caller->id ? 'accepted' : 'invited']);
    }

    return $call;
}

it('projects family call metadata only from the current caller relationship and permitted invitation audience', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $client->update(['first_name' => 'Sam', 'last_name' => 'Taylor']);
    $relative->update(['name' => 'Jo Taylor']);
    $other->update(['name' => 'Not invited relative']);
    $kin = NextOfKin::create(['user_id' => $relative->id, 'client_id' => $client->id, 'relationship' => 'Mother', 'phone' => 'PRIVATE-PHONE', 'notes' => 'PRIVATE-NOTES']);
    // A stale call-participant row does not grant conversation membership.
    $call = sharedChatPresentationCall($conversation, $relative, $worker, $cover, $other);
    $response = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.calls.status'))
        ->assertOk()->assertJsonPath('calls.0.presentation.kind', 'family')
        ->assertJsonPath('calls.0.presentation.caller_name', 'Jo Taylor')
        ->assertJsonPath('calls.0.presentation.caller_detail', 'Sam Taylor’s mother')
        ->assertJsonPath('calls.0.presentation.viewer_is_family', false)
        ->assertJsonCount(3, 'calls.0.participants');
    expect($response->json('calls.0.presentation.audience'))->toContain('Jo Taylor')->toContain($cover->name)->not->toContain($other->name);
    expect($response->getContent())->not->toContain('PRIVATE-PHONE')->not->toContain('PRIVATE-NOTES');
    $kin->delete();
    $this->getJson(route('chat.calls.status'))->assertOk()->assertJsonPath('calls.0.presentation.caller_detail', 'Approved family participant');
    $client->portalUsers()->detach($relative->id);
    $denied = $this->getJson(route('chat.calls.status'))->assertOk()->assertJsonPath('calls.0.presentation', null);
    expect($denied->getContent())->not->toContain('Jo Taylor')->not->toContain('mother');
    expect($call->fresh()->ended_at)->toBeNull();
});

it('projects care-team call metadata for portal viewers without assuming that a client id makes the caller family', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $worker->update(['name' => 'Mere Example']);
    sharedChatPresentationCall($conversation, $worker, $relative);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.calls.status'))->assertOk()
        ->assertJsonPath('calls.0.presentation.kind', 'care_team')
        ->assertJsonPath('calls.0.presentation.caller_name', 'Mere Example')
        ->assertJsonPath('calls.0.presentation.caller_detail', 'Authorised care-team cover')
        ->assertJsonPath('calls.0.presentation.viewer_is_family', true);
});

it('projects group call metadata and avoids claiming a missing family relationship', function () {
    $site = Site::factory()->create();
    [$caller, $receiver, $third] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $group = sharedChatConversation($caller, $receiver, $third);
    $group->update(['title' => 'Afternoon team']);
    sharedChatPresentationCall($group, $caller, $receiver, $third);
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id)->getJson(route('chat.calls.status'))->assertOk()
        ->assertJsonPath('calls.0.presentation.kind', 'group')->assertJsonPath('calls.0.presentation.caller_name', 'Afternoon team')
        ->assertJsonPath('calls.0.presentation.caller_detail', $caller->name.' is inviting you');
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    // Each user can have only one next-of-kin record. A blank relationship must
    // still use the approved-participant fallback rather than inventing a role.
    NextOfKin::create(['user_id' => $relative->id, 'client_id' => $client->id, 'relationship' => '  ']);
    sharedChatPresentationCall($conversation, $relative, $worker);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.calls.status'))->assertOk()
        ->assertJsonPath('calls.0.presentation.caller_detail', 'Approved family participant');
});

it('rejects stale browser actors across call reads and writes before affecting the new account', function () {
    $site = Site::factory()->create();
    [$caller, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver);
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id,
        'started_by' => $caller->id, 'host_id' => $caller->id, 'mutation_id' => (string) Str::uuid(),
        'ring_until' => now()->addSeconds(40)]);
    $call->participants()->create(['user_id' => $receiver->id, 'media_identity' => (string) Str::uuid(), 'status' => 'invited']);
    Http::fake();
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $caller->id);
    foreach (['chat.calls.status' => [], 'chat.calls.options' => [$conversation], 'chat.calls.history' => [$conversation]] as $name => $parameters) {
        $this->getJson(route($name, $parameters))->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    }
    $this->patchJson(route('chat.availability'), ['availability' => 'available'])->assertForbidden();
    $this->postJson(route('chat.calls.start', $conversation), ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'participant_ids' => [$caller->id]])->assertForbidden();
    foreach (['join', 'connected', 'leave', 'record'] as $action) {
        $this->postJson(route('chat.calls.'.$action, $call), ['device_id' => (string) Str::uuid(), 'summary' => 'Must not be saved.'])->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
    }
    expect($call->fresh()->ended_at)->toBeNull();
    expect($call->participants()->first()->status)->toBe('invited');
    expect(ChatCall::count())->toBe(1);
    expect(DB::table('chat_preferences')->where('user_id', $receiver->id)->exists())->toBeFalse();
    Http::assertNothingSent();
    $this->withHeader('X-Chat-Actor', (string) $receiver->id)->getJson(route('chat.calls.status'))->assertOk();
    $this->patchJson(route('chat.availability'), ['availability' => 'available'])->assertNoContent();
    expect(DB::table('chat_preferences')->where('user_id', $receiver->id)->value('availability'))->toBe('available');
});

it('claims an incoming call on one device and tears down the room when the pair ends', function () {
    config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'https://voice.example.test',
        'chat.calling.api_key' => 'chat-test', 'chat.calling.api_secret' => str_repeat('test', 12),
        'chat.calling.verified' => true, 'chat.calling.authorization_gateway_verified' => true]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true]]);
    Http::fake(['https://voice.example.test/*' => Http::response([], 200)]);
    $site = Site::factory()->create();
    [$caller, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver);
    DB::table('chat_preferences')->insert(['user_id' => $receiver->id, 'availability' => 'available', 'last_seen_at' => now()]);
    $callerDevice = (string) Str::uuid();
    $receiverDevice = (string) Str::uuid();
    $payload = ['mutation_id' => (string) Str::uuid(), 'device_id' => $callerDevice, 'participant_ids' => [$receiver->id]];
    $call = $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->postJson(route('chat.calls.start', $conversation), $payload)->assertCreated()->json('call.id');
    $this->postJson(route('chat.calls.start', $conversation), $payload)->assertCreated()->assertJsonPath('call.id', $call);
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id)->postJson(route('chat.calls.join', $call), ['device_id' => $receiverDevice])->assertOk();
    $this->postJson(route('chat.calls.join', $call), ['device_id' => (string) Str::uuid()])->assertConflict();
    $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)->postJson(route('chat.calls.leave', $call), ['device_id' => $callerDevice])->assertNoContent();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/DeleteRoom'));
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id)->postJson(route('chat.calls.join', $call), ['device_id' => $receiverDevice])->assertConflict();
});

it('recovers an answer using the same invitation and makes stopping win over a late join', function (bool $acceptFirst) {
    sharedChatCallingPolicy();
    $site = Site::factory()->create();
    [$caller, $receiver] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($caller, $receiver);
    sharedChatAvailable($receiver);
    $id = $this->actingAs($caller)->withHeader('X-Chat-Actor', (string) $caller->id)
        ->postJson(route('chat.calls.start', $conversation), ['mutation_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'participant_ids' => [$receiver->id]])->assertCreated()->json('call.id');
    $call = ChatCall::findOrFail($id);
    $person = $call->participants()->where('user_id', $receiver->id)->firstOrFail();
    $command = ['device_id' => (string) Str::uuid(), 'invitation_id' => $person->media_identity];
    $this->actingAs($receiver)->withHeader('X-Chat-Actor', (string) $receiver->id);
    $this->getJson(route('chat.calls.outcome', $call).'?'.http_build_query($command))->assertOk()->assertJsonPath('call.my_status', 'invited');
    expect($person->fresh()->device_id)->toBeNull();
    if ($acceptFirst) {
        $this->postJson(route('chat.calls.join', $call), $command)->assertOk()->assertJsonPath('call.my_status', 'accepted');
        $this->getJson(route('chat.calls.outcome', $call).'?'.http_build_query($command))->assertOk()->assertJsonPath('call.my_status', 'accepted')->assertJsonPath('call.connected_at', null);
        $this->postJson(route('chat.calls.join', $call), $command)->assertOk()->assertJsonPath('call.id', $id)->assertJsonPath('call.my_invitation_id', $person->media_identity);
        expect($call->participants()->count())->toBe(2);
    }
    $this->postJson(route('chat.calls.leave', $call), $command)->assertNoContent();
    $this->postJson(route('chat.calls.leave', $call), $command)->assertNoContent();
    $this->postJson(route('chat.calls.join', $call), $command)->assertConflict();
    expect($person->fresh()->status)->toBeIn(['left', 'declined']);
})->with([false, true]);

it('starts invited staff history at joining and enforces the boundary across every message entry point', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$owner, $member, $other, $newcomer] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $secret = $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Earlier private history', 'files' => [UploadedFile::fake()->create('private.txt', 1, 'text/plain')]])->assertCreated()->json('message.id');
    OpsMessage::whereKey($secret)->update(['is_pinned' => true]);
    $file = DB::table('chat_attachments')->where('message_id', $secret)->value('id');
    $payload = ['user_ids' => [$newcomer->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'from_joining', 'message_ids' => []];
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertCreated()->assertJsonPath('pending', false);
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertCreated();
    expect($conversation->participants()->where('user_id', $newcomer->id)->count())->toBe(1);
    $visible = $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'New visible message', 'reply_to_id' => $secret])->assertCreated()->json('message.id');
    $this->actingAs($newcomer)->withHeader('X-Chat-Actor', (string) $newcomer->id)->getJson(route('chat.messages', $conversation))->assertOk()->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.id', $visible)->assertJsonPath('messages.0.reply', null)->assertJsonPath('messages.0.reply_to_id', null)->assertJsonPath('messages.0.reply_unavailable', true)
        ->assertJsonPath('conversation.unread', 1);
    expect(app(OpsMessageVisibilityService::class)->unreadCount($newcomer))->toBe(1);
    $this->getJson(route('chat.messages', [$conversation, 'q' => 'Earlier']))->assertJsonCount(0, 'messages');
    $this->getJson(route('chat.messages', [$conversation, 'around' => $secret]))->assertNotFound();
    $this->getJson(route('chat.attachment', $file))->assertForbidden();
    $this->postJson(route('chat.action', $secret), ['action' => 'save', 'active' => true])->assertForbidden();
    $this->postJson(route('chat.read', $conversation), ['message_id' => $secret])->assertNotFound();
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Not permitted quote', 'reply_to_id' => $secret])->assertUnprocessable();
    $this->getJson(route('chat.collections', ['kind' => 'pinned', 'conversation' => $conversation->id]))->assertJsonCount(0, 'items');
    DB::table('chat_saved_messages')->insert(['user_id' => $newcomer->id, 'message_id' => $secret, 'created_at' => now()]);
    $this->getJson(route('chat.collections', ['kind' => 'saved']))->assertJsonCount(0, 'items');
    config(['chat.shared_experience' => false]);
    expect(app(OpsMessageVisibilityService::class)->canViewConversation($newcomer, $conversation))->toBeFalse()
        ->and(app(OpsMessageVisibilityService::class)->unreadCount($newcomer))->toBe(0);
    $this->getJson(route('operations.messages.show', $conversation))->assertForbidden();
});

it('checks the complete staff audience before creating a group or adding members across sites', function () {
    [$site, $otherSite] = [Site::factory()->create(), Site::factory()->create()];
    [$owner, $member, $other] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    HrEmployeeProfile::whereIn('user_id', [$owner->id, $member->id, $other->id])->update(['secondary_site_ids' => json_encode([$otherSite->id])]);
    [$first, $second] = [sharedChatStaff($site), sharedChatStaff($otherSite)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->getJson(route('chat.people.options', $conversation))->assertOk()->assertJsonCount(2, 'people');
    $payload = ['user_ids' => [$first->id, $second->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'from_joining'];
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertForbidden();
    expect($conversation->participants()->count())->toBe(3)->and(DB::table('chat_invitations')->count())->toBe(0);
    $this->postJson(route('chat.create'), ['participant_ids' => [$first->id, $second->id], 'title' => 'Incompatible group', 'mutation_id' => (string) Str::uuid()])->assertForbidden();
    $this->postJson(route('chat.people.store', $conversation), [...$payload, 'user_ids' => [$first->id]])->assertCreated();
    $this->getJson(route('chat.people.options', $conversation))->assertOk()->assertJsonCount(0, 'people');
    $this->postJson(route('chat.people.store', $conversation), [...$payload, 'user_ids' => [$second->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid()])->assertForbidden();
    foreach ([$owner, $member, $other, $first] as $person) {
        $this->actingAs($person)->withHeader('X-Chat-Actor', (string) $person->id)->getJson(route('chat.messages', $conversation))->assertOk();
    }
});

it('shares only reviewed messages and clears historical grants when someone leaves and rejoins', function () {
    $site = Site::factory()->create();
    [$owner, $member, $other, $newcomer] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $other);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $private = $conversation->messages()->create(['sender_id' => $owner->id, 'sender_type' => 'user', 'content' => 'Unselected private original']);
    $shared = $conversation->messages()->create(['sender_id' => $owner->id, 'sender_type' => 'user', 'content' => 'Reviewed message', 'reply_to_id' => $private->id]);
    $payload = ['user_ids' => [$newcomer->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'selected', 'message_ids' => [$shared->id], 'history_confirmed' => true];
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->postJson(route('chat.people.store', $conversation), [...$payload, 'history_confirmed' => false])->assertUnprocessable();
    $this->postJson(route('chat.people.store', $conversation), [...$payload, 'message_ids' => [999999999]])->assertUnprocessable();
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertCreated();
    $this->actingAs($newcomer)->withHeader('X-Chat-Actor', (string) $newcomer->id)->getJson(route('chat.messages', $conversation))->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $shared->id)->assertJsonPath('messages.0.reply_unavailable', true);
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->patchJson(route('chat.management', $conversation), ['action' => 'remove', 'user_id' => $newcomer->id, 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    expect(DB::table('chat_history_grants')->count())->toBe(0);
    $this->postJson(route('chat.people.store', $conversation), ['user_ids' => [$newcomer->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'from_joining'])->assertCreated();
    $this->actingAs($newcomer)->withHeader('X-Chat-Actor', (string) $newcomer->id)->getJson(route('chat.messages', $conversation))->assertJsonCount(0, 'messages');
});

it('keeps family invitations pending until reviewed acceptance and does not expose intervening or old history', function () {
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $relatives = collect(range(1, 3))->map(function () use ($client) {
        $user = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
        $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

        return $user;
    });
    [$sender, $recipient, $other] = $relatives->all();
    $created = $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.create'), ['mutation_id' => (string) Str::uuid(), 'client_id' => $client->id, 'participant_ids' => []])->assertCreated();
    $conversation = OpsConversation::findOrFail($created->json('conversation.id'));
    $earlier = $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), ...sharedChatAudience($this, $conversation), 'content' => 'Selected family history'])->assertCreated()->json('message.id');
    $payload = ['user_ids' => [$recipient->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'selected', 'message_ids' => [$earlier], 'history_confirmed' => true];
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertCreated()->assertJsonPath('pending', true);
    $this->postJson(route('chat.people.store', $conversation), $payload)->assertCreated();
    expect($conversation->participants()->where('user_id', $recipient->id)->exists())->toBeFalse()->and(DB::table('chat_invitations')->count())->toBe(1);
    $invite = DB::table('chat_invitations')->first();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->getJson(route('chat.messages', $conversation))->assertForbidden();
    // Other clients' pending invitations must not consume this client's page.
    $otherClient = Client::factory()->create(['site_id' => $client->site_id]);
    $otherConversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $otherClient->id]);
    foreach (range(1, 51) as $index) {
        DB::table('chat_invitations')->insert([
            'id' => sprintf('ffffffff-ffff-4fff-8fff-%012d', $index), 'conversation_id' => $otherConversation->id,
            'invited_by' => $sender->id, 'user_id' => $recipient->id, 'mutation_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('0', 64), 'status' => 'pending', 'history_policy' => 'from_joining',
            'message_ids' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->getJson(route('chat.invitations', ['client' => $client->id]))->assertJsonCount(1, 'invitations')->assertJsonPath('invitations.0.shared_count', 1);
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version])->assertForbidden();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), ...sharedChatAudience($this, $conversation), 'content' => 'Between invitation and acceptance'])->assertCreated();
    $this->actingAs($recipient)->withHeader('X-Chat-Actor', (string) $recipient->id)->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => 0])->assertConflict();
    $version = $conversation->fresh()->chat_version;
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $version])->assertNoContent();
    $this->postJson(route('chat.invitations.respond', $invite->id), ['action' => 'accept', 'version' => $version])->assertNoContent();
    $this->getJson(route('chat.messages', $conversation))->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $earlier);
    config(['chat.shared_experience' => false]);
    $this->getJson(route('portal.clients.messages.show', [$client, $conversation]))->assertForbidden();
    $this->getJson(route('portal.clients.messages.search', ['client' => $client, 'q' => 'Selected']))->assertOk()->assertExactJson([]);
    config(['chat.shared_experience' => true]);
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.people.store', $conversation), ['user_ids' => [$other->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'from_joining'])->assertCreated();
    $pending = DB::table('chat_invitations')->where('user_id', $other->id)->first();
    $this->postJson(route('chat.invitations.respond', $pending->id), ['action' => 'cancel'])->assertNoContent();
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $other->id)->postJson(route('chat.invitations.respond', $pending->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version])->assertConflict();
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)->postJson(route('chat.people.store', $conversation), ['user_ids' => [$other->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'from_joining'])->assertCreated();
    $pending = DB::table('chat_invitations')->where('user_id', $other->id)->where('status', 'pending')->first();
    $client->portalUsers()->detach($other->id);
    $this->actingAs($other->fresh())->withHeader('X-Chat-Actor', (string) $other->id)->postJson(route('chat.invitations.respond', $pending->id), ['action' => 'accept', 'version' => $conversation->fresh()->chat_version])->assertForbidden();
    expect($conversation->participants()->where('user_id', $other->id)->exists())->toBeFalse();
    $client->portalUsers()->attach($other->id, ['relation' => 'next_of_kin']);
    $this->actingAs($other->fresh())->withHeader('X-Chat-Actor', (string) $other->id)->postJson(route('chat.invitations.respond', $pending->id), ['action' => 'decline'])->assertNoContent();
    $this->postJson(route('chat.invitations.respond', $pending->id), ['action' => 'decline'])->assertNoContent();
    $this->getJson(route('chat.invitations', ['client' => $client->id]))->assertJsonCount(0, 'invitations');
});

it('keeps prior attended calls private after rejoining even when new calls share the same timestamp', function () {
    $this->freezeTime();
    $site = Site::factory()->create();
    [$owner, $member, $newcomer] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($owner, $member, $newcomer);
    $conversation->participants()->where('user_id', $owner->id)->update(['role' => 'admin']);
    $makeCall = function () use ($conversation, $owner, $newcomer) {
        $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $owner->id,
            'host_id' => $owner->id, 'mutation_id' => (string) Str::uuid(), 'status' => 'ended', 'ring_until' => now(), 'ended_at' => now()]);
        $call->participants()->create(['user_id' => $newcomer->id, 'media_identity' => (string) Str::uuid(), 'status' => 'left']);

        return $call;
    };
    $old = $makeCall();
    $this->actingAs($owner)->withHeader('X-Chat-Actor', (string) $owner->id)->patchJson(route('chat.management', $conversation), ['action' => 'remove', 'user_id' => $newcomer->id, 'version' => $conversation->fresh()->chat_version])->assertNoContent();
    $this->postJson(route('chat.people.store', $conversation), ['user_ids' => [$newcomer->id], 'version' => $conversation->fresh()->chat_version, 'mutation_id' => (string) Str::uuid(), 'history_policy' => 'from_joining'])->assertCreated();
    $new = $makeCall();
    expect($old->created_at->equalTo($new->created_at))->toBeTrue();
    $this->actingAs($newcomer)->withHeader('X-Chat-Actor', (string) $newcomer->id)->getJson(route('chat.calls.history', $conversation))->assertOk()->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.id', $new->id);
    $this->getJson(route('chat.calls.history', [$conversation, 'before' => $old->id]))->assertNotFound();
});

function privateChatDraftInput(array $snapshot, ?array $draft = ['content' => 'Unfinished private note']): array
{
    return ['mutation_id' => (string) Str::uuid(), 'version' => $snapshot['version'], 'boundary' => $snapshot['boundary'], 'draft' => $draft];
}

it('keeps a private draft encrypted and visible only to its current author', function () {
    $site = Site::factory()->create();
    [$author, $peer, $outsider] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $snapshot = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('state', 'empty')->json();
    $input = privateChatDraftInput($snapshot);
    $this->putJson($url, $input)->assertOk()->assertJsonPath('draft.content', 'Unfinished private note')->assertJsonPath('version', 1);
    $stored = DB::table('chat_private_drafts')->first();
    expect($stored->encrypted_payload)->not->toContain('Unfinished private note');
    expect(json_decode(Crypt::decryptString($stored->encrypted_payload), true)['content'])->toBe('Unfinished private note');
    expect(DB::table('chat_private_draft_commands')->first()->payload_hash)->toHaveLength(64);
    expect(OpsMessage::where('conversation_id', $conversation->id)->count())->toBe(0);
    $this->actingAs($peer)->withHeader('X-Chat-Actor', (string) $peer->id)->getJson($url)->assertOk()->assertJsonPath('state', 'empty')->assertJsonPath('draft', null);
    $this->actingAs($outsider)->withHeader('X-Chat-Actor', (string) $outsider->id)->getJson($url)->assertForbidden();
});

it('uses private draft versions and immutable receipts without replacing newer work on retry', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $initial = $this->getJson($url)->assertOk()->json();
    $first = privateChatDraftInput($initial);
    $saved = $this->putJson($url, $first)->assertOk()->json();
    $second = privateChatDraftInput($saved, ['content' => 'Newer device draft']);
    $this->putJson($url, $second)->assertOk()->assertJsonPath('version', 2);
    $this->putJson($url, $first)->assertOk()->assertJsonPath('applied_version', 1)->assertJsonPath('version', 2)->assertJsonPath('draft.content', 'Newer device draft');
    $this->putJson($url, privateChatDraftInput($initial, ['content' => 'Stale overwrite']))->assertConflict()->assertJsonPath('code', 'chat_draft_version_changed');
    $this->putJson($url, [...$first, 'draft' => ['content' => 'Changed retry']])->assertConflict()->assertJsonPath('code', 'chat_draft_command_changed');
    expect(DB::table('chat_private_draft_commands')->count())->toBe(2);
});

it('keeps a private draft clear tombstone against late saves and exact old retries', function () {
    $site = Site::factory()->create();
    $author = sharedChatStaff($site);
    $peer = sharedChatStaff($site);
    $conversation = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $initial = $this->getJson($url)->assertOk()->json();
    $first = privateChatDraftInput($initial);
    $saved = $this->putJson($url, $first)->assertOk()->json();
    $this->putJson($url, privateChatDraftInput($saved, null))->assertOk()->assertJsonPath('version', 2)->assertJsonPath('state', 'empty');
    $this->putJson($url, $first)->assertOk()->assertJsonPath('state', 'empty')->assertJsonPath('version', 2);
    $this->putJson($url, privateChatDraftInput($initial))->assertConflict();
    expect(DB::table('chat_private_drafts')->first()->encrypted_payload)->toBeNull();
});

it('withholds a private draft when membership history or audience changes and requires explicit discard', function () {
    $site = Site::factory()->create();
    $author = sharedChatStaff($site);
    $peer = sharedChatStaff($site);
    $conversation = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $input = privateChatDraftInput($this->getJson($url)->assertOk()->json());
    $saved = $this->putJson($url, $input)->assertOk()->json();
    $conversation->participants()->where('user_id', $author->id)->update(['history_after_id' => 300]);
    $changed = $this->getJson($url)->assertOk()->assertJsonPath('state', 'scope_changed')->assertJsonPath('draft', null)->json();
    $this->putJson($url, privateChatDraftInput($saved))->assertConflict()->assertJsonPath('code', 'chat_draft_scope_changed');
    $this->putJson($url, privateChatDraftInput($changed))->assertConflict()->assertJsonPath('code', 'chat_draft_scope_changed');
    $cleared = $this->putJson($url, privateChatDraftInput($changed, null))->assertOk()->assertJsonPath('state', 'empty')->json();
    $this->putJson($url, privateChatDraftInput($cleared, ['content' => 'Reviewed new audience']))->assertOk();
});

it('denies private draft requests from a stale tab or revoked family link', function () {
    [$client, $conversation, $relative] = sharedChatCallbackFixture();
    $url = route('chat.draft.show', $conversation);
    $this->actingAs($relative)->getJson($url)->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $relative->id);
    $snapshot = $this->getJson($url)->assertOk()->json();
    $this->putJson($url, privateChatDraftInput($snapshot))->assertOk();
    $this->withHeader('X-Chat-Actor', '999999')->getJson($url)->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $relative->id);
    $client->portalUsers()->detach($relative->id);
    $this->getJson($url)->assertForbidden();
    $this->putJson($url, privateChatDraftInput($snapshot))->assertForbidden();
});

it('clears only the matching private draft version in the committed send transaction', function () {
    $site = Site::factory()->create();
    $author = sharedChatStaff($site);
    $peer = sharedChatStaff($site);
    $conversation = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $newer = $this->putJson($url, privateChatDraftInput($saved, ['content' => 'Keep my newer draft']))->assertOk()->json();
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Earlier send', 'draft_version' => $saved['version'], 'draft_boundary' => $saved['boundary']])->assertCreated();
    $this->getJson($url)->assertOk()->assertJsonPath('draft.content', 'Keep my newer draft')->assertJsonPath('version', 2);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Keep my newer draft', 'draft_version' => $newer['version'], 'draft_boundary' => $newer['boundary']])->assertCreated();
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'empty')->assertJsonPath('version', 3);
});

it('preserves a private draft on rejected send and denies foreign reply references', function () {
    $site = Site::factory()->create();
    $author = sharedChatStaff($site);
    $peer = sharedChatStaff($site);
    $conversation = sharedChatConversation($author, $peer);
    $other = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $message = $other->messages()->create(['sender_id' => $peer->id, 'sender_type' => 'user', 'content' => 'Different conversation', 'message_type' => 'text']);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $snapshot = $this->getJson($url)->assertOk()->json();
    $this->putJson($url, privateChatDraftInput($snapshot, ['content' => 'Reply', 'reply_to_id' => $message->id]))->assertUnprocessable();
    $saved = $this->putJson($url, privateChatDraftInput($snapshot))->assertOk()->json();
    $conversation->update(['is_archived' => true]);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Rejected', 'draft_version' => $saved['version'], 'draft_boundary' => $saved['boundary']])->assertConflict();
    $this->getJson($url)->assertOk()->assertJsonPath('draft.content', 'Unfinished private note');
    $this->putJson($url, privateChatDraftInput($saved))->assertConflict();
});

it('preserves private draft whitespace and Unicode mention offsets without notifying anyone', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $peer->update(['name' => 'Māia Worker']);
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $content = "  📝 @Māia Worker\nReview next visit  \n";
    $draft = ['content' => $content, 'mentions' => [['user_id' => $peer->id, 'start' => 4, 'length' => mb_strlen('@Māia Worker')]]];
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json(), $draft))->assertOk()->assertJsonPath('draft.content', $content)->assertJsonPath('draft.mentions.0.start', 4)->json();
    $this->getJson($url)->assertJsonPath('draft.content', $content);
    $peer->update(['name' => 'Updated Worker']);
    $this->putJson($url, privateChatDraftInput($saved, $draft))->assertUnprocessable();
    $this->getJson($url)->assertJsonPath('version', 1)->assertJsonPath('draft.content', $content);
    expect(DB::table('chat_message_mentions')->count())->toBe(0);
    Bus::assertNotDispatched(DeliverChatMessageNotifications::class);
});

it('keeps private draft attachment ownership and captions without publishing files', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $url = route('chat.draft.show', $conversation);
    $id = (string) Str::uuid();
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $this->postJson(route('chat.uploads.store', [$conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional visit')])->assertOk();
    $draft = ['content' => '', 'uploads' => [['id' => $id, 'caption' => "  Please review\n"]]];
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json(), $draft))->assertOk()->assertJsonPath('draft.uploads.0.caption', "  Please review\n")->json();
    $this->actingAs($peer)->withHeader('X-Chat-Actor', (string) $peer->id);
    $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json(), $draft))->assertForbidden();
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $this->deleteJson(route('chat.uploads.destroy', [$conversation, $id]))->assertOk();
    $this->putJson($url, privateChatDraftInput($saved, $draft))->assertUnprocessable();
    $this->getJson($url)->assertJsonPath('version', 1);
    expect(DB::table('chat_attachments')->count())->toBe(0);
});

it('withholds a private draft after a canonical client site transfer even for a still linked family author', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    // Establish a completed transfer fixture; this checks Chat's canonical read, not service commencement.
    DB::table('clients')->where('id', $client->id)->update(['site_id' => Site::factory()->create()->id]);
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'scope_changed')->assertJsonPath('draft', null);
    $this->putJson($url, privateChatDraftInput($saved))->assertConflict()->assertJsonPath('code', 'chat_draft_scope_changed');
});

it('denies private draft saves with hidden history references and disabled Chat', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $message = $conversation->messages()->create(['sender_id' => $peer->id, 'sender_type' => 'user', 'content' => 'Earlier history', 'message_type' => 'text']);
    $conversation->participants()->where('user_id', $author->id)->update(['history_after_id' => $message->id]);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $snapshot = $this->getJson($url)->assertOk()->json();
    $this->putJson($url, privateChatDraftInput($snapshot, ['content' => 'Reply', 'reply_to_id' => $message->id]))->assertUnprocessable();
    expect(DB::table('chat_private_drafts')->count())->toBe(0);
    config(['chat.shared_experience' => false]);
    $this->getJson($url)->assertNotFound();
    $this->putJson($url, privateChatDraftInput($snapshot))->assertNotFound();
});

function privateChatSendAttemptInput(array $draft, ?string $mutation = null): array
{
    return ['mutation_id' => $mutation ?? (string) Str::uuid(), 'draft_version' => $draft['version'], 'draft_boundary' => $draft['boundary']];
}

it('restores a durable send attempt without creating a message and confirms exactly once', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $draftUrl = route('chat.draft.show', $conversation);
    $saved = $this->putJson($draftUrl, privateChatDraftInput($this->getJson($draftUrl)->assertOk()->json(), ['content' => "  Fictional message\n"]))->assertOk()->json();
    $input = privateChatSendAttemptInput($saved);
    $reserve = route('chat.send-attempts.store', $conversation);
    $attempt = $this->postJson($reserve, $input)->assertOk()->assertJsonPath('state', 'pending')->json();
    expect($conversation->messages()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverChatMessageNotifications::class);
    expect(DB::table('chat_send_attempts')->first()->encrypted_payload)->not->toContain('Fictional message');
    $this->getJson($draftUrl)->assertJsonPath('pending_send.mutation_id', $input['mutation_id'])->assertJsonPath('pending_send.body.content', "  Fictional message\n");
    $this->postJson($reserve, $input)->assertOk()->assertJsonPath('body', $attempt['body']);
    $this->postJson($reserve, privateChatSendAttemptInput($saved))->assertConflict()->assertJsonPath('code', 'chat_send_attempt_pending');
    $message = $this->postJson(route('chat.send', $conversation), $attempt['body'])->assertCreated()->json('message.id');
    $this->postJson(route('chat.send', $conversation), $attempt['body'])->assertCreated()->assertJsonPath('message.id', $message);
    $this->getJson(route('chat.send-attempts.show', [$conversation, $input['mutation_id']]))->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('body', null)->assertJsonPath('message_id', $message);
    $this->postJson($reserve, $input)->assertOk()->assertJsonPath('state', 'confirmed');
    $this->getJson($draftUrl)->assertJsonPath('state', 'empty')->assertJsonPath('pending_send', null);
    expect(DB::table('chat_send_attempts')->first()->encrypted_payload)->toBeNull();
    expect($conversation->messages()->count())->toBe(1);
});

it('seals a durable send attempt cancelled before reservation against delayed requests', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $input = privateChatSendAttemptInput($saved);
    $cancel = route('chat.send-attempts.cancel', [$conversation, $input['mutation_id']]);
    $this->postJson($cancel)->assertOk()->assertJsonPath('state', 'cancelled');
    $this->postJson(route('chat.send-attempts.store', $conversation), $input)->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('body', null);
    $this->postJson(route('chat.send', $conversation), ['mutation_id' => $input['mutation_id'], 'content' => 'Delayed write'])->assertConflict()->assertJsonPath('code', 'chat_send_attempt_cancelled');
    $this->postJson($cancel)->assertOk()->assertJsonPath('state', 'cancelled');
    $this->getJson($url)->assertJsonPath('draft.content', 'Unfinished private note')->assertJsonPath('version', 1);
    expect($conversation->messages()->count())->toBe(0);
});

it('cancels a pending durable send attempt without clearing the draft and allows a new explicit attempt', function (string $payloadState) {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $input = privateChatSendAttemptInput($saved);
    $body = $this->postJson(route('chat.send-attempts.store', $conversation), $input)->assertOk()->json('body');
    if ($payloadState !== 'intact') {
        $payload = match ($payloadState) {
            'damaged ciphertext' => 'damaged ciphertext',
            'invalid json' => Crypt::encryptString('{invalid json'),
            'missing payload' => null,
        };
        DB::table('chat_send_attempts')->where('actor_id', $author->id)->where('mutation_id', $input['mutation_id'])->update(['encrypted_payload' => $payload]);
    }
    $otherConversation = sharedChatConversation($author, $peer);
    $this->postJson(route('chat.send-attempts.cancel', [$otherConversation, $input['mutation_id']]))->assertConflict();
    expect(DB::table('chat_send_attempts')->where('mutation_id', $input['mutation_id'])->value('status'))->toBe('pending');
    $cancel = route('chat.send-attempts.cancel', [$conversation, $input['mutation_id']]);
    $this->postJson($cancel)->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('body', null);
    $this->postJson($cancel)->assertOk()->assertJsonPath('state', 'cancelled');
    expect(DB::table('chat_send_attempts')->where('mutation_id', $input['mutation_id'])->value('encrypted_payload'))->toBeNull();
    $this->getJson($url)->assertOk()->assertJsonPath('draft.content', 'Unfinished private note')->assertJsonPath('pending_send', null);
    $this->postJson(route('chat.send', $conversation), $body)->assertConflict()->assertJsonPath('code', 'chat_send_attempt_cancelled');
    $replacement = $this->postJson(route('chat.send-attempts.store', $conversation), privateChatSendAttemptInput($saved))->assertOk()->json('body');
    $this->postJson(route('chat.send', $conversation), $replacement)->assertCreated();
    $this->postJson(route('chat.send-attempts.cancel', [$conversation, $replacement['mutation_id']]))->assertOk()->assertJsonPath('state', 'confirmed');
    expect($conversation->messages()->count())->toBe(1);
})->with(['intact', 'damaged ciphertext', 'invalid json', 'missing payload']);

it('rejects changed durable send attempt content or references and requires a reservation before a recoverable send', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $input = privateChatSendAttemptInput($saved);
    $reserve = route('chat.send-attempts.store', $conversation);
    $body = $this->postJson($reserve, $input)->assertOk()->json('body');
    $this->postJson(route('chat.send', $conversation), [...$body, 'content' => 'Changed after reservation'])->assertConflict()->assertJsonPath('code', 'chat_send_attempt_changed');
    $this->postJson($reserve, [...$input, 'draft_version' => 2])->assertConflict()->assertJsonPath('code', 'chat_send_attempt_changed');
    $this->postJson(route('chat.send', $conversation), [...$body, 'mutation_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'chat_send_attempt_missing');
    expect($conversation->messages()->count())->toBe(0);
    $this->getJson($url)->assertJsonPath('pending_send.body.content', 'Unfinished private note');
});

it('preserves a newer private draft when an older durable send attempt succeeds', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $body = $this->postJson(route('chat.send-attempts.store', $conversation), privateChatSendAttemptInput($saved))->assertOk()->json('body');
    $this->putJson($url, privateChatDraftInput($saved, ['content' => 'Later unsent draft']))->assertOk();
    $this->postJson(route('chat.send', $conversation), $body)->assertCreated();
    $this->getJson($url)->assertJsonPath('draft.content', 'Later unsent draft')->assertJsonPath('version', 2)->assertJsonPath('pending_send', null);
});

it('withholds a durable send attempt after audience change and permits safe cancellation without sending', function () {
    $site = Site::factory()->create();
    [$author, $peer, $added] = [sharedChatStaff($site), sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $body = $this->postJson(route('chat.send-attempts.store', $conversation), privateChatSendAttemptInput($saved))->assertOk()->json('body');
    $conversation->participants()->create(['user_id' => $added->id, 'role' => 'member']);
    $this->getJson($url)->assertJsonPath('pending_send.state', 'scope_changed')->assertJsonPath('pending_send.body', null)->assertJsonPath('draft', null);
    $this->postJson(route('chat.send', $conversation), $body)->assertConflict()->assertJsonPath('code', 'chat_send_attempt_scope_changed');
    $this->postJson(route('chat.send-attempts.cancel', [$conversation, $body['mutation_id']]))->assertOk()->assertJsonPath('state', 'cancelled');
    expect($conversation->messages()->count())->toBe(0);
});

it('keeps a durable send attempt private from peers and blocks stale actors and revoked family access', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $input = [...privateChatSendAttemptInput($saved), ...sharedChatAudience($this, $conversation)];
    $body = $this->postJson(route('chat.send-attempts.store', $conversation), $input)->assertOk()->json('body');
    $read = route('chat.send-attempts.show', [$conversation, $input['mutation_id']]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($read)->assertOk()->assertJsonPath('state', 'missing')->assertJsonPath('body', null);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($read)->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $relative->id);
    $client->portalUsers()->detach($relative->id);
    $this->getJson($read)->assertForbidden();
    $this->postJson(route('chat.send-attempts.cancel', [$conversation, $input['mutation_id']]))->assertForbidden();
    $this->postJson(route('chat.send', $conversation), $body)->assertForbidden();
    expect($conversation->messages()->count())->toBe(0);
});

it('rechecks durable send attempt uploads at send time and leaves both draft and intent on rejection', function () {
    Storage::fake('local');
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional visit')])->assertOk();
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json(), ['content' => '', 'uploads' => [['id' => $id, 'caption' => 'Review']]]))->assertOk()->json();
    $body = $this->postJson(route('chat.send-attempts.store', $conversation), privateChatSendAttemptInput($saved))->assertOk()->json('body');
    $this->deleteJson(route('chat.uploads.destroy', [$conversation, $id]))->assertOk();
    $this->postJson(route('chat.send', $conversation), $body)->assertConflict();
    $this->getJson($url)->assertJsonPath('draft.uploads.0.id', $id)->assertJsonPath('pending_send.state', 'pending');
    expect($conversation->messages()->count())->toBe(0);
});

it('preserves the private draft when a restored upload reference is missing without reporting lost account access', function () {
    $site = Site::factory()->create();
    [$author, $peer] = [sharedChatStaff($site), sharedChatStaff($site)];
    $conversation = sharedChatConversation($author, $peer);
    $this->actingAs($author)->withHeader('X-Chat-Actor', (string) $author->id);
    $url = route('chat.draft.show', $conversation);
    $saved = $this->putJson($url, privateChatDraftInput($this->getJson($url)->assertOk()->json()))->assertOk()->json();
    $this->putJson($url, privateChatDraftInput($saved, ['content' => 'New typing', 'uploads' => [['id' => (string) Str::uuid(), 'caption' => 'Missing']]]))->assertUnprocessable();
    $this->getJson($url)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('draft.content', 'Unfinished private note');
});

it('orders unanswered family inbox work by response priority across stable pages', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $request = function ($thread, $when) use ($relative, $client) {
        $message = $thread->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id,
            'content' => 'Fictional unanswered request', 'message_type' => 'text', 'created_at' => $when]);
        $thread->update(['family_response_message_id' => $message->id, 'family_response_started_at' => $when]);
    };
    $request($conversation, now()->subDays(100));
    $new = [];
    for ($i = 0; $i < 31; $i++) {
        $thread = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
        $request($thread, now()->subDays($i + 1));
        $new[] = $thread->id;
    }
    $expected = [$conversation->id, ...array_reverse($new)];
    $base = route('chat.family-inbox').'?client='.$client->id;
    $first = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson($base)->assertOk()->assertJsonPath('items.0.id', $conversation->id)->assertJsonPath('items.0.response_clock.due_at', null)->json();
    $last = $this->getJson($base.'&before='.$first['next_before'].'&revision='.$first['revision'])->assertOk()->json();
    expect([...array_column($first['items'], 'id'), ...array_column($last['items'], 'id')])->toBe($expected);
    $conversation->update(['family_response_started_at' => now()]);
    $this->getJson($base.'&before='.$first['next_before'].'&revision='.$first['revision'])->assertConflict();
    $this->getJson($base.'&through='.$first['scan_floor'].'&minimum=30')->assertOk()->assertJsonCount(30, 'items');
});

it('does not rank hidden family request history ahead of visible unanswered work', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $old = $conversation->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id,
        'content' => 'Restricted older request', 'message_type' => 'text']);
    // Message timestamps are guarded from mass assignment; establish real older history explicitly.
    $old->forceFill(['created_at' => now()->subDays(10)])->save();
    $conversation->update(['family_response_message_id' => $old->id, 'family_response_started_at' => $old->created_at]);
    $conversation->participants()->create(['user_id' => $worker->id, 'role' => 'member', 'history_after_id' => $old->id]);
    $new = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    $message = $new->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id,
        'content' => 'Visible recent request', 'message_type' => 'text']);
    $new->update(['family_response_message_id' => $message->id, 'family_response_started_at' => $message->created_at]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.family-inbox'))->assertOk()->assertJsonPath('items.0.id', $new->id);
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.family-inbox'))->assertOk()->assertJsonPath('items.0.id', $conversation->id);
});

it('uses service-hour deadlines and oldest request ties in the family inbox', function () {
    [$client, $conversation, $relative, $other, $worker] = sharedChatCallbackFixture();
    $schedule = ['timezone' => 'Pacific/Auckland', 'response_minutes' => 60, 'weekly' => [['day' => 1, 'start' => '08:00', 'end' => '18:00']], 'holidays' => []];
    app(ChatSettings::class)->save($worker, [...ChatSettings::DEFAULTS, 'version' => 0, 'response_schedule' => $schedule]);
    $new = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    foreach ([[$conversation, '2026-10-16 20:00:00'], [$new, '2026-10-18 10:00:00']] as [$thread, $date]) {
        $when = Carbon::parse($date, 'Pacific/Auckland');
        $message = $thread->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id,
            'content' => 'Outside service hours', 'message_type' => 'text', 'created_at' => $when]);
        $thread->update(['family_response_message_id' => $message->id, 'family_response_started_at' => $when]);
    }
    $rows = $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->getJson(route('chat.family-inbox'))->assertOk()->assertJsonPath('items.0.id', $conversation->id)->json('items');
    expect($rows[0]['response_clock']['due_at'])->not->toBeNull()->toBe($rows[1]['response_clock']['due_at']);
});

it('delivers one private family assignment notice after exact handover and worker retries', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $input = ['mutation_id' => (string) Str::uuid(), 'action' => 'handoff', 'assigned_to' => $cover->id, 'version' => 1, 'note' => 'Sensitive staff-only handover'];
    $url = route('chat.family-work.update', $conversation);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($url, $input)->assertNoContent();
    $this->patchJson($url, $input)->assertNoContent();
    Bus::assertDispatchedTimes(DeliverChatFamilyWorkNotifications::class, 1);
    expect(DB::table('chat_family_work_deliveries')->count())->toBe(1)->and(OpsMessage::count())->toBe(0);
    $service = app(ChatFamilyWorkDelivery::class);
    expect($service->recover())->toBe(1)->and($service->recover())->toBe(0);
    $notice = $cover->notifications()->sole();
    expect($notice->data['title'])->toBe('Family work assigned to you');
    expect(json_encode($notice->data))->not->toContain('Sensitive staff-only handover')->not->toContain($client->first_name)->not->toContain($worker->name)->not->toContain('conversation_id');
    expect($relative->notifications()->count())->toBe(0)->and($worker->notifications()->count())->toBe(0);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->get(route('chat.notifications.open', $notice->id))->assertNotFound();
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->get(route('chat.notifications.open', $notice->id))->assertNotFound();
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->get(route('chat.notifications.open', $notice->id))->assertRedirect(route('chat.index', ['client' => $client->id, 'conversation' => $conversation->id]));
    expect($notice->fresh()->read_at)->not->toBeNull();
    expect(app(BoardPackAccessService::class)->visibleNotificationQuery($cover)->whereKey($notice->id)->exists())->toBeTrue();
    // Reaffirming unchanged ownership does not queue another notice or hide this one.
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson($url, [...$input, 'mutation_id' => (string) Str::uuid(), 'version' => 2])->assertNoContent();
    expect(DB::table('chat_family_work_deliveries')->count())->toBe(1);
    expect($service->visibleNoticeIds($cover))->toBe([$notice->id]);
    $this->patchJson($url, [...$input, 'mutation_id' => (string) Str::uuid(), 'version' => 3, 'assigned_to' => null])->assertNoContent();
    expect(app(BoardPackAccessService::class)->visibleNotificationQuery($cover)->whereKey($notice->id)->exists())->toBeFalse();
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->get(route('chat.notifications.open', $notice->id))->assertNotFound();
});

it('never revives a superseded family assignment notice when work returns to a previous owner', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $url = route('chat.family-work.update', $conversation);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id);
    foreach ([$cover->id, $worker->id, $cover->id] as $index => $owner) {
        $this->patchJson($url, ['mutation_id' => (string) Str::uuid(), 'action' => 'assign', 'assigned_to' => $owner, 'version' => $index + 1])->assertNoContent();
    }
    expect(DB::table('chat_family_work_deliveries')->count())->toBe(2);
    $service = app(ChatFamilyWorkDelivery::class);
    expect($service->recover())->toBe(1);
    expect(DB::table('chat_family_work_deliveries')->orderBy('id')->pluck('status')->all())->toBe(['suppressed', 'delivered']);
    $notice = $cover->notifications()->sole();
    foreach (['resolved', 'open'] as $index => $status) {
        $this->patchJson($url, ['mutation_id' => (string) Str::uuid(), 'action' => 'status', 'status' => $status, 'version' => $index + 4])->assertNoContent();
    }
    expect($service->visibleNoticeIds($cover))->toBe([]);
    $this->actingAs($cover)->withHeader('X-Chat-Actor', (string) $cover->id)->get(route('chat.notifications.open', $notice->id))->assertNotFound();
});

it('rechecks family assignment notice history permissions mute and preferences before delivery', function () {
    foreach (['history', 'muted', 'preference', 'offboarded', 'site'] as $restriction) {
        [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
        if ($restriction === 'site') {
            // The usual fixture has an explicit all-site family-management permission.
            // Use an assigned support worker to exercise loss of canonical site access.
            $role = Role::create(['name' => 'assignment_site_worker_'.Str::random(8), 'label' => 'Assigned worker', 'level' => 10, 'type' => 'custom']);
            foreach (['progress_notes.viewAny', 'progress_notes.create'] as $key) {
                $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'operations', 'module' => 'Operations']);
                $role->permissions()->attach($permission->id);
            }
            $cover->roles()->sync([$role->id]);
            $cover->update(['role' => 'support_worker']);
            $client->supportWorkers()->attach($cover->id);
        }
        $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson(route('chat.family-work.update', $conversation), ['mutation_id' => (string) Str::uuid(), 'action' => 'handoff', 'assigned_to' => $cover->id, 'version' => 1])->assertNoContent();
        $eventId = DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)->value('id');
        if ($restriction === 'history') {
            $conversation->participants()->create(['user_id' => $cover->id, 'role' => 'member', 'history_after_id' => 0, 'family_work_history_after_id' => $eventId]);
        }
        if ($restriction === 'muted') {
            $conversation->participants()->create(['user_id' => $cover->id, 'role' => 'member', 'is_muted' => true]);
        }
        if ($restriction === 'preference') {
            UserNotificationPreference::create(['user_id' => $cover->id, 'key' => 'chat.family.assigned', 'enabled' => true, 'channel_inapp' => false]);
        }
        if ($restriction === 'offboarded') {
            HrEmployeeProfile::where('user_id', $cover->id)->update(['is_active' => false]);
        }
        if ($restriction === 'site') {
            DB::table('clients')->where('id', $client->id)->update(['site_id' => Site::factory()->create()->id]);
        }
        expect(app(ChatFamilyWorkDelivery::class)->recover($eventId))->toBe(0, $restriction);
        expect(DB::table('chat_family_work_deliveries')->where('event_id', $eventId)->value('status'))->toBe('suppressed', $restriction);
    }
    expect(app(ChatFamilyWorkDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_family_work_deliveries')->where('status', 'suppressed')->count())->toBe(5);
    expect(DB::table('notifications')->count())->toBe(0);
});

it('defers a family assignment notice during quiet time and recovers a failed notification exactly once', function () {
    $this->freezeTime();
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $cover->update(['dnd_enabled' => true, 'dnd_until' => now()->addMinutes(5)]);
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson(route('chat.family-work.update', $conversation), ['mutation_id' => (string) Str::uuid(), 'action' => 'handoff', 'assigned_to' => $cover->id, 'version' => 1])->assertNoContent();
    $service = app(ChatFamilyWorkDelivery::class);
    expect($service->recover())->toBe(0)->and(DB::table('chat_family_work_deliveries')->value('status'))->toBe('pending');
    $this->travel(6)->minutes();
    $fail = true;
    Event::listen(NotificationSending::class, function ($event) use (&$fail) {
        if ($fail && $event->notification instanceof ChatMessageNotice) {
            return false;
        }
    });
    expect($service->recover())->toBe(0);
    expect(DB::table('chat_family_work_deliveries')->value('attempts'))->toBe(1);
    expect($cover->notifications()->count())->toBe(0);
    $fail = false;
    $this->travel(2)->minutes();
    expect($service->recover())->toBe(1)->and($service->recover())->toBe(0);
    expect($cover->notifications()->count())->toBe(1);
});

it('commits a family assignment notice with ownership and recovers when queue dispatch is unavailable', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $input = ['mutation_id' => (string) Str::uuid(), 'action' => 'handoff', 'assigned_to' => $cover->id, 'version' => 1];
    try {
        DB::transaction(function () use ($worker, $conversation, $input) {
            app(ChatFamilyWork::class)->update($worker, $conversation, $input);
            throw new RuntimeException('Rollback ownership');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Rollback ownership');
    }
    expect($conversation->fresh()->assigned_to)->toBeNull();
    expect(DB::table('chat_family_work_deliveries')->count())->toBe(0)->and(DB::table('chat_family_work_events')->count())->toBe(0);
    Bus::swap(Mockery::mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable'))->getMock());
    $this->actingAs($worker)->withHeader('X-Chat-Actor', (string) $worker->id)->patchJson(route('chat.family-work.update', $conversation), $input)->assertNoContent();
    expect((int) $conversation->fresh()->assigned_to)->toBe($cover->id);
    expect(DB::table('chat_family_work_deliveries')->value('status'))->toBe('pending');
    expect(app(ChatFamilyWorkDelivery::class)->recover())->toBe(1);
});

it('saves personal family assignment notice preferences without enabling unavailable delivery channels', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $this->actingAs($cover)->putJson(route('settings.notifications.update'), ['prefs' => ['chat.family.assigned' => ['enabled' => false, 'inapp' => false, 'email' => false, 'push' => false]]])->assertRedirect();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($cover, 'chat.family.assigned'))->toBeFalse();
    $this->putJson(route('settings.notifications.update'), ['prefs' => ['chat.family.assigned' => ['enabled' => true, 'inapp' => true, 'email' => false, 'push' => true]]])->assertUnprocessable();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($cover, 'chat.family.assigned'))->toBeFalse();
    $this->putJson(route('settings.notifications.update'), ['prefs' => ['chat.family.assigned' => null]])->assertRedirect();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($cover, 'chat.family.assigned'))->toBeTrue();
});

it('rejects missing and stale contact actors before reads or writes even when both accounts have care team authority', function () {
    [$client, $conversation, $relative, $other, $worker, $cover] = sharedChatCallbackFixture();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id);
    $callback = $this->postJson(route('chat.callbacks.store', $conversation), ['mutation_id' => (string) Str::uuid(), 'preferred_time' => 'After lunch'])->assertCreated()->json('id');
    $reads = [route('chat.callbacks.index', $conversation), route('chat.people.options', $conversation), route('chat.people.history', $conversation), route('chat.invitations.history', $conversation), route('chat.invitations', ['client' => $client->id])];
    $posts = [route('chat.create'), route('chat.callbacks.store', $conversation), route('chat.people.store', $conversation)];
    $patches = [route('chat.callbacks.update', $callback), route('chat.management', $conversation)];
    $this->actingAs($cover);
    foreach ([null, (string) $worker->id] as $actor) {
        $this->withoutHeader('X-Chat-Actor');
        if ($actor !== null) {
            $this->withHeader('X-Chat-Actor', $actor);
        }
        foreach ($reads as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        }
        foreach ($posts as $url) {
            $this->postJson($url, [])->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        }
        foreach ($patches as $url) {
            $this->patchJson($url, [])->assertForbidden()->assertJsonPath('code', 'chat_actor_changed');
        }
    }
    $this->withHeader('X-Chat-Actor', (string) $cover->id)->getJson(route('chat.callbacks.index', $conversation))->assertOk();
    $this->getJson(route('chat.people.history', $conversation))->assertOk()->assertJsonPath('conversation.id', $conversation->id);
    expect(OpsConversation::count())->toBe(1)->and(OpsMessage::count())->toBe(0);
    expect(DB::table('chat_callbacks')->count())->toBe(1);
});
