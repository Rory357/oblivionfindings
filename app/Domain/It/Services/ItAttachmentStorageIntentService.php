<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItAttachmentStorageReservation;
use App\Domain\It\Data\ItScannedEmailAttachment;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Durable mechanical cleanup, independent of draft/scanner/retention policy. */
final class ItAttachmentStorageIntentService
{
    /** @return array<int, ItAttachmentStorageReservation> */
    public function reserveDirect(ItTicket|ItTicketComment $parent, array $files, User $actor): array
    {
        $files = array_values($files);
        $this->validateFiles($files);
        $this->assertParent($parent, $actor);
        $fingerprints = array_map(fn (UploadedFile $file): array => [...$this->fingerprint($file),
            ...($file instanceof ItScannedEmailAttachment ? $file->recoveryOwnership() : [])], $files);
        if ($files === []) {
            return [];
        }
        if (! Schema::hasTable('it_attachment_storage_intents')) {
            throw ValidationException::withMessages([
                'attachments' => 'File uploads are unavailable until IT attachment storage setup is complete. Keep your work and retry later, or remove the files to submit without them.',
            ]);
        }
        if (array_filter($fingerprints, fn ($row) => isset($row['inbound_email_id'])) !== []
            && ! Schema::hasColumn('it_attachment_storage_intents', 'inbound_email_id')) {
            throw ValidationException::withMessages(['attachments' => 'Mailbox file recovery setup is incomplete. Keep the original files and retry after the database upgrade.']);
        }

        // Never a nested transaction: these reservations must survive the
        // caller's later rollback. No FK or actor/parent lookup can wait on it.
        return $this->independentPrimary(fn (): array => DB::transaction(function () use ($parent, $actor, $fingerprints): array {
            $reserved = [];
            foreach ($fingerprints as $fingerprint) {
                $uuid = (string) Str::uuid();
                $intent = ItAttachmentStorageIntent::query()->create([
                    ...$fingerprint, 'intent_uuid' => $uuid, 'path' => 'it_attachments/'.$uuid,
                    'actor_user_id' => (int) $actor->id, 'parent_type' => $parent->getMorphClass(),
                    'parent_id' => (int) $parent->id, 'state' => 'reserved',
                ]);
                $reserved[] = new ItAttachmentStorageReservation((int) $intent->id, $uuid, $intent->path);
            }

            return $reserved;
        }));
    }

