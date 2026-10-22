<?php

namespace App\Services\Chat;

use App\Models\ClientDocument;
use App\Models\ClientPhoto;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Clients\ClientPhotoMediaUrls;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChatProjection
{
    public function __construct(private readonly ChatAccess $access, private readonly ClientFamilyCommunicationAccess $family) {}

    public function conversation(OpsConversation $conversation, User $viewer): array
    {
        $conversation->loadMissing(['participants.user', 'client']);
        $member = $conversation->participants->firstWhere('user_id', $viewer->id);
        $participants = app(ChatAudience::class)->members($conversation);
        $latest = $this->access->messages($conversation, $viewer)->orderByDesc('id')->first();
        $unread = $this->access->messages($conversation, $viewer)->where('sender_id', '!=', $viewer->id);
        if ($member?->last_read_message_id) {
            $unread->where('id', '>', $member->last_read_message_id);
        } elseif ($member?->last_read_at) {
            $unread->where('created_at', '>', $member->last_read_at);
        }
        $mentioned = $this->access->messages($conversation, $viewer)->whereExists(fn ($query) => $query->selectRaw('1')->from('chat_message_mentions')->whereColumn('message_id', 'ops_messages.id')->where('user_id', $viewer->id));
        $latestMentionId = $mentioned->max('ops_messages.id');
        $policy = app(ChatSettings::class)->forConversation($conversation);

        return [
            ...app(ChatAudience::class)->review($conversation, $viewer),
            'id' => $conversation->id,
            'title' => $conversation->title ?: $participants->filter(fn ($p) => $p->user_id !== $viewer->id)->map(fn ($p) => $p->user->name)->join(', '),
            'type' => $conversation->is_managed_channel ? 'channel' : $conversation->conversation_type,
            'can_manage_people' => $this->access->canManageParticipants($viewer, $conversation),
            'client' => $conversation->client ? ['id' => $conversation->client_id, 'name' => $conversation->client->first_name.' '.$conversation->client->last_name] : null,
            'participants' => $participants->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->user->name, 'role' => $p->role])->values(),
            'mentionable_ids' => app(ChatMentions::class)->eligible($conversation, $viewer)->pluck('id')->values(),
            'latest_mention_id' => $latestMentionId ? (int) $latestMentionId : null,
            'latest' => $latest ? ['content' => $latest->content, 'created_at' => $latest->created_at?->toISOString()] : null,
            'unread' => max($member?->unread_marker ? 1 : 0, $unread->count()),
            'unread_marker' => $member?->unread_marker,
            'muted' => (bool) $member?->is_muted,
            'favourite' => (bool) $member?->is_favourite,
            'can_send' => $policy['enabled'] && ($conversation->conversation_type !== 'family' || $policy['family_enabled'])
                && ! $conversation->is_archived && ! $member?->is_archived_for_user && $this->access->allows($viewer, $conversation, true),
            'can_call' => $policy['enabled'] && $policy['calls_enabled'] && ($conversation->conversation_type !== 'family' || ($policy['family_enabled'] && $policy['family_calls_enabled'])),
            'policy' => app(ChatSettings::class)->publicPolicy($conversation),
            'care_team_cover' => (bool) $conversation->care_team_cover,
            'queue_status' => $conversation->queue_status,
            'response_clock' => $conversation->conversation_type === 'family'
                ? app(ChatFamilyResponse::class)->project($conversation, $viewer, app(ChatSettings::class)->forConversation($conversation)) : null,
            'assigned_to' => $conversation->assigned_to,
            'version' => $conversation->chat_version,
            'archived' => (bool) $conversation->is_archived,
            'archived_for_me' => (bool) $member?->is_archived_for_user,
            'management' => app(ChatConversationManagement::class)->capabilities($viewer, $conversation),
        ];
    }

    /**
     * Projects a page of messages for one viewer with the per-message lookups
     * (saved, mentions, attachments, replies, edit capability) batched. Output
     * and keys are identical to mapping message() over the collection.
     */
    public function messages(Collection $messages, User $viewer, ?OpsConversation $conversation = null): Collection
    {
        if ($messages->isEmpty()) {
            return $messages->map(fn ($message) => $this->message($message, $viewer));
        }
        if ($conversation) {
            $messages->each(fn (OpsMessage $message) => $message->setRelation('conversation', $conversation));
        }
        EloquentCollection::make($messages->all())->loadMissing(['conversation', 'sender:id,name', 'pinnedBy:id,name', 'reactions.user:id,name']);
        $ids = $messages->pluck('id')->all();
        $replies = collect();
        foreach ($messages->filter(fn ($message) => $message->reply_to_id && $message->conversation)->groupBy('conversation_id') as $group) {
            // Same history-bounded query as message(), once per conversation instead of once per reply.
            $replies = $replies->union($this->access->messages($group->first()->conversation, $viewer, true)
                ->whereKey($group->pluck('reply_to_id')->unique()->all())->with('sender:id,name')->get()
                ->keyBy(fn ($reply) => $group->first()->conversation_id.':'.$reply->id));
        }
        $batch = [
            'saved' => DB::table('chat_saved_messages')->where('user_id', $viewer->id)->whereIn('message_id', $ids)->pluck('message_id')->map(fn ($id) => (int) $id)->flip(),
            'mentions' => DB::table('chat_message_mentions')->whereIn('message_id', $ids)->orderBy('start')->get(['message_id', 'user_id', 'start', 'length', 'label'])
                ->groupBy('message_id')->map(fn ($rows) => $rows->map(fn ($row) => (object) ['user_id' => $row->user_id, 'start' => $row->start, 'length' => $row->length, 'label' => $row->label])->values()),
            'attachments' => DB::table('chat_attachments')->whereIn('message_id', $ids)->orderBy('id')
                ->get(['message_id', 'id', 'name', 'mime_type', 'size_bytes', 'caption', 'media_kind', 'duration_ms'])->groupBy('message_id'),
            'replies' => $replies,
            'capabilities' => new \ArrayObject,
        ];

        return $messages->map(fn ($message) => $this->message($message, $viewer, $batch));
    }

    public function message(OpsMessage $message, User $viewer, ?array $batch = null): array
    {
        $message->loadMissing(['sender:id,name', 'pinnedBy:id,name', 'reactions.user:id,name']);
        $deleted = $message->trashed();
        $reply = ! $message->reply_to_id ? null : ($batch !== null
            ? $batch['replies']->get($message->conversation_id.':'.$message->reply_to_id)
            : $this->access->messages($message->conversation, $viewer, true)->whereKey($message->reply_to_id)->with('sender:id,name')->first());
        $saved = ! $deleted && ($batch !== null ? $batch['saved']->has((int) $message->id)
            : DB::table('chat_saved_messages')->where('user_id', $viewer->id)->where('message_id', $message->id)->exists());
        $mentions = $deleted ? [] : ($batch !== null ? ($batch['mentions']->get($message->id) ?? collect())
            : DB::table('chat_message_mentions')->where('message_id', $message->id)->orderBy('start')->get(['user_id', 'start', 'length', 'label']));
        $files = $deleted ? collect() : ($batch !== null ? ($batch['attachments']->get($message->id) ?? collect())->values()
            : DB::table('chat_attachments')->where('message_id', $message->id)->get(['id', 'name', 'mime_type', 'size_bytes', 'caption', 'media_kind', 'duration_ms']));

        return [
            'id' => $message->id,
            ...app(ChatMessageEditing::class)->capabilities($message, $viewer, $batch['capabilities'] ?? null),
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name ?? 'Former participant',
            'mine' => (int) $message->sender_id === (int) $viewer->id,
            'content' => $deleted ? '' : $message->content,
            'deleted' => $deleted,
            'pinned' => ! $deleted && $message->is_pinned,
            'pinned_by_name' => ! $deleted && $message->is_pinned && $message->pinned_by ? ($message->pinned_by === $viewer->id ? 'You' : ($message->pinnedBy?->name ?? 'Former participant')) : null,
            'pinned_at' => ! $deleted && $message->is_pinned ? $message->pinned_at?->toISOString() : null,
            'saved' => $saved,
            'mentions' => $mentions,
            'created_at' => $message->created_at?->toISOString(),
            'edited_at' => $message->edited_at?->toISOString(),
            'reply_to_id' => $reply?->id,
            'reply_unavailable' => (bool) $message->reply_to_id && ! $reply,
            'reply' => ! $deleted && $reply ? ['id' => $reply->id, 'sender_name' => $reply->sender?->name ?? 'Former participant', 'content' => $reply->trashed() ? '' : mb_substr($reply->content ?? '', 0, 280), 'deleted' => $reply->trashed()] : null,
            'mutation_id' => $message->sender_id === $viewer->id ? $message->mutation_id : null,
            'attachments' => $deleted ? [] : $files->map(fn ($file) => [
                'id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'size_bytes' => $file->size_bytes,
                'caption' => $file->caption,
                'media_kind' => $file->media_kind, 'duration_ms' => $file->duration_ms,
                'url' => '/chat/api/attachments/'.$file->id,
            ]),
            // Existing record-linked media keeps its identity. It is never projected as a public storage path.
            'legacy_attachments' => $deleted ? [] : collect($message->attachments ?? [])->map(fn ($file) => $this->legacyAttachment($message, $file, $viewer)),
            // Who reacted is shown as names, as WhatsApp and Teams do; reacting is a visible action, unlike reading.
            'reactions' => $deleted ? [] : $message->reactions->sortBy('id')->groupBy('emoji')->map(fn ($rows, $emoji) => [
                'emoji' => (string) $emoji, 'count' => $rows->count(), 'mine' => $rows->contains('user_id', $viewer->id),
                'people' => $rows->map(fn ($row) => $row->user_id === $viewer->id ? 'You' : ($row->user?->name ?? 'Former participant'))->take(20)->values(),
            ])->values(),
        ];
    }

    private function legacyAttachment(OpsMessage $message, array $file, User $viewer): array
    {
        $payload = ['name' => $file['name'] ?? 'Attachment', 'url' => null];
        if (! $message->client_id || ! $message->conversation?->client) {
            return $payload;
        }
        $portal = $viewer->canAccessClientPortal($message->conversation->client);
        if (isset($file['photo_id'])) {
            $photo = ClientPhoto::whereKey($file['photo_id'])->where('client_id', $message->client_id)
                ->when($portal, fn ($query) => $query->approved()->visibleToFamily())->first();
            if ($photo) {
                $urls = app(ClientPhotoMediaUrls::class);
                $payload['url'] = ($portal ? $urls->portal($photo) : $urls->staff($photo))['url'];
            }
        } elseif (isset($file['document_id'])) {
            $document = ClientDocument::whereKey($file['document_id'])->where('client_id', $message->client_id)
                ->whereNull('source_referral_evidence_id')
                ->when($portal, fn ($query) => $query->where('portal_visible', true))->first();
            if ($document) {
                $payload['url'] = route($portal ? 'portal.clients.documents.download' : 'operations.clients.documents.download', ['client' => $message->client_id, 'document' => $document->id], false);
            }
        }

        return $payload;
    }
}
