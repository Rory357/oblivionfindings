<?php

namespace App\Domain\It\Services;

use App\Domain\It\Enums\ItTicketDraftPurpose as Purpose;
use App\Domain\It\Exceptions\ItTicketDraftException as DraftError;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketDraft;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Staging extends canonical private attachments; durable intents own cleanup. */
final class ItTicketDraftAttachmentService
{
    public function __construct(
        private readonly ItTicketDraftService $drafts,
        private readonly ItAttachmentStorageService $storage,
    ) {}

    public function upload(User $actor, string $uuid, int $expectedRevision, string $uploadUuid, UploadedFile $file): array
    {
        Validator::make(['expected_revision' => $expectedRevision, 'upload_uuid' => $uploadUuid, 'attachment' => $file], [
            'expected_revision' => ['integer', 'min:0'], 'upload_uuid' => ['uuid'],
            'attachment' => ItAttachment::uploadRules(),
        ])->validate();
        $uploadUuid = strtolower($uploadUuid);
        $hash = hash_file('sha256', $file->getPathname());

        // Commit a tracked private path before any physical write. A process
        // crash or uncertain DB acknowledgement can never orphan that path.
        try {
            $reserved = $this->drafts->forAttachmentMutation($actor, $uuid, function (ItTicketDraft $draft, User $current) use ($expectedRevision, $uploadUuid, $file, $hash): ItAttachment {
                $this->assertPurpose($draft);
                $existing = ItAttachment::query()->where('draft_upload_uuid', $uploadUuid)->lockForUpdate()->first();
                if ($existing) {
                    $this->assertOwned($existing, $draft, $current);
                    if (! hash_equals((string) $existing->draft_content_hash, $hash)
                        || $existing->original_name !== $file->getClientOriginalName()) {
                        throw new DraftError('draft_upload_conflict', 409, 'This upload identity already belongs to different file content.');
                    }
                    if (in_array($existing->draft_storage_state, ['cleanup_pending', 'removed'], true)) {
                        throw new DraftError('draft_attachment_removed', 409, 'This staged file was removed. Choose the file again to start a new upload.');
                    }

                    return $existing;
                }
                $this->drafts->assertAttachmentRevision($draft, $current, $expectedRevision);
                if ($this->rows($draft)->whereIn('draft_storage_state', ['reserved', 'ready', 'failed'])->count() >= 5) {
                    throw ValidationException::withMessages(['attachment' => 'Attach no more than five files to this draft.']);
                }
                $attachment = $draft->attachments()->create([
                    'path' => 'it_attachments/'.Str::uuid(), 'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType() ?? 'application/octet-stream', 'size' => $file->getSize() ?: 0, 'uploaded_by' => $current->id,
                    'draft_generation_uuid' => $draft->draft_uuid, 'draft_upload_uuid' => $uploadUuid,
                    'draft_content_hash' => $hash, 'draft_storage_state' => 'reserved',
                ]);
                $this->drafts->recordAttachmentChange($draft, $current, 'file_reserved');

                return $attachment;
            });
        } catch (UniqueConstraintViolationException) {
            throw DraftError::unavailable();
        }

        if ($reserved->draft_storage_state !== 'ready') {
            try {
                // The upload already reached PHP's temporary file. Hold the
                // canonical locks for the bounded private copy, so discard or
                // expiry cleanup cannot race a late write into the same path.
                $this->drafts->forAttachmentMutation($actor, $uuid, function (ItTicketDraft $draft, User $current) use ($reserved, $file): void {
                    $attachment = $this->rows($draft)->whereKey($reserved->id)->lockForUpdate()->first();
                    if (! $attachment) {
                        throw DraftError::unavailable();
                    }
                    $this->assertOwned($attachment, $draft, $current);
                    if ($attachment->draft_storage_state === 'ready') {
                        return;
                    }
                    if (in_array($attachment->draft_storage_state, ['cleanup_pending', 'removed'], true)) {
                        throw new DraftError('draft_attachment_removed', 409, 'This staged file was removed.');
                    }
                    $this->storage->storeReserved($attachment, $file);
                    $attachment->forceFill(['draft_storage_state' => 'ready', 'draft_cleanup_error_code' => null])->save();
                    $this->drafts->recordAttachmentChange($draft, $current, 'file_ready');
                });
            } catch (DraftError $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // Keep the durable intent on every uncertain outcome. Retrying
                // this exact upload UUID checks ready state before writing again.
                Log::warning('IT staged attachment write was not confirmed', ['exception_type' => $exception::class]);
                throw new DraftError('draft_upload_unconfirmed', 503, 'The file result could not be confirmed. Keep the selected file and retry the same upload.');
            }
        }

        return ['draft' => $this->drafts->inspect($actor, $uuid), 'attachment' => $this->present($reserved->fresh())];
    }

