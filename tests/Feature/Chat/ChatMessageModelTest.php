<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DeliverChatMessageNotifications;
use App\Models\AppSetting;
use App\Models\ChatCall;
use App\Models\ChatLinkPreview;
use App\Models\ChatMessageCard;
use App\Models\ChatMessageConfirmation;
use App\Models\ChatMessageLink;
use App\Models\ChatPoll;
use App\Models\ChatPollOption;
use App\Models\ChatPollVote;
use App\Models\ChatScheduledMessage;
use App\Models\ChatSticker;
use App\Models\ChatThreadState;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Support\SecurityPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    Bus::fake([DeliverChatMessageNotifications::class]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatMessageModelStaff(Site $site, string $name): User
{
    $user = User::factory()->create(['name' => $name, 'approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

/** A direct conversation between Hemi (the sender) and Ana Rēweti. */
function chatMessageModelFixture(): array
{
    $site = Site::factory()->create();
    [$sender, $reader] = [chatMessageModelStaff($site, 'Hemi Walker'), chatMessageModelStaff($site, 'Ana Rēweti')];
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$sender, $reader] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }

    return [$conversation, $sender, $reader, $site];
}

function chatMessageModelText(string $text, array $marks = []): array
{
    return ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

function chatMessageModelParagraph(array ...$content): array
{
    return ['type' => 'paragraph', 'content' => $content];
}

function chatMessageModelMention(User $person, ?string $label = null): array
{
    return ['type' => 'mention', 'user_id' => $person->id, 'label' => $label ?? $person->name];
}

function chatMessageModelSender(User $sender)
{
    return test()->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id);
}

it('adds the message model columns and tables, empty or at their defaults for existing messages', function () {
    expect(Schema::hasColumns('ops_messages', ['body_document', 'thread_root_id', 'priority', 'confirm_requested', 'shared_from_message_id',
        'call_id', 'sticker_id', 'system_event', 'system_params', 'scheduled_message_id']))->toBeTrue();
    foreach ([
        'chat_thread_states' => ['root_message_id', 'user_id', 'following', 'muted', 'last_read_message_id'],
        'chat_message_confirmations' => ['message_id', 'user_id', 'confirmed_at'],
        'chat_polls' => ['message_id', 'question', 'allows_multiple', 'anonymous', 'closes_at', 'closed_at', 'closed_by'],
        'chat_poll_options' => ['poll_id', 'position', 'label'],
        'chat_poll_votes' => ['poll_id', 'option_id', 'user_id'],
        'chat_stickers' => ['set_key', 'name', 'storage_path', 'mime_type', 'sha256', 'width', 'height', 'created_by', 'archived_at'],
        'chat_scheduled_messages' => ['id', 'conversation_id', 'sender_id', 'mutation_id', 'encrypted_payload', 'send_at', 'at_next_shift', 'state', 'message_id', 'error_code', 'created_at', 'updated_at'],
        'chat_link_previews' => ['url_sha256', 'url', 'final_url', 'site_name', 'title', 'description', 'image_path', 'status', 'error_code', 'fetched_at', 'expires_at'],
        'chat_message_links' => ['message_id', 'link_preview_id', 'position', 'hidden'],
        'chat_message_cards' => ['message_id', 'card_type', 'record_id', 'position'],
    ] as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue()->and(Schema::hasColumns($table, $columns))->toBeTrue();
    }

    [$conversation, $sender] = chatMessageModelFixture();
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'An existing message'])->fresh();

    expect($message->body_document)->toBeNull()
        ->and($message->priority)->toBe('normal')
        ->and($message->confirm_requested)->toBeFalse()
        ->and($message->thread_root_id)->toBeNull()
        ->and($message->shared_from_message_id)->toBeNull()
        ->and($message->call_id)->toBeNull()
        ->and($message->sticker_id)->toBeNull()
        ->and($message->system_event)->toBeNull()
        ->and($message->system_params)->toBeNull()
        ->and($message->scheduled_message_id)->toBeNull();
});

