<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverChatMessageNotifications;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\OpsMessageReaction;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatAudience;
use App\Services\Chat\ChatFamilyContacts;
use App\Services\Chat\ChatFamilyResponse;
use App\Services\Chat\ChatFileSafety;
use App\Services\Chat\ChatMentions;
use App\Services\Chat\ChatMessageChanges;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatMessageEditing;
use App\Services\Chat\ChatPrivateDrafts;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatReactionEmoji;
use App\Services\Chat\ChatRealtime;
use App\Services\Chat\ChatSendAttempts;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\ChatUploads;
use App\Services\Chat\ChatVoiceInspector;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Clients\ClientPortalMembershipService;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly ChatProjection $projection,
        private readonly ChatSettings $settings,
        private readonly OpsMessageVisibilityService $visibility,
        private readonly ClientFamilyCommunicationAccess $family,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $client = $request->integer('client') ? Client::findOrFail($request->integer('client')) : null;
        if ($client) {
            abort_unless($user->canAccessClientPortal($client) || $this->family->canView($user, $client), 403);
        } else {
            abort_unless($this->visibility->isCurrentStaff($user), 403);
        }

        return inertia('chat/index', [
            'chatClient' => $client ? ['id' => $client->id, 'name' => $client->first_name.' '.$client->last_name] : null,
            'chatFamily' => $client && $user->canAccessClientPortal($client),
            'initialConversation' => $request->integer('conversation') ?: null,
            'initialMessage' => $request->integer('message') ?: null,
            'initialCallHistory' => $request->query('calls') === 'missed' ? 'missed' : null,
        ]);
    }

    public function conversations(Request $request)
    {
        $user = $request->user();
        $client = $request->integer('client') ? Client::findOrFail($request->integer('client')) : null;
        $staff = $this->visibility->isCurrentStaff($user);
        abort_unless($staff || ($client && $user->canAccessClientPortal($client)), 403);
        if ($client) {
            abort_unless($user->canAccessClientPortal($client) || $this->family->canView($user, $client), 403);
        }
        // Cover conversations are narrowed in SQL to clients canView() could accept; allows() stays the final check.
        $conversations = OpsConversation::query()
            ->where(fn ($query) => $query->whereHas('participants', fn ($members) => $members->where('user_id', $user->id))
                ->when($staff, fn ($cover) => $cover->orWhere(fn ($family) => $family->where('conversation_type', 'family')->where('care_team_cover', true)
                    ->whereHas('client', fn ($clients) => $this->family->scopeViewableClients($clients, $user)))))
            ->when($client, fn ($query) => $query->where('client_id', $client->id)->where('conversation_type', 'family'))
            ->with(['client', 'participants.user'])
            ->orderByDesc('updated_at')->get();
        $conversations = $this->access->filterAllowed($user, $conversations)
            ->map(fn ($conversation) => $this->projection->conversation($conversation, $user))->values();
        $people = $staff ? $this->visibility->visibleCurrentStaffQuery($user)->where('users.id', '!=', $user->id)->get(['users.id', 'users.name']) : collect();
        if (! $staff && $client) {
            $people = app(ChatFamilyContacts::class)->namedPeople($client)
                ->map(fn (User $worker) => ['id' => $worker->id, 'name' => $worker->name])->values();
        }

        return response()->json(['conversations' => $conversations, 'people' => $people,
            'can_manage_channels' => $staff && $user->canDo('settings.access.manage'),
            'sites' => $staff && $user->canDo('settings.access.manage') ? Site::whereIn('id', app(UserSiteAccessService::class)->accessibleSiteIds($user))->where('is_active', true)->where('archived', false)->get(['id', 'name']) : [],
            'settings' => collect($this->settings->read($client?->site_id))->only(['enabled', 'family_enabled', 'files_enabled', 'max_file_mb', 'max_files', 'response_expectation']),
        ]);
    }

    public function messages(Request $request, OpsConversation $conversation)
    {
        // History reads never mutate. The transaction's consistent snapshot keeps the page
        // and sync cursor aligned (writers commit a message with its change row), so no
        // conversation lock is taken and reads do not queue behind sends.
        return DB::transaction(function () use ($request, $conversation) {
            $conversation = OpsConversation::whereKey($conversation->id)->firstOrFail();

            return $this->messageSnapshot($request, $conversation);
        });
    }

    public function changes(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate(['cursor' => ['required', 'string', 'max:4096']]);

        return response()->json(app(ChatMessageChanges::class)->read($conversation, $request->user(), $data['cursor']));
    }

    private function messageSnapshot(Request $request, OpsConversation $conversation)
    {
        $this->access->authorize($request->user(), $conversation);
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'around' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:200']]);
        if ($data['around'] ?? null) {
            $target = $this->access->messages($conversation, $request->user(), true)->findOrFail($data['around']);
            $before = $this->access->messages($conversation, $request->user(), true)->where('id', '<', $target->id)->orderByDesc('id')->limit(25)->get()->reverse();
            $after = $this->access->messages($conversation, $request->user(), true)->where('id', '>=', $target->id)->orderBy('id')->limit(26)->get();
            $rows = $before->concat($after)->values();

            return response()->json([
                'sync_cursor' => app(ChatMessageChanges::class)->cursor($conversation, $request->user()),
                'conversation' => $this->projection->conversation($conversation, $request->user()),
                'messages' => $this->projection->messages($rows, $request->user(), $conversation),
                'has_more' => $this->access->messages($conversation, $request->user(), true)->where('id', '<', $rows->first()->id)->exists(),
            ]);
        }
        $rows = $this->access->messages($conversation, $request->user(), true)
            ->when($data['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))
            ->when($data['q'] ?? null, fn ($query, $q) => $query->whereNull('deleted_at')->where('content', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->with(['sender:id,name', 'reactions.user:id,name'])->orderByDesc('id')->limit(51)->get();

        return response()->json([
            'sync_cursor' => app(ChatMessageChanges::class)->cursor($conversation, $request->user()),
            'conversation' => $this->projection->conversation($conversation, $request->user()),
            'messages' => $this->projection->messages($rows->take(50)->reverse()->values(), $request->user(), $conversation),
            'has_more' => $rows->count() > 50,
        ]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'mutation_id' => ['required', 'uuid'],
            'conversation_kind' => ['sometimes', 'in:direct,group,channel,family'],
            'reuse_contact' => ['sometimes', 'boolean'],
            'managed_channel' => ['sometimes', 'boolean'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'participant_ids' => ['present', 'array', 'max:30'], 'participant_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:200'], 'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);
        $user = $request->user();
        $client = isset($data['client_id']) ? Client::findOrFail($data['client_id']) : null;
        $managed = (bool) ($data['managed_channel'] ?? false);
        $selectedIds = collect($data['participant_ids'])->map(fn ($id) => (int) $id)->push($user->id)->unique()->sort()->values();
        $kind = $data['conversation_kind'] ?? ($client ? 'family' : ($managed ? 'channel' : ($selectedIds->count() > 2 ? 'group' : 'direct')));
        abort_unless(($kind === 'family') === (bool) $client && ($kind === 'channel') === $managed, 422, 'Review the conversation type.');
        abort_if(($data['reuse_contact'] ?? false) && ($kind !== 'family' || trim($data['title'] ?? '') !== ''), 422, 'Private contact shortcuts cannot rename or replace a topic conversation.');
        abort_if($kind === 'direct' && $selectedIds->count() !== 2, 422, 'Choose one colleague for a direct chat.');
        abort_if($kind === 'family' && $selectedIds->count() > 2, 422, 'Choose the care team or one named worker. Invite other people after starting the conversation.');
        abort_if($kind === 'group' && trim($data['title'] ?? '') === '', 422, 'Give the private group a name.');
        abort_if($managed && ($client || ! $user->canDo('settings.access.manage')), 403);
        abort_if($managed && trim($data['title'] ?? '') === '', 422, 'Give the managed channel a name.');
        abort_if(! $managed && isset($data['site_id']), 422);
        $settings = $this->settings->read($client?->site_id ?? ($managed ? ($data['site_id'] ?? null) : null));
        abort_unless($settings['enabled'] && (! $client || $settings['family_enabled']), 403, 'Chat is currently unavailable.');
        $create = function () use ($data, $user, $client, $managed, $kind, $selectedIds) {
            $ids = $selectedIds;
            $users = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $hash = hash('sha256', json_encode([$kind, $ids->all(), $client?->id, isset($data['site_id']) ? (int) $data['site_id'] : null, trim($data['title'] ?? '')], JSON_THROW_ON_ERROR));
            $requestRow = DB::table('chat_creation_requests')->where('user_id', $user->id)->where('mutation_id', $data['mutation_id'])->first();
            if ($requestRow) {
                abort_unless(hash_equals($requestRow->request_hash, $hash), 409, 'This request reference belongs to a different selection. Review your conversations before starting again.');
                $existing = $requestRow->conversation_id ? OpsConversation::find($requestRow->conversation_id) : null;
                abort_unless($existing, 410, 'This conversation is no longer available.');
                $this->access->authorize($user, $existing);

                return $existing;
            }
            abort_if(OpsConversation::where('chat_created_by', $user->id)->where('creation_mutation_id', $data['mutation_id'])->exists(), 409, 'This older request cannot be checked against your selection. Review your conversations before starting again.');
            $remember = function (OpsConversation $conversation) use ($user, $data, $hash) {
                DB::table('chat_creation_requests')->insert([
                    'user_id' => $user->id, 'mutation_id' => $data['mutation_id'], 'request_hash' => $hash,
                    'conversation_id' => $conversation->id, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $conversation;
            };
            if ($client) {
                abort_unless($user->canAccessClientPortal($client), 403);
                // Only the initiating relative is added automatically. Other relatives require the invitation flow.
                abort_unless($users->every(fn ($person) => $person->id === $user->id || $this->family->canManage($person, $client)), 403);
                $namedWorkerId = $ids->first(fn ($id) => $id !== $user->id);
                if (($data['reuse_contact'] ?? false) && ($existing = app(ChatFamilyContacts::class)->privateConversation($client, $user, $namedWorkerId, true))) {
                    return $remember($existing);
                }
            } else {
                abort_unless($this->visibility->isCurrentStaff($user) && $ids->count() >= 2
                    && $this->access->staffAudienceAllows($ids->all()), 403, 'These people cannot all access the same conversation. Review your selection.');
                abort_if(! $managed && $ids->count() > ChatAccess::PRIVATE_GROUP_MEMBERS, 422, 'Private groups can have up to 20 people, including you.');
                abort_if($ids->count() > 2 && trim($data['title'] ?? '') === '', 422, 'Give the private group a name.');
            }
            if ($managed && isset($data['site_id'])) {
                $siteConversation = new OpsConversation(['channel_site_id' => $data['site_id']]);
                abort_unless($users->every(fn ($person) => $this->access->channelSiteAllows($person, $siteConversation)), 403, 'All channel participants must have current access to its site.');
            }
            if ($kind === 'direct') {
                $existing = OpsConversation::where('conversation_type', 'direct')->whereNull('client_id')->where('is_managed_channel', false)->has('participants', '=', 2);
                foreach ($ids as $id) {
                    $existing->whereHas('participants', fn ($query) => $query->where('user_id', $id));
                }
                if ($conversation = $existing->first()) {
                    $this->access->authorize($user, $conversation);

                    return $remember($conversation);
                }
            }
            $conversation = OpsConversation::create([
                'conversation_type' => $client ? 'family' : ($kind === 'direct' ? 'direct' : 'group'),
                'title' => $data['title'] ?? ($client ? ($ids->count() === 2 ? 'Chat with '.$users->firstWhere('id', $ids->first(fn ($id) => $id !== $user->id))->name : 'Care team') : null), 'client_id' => $client?->id,
                'care_team_cover' => (bool) $client,
                'family_contact_kind' => $client && ($data['reuse_contact'] ?? false) ? ($ids->count() === 2 ? 'named_worker' : 'care_team') : null,
                'family_contact_user_id' => $client && ($data['reuse_contact'] ?? false) ? $ids->first(fn ($id) => $id !== $user->id) : null,
                'is_managed_channel' => $managed,
                'channel_site_id' => $managed ? ($data['site_id'] ?? null) : null,
                'chat_created_by' => $user->id,
                'creation_mutation_id' => $data['mutation_id'],
                'assigned_to' => $client && $ids->count() === 2 ? $ids->first(fn ($id) => $id !== $user->id) : null,
            ]);
            foreach ($ids as $id) {
                $conversation->participants()->create(['user_id' => $id, 'role' => $id === $user->id ? ($client ? 'family' : 'admin') : 'member']);
            }
            AuditLog::create(['user_id' => $user->id, 'client_id' => $client?->id, 'action' => 'chat.conversation.created', 'meta' => ['conversation_id' => $conversation->id, 'managed_channel' => $managed]]);

            return $remember($conversation);
        };
        $conversation = $client
            ? app(ClientPortalMembershipService::class)->withLockedMembership($client, $user, $create)
            : DB::transaction($create);
        app(ChatRealtime::class)->queueConversation($conversation->id);

        return response()->json(['conversation' => $this->projection->conversation($conversation, $user)], 201);
    }

    public function sendOutcome(Request $request, OpsConversation $conversation, string $mutation)
    {
        $this->access->authorize($request->user(), $conversation, true);
        $message = OpsMessage::withTrashed()->where('conversation_id', $conversation->id)
            ->where('sender_id', $request->user()->id)->where('mutation_id', $mutation)->first();
        if ($message) {
            $this->access->message($request->user(), $message, true);
        }
        $policy = $this->settings->forConversation($conversation);
        $canRetry = $policy['enabled'] && ($conversation->conversation_type !== 'family' || $policy['family_enabled'])
            && ! $conversation->is_archived && ! $conversation->participants()->where('user_id', $request->user()->id)->where('is_archived_for_user', true)->exists();

        // An absent record is not proof that an earlier request can never commit.
        return response()->json([
            'state' => $message ? 'confirmed' : 'unconfirmed', 'conversation_id' => $conversation->id,
            'mutation_id' => $mutation, 'message_id' => $message?->id, 'removed' => $message?->trashed() ?? false,
            'can_retry' => (bool) $canRetry,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, OpsConversation $conversation)
    {
        $this->access->authorize($request->user(), $conversation, true);
        $reference = $request->validate(['mutation_id' => ['required', 'uuid']]);
        $acknowledged = OpsMessage::withTrashed()->where('sender_id', $request->user()->id)->where('mutation_id', $reference['mutation_id'])->first();
        if ($acknowledged) {
            abort_unless($acknowledged->conversation_id === $conversation->id, 409);
            $this->access->message($request->user(), $acknowledged, true);

            // Resolve a confirmed send before applying newer file limits or read-only policy.
            return response()->json(['message' => $this->projection->message($acknowledged, $request->user())], 201);
        }
        $settings = $this->settings->forConversation($conversation);
        abort_unless($settings['enabled'] && ($conversation->conversation_type !== 'family' || $settings['family_enabled']), 403);
        $data = $request->validate([
            'audience_token' => ['nullable', 'string', 'size:64'],
            'recoverable_send' => ['sometimes', 'boolean'],
            'draft_version' => ['nullable', 'required_with:draft_boundary', 'integer', 'min:0', 'max:9007199254740000'],
            'draft_boundary' => ['nullable', 'required_with:draft_version', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'mutation_id' => ['required', 'uuid'], 'content' => ['nullable', 'string', 'max:10000'],
            'reply_to_id' => ['nullable', 'integer'],
            'mentions' => ['sometimes', 'array', 'max:30'],
            'mentions.*' => ['array:user_id,start,length'],
            'mentions.*.user_id' => ['required', 'integer', 'min:1'],
            'mentions.*.start' => ['required', 'integer', 'between:0,10000'],
            'mentions.*.length' => ['required', 'integer', 'between:2,256'],
            'files' => ['nullable', 'array', 'max:'.$settings['max_files']],
            'files.*' => ['file', 'max:'.($settings['max_file_mb'] * 1024), 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt,rtf'],
            'upload_ids' => ['sometimes', 'array', 'max:'.$settings['max_files']],
            'upload_ids.*' => ['required', 'uuid', 'distinct'],
            'captions' => ['sometimes', 'array', 'max:'.$settings['max_files']],
            'captions.*' => ['nullable', 'string', 'max:1000'],
        ]);
        $files = $request->file('files', []);
        if (isset($data['draft_version'])) {
            abort_unless($request->header('X-Chat-Actor') === (string) $request->user()->id, 403);
        }
        $uploadIds = $data['upload_ids'] ?? [];
        abort_if(count($files) && count($uploadIds), 422, 'Choose one attachment upload method.');
        $attachmentCount = count($files) + count($uploadIds);
        foreach (array_keys($data['captions'] ?? []) as $index) {
            abort_unless(array_key_exists($index, $files) || array_key_exists($index, $uploadIds), 422, 'Each caption must belong to an attached file.');
        }
        abort_if($attachmentCount > 0 && ! $settings['files_enabled'], 403, 'File sharing is disabled.');
        abort_unless(trim($data['content'] ?? '') !== '' || $attachmentCount, 422, 'Write a message or choose a file.');
        $scans = [];
        foreach ($files as $index => $file) {
            $scan = app(ChatFileSafety::class)->inspect($file->getRealPath() ?: '');
            if ($scan->state !== 'clean') {
                $message = match ($scan->state) {
                    'blocked' => 'Attachment '.($index + 1).' was blocked by the file safety check. Remove it or choose another file.',
                    'changed' => 'Attachment '.($index + 1).' changed during its safety check. Choose the original file again.',
                    default => 'File safety checks are unavailable. Your message was not accepted; keep your files and try again later.',
                };

                return response()->json(['message' => $message, 'code' => 'chat_file_'.$scan->state,
                    'errors' => ['files.'.$index => [$message]]], $scan->state === 'unavailable' ? 503 : 422);
            }
            $scans[$index] = $scan;
        }
        $paths = [];
        try {
            $message = DB::transaction(function () use ($request, $conversation, $data, $files, $scans, $uploadIds, $attachmentCount, &$paths) {
                $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($request->user(), $locked, true);
                $existing = OpsMessage::withTrashed()->where('sender_id', $request->user()->id)->where('mutation_id', $data['mutation_id'])->first();
                if ($existing) {
                    abort_unless($existing->conversation_id === $locked->id, 409);
                    $this->access->message($request->user(), $existing, true);

                    return $existing;
                }
                app(ChatSendAttempts::class)->assertCanSend($locked, $request->user(), $data, count($files) > 0, $request->header('X-Chat-Actor'));
                abort_if($locked->is_archived, 409, 'This conversation is archived.');
                abort_if($locked->participants()->where('user_id', $request->user()->id)->where('is_archived_for_user', true)->exists(), 409, 'Unarchive this conversation for yourself before sending.');
                if ($data['reply_to_id'] ?? null) {
                    abort_unless($this->access->messages($locked, $request->user())->whereKey($data['reply_to_id'])->exists(), 422, 'The replied-to message is no longer available.');
                }
                app(ChatAudience::class)->requireReviewed($locked, $request->user(), $data['audience_token'] ?? null);
                $mentions = app(ChatMentions::class)->prepare($locked, $request->user(), $data['content'] ?? '', $data['mentions'] ?? []);
                $message = $locked->messages()->create([
                    'sender_id' => $request->user()->id, 'sender_type' => $locked->client && $request->user()->canAccessClientPortal($locked->client) ? 'family' : 'user',
                    'content' => trim($data['content'] ?? ''), 'message_type' => $attachmentCount ? 'attachment' : 'text',
                    'client_id' => $locked->conversation_type === 'family' ? $locked->client_id : null,
                    'mutation_id' => $data['mutation_id'], 'reply_to_id' => $data['reply_to_id'] ?? null,
                ]);
                foreach ($mentions as $mention) {
                    DB::table('chat_message_mentions')->insert($mention + ['message_id' => $message->id, 'created_at' => now()]);
                }
                if ($uploadIds) {
                    app(ChatUploads::class)->attach($locked, $request->user(), $message, $uploadIds, $data['captions'] ?? [], $paths);
                }
                foreach ($files as $index => $file) {
                    $caption = trim($data['captions'][$index] ?? '');
                    $path = $file->store('chat/'.$locked->id, 'local');
                    abort_unless(is_string($path), 503, 'The file could not be saved. Please retry.');
                    $paths[] = $path;
                    abort_unless($scans[$index]->verifies(Storage::disk('local')->path($path)), 422, 'The uploaded file changed after its safety check. Choose the file again.');
                    DB::table('chat_attachments')->insert([
                        'message_id' => $message->id, 'storage_path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'created_at' => now(), 'updated_at' => now(),
                        'caption' => $caption === '' ? null : $caption,
                    ]);
                }
                if ($message->sender_type === 'family') {
                    $locked->queue_status = 'open';
                }
                app(ChatFamilyResponse::class)->sent($locked, $message);
                $locked->chat_version++;
                $locked->touch();
                app(ChatMessageChanges::class)->record($message, created: true);
                app(ChatMessageDelivery::class)->enqueue($message, $locked);
                if (isset($data['draft_version'], $data['draft_boundary'])) {
                    app(ChatPrivateDrafts::class)->clearSent($locked, $request->user(), (int) $data['draft_version'], $data['draft_boundary']);
                }
                app(ChatSendAttempts::class)->confirm($message);

                return $message;
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        foreach ($conversation->participants()->pluck('user_id') as $id) {
            Cache::forget("user:{$id}:ops-unread:v1");
        }

        try {
            Bus::dispatch(new DeliverChatMessageNotifications($message->id));
        } catch (\Throwable $error) {
            // The committed outbox remains recoverable by the scheduler if the queue is unavailable.
            report($error);
        }

        return response()->json(['message' => $this->projection->message($message, $request->user())], 201);
    }

    public function reviewAudience(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate(['audience_token' => ['required', 'string', 'size:64'], 'understood' => ['required', 'accepted']]);

        return DB::transaction(function () use ($request, $conversation, $data) {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $locked, true);
            abort_unless($locked->conversation_type === 'family' && ! $locked->is_archived, 409);
            $settings = $this->settings->forConversation($locked);
            abort_unless($settings['enabled'] && $settings['family_enabled'], 403);
            $review = app(ChatAudience::class)->review($locked, $request->user());
            abort_unless(hash_equals($review['audience_review_token'], $data['audience_token']), 409, 'The audience has changed. Review the updated people before confirming.');
            if ($review['audience_review_required']) {
                DB::table('chat_audience_reviews')->updateOrInsert(
                    ['conversation_id' => $locked->id, 'user_id' => $request->user()->id],
                    ['audience_token' => $review['audience_review_token'], 'reviewed_at' => now()],
                );
                AuditLog::create(['user_id' => $request->user()->id, 'client_id' => $locked->client_id,
                    'action' => 'chat.audience.reviewed', 'meta' => ['conversation_id' => $locked->id, 'audience_token' => $review['audience_review_token']]]);
            }

            return response()->json(['conversation' => $this->projection->conversation($locked, $request->user())]);
        });
    }

    public function read(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1'], 'unread_marker' => ['nullable', 'uuid']]);

        return DB::transaction(function () use ($request, $conversation, $data) {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $locked);
            $message = $this->access->messages($locked, $request->user(), true)->findOrFail($data['message_id']);
            $member = $locked->participants()->firstOrCreate(['user_id' => $request->user()->id], ['role' => OpsConversationParticipant::COVER_READER]);
            if ((int) $member->last_read_message_id < $message->id) {
                $member->update(['last_read_message_id' => $message->id, 'last_read_at' => $message->created_at]);
            }
            // Reading the conversation clears its bell entries up to what was read.
            app(ChatMessageDelivery::class)->markConversationNoticesRead($request->user(), $locked, (int) $member->last_read_message_id);
            // A delayed acknowledgement from an older view must not erase a new reminder.
            if ($member->unread_marker && $member->unread_marker === ($data['unread_marker'] ?? null)) {
                $member->update(['unread_marker' => null]);
            }
            Cache::forget('user:'.$request->user()->id.':ops-unread:v1');

            return response()->noContent();
        });
    }

    public function preferences(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate(['is_muted' => ['sometimes', 'boolean'], 'is_favourite' => ['sometimes', 'boolean'], 'is_archived_for_user' => ['sometimes', 'boolean'], 'mark_unread' => ['sometimes', 'boolean']]);

        return DB::transaction(function () use ($request, $conversation, $data) {
            $conversation = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $conversation);
            $member = $conversation->participants()->firstOrCreate(['user_id' => $request->user()->id], ['role' => OpsConversationParticipant::COVER_READER]);
            if (array_key_exists('mark_unread', $data)) {
                $member->unread_marker = $data['mark_unread'] ? (string) Str::uuid() : null;
                unset($data['mark_unread']);
            }
            $member->fill($data)->save();
            Cache::forget('user:'.$request->user()->id.':ops-unread:v1');

            return response()->noContent();
        });
    }

    public function action(Request $request, OpsMessage $message)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['pin', 'delete', 'react', 'save'])], 'active' => ['required_unless:action,delete', 'boolean'], 'emoji' => ['required_if:action,react', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && ! ChatReactionEmoji::valid($value)) {
                    $fail('Choose one emoji to react with.');
                }
            }],
            'mutation_id' => ['required_if:action,delete', 'uuid'], 'version' => ['required_if:action,delete', 'integer', 'min:0']]);

        if ($data['action'] === 'delete') {
            app(ChatMessageEditing::class)->remove($request->user(), $message, $data);

            return response()->noContent()->header('Cache-Control', 'private, no-store');
        }

        return DB::transaction(function () use ($request, $message, $data) {
            $conversation = OpsConversation::whereKey($message->conversation_id)->lockForUpdate()->firstOrFail();
            $message = OpsMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            $this->access->message($request->user(), $message, $data['action'] !== 'save');
            // A pin is shown to everyone, so only the care team pins in a family conversation;
            // relatives keep "Save for me".
            abort_if($data['action'] === 'pin' && $conversation->participants()->where('user_id', $request->user()->id)->value('role') === 'family',
                403, 'Only the care team can pin messages in this conversation. Use Save for me instead.');
            if ($data['action'] === 'save') {
                $identity = ['user_id' => $request->user()->id, 'message_id' => $message->id];
                if ($data['active']) {
                    DB::table('chat_saved_messages')->insertOrIgnore($identity + ['created_at' => now()]);
                } else {
                    DB::table('chat_saved_messages')->where($identity)->delete();
                }
            } elseif ($data['action'] === 'pin') {
                if ((bool) $message->is_pinned !== (bool) $data['active']) {
                    $message->update($data['active']
                        ? ['is_pinned' => true, 'pinned_by' => $request->user()->id, 'pinned_at' => now()]
                        : ['is_pinned' => false, 'pinned_by' => null, 'pinned_at' => null]);
                    AuditLog::create(['user_id' => $request->user()->id, 'client_id' => $conversation->client_id,
                        'action' => $data['active'] ? 'chat.message.pinned' : 'chat.message.unpinned',
                        'meta' => ['conversation_id' => $conversation->id, 'message_id' => $message->id]]);
                }
            } else {
                $existing = $message->reactions()->where('user_id', $request->user()->id)->where('emoji', $data['emoji'])->first();
                if (! $data['active']) {
                    $existing?->delete();
                } elseif (! $existing) {
                    OpsMessageReaction::create(['message_id' => $message->id, 'user_id' => $request->user()->id, 'emoji' => $data['emoji']]);
                }
            }

            app(ChatMessageChanges::class)->record($message, $data['action'] === 'save' ? $request->user()->id : null);

            return response()->noContent();
        });
    }

    public function attachment(Request $request, int $attachment)
    {
        $file = DB::table('chat_attachments')->where('id', $attachment)->first();
        abort_unless($file, 404);
        $message = OpsMessage::findOrFail($file->message_id);
        $this->access->message($request->user(), $message);
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);
        if (($file->media_kind ?? 'file') === 'voice_note') {
            abort_unless(ChatVoiceInspector::storedBytesMatch($file, Storage::disk('local')->path($file->storage_path)), 415, 'This voice message does not match its checked file.');
        }

        return Storage::disk('local')->download($file->storage_path, $file->name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