    public function remove(User $actor, string $uuid, int $expectedRevision, int $attachmentId): array
    {
        $draftId = $this->drafts->forAttachmentMutation($actor, $uuid, function (ItTicketDraft $draft, User $current) use ($expectedRevision, $attachmentId): int {
            $attachment = $this->rows($draft)->whereKey($attachmentId)->lockForUpdate()->first();
            if (! $attachment) {
                throw DraftError::unavailable();
            }
            $this->assertOwned($attachment, $draft, $current);
            if (in_array($attachment->draft_storage_state, ['cleanup_pending', 'removed'], true)) {
                return (int) $draft->id;
            }
            $this->drafts->assertAttachmentRevision($draft, $current, $expectedRevision);
            $attachment->forceFill(['draft_storage_state' => 'cleanup_pending'])->save();
            $this->drafts->recordAttachmentChange($draft, $current, 'file_removed');

            return (int) $draft->id;
        });
        $cleanup = $this->cleanupGeneration($draftId, $uuid);

        return ['draft' => $this->drafts->inspect($actor, $uuid), 'removed_attachment_id' => $attachmentId, 'cleanup' => $cleanup];
    }

    /** Returns current authorized file metadata only for explicit resume. */
    public function forResume(ItTicketDraft $draft): array
    {
        return $this->rows($draft)->whereNotIn('draft_storage_state', ['cleanup_pending', 'removed'])->orderBy('id')->get()
            ->map(fn (ItAttachment $file): array => $this->present($file))->all();
    }

    /** Current owner, generation, audience and readiness are mandatory. */
    public function authorizeDownload(User $actor, ItAttachment $attachment): void
    {
        $generation = $attachment->draft_generation_uuid;
        if (! is_string($generation)) {
            throw DraftError::unavailable();
        }
        $this->drafts->forAttachmentMutation($actor, $generation, function (ItTicketDraft $draft, User $current) use ($attachment): void {
            $fresh = $this->rows($draft)->whereKey($attachment->id)->first();
            if (! $fresh || $fresh->draft_storage_state !== 'ready') {
                throw DraftError::unavailable();
            }
            $this->assertOwned($fresh, $draft, $current);
        }, write: false);
    }

