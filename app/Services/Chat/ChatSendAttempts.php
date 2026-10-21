<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Immutable send intent and cancellation receipts; all writes share the conversation lock. */
final class ChatSendAttempts
{
    public function __construct(private ChatAccess $access, private ChatSettings $settings, private ChatPrivateDrafts $drafts) {}

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409, ['Cache-Control' => 'private, no-store']));
    }

    private function row(User $actor, string $mutation): ?object
    {
        return DB::table('chat_send_attempts')->where('actor_id', $actor->id)->where('mutation_id', $mutation)->first();
    }

    private function canRetry(OpsConversation $conversation, User $actor): bool
    {
        $policy = $this->settings->forConversation($conversation);

        return $this->access->allows($actor, $conversation, true) && $policy['enabled']
            && ($conversation->conversation_type !== 'family' || $policy['family_enabled'])
            && ! $conversation->is_archived
            && ! $conversation->participants()->where('user_id', $actor->id)->where('is_archived_for_user', true)->exists();
    }

    private function confirmed(OpsConversation $conversation, User $actor, string $mutation): ?OpsMessage
    {
        $message = OpsMessage::withTrashed()->where('sender_id', $actor->id)->where('mutation_id', $mutation)->first();
        if ($message) {
            abort_unless((int) $message->conversation_id === (int) $conversation->id, 409);
            $this->access->message($actor, $message);
        }

        return $message;
    }

    private function project(OpsConversation $conversation, User $actor, string $mutation, ?object $row, bool $includePendingBody = true): array
    {
        if ($row && (int) $row->conversation_id !== (int) $conversation->id) {
            $this->conflict('chat_send_attempt_changed', 'This message reference belongs to another conversation.');
        }
        $message = $this->confirmed($conversation, $actor, $mutation);
        $result = ['actor_id' => $actor->id, 'conversation_id' => $conversation->id, 'mutation_id' => $mutation,
            'state' => $message ? 'confirmed' : ($row?->status ?? 'missing'), 'message_id' => $message?->id ?? $row?->message_id,
            'body' => null, 'can_retry' => false];
        if ($result['state'] !== 'pending' || ! $includePendingBody) {
            return $result;
        }
        if (! hash_equals($row->boundary, $this->drafts->boundary($conversation, $actor))) {
            return array_replace($result, ['state' => 'scope_changed']);
        }

        return array_replace($result, ['body' => json_decode(Crypt::decryptString($row->encrypted_payload), true, flags: JSON_THROW_ON_ERROR),
            'can_retry' => $this->canRetry($conversation, $actor)]);
    }

    /** Called under the draft read's conversation lock, so draft and pending intent are one snapshot. */
    public function pending(OpsConversation $conversation, User $actor): ?array
    {
        $row = DB::table('chat_send_attempts')->where('actor_id', $actor->id)->where('conversation_id', $conversation->id)->where('status', 'pending')->first();

        return $row ? $this->project($conversation, $actor, $row->mutation_id, $row) : null;
    }

    public function read(OpsConversation $conversation, User $actor, string $mutation): array
    {
        return DB::transaction(function () use ($conversation, $actor, $mutation): array {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $locked);

            return $this->project($locked, $actor, $mutation, $this->row($actor, $mutation));
        });
    }

    public function reserve(OpsConversation $conversation, User $actor, array $input): array
    {
        try {
            return DB::transaction(function () use ($conversation, $actor, $input): array {
                $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, $locked, true);
                $hash = hash_hmac('sha256', json_encode([$locked->id, $input['draft_version'], $input['draft_boundary'], $input['audience_token']], JSON_THROW_ON_ERROR), (string) config('app.key'));
                $row = $this->row($actor, $input['mutation_id']);
                if ($row) {
                    if ((int) $row->conversation_id !== (int) $locked->id || ($row->request_hash && ! hash_equals($row->request_hash, $hash))) {
                        $this->conflict('chat_send_attempt_changed', 'This message attempt has different details. Check the original attempt.');
                    }

                    return $this->project($locked, $actor, $input['mutation_id'], $row);
                }
                if ($this->confirmed($locked, $actor, $input['mutation_id'])) {
                    return $this->project($locked, $actor, $input['mutation_id'], null);
                }
                abort_unless($this->canRetry($locked, $actor), 409, 'This conversation is read-only.');
                if (DB::table('chat_send_attempts')->where('actor_id', $actor->id)->where('conversation_id', $locked->id)->where('status', 'pending')->exists()) {
                    $this->conflict('chat_send_attempt_pending', 'Check your earlier message attempt before starting another.');
                }
                $draft = $this->drafts->read($locked, $actor);
                if ($draft['version'] !== $input['draft_version'] || ! hash_equals($draft['boundary'], $input['draft_boundary']) || $draft['state'] !== 'saved') {
                    $this->conflict('chat_draft_version_changed', 'Your saved draft changed. Review it before sending.');
                }
                app(ChatAudience::class)->requireReviewed($locked, $actor, $input['audience_token']);
                $body = ['recoverable_send' => true, 'mutation_id' => $input['mutation_id'],
                    'draft_version' => $input['draft_version'], 'draft_boundary' => $input['draft_boundary'], 'audience_token' => $input['audience_token'],
                    'content' => $draft['draft']['content'], 'reply_to_id' => $draft['draft']['reply_to_id'], 'mentions' => $draft['draft']['mentions'],
                    'upload_ids' => array_column($draft['draft']['uploads'], 'id'), 'captions' => array_column($draft['draft']['uploads'], 'caption')];
                abort_unless(trim($body['content']) !== '' || $body['upload_ids'], 422, 'Write a message or choose a file.');
                DB::table('chat_send_attempts')->insert(['actor_id' => $actor->id, 'conversation_id' => $locked->id,
                    'mutation_id' => $input['mutation_id'], 'status' => 'pending', 'request_hash' => $hash, 'boundary' => $input['draft_boundary'],
                    'encrypted_payload' => Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);

                return $this->project($locked, $actor, $input['mutation_id'], $this->row($actor, $input['mutation_id']));
            });
        } catch (UniqueConstraintViolationException $error) {
            if ($this->row($actor, $input['mutation_id'])) {
                $this->conflict('chat_send_attempt_changed', 'This message reference was already used. Check its original outcome.');
            }
            throw $error;
        }
    }

    public function cancel(OpsConversation $conversation, User $actor, string $mutation): array
    {
        try {
            return DB::transaction(function () use ($conversation, $actor, $mutation): array {
                $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, $locked);
                $row = $this->row($actor, $mutation);
                // Cancelling requires current authority and the terminal receipt, never the pending content.
                // Damaged ciphertext or an old payload format must not trap a user in an unsendable attempt.
                $result = $this->project($locked, $actor, $mutation, $row, includePendingBody: false);
                if (in_array($result['state'], ['confirmed', 'cancelled'], true)) {
                    return $result;
                }
                // A receipt exists even when cancellation beats the original reservation or send.
                DB::table('chat_send_attempts')->updateOrInsert(['actor_id' => $actor->id, 'mutation_id' => $mutation],
                    ['conversation_id' => $locked->id, 'status' => 'cancelled', 'encrypted_payload' => null,
                        'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);

                return $this->project($locked, $actor, $mutation, $this->row($actor, $mutation));
            });
        } catch (UniqueConstraintViolationException $error) {
            if ($this->row($actor, $mutation)) {
                $this->conflict('chat_send_attempt_changed', 'This message reference was already used. Check its original outcome.');
            }
            throw $error;
        }
    }

    /** Must run inside the same conversation transaction as the eventual message write. */
    public function assertCanSend(OpsConversation $conversation, User $actor, array $data, bool $hasFiles, ?string $renderedActor): void
    {
        $row = $this->row($actor, $data['mutation_id']);
        if (! $row) {
            if ($data['recoverable_send'] ?? false) {
                $this->conflict('chat_send_attempt_missing', 'Save this message attempt before sending it.');
            }

            return;
        }
        abort_unless($renderedActor === (string) $actor->id, 403);
        if ((int) $row->conversation_id !== (int) $conversation->id) {
            $this->conflict('chat_send_attempt_changed', 'This message reference belongs to another conversation.');
        }
        if ($row->status !== 'pending') {
            $this->conflict('chat_send_attempt_'.$row->status, $row->status === 'cancelled' ? 'This message attempt was cancelled. It will not be sent.' : 'This message was already accepted. Check its outcome.');
        }
        if (! hash_equals($row->boundary, $this->drafts->boundary($conversation, $actor))) {
            $this->conflict('chat_send_attempt_scope_changed', 'The conversation audience changed. Cancel this attempt and review your draft.');
        }
        $expected = json_decode(Crypt::decryptString($row->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
        $body = ['recoverable_send' => (bool) ($data['recoverable_send'] ?? false), 'mutation_id' => $data['mutation_id'],
            'draft_version' => isset($data['draft_version']) ? (int) $data['draft_version'] : null, 'draft_boundary' => $data['draft_boundary'] ?? null,
            'audience_token' => $data['audience_token'] ?? null, 'content' => $data['content'] ?? '',
            'reply_to_id' => isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
            'mentions' => array_map(fn ($item) => ['user_id' => (int) $item['user_id'], 'start' => (int) $item['start'], 'length' => (int) $item['length']], $data['mentions'] ?? []),
            'upload_ids' => array_values($data['upload_ids'] ?? []), 'captions' => array_values(array_map(fn ($caption) => $caption ?? '', $data['captions'] ?? []))];
        if ($hasFiles || $body !== $expected) {
            $this->conflict('chat_send_attempt_changed', 'The pending message has different details. Retry the original attempt or cancel it before editing.');
        }
    }

    public function confirm(OpsMessage $message): void
    {
        DB::table('chat_send_attempts')->where('actor_id', $message->sender_id)->where('conversation_id', $message->conversation_id)
            ->where('mutation_id', $message->mutation_id)->where('status', 'pending')
            ->update(['status' => 'confirmed', 'message_id' => $message->id, 'encrypted_payload' => null, 'updated_at' => now()]);
    }
}
