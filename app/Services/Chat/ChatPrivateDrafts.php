<?php

namespace App\Services\Chat;

use App\Models\ChatUpload;
use App\Models\OpsConversation;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Private author workspace. Never part of conversation history or notification delivery. */
final class ChatPrivateDrafts
{
    public function __construct(private ChatAccess $access, private ChatSettings $settings) {}

    public function boundary(OpsConversation $conversation, User $actor): string
    {
        $members = $conversation->members()->orderBy('user_id')->get(['id', 'user_id', 'role', 'history_after_id']);
        $self = $members->firstWhere('user_id', $actor->id);

        return hash('sha256', json_encode([1, $actor->id, $conversation->id, $conversation->conversation_type,
            $conversation->client_id, $conversation->client?->site_id, $conversation->channel_site_id,
            (bool) $conversation->care_team_cover, $conversation->assigned_to,
            $self?->id, $self?->history_after_id,
            $members->map(fn ($member) => [$member->id, $member->user_id, $member->role])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function authorized(OpsConversation $conversation, User $actor, bool $write): void
    {
        $this->access->authorize($actor, $conversation, $write);
        $policy = $this->settings->forConversation($conversation);
        abort_unless($policy['enabled'] && ($conversation->conversation_type !== 'family' || $policy['family_enabled']), 403);
        if ($write) {
            abort_if($conversation->is_archived || $conversation->participants()->where('user_id', $actor->id)->where('is_archived_for_user', true)->exists(), 409, 'This conversation is read-only.');
        }
    }

    private function row(OpsConversation $conversation, User $actor): ?object
    {
        return DB::table('chat_private_drafts')->where('conversation_id', $conversation->id)->where('actor_id', $actor->id)->first();
    }

    /** Read and write share the conversation lock with membership and message changes. */
    public function read(OpsConversation $conversation, User $actor): array
    {
        return DB::transaction(function () use ($conversation, $actor): array {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->authorized($locked, $actor, false);

            return $this->project($locked, $actor, $this->row($locked, $actor));
        });
    }

    private function project(OpsConversation $conversation, User $actor, ?object $row): array
    {
        $boundary = $this->boundary($conversation, $actor);
        $result = ['conversation_id' => $conversation->id, 'actor_id' => $actor->id,
            'client_id' => $conversation->client_id, 'boundary' => $boundary,
            'version' => (int) ($row?->version ?? 0), 'updated_at' => $row?->updated_at,
            'state' => 'empty', 'draft' => null, 'pending_send' => app(ChatSendAttempts::class)->pending($conversation, $actor)];
        if (! $row?->encrypted_payload) {
            return $result;
        }
        if (! hash_equals($row->boundary, $boundary)) {
            // Do not expose an earlier membership/history/client context as a current draft.
            return array_replace($result, ['state' => 'scope_changed']);
        }
        $payload = json_decode(Crypt::decryptString($row->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);

        return array_replace($result, ['state' => 'saved', 'draft' => $payload]);
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409, ['Cache-Control' => 'private, no-store']));
    }

    /** A null payload deliberately clears a reviewed version, leaving a version tombstone. */
    public function save(OpsConversation $conversation, User $actor, array $input): array
    {
        return DB::transaction(function () use ($conversation, $actor, $input): array {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->authorized($locked, $actor, true);
            $boundary = $this->boundary($locked, $actor);
            if (! hash_equals($boundary, $input['boundary'])) {
                $this->conflict('chat_draft_scope_changed', 'The conversation audience changed. Review it before restoring or saving your draft.');
            }
            // Keyed receipts avoid exposing dictionary-testable hashes of short private drafts.
            $hash = hash_hmac('sha256', json_encode([$locked->id, $input['version'], $input['boundary'], $input['draft']], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $receipt = DB::table('chat_private_draft_commands')->where('actor_id', $actor->id)->where('mutation_id', $input['mutation_id'])->first();
            if ($receipt) {
                if ((int) $receipt->conversation_id !== (int) $locked->id || ! hash_equals($receipt->payload_hash, $hash)) {
                    $this->conflict('chat_draft_command_changed', 'This draft save has different details. Check the original save before retrying.');
                }

                return $this->project($locked, $actor, $this->row($locked, $actor)) + ['applied_version' => (int) $receipt->applied_version];
            }
            $row = $this->row($locked, $actor);
            if ((int) ($row?->version ?? 0) !== $input['version']) {
                $this->conflict('chat_draft_version_changed', 'A newer draft is saved. Review it before replacing anything.');
            }
            if ($row?->encrypted_payload && ! hash_equals($row->boundary, $boundary) && $input['draft'] !== null) {
                $this->conflict('chat_draft_scope_changed', 'The saved draft belongs to an earlier audience. Discard it explicitly before starting a replacement.');
            }
            $payload = $input['draft'] === null ? null : $this->validatePayload($locked, $actor, $input['draft']);
            $version = $input['version'] + 1;
            DB::table('chat_private_drafts')->updateOrInsert(['actor_id' => $actor->id, 'conversation_id' => $locked->id], [
                'boundary' => $boundary, 'version' => $version,
                'encrypted_payload' => $payload === null ? null : Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'created_at' => $row?->created_at ?? now(), 'updated_at' => now(),
            ]);
            DB::table('chat_private_draft_commands')->insert(['actor_id' => $actor->id, 'conversation_id' => $locked->id,
                'mutation_id' => $input['mutation_id'], 'payload_hash' => $hash, 'applied_version' => $version, 'created_at' => now()]);

            return $this->project($locked, $actor, $this->row($locked, $actor)) + ['applied_version' => $version];
        });
    }

    private function validatePayload(OpsConversation $conversation, User $actor, array $draft): array
    {
        if ($draft['reply_to_id'] !== null) {
            abort_unless($this->access->messages($conversation, $actor)->whereKey($draft['reply_to_id'])->exists(), 422, 'The replied-to message is no longer available. Your draft has not been replaced.');
        }
        app(ChatMentions::class)->prepare($conversation, $actor, $draft['content'], $draft['mentions']);
        foreach ($draft['uploads'] as $item) {
            $upload = ChatUpload::find($item['id']);
            abort_unless($upload, 422, 'An attachment is no longer available. Remove it or choose the original file again. Your saved draft has not been replaced.');
            app(ChatUploads::class)->authorize($upload, $conversation, $actor);
            abort_if($upload->message_id || in_array($upload->state, ['discarded', 'consumed'], true), 422, 'An attachment is no longer available for this draft.');
        }

        return $draft;
    }

    /** Invoke within the successful send transaction; an older send never clears a newer draft. */
    public function clearSent(OpsConversation $conversation, User $actor, int $version, string $boundary): bool
    {
        if (! hash_equals($this->boundary($conversation, $actor), $boundary)) {
            return false;
        }

        return DB::table('chat_private_drafts')->where('actor_id', $actor->id)->where('conversation_id', $conversation->id)
            ->where('version', $version)->where('boundary', $boundary)->update([
                'encrypted_payload' => null, 'version' => $version + 1, 'updated_at' => now(),
            ]) === 1;
    }
}