it('links threads, polls, stickers, calls, cards, previews and scheduled sends to messages and removes them with the message', function () {
    [$conversation, $sender, $reader] = chatMessageModelFixture();
    $root = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Saturday cover']);
    $sticker = ChatSticker::create(['set_key' => 'oblivion', 'name' => 'Ka pai', 'storage_path' => 'chat/stickers/ka-pai.webp',
        'mime_type' => 'image/webp', 'sha256' => str_repeat('a', 64), 'width' => 160, 'height' => 160]);
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $sender->id,
        'host_id' => $sender->id, 'mutation_id' => (string) Str::uuid(), 'ring_until' => now()->addMinute()]);
    $scheduled = ChatScheduledMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $sender->id,
        'mutation_id' => (string) Str::uuid(), 'encrypted_payload' => ['content' => 'Morning handover'], 'at_next_shift' => true]);
    $reply = $conversation->messages()->create(['sender_id' => $reader->id, 'sender_type' => 'user', 'content' => 'I can',
        'thread_root_id' => $root->id, 'priority' => 'urgent', 'confirm_requested' => true]);
    $reply->forceFill(['shared_from_message_id' => $root->id, 'sticker_id' => $sticker->id, 'call_id' => $call->id,
        'scheduled_message_id' => $scheduled->id, 'system_event' => 'member_added', 'system_params' => ['actor_id' => $sender->id, 'user_ids' => [$reader->id]]])->save();
    $later = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Thanks', 'thread_root_id' => $root->id]);
    ChatThreadState::create(['root_message_id' => $root->id, 'user_id' => $reader->id, 'following' => true, 'last_read_message_id' => $reply->id]);
    ChatMessageConfirmation::create(['message_id' => $reply->id, 'user_id' => $sender->id, 'confirmed_at' => now()]);
    $poll = ChatPoll::create(['message_id' => $reply->id, 'question' => 'Who can cover Saturday?']);
    $options = collect(['Me', 'Not me'])->map(fn (string $label, int $position) => $poll->options()->create(['position' => $position, 'label' => $label]));
    ChatPollVote::create(['poll_id' => $poll->id, 'option_id' => $options[0]->id, 'user_id' => $sender->id]);
    $preview = ChatLinkPreview::create(['url_sha256' => hash('sha256', 'https://example.org'), 'url' => 'https://example.org']);
    ChatMessageLink::create(['message_id' => $reply->id, 'link_preview_id' => $preview->id, 'position' => 0]);
    ChatMessageCard::create(['message_id' => $reply->id, 'card_type' => 'client', 'record_id' => '12', 'position' => 0]);

    $reply = $reply->fresh();
    expect($reply->threadRoot->is($root))->toBeTrue()
        ->and($reply->sharedFrom->is($root))->toBeTrue()
        ->and($reply->sticker->is($sticker))->toBeTrue()
        ->and($reply->call->is($call))->toBeTrue()
        ->and($reply->scheduledMessage->is($scheduled))->toBeTrue()
        ->and($reply->priority)->toBe('urgent')
        ->and($reply->confirm_requested)->toBeTrue()
        ->and($reply->system_params)->toEqual(['actor_id' => $sender->id, 'user_ids' => [$reader->id]])
        ->and($reply->poll->options->pluck('label')->all())->toBe(['Me', 'Not me'])
        ->and($reply->poll->votes->sole()->option->is($options[0]))->toBeTrue()
        ->and($reply->confirmations->sole()->user->is($sender))->toBeTrue()
        ->and($reply->cards->sole()->only(['card_type', 'record_id']))->toBe(['card_type' => 'client', 'record_id' => '12'])
        ->and($reply->links->sole()->preview->is($preview))->toBeTrue()
        ->and($reply->links->sole()->hidden)->toBeFalse()
        ->and($preview->fresh()->status)->toBe('pending')
        ->and(ChatThreadState::sole()->lastReadMessage->is($reply))->toBeTrue();
    // The scheduled message is encrypted at rest and never serialised.
    expect($scheduled->fresh()->encrypted_payload)->toBe(['content' => 'Morning handover'])
        ->and(DB::table('chat_scheduled_messages')->value('encrypted_payload'))->not->toContain('Morning handover')
        ->and($scheduled->fresh()->toArray())->not->toHaveKey('encrypted_payload')
        ->and($scheduled->fresh()->state)->toBe('waiting');
    expect(fn () => ChatThreadState::create(['root_message_id' => $root->id, 'user_id' => $reader->id]))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => ChatPollVote::create(['poll_id' => $poll->id, 'option_id' => $options[0]->id, 'user_id' => $sender->id]))->toThrow(UniqueConstraintViolationException::class);

    // What belongs to a message goes with it; the shared link cache stays.
    $reply->forceDelete();
    expect(ChatMessageConfirmation::count())->toBe(0)
        ->and(ChatPoll::count() + ChatPollOption::count() + ChatPollVote::count())->toBe(0)
        ->and(ChatMessageLink::count() + ChatMessageCard::count())->toBe(0)
        ->and(ChatLinkPreview::count())->toBe(1)
        ->and(ChatThreadState::sole()->last_read_message_id)->toBeNull()
        ->and(ChatScheduledMessage::count())->toBe(1);
    // Removing a thread root clears the thread on its replies and removes everyone's thread state.
    $root->forceDelete();
    expect($later->fresh()->thread_root_id)->toBeNull()->and(ChatThreadState::count())->toBe(0);
});

