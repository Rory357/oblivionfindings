<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeviceDocumentLifecycleService
{
    private const STAGING_PREFIX = 'device_documents/.staging';

    private const QUARANTINE_PREFIX = 'device_documents/.quarantine';

    private const ORPHAN_STAGE_GRACE_SECONDS = 3600;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /**
     * @param array{
     *   title: string,
     *   category: string,
     *   version?: string|null,
     *   effective_date?: string|null,
     *   expiry_date?: string|null,
     *   notes?: string|null
     * } $metadata
     */
    public function stageUpload(Device $device, User $actor, UploadedFile $file, array $metadata): DeviceDocument
    {
        abort_unless($actor->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($actor, $device);

        $temporaryHash = hash_file('sha256', $file->getRealPath());
        if (! is_string($temporaryHash)) {
            throw ValidationException::withMessages([
                'file' => 'The document integrity hash could not be calculated. Choose the file again.',
            ]);
        }

        $operationUuid = (string) Str::orderedUuid();
        $extension = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $file->getClientOriginalExtension()));
        $extension = $extension === '' ? 'bin' : substr($extension, 0, 12);
        $stagingPath = self::STAGING_PREFIX.'/'.$operationUuid.'.'.$extension;
        $finalPath = 'device_documents/'.$device->id.'/'.$operationUuid.'.'.$extension;
        $disk = Storage::disk(DeviceDocument::DISK);

        try {
            $storedPath = $file->storeAs(
                dirname($stagingPath),
                basename($stagingPath),
                DeviceDocument::DISK,
            );
        } catch (Throwable) {
            $storedPath = false;
        }
        if ($storedPath !== $stagingPath) {
            throw ValidationException::withMessages([
                'file' => 'The private staging file could not be stored. Try again after storage is restored.',
            ]);
        }

        try {
            $stagedHash = $this->hashPath($disk, $stagingPath);
        } catch (Throwable $failure) {
            $this->deletePathBestEffort($disk, $stagingPath);
            throw new DomainException('The private staging file could not be verified.', 0, $failure);
        }
        if (! hash_equals($temporaryHash, $stagedHash)) {
            $this->deletePathBestEffort($disk, $stagingPath);
            throw ValidationException::withMessages([
                'file' => 'The stored document failed its integrity check. Choose the file again.',
            ]);
        }

        try {
            $document = DB::transaction(function () use (
                $device,
                $actor,
                $file,
                $metadata,
                $operationUuid,
                $stagingPath,
                $finalPath,
                $temporaryHash,
            ): DeviceDocument {
                $lockedDevice = Device::query()
                    ->whereKey($device->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($actor->canDo('securityDevices.devices.update'), 403);
                $this->access->assertCanViewDevice($actor, $lockedDevice);

                return DeviceDocument::query()->create([
                    'device_id' => $lockedDevice->id,
                    'uploaded_by_user_id' => $actor->id,
                    'title' => $metadata['title'],
                    'category' => $metadata['category'],
                    'version' => $metadata['version'] ?? null,
                    'effective_date' => $metadata['effective_date'] ?? null,
                    'expiry_date' => $metadata['expiry_date'] ?? null,
                    'storage_disk' => DeviceDocument::DISK,
                    'storage_path' => $finalPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize() ?: 0,
                    'content_sha256' => $temporaryHash,
                    'lifecycle_state' => DeviceDocument::STATE_UPLOAD_STAGED,
                    'upload_operation_uuid' => $operationUuid,
                    'upload_requested_by_user_id' => $actor->id,
                    'staged_storage_path' => $stagingPath,
                    'notes' => $metadata['notes'] ?? null,
                ]);
            }, 3);
        } catch (Throwable $failure) {
            // A killed process can miss this immediate cleanup; the scheduled
            // reconciler independently removes unreferenced aged stage files.
            $this->deletePathBestEffort($disk, $stagingPath);
            throw $failure;
        }

        $this->reconcileDocument((int) $document->id);

        $document->refresh();

        return $document;
    }

    public function requestRemoval(
        Device $device,
        DeviceDocument $document,
        User $actor,
        string $reason,
    ): DeviceDocument {
        abort_unless($actor->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($actor, $device);

        $operationUuid = (string) Str::orderedUuid();
        $quarantinePath = self::QUARANTINE_PREFIX.'/'.$operationUuid;

        $pending = DB::transaction(function () use (
            $device,
            $document,
            $actor,
            $reason,
            $operationUuid,
            $quarantinePath,
        ): DeviceDocument {
            $lockedDevice = Device::query()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($actor->canDo('securityDevices.devices.update'), 403);
            $this->access->assertCanViewDevice($actor, $lockedDevice);

            $lockedDocument = DeviceDocument::query()
                ->whereKey($document->getKey())
                ->where('device_id', $lockedDevice->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedDocument->lifecycle_state === DeviceDocument::STATE_ACTIVE, 404);
            $lockedDocument->requestGovernedRemoval(
                operationUuid: $operationUuid,
                actorId: (int) $actor->id,
                reason: trim($reason),
                quarantinePath: $quarantinePath,
                requestedAt: now(),
            );

            AuditLogger::logOrFail('security_devices.device_document.removal_requested', $lockedDocument, [
                'actor_id' => (int) $actor->id,
                'fields' => ['status', 'removal_requested_at', 'removal_requested_by_user_id'],
                'before' => ['status' => DeviceDocument::STATE_ACTIVE],
                'after' => ['status' => DeviceDocument::STATE_REMOVAL_PENDING],
            ]);

            return $lockedDocument;
        }, 3);

        $this->reconcileDocument((int) $pending->id);

        $pending->refresh();

        return $pending;
    }

    public function verifiedContents(DeviceDocument $document): string
    {
        if (! $document->isDownloadable()) {
            throw new DomainException('The document is not in a verified downloadable state.');
        }

        try {
            $contents = Storage::disk(DeviceDocument::DISK)->get($document->storage_path);
        } catch (Throwable $failure) {
            $this->recordErrorBestEffort((int) $document->id, 'download_unavailable');
            throw new DomainException('The verified private document is unavailable.', 0, $failure);
        }
        if (! is_string($contents)) {
            $this->recordErrorBestEffort((int) $document->id, 'download_unavailable');
            throw new DomainException('The verified private document is unavailable.');
        }

        $actualHash = hash('sha256', $contents);
        if (! hash_equals((string) $document->content_sha256, $actualHash)) {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($contents);
            }
            $this->recordErrorBestEffort((int) $document->id, 'integrity_mismatch');
            throw new DomainException('The private document failed its integrity check.');
        }

        return $contents;
    }

    /** @return array{processed: int, recovered: int, pending: int, orphan_stages_removed: int} */
    public function reconcileAll(int $limit = 100): array
    {
        $documents = $this->pendingReconciliationQuery()
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        $recovered = 0;
        foreach ($documents as $documentId) {
            if ($this->reconcileDocument((int) $documentId)) {
                $recovered++;
            }
        }

        return [
            'processed' => $documents->count(),
            'recovered' => $recovered,
            'pending' => $this->pendingReconciliationQuery()->count(),
            'orphan_stages_removed' => $this->removeAgedOrphanStages(),
        ];
    }

    private function pendingReconciliationQuery(): Builder
    {
        return DeviceDocument::query()->where(function (Builder $pending): void {
            $pending->whereIn('lifecycle_state', [
                DeviceDocument::STATE_UPLOAD_STAGED,
                DeviceDocument::STATE_REMOVAL_PENDING,
            ])->orWhere(function (Builder $removed): void {
                $removed->where('lifecycle_state', DeviceDocument::STATE_REMOVED)
                    ->where(function (Builder $cleanup): void {
                        $cleanup->whereNull('storage_deleted_at')
                            ->orWhereNotNull('quarantine_storage_path')
                            ->orWhereNotNull('lifecycle_error_code');
                    });
            })->orWhere(function (Builder $legacy): void {
                $legacy->where('lifecycle_state', DeviceDocument::STATE_ACTIVE)
                    ->where(function (Builder $unverified): void {
                        $unverified->where('storage_disk', '!=', DeviceDocument::DISK)
                            ->orWhereNull('content_sha256')
                            ->orWhereNull('storage_verified_at')
                            ->orWhereNotNull('lifecycle_error_code');
                    });
            });
        });
    }

    public function reconcileDocument(int $documentId): bool
    {
        try {
            $document = DeviceDocument::query()->find($documentId);
            if (! $document instanceof DeviceDocument) {
                return true;
            }

            return match ($document->lifecycle_state) {
                DeviceDocument::STATE_UPLOAD_STAGED => $this->reconcileStagedUpload($document),
                DeviceDocument::STATE_ACTIVE => $this->reconcileLegacyDocument($document),
                DeviceDocument::STATE_REMOVAL_PENDING => $this->reconcilePendingRemoval($document),
                DeviceDocument::STATE_REMOVED => $this->reconcileRemovedQuarantine($document),
                default => false,
            };
        } catch (Throwable) {
            $this->recordErrorBestEffort($documentId, 'reconciliation_failed');

            return false;
        }
    }

    private function reconcileStagedUpload(DeviceDocument $document): bool
    {
        $disk = Storage::disk(DeviceDocument::DISK);
        $stagedPath = (string) $document->staged_storage_path;
        $finalPath = (string) $document->storage_path;
        if ($stagedPath === '' || $finalPath === '') {
            $this->recordErrorBestEffort((int) $document->id, 'staged_path_missing');

            return false;
        }

        if (! $this->pathExistsOrFail($disk, $finalPath)) {
            if (! $this->pathExistsOrFail($disk, $stagedPath)) {
                $this->recordErrorBestEffort((int) $document->id, 'staged_blob_missing');

                return false;
            }
            if (! $this->movePath($disk, $stagedPath, $finalPath)
                && ! $this->pathExistsOrFail($disk, $finalPath)) {
                $this->recordErrorBestEffort((int) $document->id, 'staged_move_pending');

                return false;
            }
        }

        $actualHash = $this->hashPath($disk, $finalPath);
        if (! hash_equals((string) $document->content_sha256, $actualHash)) {
            $this->recordErrorBestEffort((int) $document->id, 'integrity_mismatch');

            return false;
        }

        if ($this->pathExistsOrFail($disk, $stagedPath)) {
            $this->deletePathBestEffort($disk, $stagedPath);
        }

        DB::transaction(function () use ($document, $actualHash): void {
            $locked = DeviceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->lifecycle_state !== DeviceDocument::STATE_UPLOAD_STAGED) {
                return;
            }
            $locked->activateVerifiedStorage($actualHash, now());
            AuditLogger::logOrFail('security_devices.device_document.uploaded', $locked, [
                'actor_id' => (int) ($locked->upload_requested_by_user_id ?? $locked->uploaded_by_user_id),
                'fields' => ['status', 'content_sha256', 'storage_disk', 'storage_verified_at'],
                'before' => ['status' => DeviceDocument::STATE_UPLOAD_STAGED],
                'after' => ['status' => DeviceDocument::STATE_ACTIVE],
            ]);
        }, 3);

        return DeviceDocument::query()->whereKey($document->id)->where('lifecycle_state', DeviceDocument::STATE_ACTIVE)->exists();
    }

    private function reconcileLegacyDocument(DeviceDocument $document): bool
    {
        if ($document->isDownloadable()) {
            return true;
        }

        $privateDisk = Storage::disk(DeviceDocument::DISK);
        if (! $this->pathExistsOrFail($privateDisk, (string) $document->storage_path)) {
            $this->recordErrorBestEffort((int) $document->id, 'legacy_blob_missing');

            return false;
        }
        $hash = $this->hashPath($privateDisk, (string) $document->storage_path);
        if (is_string($document->content_sha256)
            && ! hash_equals($document->content_sha256, $hash)) {
            $this->recordErrorBestEffort((int) $document->id, 'integrity_mismatch');

            return false;
        }

        DB::transaction(function () use ($document, $hash): void {
            $locked = DeviceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->lifecycle_state !== DeviceDocument::STATE_ACTIVE || $locked->isDownloadable()) {
                return;
            }
            $locked->adoptVerifiedLegacyStorage($hash, now());
            AuditLogger::logOrFail('security_devices.device_document.integrity_verified', $locked, [
                'actor_id' => (int) ($locked->upload_requested_by_user_id ?? $locked->uploaded_by_user_id ?? 0),
                'fields' => ['status', 'content_sha256', 'storage_disk', 'storage_verified_at'],
                'before' => ['status' => 'verification_pending'],
                'after' => ['status' => DeviceDocument::STATE_ACTIVE],
            ]);
        }, 3);

        return DeviceDocument::query()->whereKey($document->id)->whereNotNull('storage_verified_at')->exists();
    }

    private function reconcilePendingRemoval(DeviceDocument $document): bool
    {
        $disk = Storage::disk(DeviceDocument::DISK);
        $finalPath = (string) $document->storage_path;
        $quarantinePath = (string) $document->quarantine_storage_path;
        if ($finalPath === '' || $quarantinePath === '') {
            $this->recordErrorBestEffort((int) $document->id, 'quarantine_path_missing');

            return false;
        }

        if (! $this->pathExistsOrFail($disk, $quarantinePath)) {
            if (! $this->pathExistsOrFail($disk, $finalPath)) {
                $this->recordErrorBestEffort((int) $document->id, 'removal_blob_missing');

                return false;
            }
            $finalHash = $this->hashPath($disk, $finalPath);
            if (! hash_equals((string) $document->content_sha256, $finalHash)) {
                $this->recordErrorBestEffort((int) $document->id, 'integrity_mismatch');

                return false;
            }
            if (! $this->movePath($disk, $finalPath, $quarantinePath)
                && ! $this->pathExistsOrFail($disk, $quarantinePath)) {
                $this->recordErrorBestEffort((int) $document->id, 'quarantine_move_pending');

                return false;
            }
        }

        $quarantineHash = $this->hashPath($disk, $quarantinePath);
        if (! hash_equals((string) $document->content_sha256, $quarantineHash)) {
            $this->recordErrorBestEffort((int) $document->id, 'quarantine_integrity_mismatch');

            return false;
        }
        if ($this->pathExistsOrFail($disk, $finalPath) && ! $this->deletePathBestEffort($disk, $finalPath)) {
            $this->recordErrorBestEffort((int) $document->id, 'duplicate_active_blob');

            return false;
        }

        DB::transaction(function () use ($document): void {
            $locked = DeviceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->lifecycle_state !== DeviceDocument::STATE_REMOVAL_PENDING) {
                return;
            }
            $locked->completeGovernedRemoval(now());
            AuditLogger::logOrFail('security_devices.device_document.removed', $locked, [
                'actor_id' => (int) $locked->removed_by_user_id,
                'fields' => ['status', 'removed_at', 'removed_by_user_id', 'storage_deleted_at'],
                'before' => ['status' => DeviceDocument::STATE_REMOVAL_PENDING],
                'after' => ['status' => DeviceDocument::STATE_REMOVED],
            ]);
        }, 3);

        $fresh = $document->fresh();
        if (! $fresh instanceof DeviceDocument || $fresh->lifecycle_state !== DeviceDocument::STATE_REMOVED) {
            return false;
        }

        return $this->reconcileRemovedQuarantine($fresh);
    }

    private function reconcileRemovedQuarantine(DeviceDocument $document): bool
    {
        if ($document->storage_deleted_at !== null && $document->quarantine_storage_path === null) {
            return true;
        }
        $quarantinePath = (string) $document->quarantine_storage_path;
        if ($quarantinePath === '') {
            $this->recordErrorBestEffort((int) $document->id, 'quarantine_reference_missing');

            return false;
        }

        $disk = Storage::disk(DeviceDocument::DISK);
        if ($this->pathExistsOrFail($disk, $quarantinePath)
            && ! $this->deletePathBestEffort($disk, $quarantinePath)) {
            $this->recordErrorBestEffort((int) $document->id, 'quarantine_cleanup_pending');

            return false;
        }

        DB::transaction(function () use ($document): void {
            $locked = DeviceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->lifecycle_state !== DeviceDocument::STATE_REMOVED
                || ($locked->storage_deleted_at !== null && $locked->quarantine_storage_path === null)) {
                return;
            }
            $locked->completeQuarantineDeletion(now());
            AuditLogger::logOrFail('security_devices.device_document.private_blob_deleted', $locked, [
                'actor_id' => (int) ($locked->removed_by_user_id ?? 0),
                'fields' => ['status', 'storage_deleted_at'],
                'before' => ['status' => 'quarantine_cleanup_pending'],
                'after' => ['status' => 'private_blob_deleted'],
            ]);
        }, 3);

        return true;
    }

    private function hashPath(FilesystemAdapter $disk, string $path): string
    {
        try {
            $stream = $disk->readStream($path);
        } catch (Throwable $failure) {
            throw new DomainException('Private storage could not be read for integrity verification.', 0, $failure);
        }
        if (! is_resource($stream)) {
            throw new DomainException('Private storage could not be read for integrity verification.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function pathExistsOrFail(FilesystemAdapter $disk, string $path): bool
    {
        try {
            return $disk->exists($path);
        } catch (Throwable $failure) {
            throw new DomainException('Private storage availability could not be verified.', 0, $failure);
        }
    }

    private function movePath(FilesystemAdapter $disk, string $from, string $to): bool
    {
        try {
            return $disk->move($from, $to) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deletePathBestEffort(FilesystemAdapter $disk, string $path): bool
    {
        try {
            return ! $disk->exists($path) || $disk->delete($path) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordErrorBestEffort(int $documentId, string $code): void
    {
        try {
            DB::transaction(function () use ($documentId, $code): void {
                $document = DeviceDocument::query()->whereKey($documentId)->lockForUpdate()->first();
                $document?->recordLifecycleError($code);
            }, 3);
        } catch (Throwable) {
            // The durable state/path fields remain sufficient for a later retry.
        }
    }

    private function removeAgedOrphanStages(): int
    {
        $disk = Storage::disk(DeviceDocument::DISK);
        try {
            $paths = $disk->allFiles(self::STAGING_PREFIX);
        } catch (Throwable) {
            return 0;
        }
        if ($paths === []) {
            return 0;
        }

        $referenced = DeviceDocument::query()
            ->whereNotNull('staged_storage_path')
            ->pluck('staged_storage_path')
            ->filter()
            ->flip();
        $cutoff = now()->subSeconds(self::ORPHAN_STAGE_GRACE_SECONDS)->timestamp;
        $removed = 0;
        foreach ($paths as $path) {
            if ($referenced->has($path)) {
                continue;
            }
            try {
                if ($disk->lastModified($path) > $cutoff) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            if ($this->deletePathBestEffort($disk, $path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