    /** Caller holds Ticket/User/draft; rows move in the same business commit. */
    public function transfer(ItTicketDraft $draft, ItTicket|ItTicketComment|null $target): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Staged attachments must transfer inside the canonical commit transaction.');
        }
        $files = $this->rows($draft)->whereNotIn('draft_storage_state', ['cleanup_pending', 'removed'])->lockForUpdate()->get();
        if ($files->isEmpty()) {
            return;
        }
        $validTarget = match ($draft->purpose) {
            Purpose::RequesterIntake, Purpose::TechnicianIntake => $target instanceof ItTicket,
            Purpose::PublicReply => $target instanceof ItTicketComment && ! $target->is_internal && (int) $target->ticket_id === (int) $draft->it_ticket_id,
            Purpose::InternalNote => $target instanceof ItTicketComment && $target->is_internal && (int) $target->ticket_id === (int) $draft->it_ticket_id,
            default => false,
        };
        if (! $validTarget || $files->contains(fn (ItAttachment $file): bool => $file->draft_storage_state !== 'ready')) {
            throw new DraftError('draft_files_not_ready', 409, 'Finish or remove the staged files before submitting this draft.');
        }
        if ($target->attachments()->count() + $files->count() > 5) {
            throw ValidationException::withMessages(['attachments' => 'Attach no more than five files to this submission.']);
        }
        foreach ($files as $file) {
            $file->attachable()->associate($target);
            $file->forceFill(['draft_generation_uuid' => null, 'draft_storage_state' => null])->save();
        }
    }

    /**
     * Only exact draft-owned rows are deleted. Transfer and writes share the
     * draft lock; failed physical cleanup retains the record for the next run.
     *
     * @return array{deleted: int, failed: int}
     */
    public function cleanupGeneration(int $draftId, string $generation): array
    {
        if (! $this->drafts->enabled()) {
            return ['deleted' => 0, 'failed' => 0];
        }

        return DB::transaction(function () use ($draftId, $generation): array {
            $draft = ItTicketDraft::query()->whereKey($draftId)->lockForUpdate()->first();
            if (! $draft) {
                return ['deleted' => 0, 'failed' => 0];
            }
            $rows = $draft->attachments()->where('draft_generation_uuid', $generation);
            if ($draft->draft_uuid === $generation && $draft->state === 'active' && ! $draft->isExpired()) {
                $rows->where('draft_storage_state', 'cleanup_pending');
            }
            $result = ['deleted' => 0, 'failed' => 0];
            foreach ($rows->lockForUpdate()->get() as $file) {
                try {
                    if (Storage::disk(ItAttachment::DISK)->exists($file->path)
                        && ! Storage::disk(ItAttachment::DISK)->delete($file->path)) {
                        throw new LogicException('Private staged file cleanup was not confirmed.');
                    }
                    if ($draft->draft_uuid === $generation && $draft->state === 'active' && ! $draft->isExpired()) {
                        // Retain an upload-identity tombstone: a delayed upload
                        // retry cannot recreate a file explicitly removed here.
                        $file->forceFill(['draft_storage_state' => 'removed', 'draft_cleanup_error_code' => null])->save();
                    } else {
                        $file->delete();
                    }
                    $result['deleted']++;
                } catch (Throwable) {
                    $file->forceFill([
                        'draft_storage_state' => 'cleanup_pending',
                        'draft_cleanup_attempts' => (int) $file->draft_cleanup_attempts + 1,
                        'draft_cleanup_error_code' => 'storage_delete_failed',
                    ])->save();
                    $result['failed']++;
                }
            }

            return $result;
        });
    }

    private function rows(ItTicketDraft $draft)
    {
        return $draft->attachments()->where('draft_generation_uuid', $draft->draft_uuid);
    }

    private function assertOwned(ItAttachment $file, ItTicketDraft $draft, User $actor): void
    {
        if ($file->attachable_type !== $draft->getMorphClass() || (int) $file->attachable_id !== (int) $draft->id
            || $file->draft_generation_uuid !== $draft->draft_uuid || (int) $file->uploaded_by !== (int) $actor->id) {
            throw DraftError::unavailable();
        }
    }

    private function assertPurpose(ItTicketDraft $draft): void
    {
        if (! in_array($draft->purpose, [Purpose::RequesterIntake, Purpose::TechnicianIntake, Purpose::PublicReply, Purpose::InternalNote], true)) {
            throw new DraftError('draft_files_unsupported', 422, 'This form does not accept attachments.');
        }
    }

    private function present(ItAttachment $file): array
    {
        return [
            'id' => (int) $file->id, 'upload_uuid' => $file->draft_upload_uuid, 'name' => $file->original_name,
            'mime' => $file->mime, 'size' => (int) $file->size, 'state' => $file->draft_storage_state,
            'download_url' => $file->draft_storage_state === 'ready' ? route('it.attachments.download', $file, false) : null,
        ];
    }
}
