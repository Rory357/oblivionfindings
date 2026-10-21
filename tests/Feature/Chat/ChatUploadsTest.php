<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DeliverChatMessageNotifications;
use App\Models\AppSetting;
use App\Models\ChatUpload;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\ChatUploads;
use App\Services\Chat\ChatVoiceInspection;
use App\Services\Chat\ChatVoiceInspector;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    Storage::fake('local');
    Bus::fake([DeliverChatMessageNotifications::class]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => []]);
    $site = Site::factory()->create();
    $people = collect(range(1, 3))->map(function () use ($site) {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $user;
    });
    [$this->sender, $this->reader, $this->outside] = $people->all();
    $this->conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$this->sender, $this->reader] as $user) {
        $this->conversation->participants()->create(['user_id' => $user->id, 'role' => 'member']);
    }
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanner->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'))->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    $this->actingAs($this->sender)->withHeader('X-Chat-Actor', (string) $this->sender->id);
});

it('stages checked bytes privately and resolves an upload retry without rescanning or publishing', function () {
    $id = (string) Str::uuid();
    $file = UploadedFile::fake()->createWithContent('visit.txt', 'Fictional visit plan');
    $this->scanner->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'));
    $url = route('chat.uploads.store', [$this->conversation, $id]);
    $first = $this->postJson($url, ['file' => $file])->assertOk()->assertJsonPath('state', 'ready');
    expect($first->headers->get('Cache-Control'))->toContain('no-store');
    expect(array_keys($first->json()))->not->toContain('storage_path', 'sha256', 'actor_id');
    $this->postJson($url, ['file' => $file])->assertOk()->assertJsonPath('id', $id);
    $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Different bytes')])->assertConflict();
    $this->getJson(route('chat.uploads.index', $this->conversation))->assertOk()->assertJsonCount(1, 'uploads');
    expect($this->conversation->messages()->count())->toBe(0);
    expect(DB::table('chat_attachments')->count())->toBe(0);
    $this->actingAs($this->reader)->withHeader('X-Chat-Actor', (string) $this->reader->id)->getJson(route('chat.uploads.show', [$this->conversation, $id]))->assertForbidden();
    $this->getJson(route('chat.uploads.index', $this->conversation))->assertOk()->assertJsonCount(0, 'uploads');
    $this->actingAs($this->outside)->withHeader('X-Chat-Actor', (string) $this->outside->id)->getJson(route('chat.uploads.show', [$this->conversation, $id]))->assertForbidden();
});

it('atomically consumes ready uploads with captions and resolves exact message retries', function () {
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional plan')])->assertOk()->assertJsonPath('state', 'ready');
    $payload = ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id], 'captions' => ['0']];
    $temporary = ChatUpload::findOrFail($id)->storage_path;
    $result = $this->postJson(route('chat.send', $this->conversation), $payload)->assertCreated()->assertJsonPath('message.attachments.0.caption', '0');
    $messageId = $result->json('message.id');
    $this->postJson(route('chat.send', $this->conversation), $payload)->assertCreated()->assertJsonPath('message.id', $messageId);
    $this->postJson(route('chat.send', $this->conversation), [...$payload, 'mutation_id' => (string) Str::uuid()])->assertConflict();
    expect($this->conversation->messages()->count())->toBe(1);
    expect(DB::table('chat_attachments')->count())->toBe(1);
    $this->getJson(route('chat.uploads.show', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'consumed')->assertJsonPath('message_id', $messageId)->assertJsonPath('name', null);
    $this->deleteJson(route('chat.uploads.destroy', [$this->conversation, $id]))->assertConflict();
    expect(ChatUpload::findOrFail($id)->state)->toBe('consumed');
    expect(ChatUpload::findOrFail($id)->storage_path)->toBeNull();
    expect(Storage::disk('local')->exists($temporary))->toBeFalse();
    expect(Storage::disk('local')->exists(DB::table('chat_attachments')->value('storage_path')))->toBeTrue();
});