    /** Caller holds its business locks; intent rows are then locked by ascending ID. */
    public function storeReservedDirect(ItTicket|ItTicketComment $parent, array $files, User $actor, array $reservations, array &$storedPaths): void
    {
        $files = array_values($files);
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Reserved attachments require the canonical business transaction.');
        }
        $this->validateFiles($files);
        $this->assertParent($parent, $actor);
        if (count($files) !== count($reservations)) {
            throw new DomainException('The attachment reservation does not match this submission.');
        }
        if ($parent->attachments()->count() + count($files) > 5) {
            throw new DomainException('Attach no more than five files to this submission.');
        }
        if ($files === []) {
            return;
        }
        $references = $this->references($reservations);
        $rows = ItAttachmentStorageIntent::query()->whereIn('id', array_keys($references))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $fingerprints = array_map(fn (UploadedFile $file): array => $this->fingerprint($file), $files);
        // Validate the entire batch under its locks before the first byte copy.
        foreach (array_values($reservations) as $index => $reference) {
            $intent = $rows->get($reference->id);
            $this->assertReference($intent, $reference);
            if ($intent->state !== 'reserved' || $intent->attachment_id !== null
                || $intent->actor_user_id !== (int) $actor->id
                || $intent->parent_type !== $parent->getMorphClass() || $intent->parent_id !== (int) $parent->id
                || $intent->size !== $fingerprints[$index]['size']
                || ! hash_equals($intent->content_sha256, $fingerprints[$index]['content_sha256'])
                || ! hash_equals($intent->original_name_sha256, $fingerprints[$index]['original_name_sha256'])) {
                throw new DomainException('This attachment reservation is no longer available for this submission.');
            }
        }
        foreach (array_values($reservations) as $index => $reference) {
            $intent = $rows->get($reference->id);
            $file = array_values($files)[$index];
            $scanEvidence = $file instanceof ItScannedEmailAttachment ? $file->scanEvidence() : [];
            $storedPaths[] = $intent->path;
            $written = Storage::disk(ItAttachment::DISK)->putFileAs('it_attachments', $file, basename($intent->path));
            if ($written !== $intent->path) {
                throw new DomainException('The attachment could not be stored. Keep the original submission and try again.');
            }
            if ($file instanceof ItScannedEmailAttachment
                && ! hash_equals($file->contentHash, (string) hash_file('sha256', Storage::disk(ItAttachment::DISK)->path($intent->path)))) {
                throw new DomainException('The stored email attachment did not match its clean scan.');
            }
            $attachment = $parent->attachments()->create([
                'path' => $intent->path, 'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(), 'size' => $intent->size, 'uploaded_by' => $actor->id,
            ]);
            if ($scanEvidence !== []) {
                $attachment->forceFill($scanEvidence)->save();
            }
            $intent->forceFill(['state' => 'attached', 'attachment_id' => $attachment->id, 'revision' => $intent->revision + 1])->save();
        }
    }

    /**
     * Call only after the caller proves rollback, never merely after an exception.
     * This cannot replace the original business failure, even if storage/DB is down.
     *
     * @return array{requested:int,deleted:int,failed:int,deferred:int,reconciliation_required:int}
     */
    public function requestRollbackCleanup(array $reservations): array
    {
        $result = $this->emptyResult();
        if ($reservations === []) {
            return $result;
        }
        try {
            $references = $this->references($reservations);
            // InnoDB may retain row locks after rollback-to-savepoint. A second
            // connection here would wait on our own still-open transaction.
            if (DB::transactionLevel() > 0 || DB::connection()->getPdo()->inTransaction()) {
                $result['deferred'] = count($references);

                return $result;
            }
            foreach ($references as $reference) {
                try {
                    $ready = $this->independentPrimary(fn (): bool => DB::transaction(function () use ($reference): bool {
                        $intent = ItAttachmentStorageIntent::query()->whereKey($reference->id)->lockForUpdate()->first();
                        $this->assertReference($intent, $reference);
                        if ($intent->state === 'deleted' || $intent->state === 'cleanup_pending') {
                            return true;
                        }
                        if ($intent->state !== 'reserved' || $intent->attachment_id !== null
                            || ItAttachment::query()->where('path', $intent->path)->exists()) {
                            return false;
                        }
                        $intent->forceFill([
                            'state' => 'cleanup_pending', 'cleanup_requested_at' => now(),
                            'revision' => $intent->revision + 1,
                        ])->save();
                        AuditLogger::logOrFail('it.attachment.cleanup.requested', $intent, [
                            'intent_uuid' => $intent->intent_uuid, 'original_actor_user_id' => $intent->actor_user_id,
                            'reason' => 'confirmed_rollback',
                        ], systemActor: true);

                        return true;
                    }));
                    if (! $ready) {
                        $result['reconciliation_required']++;

                        continue;
                    }
                    $result['requested']++;
                    $outcome = $this->retryOne($reference);
                    $result[$outcome]++;
                } catch (Throwable) {
                    $result['failed']++;
                }
            }
        } catch (Throwable) {
            $result['failed'] = max(1, count($reservations));
        }

        return $result;
    }

    /** Only explicit pending intent is retryable; reserved/unknown bytes never expire here. */
    public function retryPending(int $limit = 100, ?array $onlyIds = null): array
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() > 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('Cleanup requires a bounded run outside an existing transaction.');
        }
        if ($onlyIds !== null && (! array_is_list($onlyIds) || count($onlyIds) > $limit
            || array_filter($onlyIds, fn ($id) => ! is_int($id) || $id < 1) !== []
            || count(array_unique($onlyIds)) !== count($onlyIds))) {
            throw new LogicException('Invalid cleanup batch selection.');
        }
        $result = $this->emptyResult();
        $rows = ItAttachmentStorageIntent::query()->useWritePdo()->where('state', 'cleanup_pending')
            ->when($onlyIds !== null, fn ($query) => $query->whereIn('id', $onlyIds))
            ->orderBy('last_cleanup_attempt_at')->orderBy('cleanup_attempts')->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            $outcome = $this->retryOne(new ItAttachmentStorageReservation((int) $row->id, $row->intent_uuid, $row->path));
            $result['requested']++;
            $result[$outcome]++;
        }

        return $result;
    }

    /** Candidates only; the current writer and canonical records are locked again before classification. */
    public function abandonedMailboxQuery(): Builder
    {
        return ItAttachmentStorageIntent::query()->useWritePdo()->where('state', 'reserved')
            ->whereNotNull('inbound_email_id')->whereNotNull('mailbox_claim_token')
            ->whereNotNull('mailbox_connection_id')->whereNotNull('source_inbound_attachment_id')->where('mailbox_configuration_version', '>', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('it_mailbox_connections as writer')
                ->whereColumn('writer.id', 'it_attachment_storage_intents.mailbox_connection_id')
                ->whereColumn('writer.configuration_version', 'it_attachment_storage_intents.mailbox_configuration_version')
                ->whereColumn('writer.poll_claim_token', 'it_attachment_storage_intents.mailbox_claim_token')
                ->whereIn('writer.status', ['connected', 'error'])->where('writer.poll_claim_expires_at', '>', now()));
    }

    /** A selected reservation consumes one shared cleanup slot, including deferred or failed classification. */
    public function retryAbandonedMailboxReservations(array $ids): array
    {
        if (! array_is_list($ids) || count($ids) > 1000 || count(array_unique($ids)) !== count($ids)
            || array_filter($ids, fn ($id) => ! is_int($id) || $id < 1) !== []
            || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('Mailbox reconciliation requires a bounded batch outside an existing transaction.');
        }
        $result = $this->emptyResult();
        foreach ($ids as $id) {
            $result['requested']++;
            try {
                $row = ItAttachmentStorageIntent::query()->useWritePdo()->findOrFail($id);
                $reference = new ItAttachmentStorageReservation((int) $row->id, $row->intent_uuid, $row->path);
                $outcome = $this->classifyInterruptedMailbox($reference);
                $result[$outcome === 'ready' ? $this->retryOne($reference) : $outcome]++;
            } catch (Throwable) {
                try {
                    $this->independentPrimary(fn () => DB::transaction(function () use ($id): void {
                        $current = ItAttachmentStorageIntent::query()->whereKey($id)->lockForUpdate()->first();
                        if ($current?->state === 'reserved' && $current->inbound_email_id !== null) {
                            $current->forceFill(['last_cleanup_attempt_at' => now(), 'cleanup_attempts' => min(1000000, $current->cleanup_attempts + 1),
                                'cleanup_error_code' => 'mailbox_reconciliation_failed'])->save();
                        }
                    }));
                } catch (Throwable) {
                    // Keep failure truthful when its retry metadata is also unavailable.
                }
                $result['failed']++;
            }
        }

        return $result;
    }

    private function classifyInterruptedMailbox(ItAttachmentStorageReservation $reference): string
    {
        return $this->independentPrimary(fn () => DB::transaction(function () use ($reference): string {
            $candidate = ItAttachmentStorageIntent::query()->findOrFail($reference->id);
            $this->assertReference($candidate, $reference);
            if ($candidate->state !== 'reserved' || $candidate->mailbox_connection_id === null
                || $candidate->inbound_email_id === null || $candidate->source_inbound_attachment_id === null
                || $candidate->mailbox_configuration_version < 1 || ! Str::isUuid((string) $candidate->mailbox_claim_token)) {
                return 'deferred';
            }
            // Writers hold this same row throughout canonical file copying and commit.
            // An expired timestamp alone never authorizes deletion of an in-flight copy.
            $connection = ItMailboxConnection::query()->whereKey($candidate->mailbox_connection_id)->lockForUpdate()->first();
            $sameClaim = $connection && $connection->poll_claim_token === $candidate->mailbox_claim_token
                && $connection->configuration_version === $candidate->mailbox_configuration_version;
            if ($sameClaim && in_array($connection->status, ['connected', 'error'], true)
                && $connection->poll_claim_expires_at?->isFuture()) {
                return 'deferred';
            }
            $receipt = ItInboundEmail::query()->whereKey($candidate->inbound_email_id)->lockForUpdate()->first();
            $source = $receipt?->attachments()->whereKey($candidate->source_inbound_attachment_id)->lockForUpdate()->first();
            $intent = ItAttachmentStorageIntent::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $this->assertReference($intent, $reference);
            if ($intent->state !== 'reserved') {
                return 'deferred';
            }
            foreach (['inbound_email_id', 'source_inbound_attachment_id', 'mailbox_connection_id', 'mailbox_configuration_version', 'mailbox_claim_token'] as $field) {
                if ($intent->{$field} !== $candidate->{$field}) {
                    return 'reconciliation_required';
                }
            }
            if (! $source || $source->path === $intent->path || $intent->attachment_id !== null
                || ItAttachment::query()->where('path', $intent->path)->lockForUpdate()->first(['id']) !== null
                || $source->size !== $intent->size || ! is_string($source->inbound_content_hash)
                || ! hash_equals($intent->content_sha256, $source->inbound_content_hash)
                || ! hash_equals($intent->original_name_sha256, hash('sha256', $source->original_name))) {
                $intent->forceFill(['state' => 'reconciliation_required', 'cleanup_error_code' => 'mailbox_ownership_unproven',
                    'revision' => $intent->revision + 1])->save();
                AuditLogger::logOrFail('it.attachment.reconciliation.required', $intent, ['reason' => 'mailbox_ownership_unproven'], systemActor: true);

                return 'reconciliation_required';
            }
            if ($sameClaim) {
                // Revoke the expired capability under its lock. A surviving old process
                // can no longer enter another claim transaction after this commit.
                $connection->forceFill(['poll_claim_token' => null, 'poll_claim_expires_at' => null])->save();
                AuditLogger::logOrFail('it.mailbox.interrupted_writer.fenced', $connection, [
                    'configuration_version' => $connection->configuration_version,
                ], systemActor: true);
            }
            $intent->forceFill(['state' => 'cleanup_pending', 'cleanup_requested_at' => now(),
                'revision' => $intent->revision + 1])->save();
            AuditLogger::logOrFail('it.attachment.cleanup.requested', $intent, [
                'intent_uuid' => $intent->intent_uuid, 'original_actor_user_id' => $intent->actor_user_id,
                'receipt_id' => $receipt->id, 'reason' => 'fenced_mailbox_writer',
            ], systemActor: true);

            return 'ready';
        }));
    }

    private function retryOne(ItAttachmentStorageReservation $reference): string
    {
        try {
            return $this->independentPrimary(fn (): string => DB::transaction(function () use ($reference): string {
                $intent = ItAttachmentStorageIntent::query()->whereKey($reference->id)->lockForUpdate()->first();
                $this->assertReference($intent, $reference);
                if ($intent->state === 'deleted') {
                    return 'deleted';
                }
                if ($intent->state !== 'cleanup_pending') {
                    return 'reconciliation_required';
                }
                if ($intent->attachment_id !== null || ItAttachment::query()->where('path', $intent->path)->exists()) {
                    $intent->forceFill(['state' => 'reconciliation_required', 'cleanup_error_code' => 'canonical_attachment_present', 'revision' => $intent->revision + 1])->save();

                    return 'reconciliation_required';
                }
                $intent->forceFill(['cleanup_attempts' => $intent->cleanup_attempts + 1, 'last_cleanup_attempt_at' => now()]);
                try {
                    $disk = Storage::disk(ItAttachment::DISK);
                    if ($disk->exists($intent->path) && ! $disk->delete($intent->path)) {
                        throw new DomainException('Storage deletion was not confirmed.');
                    }
                } catch (Throwable) {
                    $intent->forceFill(['cleanup_error_code' => 'storage_delete_failed'])->save();

                    return 'failed';
                }
                $intent->forceFill(['state' => 'deleted', 'deleted_at' => now(), 'cleanup_error_code' => null, 'revision' => $intent->revision + 1])->save();
                AuditLogger::logOrFail('it.attachment.cleanup.completed', $intent, [
                    'intent_uuid' => $intent->intent_uuid, 'original_actor_user_id' => $intent->actor_user_id,
                ], systemActor: true);

                return 'deleted';
            }));
        } catch (Throwable) {
            // The deletion transaction may have rolled back its attempt time
            // after storage or a required audit failed. Preserve retry fairness
            // separately; never change an attached or completed row here.
            try {
                $this->independentPrimary(fn () => DB::transaction(function () use ($reference): void {
                    $intent = ItAttachmentStorageIntent::query()->whereKey($reference->id)->lockForUpdate()->first();
                    $this->assertReference($intent, $reference);
                    if ($intent->state === 'cleanup_pending') {
                        $intent->forceFill([
                            'cleanup_attempts' => $intent->cleanup_attempts + 1,
                            'last_cleanup_attempt_at' => now(), 'cleanup_error_code' => 'cleanup_record_failed',
                        ])->save();
                    }
                }));
            } catch (Throwable) {
                // A database outage cannot be reported as a completed retry.
            }

            // Missing/unavailable evidence is not successful cleanup.
            return 'failed';
        }
    }

    private function validateFiles(array $files): void
    {
        Validator::make(['attachments' => $files], [
            'attachments' => ['array', 'max:5'],
            'attachments.*' => ['required', ...ItAttachment::uploadRules()],
        ])->validate();
    }

    private function fingerprint(UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getPathname());
        if (! is_string($hash)) {
            throw new DomainException('The attachment content could not be verified.');
        }

        return ['content_sha256' => $hash, 'original_name_sha256' => hash('sha256', $file->getClientOriginalName()), 'size' => (int) $file->getSize()];
    }

    private function assertParent(ItTicket|ItTicketComment $parent, User $actor): void
    {
        if (! $parent->exists || (int) $parent->id < 1 || (int) $actor->id < 1
            || $parent->getConnection()->getDatabaseName() !== DB::connection()->getDatabaseName()) {
            throw new LogicException('Attachments require their current canonical parent and actor.');
        }
    }

    private function references(array $reservations): array
    {
        $references = [];
        foreach ($reservations as $reference) {
            if (! $reference instanceof ItAttachmentStorageReservation || $reference->id < 1 || isset($references[$reference->id])
                || ! Str::isUuid($reference->uuid) || $reference->path !== 'it_attachments/'.$reference->uuid) {
                throw new LogicException('Invalid storage reservation identity.');
            }
            $references[$reference->id] = $reference;
        }
        ksort($references);

        return $references;
    }

    private function assertReference(?ItAttachmentStorageIntent $intent, ItAttachmentStorageReservation $reference): void
    {
        if (! $intent || $intent->intent_uuid !== $reference->uuid || $intent->path !== $reference->path) {
            throw new DomainException('The attachment reservation is unavailable.');
        }
    }

    private function independentPrimary(Closure $callback): mixed
    {
        $connection = DB::connection();
        $configuration = $connection->getConfig();
        $name = 'it_storage_intent_'.Str::uuid();
        $configuration['name'] = $name;
        $configuration['database'] = $connection->getDatabaseName();
        unset($configuration['url'], $configuration['read']);
        if (isset($configuration['write'])) {
            $configuration['write']['name'] = $name;
            $configuration['write']['database'] = $connection->getDatabaseName();
            unset($configuration['write']['url']);
        }
        try {
            DB::connectUsing($name, $configuration)->useWriteConnectionWhenReading();

            return DB::usingConnection($name, $callback);
        } finally {
            DB::purge($name);
        }
    }

    private function emptyResult(): array
    {
        return ['requested' => 0, 'deleted' => 0, 'failed' => 0, 'deferred' => 0, 'reconciliation_required' => 0];
    }
}
