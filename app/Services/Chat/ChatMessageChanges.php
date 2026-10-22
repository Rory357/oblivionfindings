<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ChatMessageChanges
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatProjection $projection) {}

    /**
     * The caller must hold the conversation lock until its message transaction commits.
     * $kind says what changed for live updates (message, edit, removal, reaction, pin, save).
     */
    public function record(OpsMessage $message, ?int $onlyUserId = null, bool $created = false, ?string $kind = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Chat changes must commit with their message.');
        }
        DB::table('chat_message_changes')->insert([
            'conversation_id' => $message->conversation_id, 'message_id' => $message->id,
            'kind' => $created ? 'created' : 'updated',
            'only_user_id' => $onlyUserId, 'created_at' => now(),
        ]);
        app(ChatRealtime::class)->queueConversation($message->conversation_id, $onlyUserId, $kind ?? ($created ? 'message' : 'update'));
    }

    private function scope(OpsConversation $conversation, User $viewer): string
    {
        $member = $conversation->participants()->where('user_id', $viewer->id)->first();
        $grants = $member ? DB::table('chat_history_grants')->where('participant_id', $member->id)->orderBy('message_id')->pluck('message_id')->all() : [];

        // chat_version also changes on ordinary sends; only history/authority changes
        // invalidate the recovery window. Current access is checked separately per page.
        return hash('sha256', json_encode([$conversation->client_id, $conversation->conversation_type, $conversation->channel_site_id,
            $conversation->client ? $viewer->canAccessClientPortal($conversation->client) : false,
            $member?->id, $member?->history_after_id, $grants], JSON_THROW_ON_ERROR));
    }

    /** The newest change ID; live events name it so a tab can skip what its cursor has passed. */
    public function latestId(OpsConversation $conversation): int
    {
        return (int) DB::table('chat_message_changes')->where('conversation_id', $conversation->id)->max('id');
    }

    /** Read under the same conversation lock as the initial message snapshot. */
    public function cursor(OpsConversation $conversation, User $viewer, ?int $after = null): string
    {
        $after ??= $this->latestId($conversation);

        return Crypt::encryptString(json_encode(['v' => 1, 'user' => $viewer->id, 'conversation' => $conversation->id,
            'scope' => $this->scope($conversation, $viewer), 'after' => $after], JSON_THROW_ON_ERROR));
    }

    public function read(OpsConversation $conversation, User $viewer, string $cursor): array
    {
        return DB::transaction(function () use ($conversation, $viewer, $cursor): array {
            // Shared: readers wait for writers still allocating change IDs, but not for each other.
            $conversation = OpsConversation::whereKey($conversation->id)->sharedLock()->firstOrFail();
            $this->access->authorize($viewer, $conversation);
            // Disabling new messages must not revoke authorised history or its refresh stream.
            try {
                $token = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'The Chat recovery reference is invalid.');
            }
            abort_unless(is_array($token) && ($token['v'] ?? null) === 1
                && ($token['user'] ?? null) === $viewer->id && ($token['conversation'] ?? null) === $conversation->id
                && is_int($token['after'] ?? null) && $token['after'] >= 0, 422, 'The Chat recovery reference is invalid.');
            if (! hash_equals($this->scope($conversation, $viewer), $token['scope'] ?? '')) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Conversation access changed. Reload the current history.', 'code' => 'chat_sync_reset',
                ], 409));
            }
            // Writers lock the conversation before allocating a change ID. A later commit
            // therefore cannot appear behind an acknowledged cursor in this conversation.
            $rows = DB::table('chat_message_changes')->where('conversation_id', $conversation->id)
                ->where('id', '>', $token['after'])->orderBy('id')->limit(101)->get();
            $page = $rows->take(100);
            $ids = $page->filter(fn ($row) => $row->only_user_id === null || (int) $row->only_user_id === $viewer->id)->pluck('message_id')->unique();
            $messages = $this->access->messages($conversation, $viewer, true)->whereIn('id', $ids)
                ->with(['sender:id,name', 'reactions.user:id,name'])->orderBy('id')->get();

            $position = (int) ($page->last()?->id ?? $token['after']);

            return [
                'conversation' => $this->projection->conversation($conversation, $viewer),
                'messages' => $this->projection->messages($messages, $viewer, $conversation),
                'new_message_ids' => $page->where('kind', 'created')->pluck('message_id')->intersect($messages->pluck('id'))->unique()->values(),
                'sync_cursor' => $this->cursor($conversation, $viewer, $position),
                'sync_change_id' => $position,
                'has_more_changes' => $rows->count() > 100,
            ];
        });
    }
}