it('requires an audio inspection before voice staging can be sent and checks stored bytes before playback', function () {
    $probe = Mockery::mock(ChatVoiceInspector::class);
    $probe->shouldReceive('configured')->andReturn(true);
    $probe->shouldReceive('inspect')->once()->andReturn(new ChatVoiceInspection('ready', 'audio/ogg', 1200));
    app()->instance(ChatVoiceInspector::class, $probe);
    $id = (string) Str::uuid();
    // Recognisable Ogg container header; media inspection is explicitly faked in this transport test.
    $bytes = "OggS\0\2".str_repeat("\0", 20)."\1\23OpusHead".str_repeat("\0", 11);
    $file = UploadedFile::fake()->createWithContent('voice.ogg', $bytes);
    $url = route('chat.uploads.store', [$this->conversation, $id]);
    $this->postJson($url, ['file' => $file, 'media_kind' => 'voice_note', 'duration_ms' => 1])->assertOk()->assertJsonPath('state', 'ready')->assertJsonPath('duration_ms', 1200);
    $this->postJson($url, ['file' => $file, 'media_kind' => 'file'])->assertUnprocessable();
    $result = $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id]])->assertCreated()->assertJsonPath('message.attachments.0.media_kind', 'voice_note')->assertJsonPath('message.attachments.0.duration_ms', 1200);
    $attachment = DB::table('chat_attachments')->find($result->json('message.attachments.0.id'));
    $this->actingAs($this->reader)->withHeader('X-Chat-Actor', (string) $this->reader->id)->get(route('chat.attachment.preview', $attachment->id))->assertOk()->assertHeader('Content-Type', 'audio/ogg');
    $this->getJson(route('chat.attachment.metadata', $attachment->id))->assertOk()->assertJsonPath('can_publish', false);
    $this->actingAs($this->outside)->withHeader('X-Chat-Actor', (string) $this->outside->id)->get(route('chat.attachment.preview', $attachment->id))->assertForbidden();
    Storage::disk('local')->put($attachment->storage_path, 'Changed voice bytes');
    $this->actingAs($this->reader)->withHeader('X-Chat-Actor', (string) $this->reader->id)->get(route('chat.attachment.preview', $attachment->id))->assertStatus(415);
    $this->get(route('chat.attachment', $attachment->id))->assertStatus(415);
});

it('retains voice files privately when inspection is unavailable and respects disabled voice policy on retry and send', function () {
    $id = (string) Str::uuid();
    $file = UploadedFile::fake()->createWithContent('voice.ogg', "OggS\0\2".str_repeat("\0", 20)."\1\23OpusHead".str_repeat("\0", 11));
    config(['chat.files.voice_probe_binary' => '']);
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => $file, 'media_kind' => 'voice_note'])->assertOk()->assertJsonPath('state', 'unavailable')->assertJsonPath('error_code', 'voice_check_unavailable');
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id]])->assertConflict();
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['voice_notes_enabled' => false])]);
    $this->postJson(route('chat.uploads.retry', [$this->conversation, $id]))->assertForbidden();
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id]])->assertForbidden();
    $this->deleteJson(route('chat.uploads.destroy', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'discarded');
    expect($this->conversation->messages()->count())->toBe(0);
});

it('keeps unavailable scans private for retry and permits deliberate idempotent removal', function () {
    config(['chat.files.scanner' => []]);
    app()->instance(MalwareScanner::class, new MalwareScanner);
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional plan')])->assertOk()->assertJsonPath('state', 'unavailable')->assertJsonPath('retry_available', true);
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id]])->assertConflict();
    expect($this->conversation->messages()->count())->toBe(0);
    app()->instance(MalwareScanner::class, $this->scanner);
    $this->postJson(route('chat.uploads.retry', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'ready');
    $path = ChatUpload::findOrFail($id)->storage_path;
    AppSetting::where('key', ChatSettings::KEY)->update(['value' => json_encode(['enabled' => false])]);
    $this->deleteJson(route('chat.uploads.destroy', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'discarded');
    $this->deleteJson(route('chat.uploads.destroy', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'discarded');
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('does not revive an upload removed while its scan was still running', function () {
    $id = (string) Str::uuid();
    $this->scanner->shouldReceive('scanPath')->once()->andReturnUsing(function () use ($id) {
        app(ChatUploads::class)->discard($this->conversation, $this->sender, $id);

        return new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner');
    });
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional plan')])->assertOk()->assertJsonPath('state', 'discarded');
    expect(Storage::disk('local')->allFiles('chat-quarantine'))->toBe([]);
    $this->postJson(route('chat.uploads.retry', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'discarded');
});

it('retains a removal receipt before an interrupted upload reaches the server', function () {
    $id = (string) Str::uuid();
    $this->deleteJson(route('chat.uploads.destroy', [$this->conversation, $id]))->assertOk()->assertJsonPath('state', 'discarded');
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('late.txt', 'Late bytes')])->assertOk()->assertJsonPath('state', 'discarded');
    expect(Storage::disk('local')->allFiles('chat-quarantine'))->toBe([]);
    expect(ChatUpload::whereKey($id)->count())->toBe(1);
});

it('rejects changed private bytes and uploads belonging to a previous membership', function () {
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional plan')])->assertOk();
    $upload = ChatUpload::findOrFail($id);
    Storage::disk('local')->put($upload->storage_path, 'Replaced after scan');
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id]])->assertUnprocessable();
    expect($this->conversation->messages()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('chat'))->toBe([]);
    $this->conversation->participants()->where('user_id', $this->sender->id)->delete();
    $this->conversation->participants()->create(['user_id' => $this->sender->id, 'role' => 'member', 'history_after_id' => 0]);
    $this->getJson(route('chat.uploads.show', [$this->conversation, $id]))->assertForbidden();
    $this->getJson(route('chat.uploads.index', $this->conversation))->assertOk()->assertJsonCount(0, 'uploads');
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id]])->assertForbidden();
});

