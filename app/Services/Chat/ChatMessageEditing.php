<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ChatMessageEditing
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatSettings $settings) {}

    /** @param  \ArrayObject|null  $memo  Per-page memo of conversation-level checks; one actor per memo. */
    public function capabilities(OpsMessage $message, User $actor, ?\ArrayObject $memo = null): array
    {
        $conversation = $message->conversation;
        // Each check stays lazy (match order) but runs once per conversation within a projected page.
        $once = fn (string $check, \Closure $resolve) => $memo ? ($memo[$check.':'.$conversation->id] ??= $resolve()) : $resolve();
        $settings = $once('settings', fn () => $this->settings->forConversation($conversation));
        $minutes = $settings['message_change_window_minutes'];
        $until = $minutes !== null && $minutes > 0 ? $message->created_at?->copy()->addMinutes($minutes) : null;
        $reason = match (true) {
            $message->trashed() => 'This message was removed.',
            (int) $message->sender_id !== (int) $actor->id => 'Only the sender can change this message.',
            $once('held', fn () => app(ChatPreservation::class)->isHeld((int) $conversation->id)) => 'This conversation is preserved for a case. Messages cannot be changed or removed.',
            ! $settings['enabled'] || ($conversation->conversation_type === 'family' && ! $settings['family_enabled']) => 'Chat is currently read-only.',
            $conversation->is_archived || $once('archived', fn () => $conversation->participants()->where('user_id', $actor->id)->where('is_archived_for_user', true)->exists()) => 'Unarchive the conversation before changing messages.',
            ! $once('writable', fn () => $this->access->allows($actor, $conversation, true)) => 'You can no longer change this conversation.',
            $minutes === null => 'An administrator must configure the edit/removal window first.',
            (int) $minutes === 0 => 'Editing and removal are disabled by Chat policy.',
            ! $until || ! $until->isFuture() => 'The time limit for changing this message has passed.',
            default => null,
        };

        return ['can_edit' => $reason === null, 'can_remove' => $reason === null,
            'change_until' => $until?->toISOString(), 'change_unavailable_reason' => $reason,
            'edit_version' => (int) $message->chat_edit_version];
    }

    public function requireChangeable(OpsMessage $message, User $actor): void
    {
        $capability = $this->capabilities($message, $actor);
        abort_unless($capability['can_edit'], 409, $capability['change_unavailable_reason']);
    }

    public function edit(User $actor, OpsMessage $input, array $data): OpsMessage
    {
        return DB::transaction(function () use ($actor, $input, $data) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $conversation = OpsConversation::whereKey($input->conversation_id)->lockForUpdate()->firstOrFail();
            $message = OpsMessage::whereKey($input->id)->lockForUpdate()->firstOrFail();
            $message->setRelation('conversation', $conversation);
            $this->access->message($actor, $message, true);
            abort_unless((int) $message->sender_id === (int) $actor->id, 403);
            $hash = hash('sha256', json_encode([$message->id, (int) $data['version'], $data['content'] ?? '', $data['mentions'] ?? []], JSON_THROW_ON_ERROR));
            $receipt = DB::table('chat_message_revisions')->where('actor_id', $actor->id)->where('mutation_id', $data['mutation_id'])->first();
            if ($receipt) {
                abort_unless((int) $receipt->message_id === (int) $message->id && hash_equals($receipt->request_hash, $hash), 409, 'This edit reference was already used for different content.');

                return $message;
            }
            $this->requireChangeable($message, $actor);
            if ((int) $message->chat_edit_version !== (int) $data['version']) {
                abort(response()->json(['message' => 'This message changed in another window. Review the latest text before trying again.', 'code' => 'chat_edit_conflict'], 409));
            }
            $content = trim($data['content'] ?? '');
            abort_unless($content !== '' || count($message->attachments ?? []) || DB::table('chat_attachments')->where('message_id', $message->id)->exists(), 422, 'A text message cannot be empty. Use Remove my message instead.');
            $mentions = app(ChatMentions::class)->prepare($conversation, $actor, $data['content'] ?? '', $data['mentions'] ?? []);
            $previousMentions = DB::table('chat_message_mentions')->where('message_id', $message->id)->orderBy('start')->get(['user_id', 'start', 'length', 'label']);
            DB::table('chat_message_revisions')->insert([
                'message_id' => $message->id, 'actor_id' => $actor->id, 'mutation_id' => $data['mutation_id'],
                'request_hash' => $hash, 'version' => (int) $message->chat_edit_version + 1,
                'previous_state' => Crypt::encryptString(json_encode(['content' => $message->content, 'mentions' => $previousMentions], JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);
            DB::table('chat_message_mentions')->where('message_id', $message->id)->delete();
            foreach ($mentions as $mention) {
                DB::table('chat_message_mentions')->insert($mention + ['message_id' => $message->id, 'created_at' => now()]);
            }
            $message->forceFill(['content' => $content, 'edited_at' => now(), 'chat_edit_version' => (int) $message->chat_edit_version + 1])->save();
            app(ChatMessageChanges::class)->record($message, kind: 'edit');
            AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.message.edited',
                'meta' => ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'version' => $message->chat_edit_version]]);

            return $message;
        });
    }

    public function remove(User $actor, OpsMessage $input, array $data): void
    {
        DB::transaction(function () use ($actor, $input, $data) {
            // Serialise actor-scoped references across conversations and both operations.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $conversation = OpsConversation::whereKey($input->conversation_id)->lockForUpdate()->firstOrFail();
            $message = OpsMessage::withTrashed()->whereKey($input->id)->lockForUpdate()->firstOrFail();
            $message->setRelation('conversation', $conversation);
            $this->access->message($actor, $message, true);
            abort_unless((int) $message->sender_id === (int) $actor->id, 403);
            $hash = hash('sha256', json_encode(['remove', $message->id, (int) $data['version']], JSON_THROW_ON_ERROR));
            $receipt = DB::table('chat_message_revisions')->where('actor_id', $actor->id)->where('mutation_id', $data['mutation_id'])->first();
            if ($receipt) {
                abort_unless((int) $receipt->message_id === (int) $message->id && hash_equals($receipt->request_hash, $hash), 409, 'This change reference was already used for a different request.');

                return;
            }
            $this->requireChangeable($message, $actor);
            if ((int) $message->chat_edit_version !== (int) $data['version']) {
                abort(response()->json(['message' => 'This message changed in another window. Review the latest text before removing it.', 'code' => 'chat_remove_conflict'], 409));
            }
            $mentions = DB::table('chat_message_mentions')->where('message_id', $message->id)->orderBy('start')->get(['user_id', 'start', 'length', 'label']);
            DB::table('chat_message_revisions')->insert([
                'message_id' => $message->id, 'actor_id' => $actor->id, 'mutation_id' => $data['mutation_id'],
                'request_hash' => $hash, 'version' => (int) $message->chat_edit_version + 1,
                'previous_state' => Crypt::encryptString(json_encode(['content' => $message->content, 'mentions' => $mentions], JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);
            $message->forceFill(['chat_edit_version' => (int) $message->chat_edit_version + 1])->save();
            $message->delete();
            app(ChatMessageChanges::class)->record($message, kind: 'removal');
            AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.message.removed',
                'meta' => ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'version' => $message->chat_edit_version]]);
        });
    }
}