it('stores a formatted message and writes its plain text and mentions from the document', function () {
    [$conversation, $sender, $reader] = chatMessageModelFixture();
    $document = ['v' => 1, 'blocks' => [
        chatMessageModelParagraph(
            chatMessageModelText('Ring '),
            chatMessageModelMention($reader),
            chatMessageModelText(' before ', ['bold']),
            ['type' => 'link', 'href' => 'https://example.org/x', 'content' => [chatMessageModelText('the form')]],
        ),
        ['type' => 'list', 'ordered' => false, 'items' => [[chatMessageModelParagraph(chatMessageModelText('Kai at 12 for Māmā'))]]],
        ['type' => 'quote', 'blocks' => [chatMessageModelParagraph(chatMessageModelText('Kia ora', ['italic']))]],
        ['type' => 'code', 'text' => "Room 4\nBed 2"],
    ]];
    $plain = "Ring @Ana Rēweti before the form\n• Kai at 12 for Māmā\n> Kia ora\nRoom 4\nBed 2";

    $sent = chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), [
        'mutation_id' => (string) Str::uuid(), 'document' => $document,
        // Ignored: the server writes content and mentions from the document.
        'content' => 'Something else entirely', 'mentions' => [['user_id' => $reader->id, 'start' => 0, 'length' => 9]],
    ])->assertCreated()
        ->assertJsonPath('message.content', $plain)
        ->assertJsonPath('message.kind', 'text')
        ->assertJsonPath('message.document', $document)
        ->assertJsonPath('message.mentions.0.user_id', $reader->id)
        ->assertJsonPath('message.mentions.0.start', 5)
        ->assertJsonPath('message.mentions.0.length', 11)
        ->assertJsonPath('message.mentions.0.label', '@Ana Rēweti')
        ->assertJsonCount(1, 'message.mentions');

    $message = OpsMessage::findOrFail($sent->json('message.id'));
    expect($message->content)->toBe($plain)
        ->and($message->body_document)->toEqual($document)
        ->and($message->message_type)->toBe('text')
        ->and(DB::table('chat_message_mentions')->where('message_id', $message->id)->get(['user_id', 'start', 'length', 'label'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['user_id' => $reader->id, 'start' => 5, 'length' => 11, 'label' => '@Ana Rēweti']]);
    $read = chatMessageModelSender($reader)->getJson(route('chat.messages', $conversation))->assertOk();
    expect($read->json('messages.0.document'))->toEqual($document)
        ->and($read->json('messages.0.kind'))->toBe('text')
        ->and($read->json('messages.0.content'))->toBe($plain);

    // Leading blank lines are trimmed from content and the mention offsets move with them.
    chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(),
        'document' => ['v' => 1, 'blocks' => [chatMessageModelParagraph(), chatMessageModelParagraph(chatMessageModelMention($reader), chatMessageModelText(' ka pai'))]]])
        ->assertCreated()->assertJsonPath('message.content', '@Ana Rēweti ka pai')->assertJsonPath('message.mentions.0.start', 0);
    // A form post carries the document as a JSON string.
    chatMessageModelSender($sender)->post(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(),
        'document' => json_encode(['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText('Kia ora', ['bold']))]])], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('message.content', 'Kia ora')->assertJsonPath('message.document.blocks.0.content.0.marks', ['bold']);
});

it('accepts a formatted message with files, carrying the document as a JSON string in the multipart send', function () {
    Storage::fake('local');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner'));
    app()->instance(MalwareScanner::class, $scanner);
    [$conversation, $sender] = chatMessageModelFixture();
    $document = ['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText('See the '), chatMessageModelText('visit notes', ['underline']))]];

    chatMessageModelSender($sender)->post(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'document' => json_encode($document),
        'files' => [UploadedFile::fake()->createWithContent('visit.txt', 'Fictional visit')]], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('message.kind', 'text')
        ->assertJsonPath('message.content', 'See the visit notes')
        ->assertJsonPath('message.document', $document)
        ->assertJsonCount(1, 'message.attachments');
    expect(OpsMessage::sole()->message_type)->toBe('attachment');
});

it('refuses an invalid document with a 422 and stores nothing', function (mixed $document) {
    [$conversation, $sender] = chatMessageModelFixture();

    chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'document' => $document, 'content' => 'A plain copy'])
        ->assertUnprocessable()->assertJsonValidationErrors('document');
    expect(OpsMessage::withTrashed()->count())->toBe(0)
        ->and(DB::table('chat_message_mentions')->count())->toBe(0)
        ->and(DB::table('chat_message_changes')->count())->toBe(0);
})->with([
    'script link' => [['v' => 1, 'blocks' => [chatMessageModelParagraph(['type' => 'link', 'href' => 'javascript:alert(1)', 'content' => [chatMessageModelText('Open')]])]]],
    'html block' => [['v' => 1, 'blocks' => [['type' => 'html', 'html' => '<img src=x onerror=alert(1)>']]]],
    'extra key' => [['v' => 1, 'blocks' => [[...chatMessageModelParagraph(chatMessageModelText('Hi')), 'style' => 'color:red']]]],
    'unknown mark' => [['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText('Hi', ['colour']))]]],
    'control character' => [['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText("Hi\u{0007}"))]]],
    'too long' => [['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText(str_repeat('ā', 10001)))]]],
    'unsupported version' => [['v' => 2, 'blocks' => []]],
    'not JSON' => ['{"v": 1, "blocks": ['],
    'HTML string' => ['<p>Hi</p>'],
]);

it('refuses a document that mentions someone outside the conversation or by an old name, and one with no text', function () {
    [$conversation, $sender, $reader, $site] = chatMessageModelFixture();
    $outsider = chatMessageModelStaff($site, 'Kiri Tūhoe');

    foreach ([chatMessageModelMention($outsider), chatMessageModelMention($reader, 'Ana')] as $mention) {
        chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(),
            'document' => ['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText('Ring '), $mention)]]])
            ->assertUnprocessable()->assertJsonValidationErrors('mentions');
    }
    chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Ignored', 'document' => ['v' => 1, 'blocks' => []]])
        ->assertUnprocessable()->assertJsonPath('message', 'Write a message or choose a file.');
    expect(OpsMessage::count())->toBe(0)->and(DB::table('chat_message_mentions')->count())->toBe(0);
});

it('keeps the earlier document in the encrypted revision when a formatted message is edited', function () {
    [$conversation, $sender, $reader] = chatMessageModelFixture();
    $first = ['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText('Ring '), chatMessageModelMention($reader))]];
    $second = ['v' => 1, 'blocks' => [
        chatMessageModelParagraph(chatMessageModelText('Updated', ['bold']), chatMessageModelText(': ring')),
        ['type' => 'list', 'ordered' => true, 'items' => [[chatMessageModelParagraph(chatMessageModelMention($reader), chatMessageModelText(' after lunch'))]]],
    ]];
    $id = chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'document' => $first])
        ->assertCreated()->json('message.id');
    $message = OpsMessage::findOrFail($id);
    $edit = ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'document' => $second,
        // Ignored: the server writes content and mentions from the document.
        'content' => 'Something else', 'mentions' => [['user_id' => $reader->id, 'start' => 0, 'length' => 9]]];

    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), $edit)->assertOk()
        ->assertJsonPath('message.edit_version', 1)
        ->assertJsonPath('message.content', "Updated: ring\n1. @Ana Rēweti after lunch")
        ->assertJsonPath('message.document', $second)
        ->assertJsonPath('message.mentions.0.start', 17)
        ->assertJsonPath('message.mentions.0.length', 11);
    $revision = DB::table('chat_message_revisions')->sole();
    $state = json_decode(Crypt::decryptString($revision->previous_state), true);
    expect($revision->previous_state)->not->toContain('Ring')
        ->and($state['content'])->toBe('Ring @Ana Rēweti')
        ->and($state['document'])->toEqual($first)
        ->and($state['mentions'][0]['label'])->toBe('@Ana Rēweti')
        ->and($message->fresh()->body_document)->toEqual($second);

    // An exact retry resolves without another revision; the same reference with another document is a conflict.
    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), $edit)->assertOk()->assertJsonPath('message.edit_version', 1);
    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), [...$edit, 'document' => $first])->assertConflict();
    expect(DB::table('chat_message_revisions')->count())->toBe(1);

    // An invalid document changes nothing.
    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), ['mutation_id' => (string) Str::uuid(), 'version' => 1,
        'document' => ['v' => 1, 'blocks' => [chatMessageModelParagraph(['type' => 'link', 'href' => '//evil.example', 'content' => [chatMessageModelText('Open')]])]]])
        ->assertUnprocessable()->assertJsonValidationErrors('document');
    expect(DB::table('chat_message_revisions')->count())->toBe(1);

    // A plain edit clears the document, and its revision keeps the one it replaced.
    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'content' => 'Plain again', 'mentions' => []])
        ->assertOk()->assertJsonPath('message.content', 'Plain again')->assertJsonPath('message.document', null);
    $latest = json_decode(Crypt::decryptString(DB::table('chat_message_revisions')->where('version', 2)->value('previous_state')), true);
    expect($message->fresh()->body_document)->toBeNull()
        ->and($latest['document'])->toEqual($second)
        ->and($latest['content'])->toBe("Updated: ring\n1. @Ana Rēweti after lunch");
});

