<?php

namespace App\Services\Chat;

use App\Models\ChatUpload;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ChatUploads
{
    public function __construct(private ChatAccess $access, private ChatSettings $settings, private ChatFileSafety $safety, private ChatVoiceInspector $voice) {}

    private function boundary(OpsConversation $conversation, User $actor): string
    {
        $member = $conversation->participants()->where('user_id', $actor->id)->first();

        return hash('sha256', json_encode([$conversation->id, $conversation->client_id, $conversation->channel_site_id, $actor->id, $member?->id, $member?->history_after_id], JSON_THROW_ON_ERROR));
    }

    public function authorize(ChatUpload $upload, OpsConversation $conversation, User $actor): void
    {
        $this->access->authorize($actor, $conversation, true);
        abort_unless((int) $upload->conversation_id === (int) $conversation->id && (int) $upload->actor_id === (int) $actor->id, 403);
        abort_unless(hash_equals($upload->access_boundary, $this->boundary($conversation, $actor)), 403);
        if ($upload->message_id) {
            $this->access->message($actor, OpsMessage::withTrashed()->findOrFail($upload->message_id), true);
        }
    }

    private function writable(OpsConversation $conversation, User $actor, string $kind = 'file'): array
    {
        $this->access->authorize($actor, $conversation, true);
        $policy = $this->settings->forConversation($conversation);
        abort_unless($policy['enabled'] && $policy['files_enabled'] && ($conversation->conversation_type !== 'family' || $policy['family_enabled']), 403, 'File sharing is unavailable in this conversation.');
        abort_if($kind === 'voice_note' && ! $policy['voice_notes_enabled'], 403, 'Voice messages are unavailable in this conversation.');
        abort_if($conversation->is_archived || $conversation->participants()->where('user_id', $actor->id)->where('is_archived_for_user', true)->exists(), 409, 'This conversation is read-only.');

        return $policy;
    }

    public function store(OpsConversation $conversation, User $actor, string $id, UploadedFile $file, string $kind = 'file'): ChatUpload
    {
        abort_unless(in_array($kind, ['file', 'voice_note'], true), 422);
        $path = null;
        try {
            [$upload, $created] = DB::transaction(function () use ($conversation, $actor, $id, $file, $kind, &$path) {
                $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, $locked, true);
                $hash = hash_file('sha256', $file->getRealPath());
                $existing = ChatUpload::whereKey($id)->lockForUpdate()->first();
                if ($existing) {
                    $this->authorize($existing, $locked, $actor);
                    if ($existing->state === 'discarded') {
                        return [$existing, false];
                    }
                    abort_unless($existing->media_kind === $kind && hash_equals($existing->sha256, $hash) && $existing->name === mb_substr(basename($file->getClientOriginalName()), 0, 255), 409, 'This upload reference belongs to a different file.');

                    return [$existing, false];
                }
                $policy = $this->writable($locked, $actor, $kind);
                abort_if($file->getSize() > $policy['max_file_mb'] * 1024 * 1024, 422, 'This file exceeds the current size limit.');
                abort_if(ChatUpload::where('actor_id', $actor->id)->where('conversation_id', $locked->id)->where('access_boundary', $this->boundary($locked, $actor))->whereNotIn('state', ['consumed', 'discarded'])->count() >= 20, 422, 'Remove unused uploads in this conversation before adding more files.');
                $path = $file->store('chat-quarantine/'.$locked->id, 'local');
                abort_unless(is_string($path), 503, 'The file could not be staged.');
                abort_unless(hash_equals($hash, hash_file('sha256', Storage::disk('local')->path($path))), 422, 'The file changed during upload.');
                $upload = ChatUpload::create(['id' => $id, 'actor_id' => $actor->id, 'conversation_id' => $locked->id,
                    'access_boundary' => $this->boundary($locked, $actor), 'state' => 'uploaded', 'storage_path' => $path,
                    'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(), 'sha256' => $hash, 'media_kind' => $kind]);

                return [$upload, true];
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return $created ? $this->scan($conversation, $actor, $upload->id) : $upload;
    }

    public function scan(OpsConversation $conversation, User $actor, string $id): ChatUpload
    {
        [$upload, $token] = DB::transaction(function () use ($conversation, $actor, $id) {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $upload = ChatUpload::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorize($upload, $locked, $actor);
            if (in_array($upload->state, ['ready', 'consumed', 'blocked', 'changed', 'discarded'], true)) {
                return [$upload, null];
            }
            $this->writable($locked, $actor, $upload->media_kind);
            // A crashed scan may be retried explicitly after its bounded process timeout.
            if ($upload->state === 'scanning' && $upload->scan_started_at?->gt(now()->subSeconds(180))) {
                return [$upload, null];
            }
            $token = (string) Str::uuid();
            $upload->update(['state' => 'scanning', 'scan_token' => $token, 'scan_started_at' => now(), 'error_code' => null]);

            return [$upload, $token];
        });
        if ($token === null) {
            return $upload;
        }
        $path = $upload->storage_path ? Storage::disk('local')->path($upload->storage_path) : '';
        $result = $this->safety->inspect($path);
        $state = $result->state === 'clean' ? 'ready' : $result->state;
        if ($state === 'ready' && ! hash_equals($upload->sha256, $result->sha256)) {
            $state = 'changed';
        }
        $voice = null;
        if ($state === 'ready' && $upload->media_kind === 'voice_note') {
            $voice = $this->voice->inspect($path);
            $state = $voice->state;
            if (! $result->verifies($path)) {
                $state = 'changed';
            }
        }

        return DB::transaction(function () use ($conversation, $actor, $upload, $token, $result, $state, $voice) {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $current = ChatUpload::whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $this->authorize($current, $locked, $actor);
            if ($current->state === 'scanning' && $current->scan_token === $token) {
                $current->update(['state' => $state, 'scan_token' => null, 'scanned_at' => now(),
                    'duration_ms' => $state === 'ready' ? $voice?->durationMs : null,
                    'mime_type' => $state === 'ready' && $voice ? $voice->mime : $current->mime_type,
                    'error_code' => $state === 'changed' ? 'file_changed' : ($voice?->errorCode ?? $result->errorCode)]);
            }

            return $current;
        });
    }

    public function discard(OpsConversation $conversation, User $actor, string $id): ChatUpload
    {
        $upload = DB::transaction(function () use ($conversation, $actor, $id) {
            $locked = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $locked, true);
            $upload = ChatUpload::whereKey($id)->lockForUpdate()->first();
            if (! $upload) {
                // A removal receipt also cancels an upload whose request has not arrived yet.
                return ChatUpload::create(['id' => $id, 'conversation_id' => $locked->id, 'actor_id' => $actor->id,
                    'access_boundary' => $this->boundary($locked, $actor), 'state' => 'discarded',
                    'name' => '', 'mime_type' => 'application/octet-stream', 'size_bytes' => 0, 'sha256' => hash('sha256', '')]);
            }
            $this->authorize($upload, $locked, $actor);
            abort_if($upload->state === 'consumed', 409, 'This file already belongs to a sent message.');
            $upload->update(['state' => 'discarded', 'scan_token' => null]);

            return $upload;
        });
        if ($upload->storage_path) {
            abort_unless(Storage::disk('local')->delete($upload->storage_path), 503, 'Removal could not be confirmed. Retry removal.');
            $upload->update(['storage_path' => null]);
        }

        return $upload;
    }

    /** Called only within the message transaction, with its conversation row locked. */
    public function attach(OpsConversation $conversation, User $actor, OpsMessage $message, array $ids, array $captions, array &$createdPaths): void
    {
        $policy = $this->writable($conversation, $actor);
        abort_if(count($ids) > $policy['max_files'], 422, 'Remove extra files before sending.');
        foreach ($ids as $index => $id) {
            $upload = ChatUpload::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorize($upload, $conversation, $actor);
            $this->writable($conversation, $actor, $upload->media_kind);
            abort_unless($upload->state === 'ready' && $upload->storage_path, 409, 'Finish checking every file before sending.');
            abort_if($upload->size_bytes > $policy['max_file_mb'] * 1024 * 1024, 422, 'A file exceeds the current size limit.');
            $verdict = new ChatFileScan('clean', $upload->sha256);
            abort_unless($verdict->verifies(Storage::disk('local')->path($upload->storage_path)), 422, 'A staged file changed. Remove it and choose the original again.');
            $path = 'chat/'.$conversation->id.'/'.Str::uuid();
            $createdPaths[] = $path;
            abort_unless(Storage::disk('local')->copy($upload->storage_path, $path), 503, 'The checked file could not be attached. Retry sending.');
            abort_unless($verdict->verifies(Storage::disk('local')->path($path)), 422, 'A file changed while being attached.');
            $caption = trim($captions[$index] ?? '');
            DB::table('chat_attachments')->insert(['message_id' => $message->id, 'storage_path' => $path,
                'name' => $upload->name, 'mime_type' => $upload->mime_type, 'size_bytes' => $upload->size_bytes,
                'caption' => $caption === '' ? null : $caption, 'media_kind' => $upload->media_kind,
                'duration_ms' => $upload->duration_ms, 'media_sha256' => $upload->sha256, 'created_at' => now(), 'updated_at' => now()]);
            $upload->update(['state' => 'consumed', 'message_id' => $message->id, 'scan_token' => null]);
            $quarantinePath = $upload->storage_path;
            DB::afterCommit(function () use ($upload, $quarantinePath) {
                try {
                    // Only the temporary copy is removed; the verified message copy is independent.
                    if (Storage::disk('local')->delete($quarantinePath)) {
                        ChatUpload::whereKey($upload->id)->where('state', 'consumed')->where('storage_path', $quarantinePath)->update(['storage_path' => null]);
                    }
                } catch (\Throwable $error) {
                    report($error); // Cleanup failure cannot revoke the committed acknowledgement.
                }
            });
        }
    }

    public function current(OpsConversation $conversation, User $actor): array
    {
        $this->access->authorize($actor, $conversation, true);

        return ChatUpload::where('actor_id', $actor->id)->where('conversation_id', $conversation->id)
            ->where('access_boundary', $this->boundary($conversation, $actor))->whereNotIn('state', ['consumed', 'discarded'])
            ->orderByDesc('created_at')->limit(20)->get()->map(fn (ChatUpload $upload) => $this->project($upload))->all();
    }

    public function project(ChatUpload $upload): array
    {
        return ['id' => $upload->id, 'conversation_id' => (int) $upload->conversation_id, 'state' => $upload->state,
            'media_kind' => $upload->media_kind, 'duration_ms' => $upload->duration_ms, 'error_code' => $upload->error_code,
            'name' => in_array($upload->state, ['discarded', 'consumed'], true) ? null : $upload->name,
            'mime_type' => in_array($upload->state, ['discarded', 'consumed'], true) ? null : $upload->mime_type,
            'size_bytes' => $upload->size_bytes, 'message_id' => $upload->message_id ? (int) $upload->message_id : null,
            'retry_available' => in_array($upload->state, ['uploaded', 'unavailable'], true)
                || ($upload->state === 'scanning' && $upload->scan_started_at?->lte(now()->subSeconds(180))),
            'scanned_at' => $upload->scanned_at?->toIso8601String()];
    }
}
