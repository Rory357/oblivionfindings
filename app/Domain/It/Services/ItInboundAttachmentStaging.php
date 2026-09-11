<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItEmailAttachment;
use App\Domain\It\Data\ItScannedEmailAttachment;
use App\Domain\It\Exceptions\ItInboundAttachmentUnavailable;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Models\ItAttachment;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Services\AuditLogger;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Private file preparation on canonical inbound receipts; this service grants no ticket access. */
final class ItInboundAttachmentStaging
{
    public function __construct(private readonly ItMailboxPollState $state, private readonly MalwareScanner $scanner) {}

    /**
     * Reservations and byte fingerprints commit BEFORE any private write. A dead worker leaves
     * a tracked path that the same receipt can resume. Provider HTTP never holds database locks.
     *
     * @param  list<ItEmailAttachment>  $files
     * @param  Closure(ItEmailAttachment): string  $download
     */
    public function receive(ItMailboxConnection $claim, ItInboundEmail $receipt, array $files, Closure $download): void
    {
        $this->assertOutsideTransaction();
        (new ItEmailAttachments)->validateBatch($files);
        $manifest = hash('sha256', json_encode(array_map(fn (ItEmailAttachment $file) => [
            $file->provider, $file->remoteId, $file->providerName, $file->name, $file->mime, $file->size, $file->inline,
        ], $files), JSON_THROW_ON_ERROR));
        $this->state->withinClaim($claim, function () use ($claim, $receipt, $files, $manifest): void {
            $current = $this->pendingReceipt($claim, $receipt);
            if ($current->attachment_manifest_hash !== null) {
                if (! hash_equals($current->attachment_manifest_hash, $manifest)) {
                    throw new ItInboundContentException('attachment_manifest_changed');
                }
                $this->assertComplete($current);

                return;
            }
            $current->forceFill(['attachment_manifest_hash' => $manifest, 'attachment_expected_count' => count($files)])->save();
            foreach ($files as $position => $file) {
                $attachment = new ItAttachment;
                $attachment->forceFill([
                    'attachable_type' => $current->getMorphClass(), 'attachable_id' => $current->id,
                    'path' => 'it_attachments/'.Str::uuid(), 'original_name' => $file->name,
                    'mime' => $file->mime, 'size' => $file->size, 'uploaded_by' => null,
                    'inbound_position' => $position, 'inbound_storage_state' => 'reserved',
                ])->save();
            }
            if ($files !== []) {
                AuditLogger::logOrFail('it.inbound.attachments.reserved', $current, ['file_count' => count($files)], systemActor: true);
            }
        });

        foreach ($files as $position => $file) {
            $this->state->renew($claim);
            $contents = $download($file);
            if (! is_string($contents) || strlen($contents) !== $file->size || strlen($contents) > ItEmailAttachments::MAX_FILE_BYTES) {
                throw new ItInboundContentException('invalid_attachment_content');
            }
            $hash = hash('sha256', $contents);
            $this->state->withinClaim($claim, function () use ($claim, $receipt, $position, $hash): void {
                $current = $this->pendingReceipt($claim, $receipt);
                $attachment = $current->attachments()->where('inbound_position', $position)->lockForUpdate()->sole();
                if ($attachment->inbound_content_hash !== null && ! hash_equals($attachment->inbound_content_hash, $hash)) {
                    throw new ItInboundContentException('attachment_content_changed');
                }
                $attachment->forceFill(['inbound_content_hash' => $hash])->save();
            });
            // Commit failure state before surfacing the error; retries never report a clean file.
            $error = $this->state->withinClaim($claim, function () use ($claim, $receipt, $position, $contents): ?string {
                $current = $this->pendingReceipt($claim, $receipt);
                $attachment = $current->attachments()->where('inbound_position', $position)->lockForUpdate()->sole();

                return $this->writeAndScan($attachment, $contents);
            });
            if ($error !== null) {
                if (in_array($error, ['attachment_infected', 'unsupported_attachment_content'], true)) {
                    throw new ItInboundContentException($error);
                }
                throw new ItInboundAttachmentUnavailable($error);
            }
        }
    }