it('projects each message kind and its document, with files shown as text', function () {
    [$conversation, $sender, $reader] = chatMessageModelFixture();
    $document = ['v' => 1, 'blocks' => [chatMessageModelParagraph(chatMessageModelText('Kia ora', ['bold']))]];
    foreach (['text', 'attachment', 'card', 'poll', 'sticker', 'call', 'system', 'image'] as $type) {
        $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'message_type' => $type, 'content' => ucfirst($type)]);
    }
    $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Kia ora', 'body_document' => $document]);
    $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Removed', 'body_document' => $document])->delete();

    $messages = collect(chatMessageModelSender($reader)->getJson(route('chat.messages', $conversation))->assertOk()->json('messages'));

    // The old 'attachment' type and any older one read as text.
    expect($messages->pluck('kind')->all())->toBe(['text', 'text', 'card', 'poll', 'sticker', 'call', 'system', 'text', 'text', 'text'])
        ->and($messages->take(8)->pluck('document')->filter()->all())->toBe([])
        ->and($messages[8]['document'])->toEqual($document)
        ->and($messages[9]['deleted'])->toBeTrue()
        ->and($messages[9]['document'])->toBeNull()
        ->and($messages[9]['content'])->toBe('');
    // Existing keys are all still there.
    expect(array_keys($messages[0]))->toContain('content', 'mentions', 'attachments', 'legacy_attachments', 'reactions', 'reply', 'edit_version');
});