it('rolls ready-file consumption back when another selected upload is unavailable', function () {
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional plan')])->assertOk();
    $source = ChatUpload::findOrFail($id)->storage_path;
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'upload_ids' => [$id, (string) Str::uuid()]])->assertNotFound();
    expect($this->conversation->messages()->count())->toBe(0);
    expect(DB::table('chat_attachments')->count())->toBe(0);
    expect(ChatUpload::findOrFail($id)->state)->toBe('ready');
    expect(Storage::disk('local')->allFiles('chat'))->toBe([]);
    expect(Storage::disk('local')->exists($source))->toBeTrue();
    Bus::assertNotDispatched(DeliverChatMessageNotifications::class);
});

it('keeps private family staging tied to the same canonical client and invited relative', function () {
    $client = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $relative = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $relative->roles()->attach($role->id);
    foreach ([$client, $otherClient] as $record) {
        $record->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);
    }
    $family = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id]);
    $family->participants()->create(['user_id' => $relative->id, 'role' => 'family']);
    $id = (string) Str::uuid();
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->postJson(route('chat.uploads.store', [$family, $id]), ['file' => UploadedFile::fake()->createWithContent('family.txt', 'Private family file')])->assertOk()->assertJsonPath('state', 'ready');
    $this->actingAs($this->sender)->withHeader('X-Chat-Actor', (string) $this->sender->id)->getJson(route('chat.uploads.show', [$family, $id]))->assertForbidden();
    $family->update(['client_id' => $otherClient->id]);
    $this->actingAs($relative)->withHeader('X-Chat-Actor', (string) $relative->id)->getJson(route('chat.uploads.show', [$family, $id]))->assertForbidden();
    $this->getJson(route('chat.uploads.index', $family))->assertOk()->assertJsonCount(0, 'uploads');
});

it('serves a restored private upload only to its current author while its checked bytes are still available', function () {
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Fictional private attachment')])->assertOk()->assertJsonPath('mime_type', 'text/plain');
    $download = route('chat.uploads.download', [$this->conversation, $id]);
    $this->get($download)->assertOk()->assertDownload('visit.txt')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->getJson(route('chat.uploads.preview', [$this->conversation, $id]))->assertStatus(415);
    $this->actingAs($this->reader)->withHeader('X-Chat-Actor', (string) $this->reader->id)->get($download)->assertForbidden();
    $this->actingAs($this->sender)->withHeader('X-Chat-Actor', (string) $this->sender->id);
    $upload = ChatUpload::findOrFail($id);
    Storage::disk('local')->put($upload->storage_path, 'Changed bytes');
    $this->get($download)->assertStatus(422);
    $this->deleteJson(route('chat.uploads.destroy', [$this->conversation, $id]))->assertOk();
    $this->get($download)->assertConflict();
});

it('previews a restored private upload through the protected renderer and closes access after sending or membership change', function () {
    $id = (string) Str::uuid();
    $this->postJson(route('chat.uploads.store', [$this->conversation, $id]), ['file' => UploadedFile::fake()->image('visit.png', 8, 8)])->assertOk();
    $preview = route('chat.uploads.preview', [$this->conversation, $id]);
    $response = $this->get($preview)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
    $this->conversation->participants()->where('user_id', $this->sender->id)->update(['history_after_id' => 999]);
    $this->get($preview)->assertForbidden();
    $this->conversation->participants()->where('user_id', $this->sender->id)->update(['history_after_id' => null]);
    $this->postJson(route('chat.send', $this->conversation), ['mutation_id' => (string) Str::uuid(), 'content' => '', 'upload_ids' => [$id], 'captions' => ['Visit']])->assertCreated();
    $this->get($preview)->assertConflict();
    $this->getJson(route('chat.uploads.show', [$this->conversation, $id]))->assertJsonPath('state', 'consumed')->assertJsonPath('mime_type', null);
});