    /**
     * Called inside the final claim/intake transaction. Cleanup takes the same receipt lock.
     * The owning command still applies current sender, site, ticket and audience authorization.
     *
     * @return list<UploadedFile>
     */
    public function filesForIngestion(ItMailboxConnection $claim, ItInboundEmail $receipt): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Prepared files require the owning ingestion transaction.');
        }
        $this->state->current($claim, lock: true);
        $current = $this->pendingReceipt($claim, $receipt);
        $rows = $this->assertComplete($current);
        $files = [];
        foreach ($rows as $attachment) {
            if ($attachment->inbound_storage_state !== 'ready' || $attachment->malware_scan_status !== 'clean') {
                throw new ItInboundAttachmentUnavailable('attachment_not_ready');
            }
            $path = $this->path($attachment);
            if (! $this->matchesStoredBytes($attachment, $path)) {
                throw new ItInboundAttachmentUnavailable('attachment_storage_changed');
            }
            $files[] = new ItScannedEmailAttachment($attachment, $claim);
        }

        return $files;
    }

    /** Mechanical cleanup of temporary copies only after canonical ingestion commits. */
    public function finish(ItInboundEmail $receipt): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stage completion requires the owning ingestion transaction.');
        }
        $current = ItInboundEmail::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        if (! in_array($current->status, ['processed', 'duplicate'], true)) {
            throw new LogicException('Only accepted receipts can release temporary file copies.');
        }
        $count = $current->attachments()->whereNotIn('inbound_storage_state', ['cleanup_pending', 'deleted'])
            ->update(['inbound_storage_state' => 'cleanup_pending']);
        if ($count > 0) {
            AuditLogger::logOrFail('it.inbound.attachments.cleanup_requested', $current, ['file_count' => $count], systemActor: true);
        }
    }

    /** Quarantined content is excluded: its retention/review policy must be supplied by its owner. */
    public function cleanupPending(int $limit = 50, ?int $receiptId = null, ?array $onlyIds = null): array
    {
        $this->assertOutsideTransaction();
        if ($limit < 1 || $limit > 1000 || ($onlyIds !== null && (! array_is_list($onlyIds) || count($onlyIds) > $limit
            || array_filter($onlyIds, fn ($id) => ! is_int($id) || $id < 1) !== []
            || count(array_unique($onlyIds)) !== count($onlyIds)))) {
            throw new LogicException('Invalid bounded inbound cleanup selection.');
        }
        $result = ['deleted' => 0, 'failed' => 0];
        $ids = $this->pendingCleanupQuery()
            ->when($receiptId !== null, fn ($query) => $query->where('attachable_id', $receiptId))
            ->when($onlyIds !== null, fn ($query) => $query->whereIn('id', $onlyIds))
            ->orderByRaw('CASE WHEN inbound_cleanup_attempts = 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN inbound_cleanup_attempts = 0 THEN NULL ELSE updated_at END')
            ->orderBy('inbound_cleanup_attempts')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $outcome = DB::transaction(function () use ($id): ?string {
                    $candidate = ItAttachment::query()->find($id);
                    if (! $candidate) {
                        return null;
                    }
                    $receipt = ItInboundEmail::query()->whereKey($candidate->attachable_id)->lockForUpdate()->first();
                    $attachment = $receipt?->attachments()->whereKey($id)->lockForUpdate()->first();
                    if (! $attachment || $attachment->inbound_storage_state !== 'cleanup_pending'
                        || ! in_array($receipt->status, ['processed', 'duplicate'], true)) {
                        return null;
                    }
                    try {
                        $this->path($attachment);
                        $disk = Storage::disk(ItAttachment::DISK);
                        if ($disk->exists($attachment->path) && ! $disk->delete($attachment->path)) {
                            throw new LogicException('File removal was not confirmed.');
                        }
                        if ($disk->exists($attachment->path)) {
                            throw new LogicException('Private file still exists after removal.');
                        }
                    } catch (Throwable) {
                        $attachment->forceFill(['inbound_error_code' => 'storage_delete_failed',
                            'inbound_cleanup_attempts' => min(1000000, $attachment->inbound_cleanup_attempts + 1)])->save();

                        return 'failed';
                    }
                    $attachment->forceFill(['inbound_storage_state' => 'deleted', 'inbound_error_code' => null])->save();
                    AuditLogger::logOrFail('it.inbound.attachment.deleted', $attachment, ['receipt_id' => $receipt->id], systemActor: true);

                    return 'deleted';
                });
            } catch (Throwable) {
                // A required audit or transaction failure is not a completed deletion.
                // Retain retry ordering separately after rollback so this file cannot
                // repeatedly displace other work. Never promote uncertain ownership.
                try {
                    $this->assertOutsideTransaction();
                    DB::transaction(function () use ($id): void {
                        $candidate = ItAttachment::query()->useWritePdo()->find($id);
                        if (! $candidate) {
                            return;
                        }
                        $receipt = ItInboundEmail::query()->whereKey($candidate->attachable_id)->lockForUpdate()->first();
                        $attachment = $receipt?->attachments()->whereKey($id)->lockForUpdate()->first();
                        if ($attachment && $attachment->inbound_storage_state === 'cleanup_pending'
                            && in_array($receipt->status, ['processed', 'duplicate'], true)) {
                            $attachment->forceFill(['inbound_error_code' => 'cleanup_record_failed',
                                'inbound_cleanup_attempts' => min(1000000, $attachment->inbound_cleanup_attempts + 1)])->save();
                        }
                    });
                } catch (Throwable) {
                    // Unavailable failure metadata never becomes successful cleanup.
                }
                $outcome = 'failed';
            }
            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /** Mechanical eligibility only; callers still lock and recheck the current receipt before deletion. */
    public function pendingCleanupQuery(): Builder
    {
        return ItAttachment::query()->useWritePdo()->where('attachable_type', (new ItInboundEmail)->getMorphClass())
            ->where('inbound_storage_state', 'cleanup_pending')
            ->whereIn('attachable_id', ItInboundEmail::query()->select('id')->whereIn('status', ['processed', 'duplicate']));
    }

    private function writeAndScan(ItAttachment $attachment, string $contents): ?string
    {
        if ($attachment->inbound_storage_state === 'rejected') {
            return $attachment->inbound_error_code;
        }
        if (! in_array($attachment->inbound_storage_state, ['reserved', 'failed', 'ready'], true)) {
            throw new LogicException('This inbound file is no longer writable.');
        }
        try {
            $path = $this->path($attachment);
            if (is_link($path)) {
                throw new LogicException('Private file path must not be a symbolic link.');
            }
            if (! $this->matchesStoredBytes($attachment, $path)) {
                if (! Storage::disk(ItAttachment::DISK)->put($attachment->path, $contents)
                    || ! $this->matchesStoredBytes($attachment, $path)) {
                    throw new LogicException('Private file write was not confirmed.');
                }
                // A repaired copy requires a new scan even if an earlier copy was clean.
                $attachment->malware_scan_status = null;
                $attachment->malware_scanned_at = null;
            }
            $upload = new UploadedFile($path, $attachment->original_name, $attachment->mime, null, true);
            if (Validator::make(['file' => $upload], ['file' => ['required', ...ItAttachment::uploadRules()]])->fails()) {
                $error = 'unsupported_attachment_content';
            } else {
                if ($attachment->inbound_storage_state === 'ready' && $attachment->malware_scan_status === 'clean') {
                    return null;
                }
                $scan = $this->scanner->scanPath($path, (array) config('it.inbound_mail.malware_scanner', []));
                $attachment->forceFill([
                    'mime' => $upload->getMimeType(), 'malware_scan_status' => $scan->disposition->value,
                    'malware_scanner' => mb_substr(preg_replace('/[^a-zA-Z0-9 ._-]/', '', $scan->scanner), 0, 80),
                    'malware_scan_attempted_at' => now(),
                    'malware_scanned_at' => $scan->disposition === MalwareScanDisposition::Unavailable ? null : now(),
                ]);
                $error = match ($scan->disposition) {
                    MalwareScanDisposition::Clean => null,
                    MalwareScanDisposition::Infected => 'attachment_infected',
                    MalwareScanDisposition::Unavailable => in_array($scan->errorCode, ['scanner_unavailable', 'scanner_failed', 'scanner_timeout', 'file_unavailable'], true)
                        ? $scan->errorCode : 'scanner_failed',
                };
            }
        } catch (Throwable) {
            // Only storage/validation/adapter operations are caught; required audit failures roll back.
            return $this->recordOutcome($attachment, 'failed', 'attachment_storage_unavailable');
        }

        return $this->recordOutcome($attachment, $error === null ? 'ready'
            : (in_array($error, ['attachment_infected', 'unsupported_attachment_content'], true) ? 'rejected' : 'failed'), $error);
    }

    private function recordOutcome(ItAttachment $attachment, string $state, ?string $error): ?string
    {
        $attachment->forceFill(['inbound_storage_state' => $state, 'inbound_error_code' => $error])->save();
        AuditLogger::logOrFail('it.inbound.attachment.prepared', $attachment,
            ['state' => $state, 'error_code' => $error, 'scan_status' => $attachment->malware_scan_status], systemActor: true);

        return $error;
    }

    private function pendingReceipt(ItMailboxConnection $claim, ItInboundEmail $receipt): ItInboundEmail
    {
        $current = ItInboundEmail::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        if ($current->status !== 'pending' || (int) $current->it_mailbox_connection_id !== (int) $claim->id
            || $current->mailbox_scope_hash !== $claim->mailboxScopeHash()) {
            throw new LogicException('The inbound receipt is outside this pending mailbox claim.');
        }

        return $current;
    }

    private function assertComplete(ItInboundEmail $receipt)
    {
        $rows = $receipt->attachments()->orderBy('inbound_position')->lockForUpdate()->get();
        if ($receipt->attachment_manifest_hash === null || $receipt->attachment_expected_count !== $rows->count()
            || $rows->pluck('inbound_position')->all() !== ($rows->isEmpty() ? [] : range(0, $rows->count() - 1))) {
            throw new ItInboundAttachmentUnavailable('attachment_reservation_incomplete');
        }

        return $rows;
    }

    private function path(ItAttachment $attachment): string
    {
        if ($attachment->attachable_type !== (new ItInboundEmail)->getMorphClass()
            || ! preg_match('~^it_attachments/[a-f0-9-]{36}$~D', $attachment->path)
            || ! Str::isUuid(substr($attachment->path, strlen('it_attachments/')))) {
            throw new LogicException('Invalid inbound file storage identity.');
        }

        return Storage::disk(ItAttachment::DISK)->path($attachment->path);
    }

    private function matchesStoredBytes(ItAttachment $attachment, string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path) && ! is_link($path) && filesize($path) === $attachment->size
            && is_string($attachment->inbound_content_hash) && hash_equals($attachment->inbound_content_hash, hash_file('sha256', $path));
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('Inbound file storage requires independently committed preparation.');
        }
    }
}
