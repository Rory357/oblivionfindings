<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientPhoto;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\OpsMessageReaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Clients\ClientPhotoMediaUrls;
use App\Services\Clients\ClientPhotoStorage;
use App\Services\Clients\ClientPortalMembershipService;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortalMessageController extends Controller
{
    public function __construct(
        private readonly ClientPhotoMediaUrls $mediaUrls,
        private readonly ClientPhotoStorage $photoStorage,
        private readonly ClientWorkerEligibility $workerEligibility,
        private readonly ClientFamilyCommunicationAccess $familyCommunicationAccess,
        private readonly ClientPortalMembershipService $portalMembership,
    ) {}

    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $conversations = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'participants.user:id,name,role,approved_at,last_seen_at,presence_status',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (OpsConversation $conversation) use ($client): array {
                $latestMessage = $this->latestVisibleMessage($conversation, $client);

                return [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'updated_at' => $conversation->updated_at?->toISOString(),
                    'is_archived' => $conversation->is_archived,
                    'latest_message' => $latestMessage ? [
                        'id' => $latestMessage->id,
                        'content' => $latestMessage->content,
                        'sender_type' => $latestMessage->sender_type,
                        'created_at' => $latestMessage->created_at?->toISOString(),
                    ] : null,
                    'participants' => $conversation->participants
                        ->map(fn ($participant) => $this->participantPayload($participant->user, $client))
                        ->filter()
                        ->values(),
                ];
            });

        $workers = $this->portalWorkersForClient($client);

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'conversations' => $conversations->values(),
            'supportWorkers' => $workers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'presence' => $this->derivePresence($w),
            ])->values(),
            'currentUserId' => $user->id,
        ]);
    }

    public function show(Request $request, Client $client, OpsConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $this->assertConversationAccess($user, $client, $conversation);

        $messageRecords = $conversation->messages()->withTrashed()
            ->where('client_id', $client->id)
            ->with(['sender:id,name,profile_photo_path', 'reactions.user:id,name'])
            ->orderBy('created_at')
            ->get();
        $messagePhotos = ClientPhoto::query()
            ->where('client_id', $client->id)
            ->whereIn(
                'id',
                $messageRecords
                    ->flatMap(fn (OpsMessage $message) => collect($message->attachments ?? [])
                        ->pluck('photo_id'))
                    ->filter()
                    ->unique()
                    ->values(),
            )
            ->get()
            ->keyBy('id');
        $messages = $messageRecords->map(fn ($msg) => [
            'id' => $msg->id,
            'content' => $msg->content,
            'sender_id' => $msg->sender_id,
            'sender_type' => $msg->sender_type,
            'message_type' => $msg->message_type,
            'attachments' => $this->portalAttachmentPayload(
                $msg->attachments,
                $messagePhotos,
            ),
            'is_pinned' => (bool) $msg->is_pinned,
            'is_read' => (bool) $msg->is_read,
            'read_at' => $msg->read_at?->toISOString(),
            'shift_id' => $msg->shift_id,
            'is_deleted' => $msg->trashed(),
            'reactions' => $msg->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'user_ids' => $group->pluck('user_id')->all(),
                    'user_names' => $group->map(fn ($r) => $r->user?->name)->filter()->values()->all(),
                ])
                ->values()
                ->all(),
            'sender' => $msg->sender ? [
                'id' => $msg->sender->id,
                'name' => $msg->sender->name,
                'avatar' => $msg->sender->avatar,
            ] : null,
            'created_at' => $msg->created_at?->toISOString(),
        ]);

        // Pinned messages
        $pinnedMessages = $conversation->messages()
            ->where('client_id', $client->id)
            ->where('is_pinned', true)
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_name' => $msg->sender?->name,
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        OpsConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);

        $participants = $conversation->participants()
            ->with('user:id,name,role,approved_at,last_seen_at,presence_status')
            ->get()
            ->map(fn ($participant) => $this->participantPayload($participant->user, $client))
            ->filter()
            ->values();

        // Re-fetch conversations for sidebar (same as index)
        $allConversations = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'participants.user:id,name,role,approved_at,last_seen_at,presence_status',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($convo) use ($client) {
                $latestMessage = $this->latestVisibleMessage($convo, $client, withSender: true);

                return [
                    'id' => $convo->id,
                    'title' => $convo->title,
                    'updated_at' => $convo->updated_at?->toISOString(),
                    'latest_message' => $latestMessage ? [
                        'content' => $latestMessage->content,
                        'created_at' => $latestMessage->created_at?->toISOString(),
                        'sender_name' => $latestMessage->sender?->name,
                    ] : null,
                    'participants' => $convo->participants
                        ->map(fn ($participant) => $this->participantPayload($participant->user, $client))
                        ->filter()
                        ->values(),
                ];
            });

        $workers = $this->portalWorkersForClient($client);

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'conversations' => $allConversations->values(),
            'supportWorkers' => $workers->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'presence' => $this->derivePresence($w)])->values(),
            'currentUserId' => $user->id,
            'activeConversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'participants' => $participants,
            ],
            'activeMessages' => $messages->values(),
            'pinnedMessages' => $pinnedMessages->values(),
        ]);
    }

    public function toggleReaction(Request $request, Client $client, OpsMessage $message)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->assertMessageAccess($user, $client, $message);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10'],
        ]);

        return $this->portalMembership->withLockedMembership(
            $client,
            $user,
            function () use ($client, $message, $user, $validated) {
                $message->refresh();
                $this->assertMessageAccess($user, $client, $message);
                $existing = OpsMessageReaction::where('message_id', $message->id)
                    ->where('user_id', $user->id)
                    ->where('emoji', $validated['emoji'])
                    ->first();

                if ($existing) {
                    $existing->delete();
                } else {
                    OpsMessageReaction::create([
                        'message_id' => $message->id,
                        'user_id' => $user->id,
                        'emoji' => $validated['emoji'],
                    ]);
                }

                return redirect()->back();
            },
        );
    }

    public function togglePin(Request $request, Client $client, OpsMessage $message)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->assertMessageAccess($user, $client, $message);

        return $this->portalMembership->withLockedMembership(
            $client,
            $user,
            function () use ($client, $message, $user) {
                $message->refresh();
                $this->assertMessageAccess($user, $client, $message);
                $message->update(['is_pinned' => ! $message->is_pinned]);

                return redirect()->back();
            },
        );
    }

    public function archiveMessage(Request $request, Client $client, OpsMessage $message)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->assertMessageAccess($user, $client, $message);
        abort_unless($message->sender_id === $user->id, 403);

        return $this->portalMembership->withLockedMembership(
            $client,
            $user,
            function () use ($client, $message, $user) {
                $message->refresh();
                $this->assertMessageAccess($user, $client, $message);
                abort_unless($message->sender_id === $user->id, 403);

                // Soft delete — data preserved for auditing.
                $message->delete();

                return redirect()->back();
            },
        );
    }

    public function searchMessages(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $q = $request->query('q', '');
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $conversationIds = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($qb) => $qb->where('user_id', $user->id))
            ->pluck('id');

        $results = OpsMessage::whereIn('conversation_id', $conversationIds)
            ->where('client_id', $client->id)
            ->where('content', 'like', "%{$q}%")
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_name' => $msg->sender?->name,
                'conversation_id' => $msg->conversation_id,
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        return response()->json($results);
    }

    public function storeMessage(Request $request, Client $client, OpsConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        return $this->portalMembership->withLockedMembership(
            $client,
            $user,
            function () use ($client, $conversation, $request, $user) {
                $this->assertConversationAccess($user, $client, $conversation);

                $request->validate([
                    'content' => ['nullable', 'string', 'max:5000'],
                    'attachment' => [
                        'nullable',
                        'file',
                        'max:20480',
                        'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt,rtf',
                    ],
                ]);

                $content = $request->input('content', '');
                $messageType = 'text';
                $attachments = null;

                // Handle file attachment
                if ($request->hasFile('attachment')) {
                    $file = $request->file('attachment');
                    $isImage = in_array(
                        strtolower((string) $file->getMimeType()),
                        ClientPhotoStorage::SAFE_IMAGE_MIME_TYPES,
                        true,
                    );

                    if ($isImage) {
                        $stored = $this->photoStorage->store($file, $client);

                        $photo = ClientPhoto::create([
                            'client_id' => $client->id,
                            'uploaded_by_user_id' => $user->id,
                            ...$stored,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'size_bytes' => $file->getSize(),
                            'caption' => $content ?: 'Shared via chat',
                            'visibility' => 'family',
                            'status' => 'approved',
                        ]);

                        app(TimelineEmitter::class)->record([
                            'source_type' => ClientPhoto::class,
                            'source_id' => $photo->id,
                            'occurred_at' => now(),
                            'type' => 'photo_uploaded',
                            'actor_user_id' => $user->id,
                            'client_id' => $client->id,
                            'site_id' => $client->site_id,
                            'subject' => 'Photo shared in chat',
                            'visibility' => 'portal',
                            'is_pinned' => false,
                            'created_by' => $user->id,
                        ]);

                        $attachments = [[
                            'type' => 'photo',
                            'name' => $file->getClientOriginalName(),
                            'size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                            'photo_id' => $photo->id,
                        ]];
                        $content = $content ?: '📸 Shared a photo';
                        $messageType = 'attachment';

                    } else {
                        // Store as document (same pattern as PortalDocumentController)
                        $path = $file->store("client_documents/{$client->id}", 'local');

                        $doc = ClientDocument::create([
                            'client_id' => $client->id,
                            'uploaded_by_user_id' => $user->id,
                            'title' => $content ?: $file->getClientOriginalName(),
                            'category' => 'chat_upload',
                            'folder' => 'Chat Uploads',
                            'version' => '1',
                            'storage_disk' => 'local',
                            'storage_path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'size_bytes' => $file->getSize(),
                            'portal_visible' => true,
                        ]);

                        app(TimelineEmitter::class)->record([
                            'source_type' => ClientDocument::class,
                            'source_id' => $doc->id,
                            'occurred_at' => now(),
                            'type' => 'document_uploaded',
                            'actor_user_id' => $user->id,
                            'client_id' => $client->id,
                            'site_id' => $client->site_id,
                            'subject' => 'Document shared in chat: '.$file->getClientOriginalName(),
                            'visibility' => 'portal',
                            'is_pinned' => false,
                            'created_by' => $user->id,
                        ]);

                        $attachments = [[
                            'type' => 'document',
                            'name' => $file->getClientOriginalName(),
                            'size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                            'document_id' => $doc->id,
                        ]];
                        $content = $content ?: '📎 '.$file->getClientOriginalName();
                        $messageType = 'attachment';
                    }
                }

                if (! $content && ! $attachments) {
                    return redirect()->back();
                }

                OpsMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'sender_type' => 'family',
                    'content' => $content,
                    'message_type' => $messageType,
                    'attachments' => $attachments,
                    'client_id' => $client->id,
                    'shift_id' => Shift::where('client_id', $client->id)->where('status', 'in_progress')->where('user_id', $user->id)->value('id'),
                ]);

                $conversation->update(['updated_at' => now()]);

                return redirect()->back();
            },
        );
    }

    public function startConversation(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        return $this->portalMembership->withLockedMembership(
            $client,
            $user,
            function () use ($client, $request, $user) {
                $careTeamWorkers = $this->portalWorkersForClient($client);
                $validated = $request->validate([
                    'title' => ['nullable', 'string', 'max:200'],
                    'content' => ['required', 'string', 'max:5000'],
                    'worker_id' => [
                        'nullable',
                        'integer',
                        function (string $attribute, mixed $value, \Closure $fail) use ($careTeamWorkers): void {
                            if (! $careTeamWorkers->contains('id', (int) $value)) {
                                $fail('Choose a worker from this client\'s care team.');
                            }
                        },
                    ],
                ]);

                $workerId = $validated['worker_id']
                    ?? $careTeamWorkers->firstWhere('id', $client->key_worker_id)?->id;

                // Check if a family conversation already exists between this user and worker for this client
                if ($workerId) {
                    $existing = OpsConversation::where('client_id', $client->id)
                        ->where('conversation_type', 'family')
                        ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
                        ->whereHas('participants', fn ($q) => $q->where('user_id', $workerId))
                        ->first();

                    if ($existing) {
                        // Add message to existing conversation
                        OpsMessage::create([
                            'conversation_id' => $existing->id,
                            'sender_id' => $user->id,
                            'sender_type' => 'family',
                            'content' => $validated['content'],
                            'message_type' => 'text',
                            'client_id' => $client->id,
                        ]);
                        $existing->touch();

                        return redirect("/portal/clients/{$client->id}/messages/{$existing->id}");
                    }
                }

                // Create new conversation
                $workerName = $workerId ? User::find($workerId)?->name : null;
                $conversation = DB::transaction(function () use (
                    $client,
                    $user,
                    $workerId,
                    $workerName,
                    $validated,
                ): OpsConversation {
                    $conversation = OpsConversation::create([
                        'title' => $workerName ? "Chat with {$workerName}" : ($validated['title'] ?? 'Family Message'),
                        'conversation_type' => 'family',
                        'client_id' => $client->id,
                    ]);

                    OpsConversationParticipant::create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $user->id,
                        'role' => 'family',
                    ]);

                    if ($workerId) {
                        OpsConversationParticipant::create([
                            'conversation_id' => $conversation->id,
                            'user_id' => $workerId,
                            'role' => 'staff',
                        ]);
                    }

                    OpsMessage::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $user->id,
                        'sender_type' => 'family',
                        'content' => $validated['content'],
                        'message_type' => 'text',
                        'client_id' => $client->id,
                    ]);

                    return $conversation;
                });

                return redirect("/portal/clients/{$client->id}/messages/{$conversation->id}");
            },
        );
    }

    private function portalWorkersForClient(Client $client)
    {
        $client->load([
            'supportWorkers:id,role,name,profile_photo_path,last_seen_at,presence_status',
            'keyWorker:id,role,name,profile_photo_path,last_seen_at,presence_status',
        ]);

        $workers = collect();
        $pushWorker = function ($worker) use ($workers): void {
            if ($worker && ! $workers->contains('id', $worker->id)) {
                $workers->push($worker);
            }
        };

        $pushWorker($client->keyWorker);

        foreach ($client->supportWorkers as $worker) {
            $pushWorker($worker);
        }

        $shiftWorkers = Shift::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
            ->where('ends_at', '>=', now()->subDays(7))
            ->with('staff:id,role,name,profile_photo_path,last_seen_at,presence_status')
            ->orderByDesc('starts_at')
            ->get()
            ->pluck('staff')
            ->filter();

        foreach ($shiftWorkers as $worker) {
            $pushWorker($worker);
        }

        return $workers
            ->filter(fn (User $worker) => $this->workerEligibility
                ->isEligible($client, $worker))
            ->values();
    }

    private function assertConversationAccess(
        User $user,
        Client $client,
        OpsConversation $conversation,
    ): void {
        abort_unless(
            (int) $conversation->client_id === (int) $client->id
                && $conversation->conversation_type === 'family'
                && $conversation->participants()
                    ->where('user_id', $user->id)
                    ->exists(),
            403,
        );
    }

    private function assertMessageAccess(
        User $user,
        Client $client,
        OpsMessage $message,
    ): void {
        $conversation = $message->conversation()->first();
        abort_unless(
            $conversation
                && (int) $message->client_id === (int) $client->id
                && (int) $message->conversation_id === (int) $conversation->id,
            403,
        );
        $this->assertConversationAccess($user, $client, $conversation);
    }

    /**
     * Never replay stored paths or historical public URLs. Attachment rows
     * retain only identifiers and display metadata; fresh, client-bound URLs
     * are minted for photos after the conversation is authorized.
     *
     * @param  array<int, array<string, mixed>>|null  $attachments
     * @param  Collection<int, ClientPhoto>  $photos
     * @return array<int, array<string, mixed>>|null
     */
    private function portalAttachmentPayload(
        ?array $attachments,
        Collection $photos,
    ): ?array {
        if ($attachments === null) {
            return null;
        }

        return collect($attachments)
            ->map(function (array $attachment) use ($photos): array {
                $payload = [
                    'type' => $attachment['type'] ?? 'document',
                    'name' => $attachment['name'] ?? 'Attachment',
                    'size' => (int) ($attachment['size'] ?? 0),
                    'mime_type' => $attachment['mime_type'] ?? null,
                ];

                if (($attachment['type'] ?? null) === 'photo') {
                    $photo = $photos->get((int) ($attachment['photo_id'] ?? 0));
                    if ($photo) {
                        $payload['photo_id'] = $photo->id;
                        $payload = [
                            ...$payload,
                            ...$this->mediaUrls->portal($photo),
                        ];
                    }
                } elseif (isset($attachment['document_id'])) {
                    $payload['document_id'] = (int) $attachment['document_id'];
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    private function derivePresence($user): string
    {
        if (! $user?->last_seen_at) {
            return 'offline';
        }

        if ($user->presence_status === 'online' && $user->last_seen_at->gt(now()->subMinutes(5))) {
            return 'online';
        }

        if ($user->last_seen_at->gt(now()->subMinutes(15))) {
            return 'away';
        }

        return 'offline';
    }

    private function participantPayload(?User $user, Client $client): ?array
    {
        if (
            ! $user
            || ! $this->familyCommunicationAccess->canAppearAsParticipant($user, $client)
        ) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'presence' => $this->derivePresence($user),
        ];
    }

    private function latestVisibleMessage(
        OpsConversation $conversation,
        Client $client,
        bool $withSender = false,
    ): ?OpsMessage {
        return $conversation->messages()
            ->where('client_id', $client->id)
            ->when($withSender, fn ($query) => $query->with('sender:id,name'))
            ->latest('created_at')
            ->latest('id')
            ->first();
    }
}