it('sends and edits a plain message exactly as before', function () {
    [$conversation, $sender, $reader] = chatMessageModelFixture();
    $payload = ['mutation_id' => (string) Str::uuid(), 'content' => '  🙂 @Ana Rēweti check this', 'mentions' => [['user_id' => $reader->id, 'start' => 4, 'length' => 11]]];

    $sent = chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), $payload)->assertCreated()
        ->assertJsonPath('message.content', '🙂 @Ana Rēweti check this')
        ->assertJsonPath('message.mentions.0.start', 2)
        ->assertJsonPath('message.mentions.0.length', 11)
        ->assertJsonPath('message.mentions.0.label', '@Ana Rēweti')
        ->assertJsonPath('message.kind', 'text')
        ->assertJsonPath('message.document', null);
    $message = OpsMessage::sole();
    expect($message->body_document)->toBeNull()
        ->and($message->fresh()->priority)->toBe('normal')
        ->and($message->fresh()->confirm_requested)->toBeFalse()
        ->and($message->thread_root_id)->toBeNull()
        ->and($message->message_type)->toBe('text');
    chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), $payload)->assertCreated()->assertJsonPath('message.id', $sent->json('message.id'));
    chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'No mention here',
        'mentions' => [['user_id' => $reader->id, 'start' => 0, 'length' => 11]]])->assertUnprocessable()->assertJsonValidationErrors('mentions');
    chatMessageModelSender($sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => '   '])
        ->assertUnprocessable()->assertJsonPath('message', 'Write a message or choose a file.');
    expect(OpsMessage::count())->toBe(1);

    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Corrected', 'mentions' => []])
        ->assertOk()->assertJsonPath('message.content', 'Corrected')->assertJsonPath('message.document', null);
    chatMessageModelSender($sender)->patchJson(route('chat.messages.edit', $message), ['mutation_id' => (string) Str::uuid(), 'version' => 1, 'content' => 'Missing mentions'])
        ->assertUnprocessable()->assertJsonValidationErrors('mentions');
    $state = json_decode(Crypt::decryptString(DB::table('chat_message_revisions')->value('previous_state')), true);
    expect($state['content'])->toBe('🙂 @Ana Rēweti check this')->and($state['document'])->toBeNull();
});
