<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\AssetDocumentSetEvent;
use App\Models\FleetServiceCompletion;
use App\Models\FleetVehicleComplianceVersion;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private, versioned vehicle documents. A set is one document made of one or
 * more files; replacing its files adds a revision and keeps the set (and so
 * its single renewal reminder). Bytes go to the private disk under a random
 * name and only a clean scan makes a new file available. The previous
 * revision stays current while a replacement is pending or has failed.
 */
class VehicleDocumentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_FILES = 10;

    /** Extension => accepted detected MIME types. */
    public const ACCEPTED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    /** Records that can own evidence files, and the model that proves they belong to the vehicle. */
    private const SOURCES = [
        'compliance_version' => 'compliance',
        'odometer_observation' => 'odometer',
        'service_completion' => 'completion',
        'service_schedule' => 'schedule',
        'booking' => 'booking',
        'unavailable_period' => 'unavailable',
        // PKG-02B vehicle finance: supporting files of an open Finance review request.
        'finance_review_request' => 'finance',
        // PKG-02B vehicle checks: files kept with a submitted check. They never
        // change the check's original answers or outcome.
        'checklist_run' => 'check',
        // PKG-02B driving insights: the sign photo or authority document behind
        // a manual speed limit, added while it is pending or approved.
        'speed_limit' => 'speed_limit',
    ];

    private const DISK = 'private';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly MalwareScanner $scanner,
        private readonly VehicleReminderService $reminders,
        private readonly VehicleBookingAccessService $bookings,
    ) {}

    public function canManage(User $actor, Asset $asset): bool
    {
        return Gate::forUser($actor)->allows('manageDocuments', $asset);
    }

    /**
     * Upload a new document (set) with its files and optional renewal plan.
     * An identical retry returns the same set and resumes unfinished files.
     *
     * @param  array<string,mixed>  $meta
     * @param  list<UploadedFile>  $files
     * @return array{set: AssetDocumentSet, files: list<AssetDocument>}
     */
    public function create(User $actor, int $assetId, array $meta, array $files, string $requestKey): array
    {
        self::assertKey($requestKey);
        $checked = $this->checkFiles($files, true);
        $meta = $this->validMeta($meta, false);
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'asset' => $assetId, 'meta' => $meta,
            'files' => array_map(fn (array $file): string => $file['sha256'], $checked),
        ]);

        [$set, $reserved] = DB::transaction(function () use ($actor, $assetId, $meta, $checked, $requestKey, $fingerprint): array {
            [$current, $asset] = $this->resolve($actor, $assetId,
                $meta['source_type'] === 'booking' && $meta['source_id'] !== null ? (int) $meta['source_id'] : null);
            $prior = AssetDocumentSet::query()->where('asset_id', $asset->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different document.');

                return [$prior, $prior->files()->where('request_key', 'like', $requestKey.':%')->orderBy('id')->get()->all()];
            }
            $this->assertSource($asset, $meta['source_type'], $meta['source_id']);
            $set = AssetDocumentSet::query()->create([
                'asset_id' => $asset->id, 'category' => $meta['category'], 'reference' => $meta['reference'],
                'document_date' => $meta['document_date'], 'expires_on' => $meta['expires_on'],
                'source_type' => $meta['source_type'], 'source_id' => $meta['source_id'],
                'lock_version' => 1, 'current_revision' => 1, 'created_by_user_id' => $current->id,
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint,
            ]);
            $reserved = $this->reserve($current, $asset, $set, 1, $checked, $requestKey);
            $this->reminders->syncDocumentRenewal($current, $asset, $set, $meta['reminder'], $requestKey);
            $this->event($set, $current, 'created', $meta['reason'], null, $this->snapshot($set), $requestKey, $fingerprint);

            return [$set, $reserved];
        }, 3);

        return ['set' => $set->fresh(), 'files' => $this->finish($actor, $assetId, $set, $reserved, $files)];
    }

    /**
     * Upload a new revision of a set's files. Previous files stay in history
     * and remain current until a file of the new revision is available.
     *
     * @param  list<UploadedFile>  $files
     * @return array{set: AssetDocumentSet, files: list<AssetDocument>}
     */
    public function replace(User $actor, int $assetId, int $setId, array $files, string $reason, int $expectedVersion, string $requestKey): array
    {
        self::assertKey($requestKey);
        $checked = $this->checkFiles($files, true);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Record the reason for this new version.']);
        }
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'set' => $setId, 'reason' => trim($reason),
            'files' => array_map(fn (array $file): string => $file['sha256'], $checked),
        ]);

        [$set, $reserved] = DB::transaction(function () use ($actor, $assetId, $setId, $checked, $reason, $expectedVersion, $requestKey, $fingerprint): array {
            [$current, $asset] = $this->resolve($actor, $assetId);
            $set = $this->lockSet($asset, $setId);
            if ($this->replayed($set, $requestKey, $fingerprint)) {
                return [$set, $set->files()->where('request_key', 'like', $requestKey.':%')->orderBy('id')->get()->all()];
            }
            abort_unless($set->lock_version === $expectedVersion, 409, 'This document changed while you were editing. Reload before replacing it.');
            abort_if($set->archived_at !== null, 409, 'This document is archived.');
            $revision = (int) $set->files()->max('revision') + 1;
            $before = $this->snapshot($set);
            $set->forceFill(['lock_version' => $set->lock_version + 1])->save();
            $reserved = $this->reserve($current, $asset, $set, $revision, $checked, $requestKey);
            $this->event($set, $current, 'replacement_started', trim($reason), $before, $this->snapshot($set) + ['revision' => $revision], $requestKey, $fingerprint);

            return [$set, $reserved];
        }, 3);

        return ['set' => $set->fresh(), 'files' => $this->finish($actor, $assetId, $set, $reserved, $files)];
    }

    /** Edit the shared details, expiry and renewal plan. @param array<string,mixed> $meta */
    public function update(User $actor, int $assetId, int $setId, array $meta, int $expectedVersion, string $requestKey): AssetDocumentSet
    {
        self::assertKey($requestKey);
        $meta = $this->validMeta($meta, true);
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'set' => $setId, 'meta' => $meta]);

        return DB::transaction(function () use ($actor, $assetId, $setId, $meta, $expectedVersion, $requestKey, $fingerprint): AssetDocumentSet {
            [$current, $asset] = $this->resolve($actor, $assetId);
            $set = $this->lockSet($asset, $setId);
            if ($this->replayed($set, $requestKey, $fingerprint)) {
                return $set;
            }
            abort_unless($set->lock_version === $expectedVersion, 409, 'This document changed while you were editing. Reload before saving.');
            abort_if($set->archived_at !== null, 409, 'This document is archived.');
            $before = $this->snapshot($set);
            $set->forceFill([
                'category' => $meta['category'], 'reference' => $meta['reference'],
                'document_date' => $meta['document_date'], 'expires_on' => $meta['expires_on'],
                'lock_version' => $set->lock_version + 1,
            ])->save();
            $this->reminders->syncDocumentRenewal($current, $asset, $set, $meta['reminder'], $requestKey);
            $this->event($set, $current, 'details_updated', $meta['reason'], $before, $this->snapshot($set), $requestKey, $fingerprint);

            return $set->fresh();
        }, 3);
    }

    /** Archive one file with a reason. The original bytes and history are kept. */
    public function archiveFile(User $actor, int $assetId, int $documentId, string $reason, bool $pauseRenewal, string $requestKey): AssetDocument
    {
        self::assertKey($requestKey);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Record why this file is archived.']);
        }

        return DB::transaction(function () use ($actor, $assetId, $documentId, $reason, $pauseRenewal, $requestKey): AssetDocument {
            [$current, $asset] = $this->resolve($actor, $assetId);
            $document = AssetDocument::query()->whereKey($documentId)->where('asset_id', $asset->id)->whereNotNull('document_set_id')->first() ?? abort(404);
            $set = $this->lockSet($asset, (int) $document->document_set_id);
            $document = AssetDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
            $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $current->id, 'document' => $documentId, 'reason' => trim($reason), 'pause' => $pauseRenewal]);
            if ($this->replayed($set, $requestKey, $fingerprint)) {
                return $document;
            }
            abort_if($document->archived_at !== null, 409, 'This file is already archived.');
            abort_if((int) DB::table('assets')->where('id', $asset->id)->value('profile_photo_document_id') === $document->id, 409,
                'This file is the vehicle photo. Choose another photo first.');
            $document->forceFill(['archived_at' => now(), 'archived_by_user_id' => $current->id, 'archive_reason' => trim($reason)])->save();
            $before = $this->snapshot($set);
            $set->forceFill(['lock_version' => $set->lock_version + 1])->save();
            if ($pauseRenewal) {
                $this->reminders->syncDocumentRenewal($current, $asset, $set, null, $requestKey);
            }
            $this->event($set, $current, 'file_archived', trim($reason), $before, $this->snapshot($set) + ['document_id' => $document->id], $requestKey, $fingerprint);

            return $document;
        }, 3);
    }

    /** Retry storage, scanning or publication of a file that did not finish. */
    public function retryFile(User $actor, int $assetId, int $documentId): AssetDocument
    {
        [, $asset] = DB::transaction(fn (): array => $this->resolve($actor, $assetId));
        $document = AssetDocument::query()->whereKey($documentId)->where('asset_id', $asset->id)->whereNotNull('document_set_id')->first() ?? abort(404);
        abort_unless(in_array($document->state, ['scan_unavailable', 'publication_failed', 'reserved', 'stored'], true), 409,
            'Only files still waiting for their check can be retried.');
        abort_unless($document->storage_path && Storage::disk(self::DISK)->exists($document->storage_path), 409,
            'The original file did not reach storage. Upload it again.');

        return $this->scanAndPublish($actor, $assetId, $document);
    }

    /** Scan a pre-existing (legacy) file now that checking is available. */
    public function verifyLegacyFile(User $actor, int $assetId, int $documentId): AssetDocument
    {
        [, $asset] = DB::transaction(fn (): array => $this->resolve($actor, $assetId));
        $document = AssetDocument::query()->whereKey($documentId)->where('asset_id', $asset->id)
            ->where('state', AssetDocument::STATE_LEGACY)->first() ?? abort(404);
        $disk = Storage::disk($document->storage_disk ?: 'local');
        abort_unless($disk->exists($document->storage_path), 409, 'The original file is no longer in storage.');
        $scan = $this->scan($disk->path($document->storage_path));
        $document->forceFill([
            'scan_disposition' => $scan->disposition->value, 'scanner' => mb_substr($scan->scanner, 0, 80),
            'scan_failure_code' => $scan->errorCode, 'scan_attempted_at' => now(),
            'scanned_at' => $scan->disposition === MalwareScanDisposition::Unavailable ? null : now(),
            // Only a clean result upgrades trust; infected files are withheld.
            'state' => match ($scan->disposition) {
                MalwareScanDisposition::Clean => AssetDocument::STATE_AVAILABLE,
                MalwareScanDisposition::Infected => 'quarantined',
                default => AssetDocument::STATE_LEGACY,
            },
        ])->save();

        return $document;
    }

    /** Stream a file after rechecking current access. Images may be shown inline. */
    public function download(User $actor, int $assetId, int $documentId, bool $inline = false): StreamedResponse
    {
        $asset = $this->access->assignableVehicle($actor, $assetId) ?? abort(404);
        abort_unless(Gate::forUser($actor)->allows('view', $asset), 404);
        $document = AssetDocument::query()->whereKey($documentId)->where('asset_id', $asset->id)->first() ?? abort(404);
        // Finance review evidence (quotes, invoices) opens only for Finance viewers.
        abort_if($document->source_type === 'finance_review_request' && ! $actor->canDo('finance.assets.view'), 404);
        // Booking evidence follows the booking's own Site rule, as the calendar does.
        abort_if($document->source_type === 'booking' && ! $this->bookings->accessibleBookings($actor)
            ->whereKey((int) $document->source_id)->exists(), 404);
        abort_unless($document->isOpenable(), 409, 'This file is not available to open. It has not passed its virus check.');
        $disk = Storage::disk($document->storage_disk ?: 'local');
        abort_unless($disk->exists($document->storage_path), 404);
        AuditLogger::log('fleet.vehicle.document.download', $document, ['asset_id' => $asset->id]);
        $mime = $document->detected_mime ?: ($document->mime_type ?: 'application/octet-stream');
        $name = self::safeName((string) ($document->original_name ?: 'vehicle-document'));
        $inline = $inline && str_starts_with($mime, 'image/');

        return $disk->response($document->storage_path, $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    /**
     * Upload a new profile photo. It replaces the current photo only once the
     * image is clean; until then the previous photo stays.
     */
    public function uploadPhoto(User $actor, int $assetId, UploadedFile $file, string $requestKey): AssetDocument
    {
        self::assertKey($requestKey);
        $checked = $this->checkFiles([$file], false, true);
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'photo' => $checked[0]['sha256']]);
        $document = DB::transaction(function () use ($actor, $assetId, $checked, $requestKey, $fingerprint): AssetDocument {
            [$current, $asset] = $this->resolve($actor, $assetId);
            $prior = AssetDocument::query()->where('asset_id', $asset->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different photo.');

                return $prior;
            }

            return $this->reserveOne($current, $asset, null, null, $checked[0], $requestKey, $fingerprint, 'vehicle_profile_photo');
        }, 3);
        $document = $this->storeBytes($document, $file);
        if ($document->state === 'stored') {
            $document = $this->scanAndPublish($actor, $assetId, $document);
        }
        if ($document->state === AssetDocument::STATE_AVAILABLE) {
            DB::transaction(function () use ($actor, $assetId, $document): void {
                [, $asset] = $this->resolve($actor, $assetId);
                $asset->forceFill(['profile_photo_document_id' => $document->id])->save();
                AuditLogger::logOrFail('fleet.vehicle.photo.update', $asset, ['asset_id' => $asset->id, 'document_id' => $document->id]);
            }, 3);
        }

        return $document->fresh();
    }

    public function removePhoto(User $actor, int $assetId): void
    {
        DB::transaction(function () use ($actor, $assetId): void {
            [, $asset] = $this->resolve($actor, $assetId);
            $asset->forceFill(['profile_photo_document_id' => null])->save();
            AuditLogger::logOrFail('fleet.vehicle.photo.remove', $asset, ['asset_id' => $asset->id]);
        }, 3);
    }

    /**
     * @param  list<AssetDocument>  $reserved
     * @param  list<UploadedFile>  $files
     * @return list<AssetDocument>
     */
    private function finish(User $actor, int $assetId, AssetDocumentSet $set, array $reserved, array $files): array
    {
        $result = [];
        foreach ($reserved as $index => $document) {
            $document = $document->fresh();
            if (in_array($document->state, ['reserved', 'storage_failed'], true) && isset($files[$index])) {
                $document = $this->storeBytes($document, $files[$index]);
            }
            if (in_array($document->state, ['stored', 'scan_unavailable', 'publication_failed'], true)) {
                $document = $this->scanAndPublish($actor, $assetId, $document);
            }
            $result[] = $document;
        }

        return $result;
    }

    private function storeBytes(AssetDocument $document, UploadedFile $file): AssetDocument
    {
        try {
            $stored = Storage::disk(self::DISK)->putFileAs(dirname($document->storage_path), $file, basename($document->storage_path));
        } catch (\Throwable) {
            $stored = false;
        }
        $document->forceFill(['state' => $stored === $document->storage_path ? 'stored' : 'storage_failed'])->save();

        return $document;
    }

    private function scanAndPublish(User $actor, int $assetId, AssetDocument $document): AssetDocument
    {
        // No database locks are held while scanning.
        $scan = $this->scan(Storage::disk(self::DISK)->path($document->storage_path));
        $document->forceFill([
            'scan_disposition' => $scan->disposition->value, 'scanner' => mb_substr($scan->scanner, 0, 80),
            'scan_failure_code' => $scan->errorCode, 'scan_attempted_at' => now(),
            'scanned_at' => $scan->disposition === MalwareScanDisposition::Unavailable ? null : now(),
            'state' => match ($scan->disposition) {
                MalwareScanDisposition::Clean => 'stored',
                MalwareScanDisposition::Infected => 'quarantined',
                default => 'scan_unavailable',
            },
        ])->save();
        if ($scan->disposition !== MalwareScanDisposition::Clean) {
            return $document;
        }

        try {
            return DB::transaction(function () use ($actor, $assetId, $document): AssetDocument {
                // Recheck the actor, the vehicle and the document before publishing.
                [$current, $asset] = $this->resolve($actor, $assetId,
                    $document->source_type === 'booking' && $document->source_id ? (int) $document->source_id : null);
                $locked = AssetDocument::query()->whereKey($document->id)->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
                if ($locked->document_set_id) {
                    $set = $this->lockSet($asset, (int) $locked->document_set_id);
                    abort_if($set->archived_at !== null, 409, 'This document was archived before the upload finished.');
                    if ((int) $locked->revision > (int) $set->current_revision) {
                        $set->forceFill(['current_revision' => $locked->revision])->save();
                        $this->event($set, $current, 'revision_published', null, null, $this->snapshot($set), null, null);
                    }
                }
                $locked->forceFill(['state' => AssetDocument::STATE_AVAILABLE])->save();
                AuditLogger::logOrFail('fleet.vehicle.document.publish', $locked, ['asset_id' => $asset->id]);

                return $locked;
            }, 3);
        } catch (\Throwable $exception) {
            $document->forceFill(['state' => 'publication_failed'])->save();
            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $exception;
            }

            return $document;
        }
    }

    private function scan(string $path): \App\Services\Files\MalwareScanResult
    {
        try {
            return $this->scanner->scanPath($path, (array) config('it.inbound_mail.malware_scanner', []));
        } catch (\Throwable) {
            return new \App\Services\Files\MalwareScanResult(MalwareScanDisposition::Unavailable, 'clamav', 'scanner_failed');
        }
    }

    /**
     * @param  list<array{name:string,size:int,mime:string,sha256:string}>  $checked
     * @return list<AssetDocument>
     */
    private function reserve(User $actor, Asset $asset, AssetDocumentSet $set, int $revision, array $checked, string $requestKey): array
    {
        $reserved = [];
        foreach ($checked as $index => $file) {
            $reserved[] = $this->reserveOne($actor, $asset, $set, $revision, $file, $requestKey.':'.$index, null, $set->source_type);
        }

        return $reserved;
    }

    /** @param array{name:string,size:int,mime:string,sha256:string} $file */
    private function reserveOne(User $actor, Asset $asset, ?AssetDocumentSet $set, ?int $revision, array $file, string $requestKey, ?string $fingerprint, ?string $sourceType): AssetDocument
    {
        $extension = self::extensionFor($file['mime']);

        return AssetDocument::query()->create([
            'asset_id' => $asset->id, 'uploaded_by_user_id' => $actor->id,
            'title' => $set?->category ?? 'Vehicle photo', 'category' => $set?->category ?? 'Vehicle photo',
            'effective_date' => $set?->document_date, 'expiry_date' => $set?->expires_on,
            'storage_disk' => self::DISK,
            // A random private name: the original file name never reaches the path.
            'storage_path' => 'vehicle-documents/'.$asset->id.'/'.Str::uuid()->toString().'.'.$extension,
            'original_name' => $file['name'], 'mime_type' => $file['mime'], 'size_bytes' => $file['size'],
            'document_set_id' => $set?->id, 'revision' => $revision,
            'source_type' => $sourceType, 'source_id' => $set?->source_id,
            'state' => 'reserved', 'sha256' => $file['sha256'], 'detected_mime' => $file['mime'],
            'request_key' => $requestKey, 'request_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Validate bytes before anything is recorded: size, detected type against
     * extension, and decodable images.
     *
     * @param  list<mixed>  $files
     * @return list<array{name:string,size:int,mime:string,sha256:string}>
     */
    private function checkFiles(array $files, bool $required, bool $imagesOnly = false): array
    {
        if ($required && $files === []) {
            throw ValidationException::withMessages(['files' => 'Attach at least one file.']);
        }
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'Attach up to '.self::MAX_FILES.' files at a time.']);
        }
        $message = $imagesOnly ? 'Choose a PNG or JPEG image up to 10 MiB.' : 'Choose PDF, PNG or JPEG files up to 10 MiB each.';
        $checked = [];
        foreach (array_values($files) as $index => $file) {
            $key = $imagesOnly ? 'photo' : "files.{$index}";
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_BYTES) {
                throw ValidationException::withMessages([$key => $message]);
            }
            $extension = strtolower($file->getClientOriginalExtension());
            // Detect the type from the bytes themselves; neither the client's
            // claim nor the file name decides what was uploaded.
            $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()) ?: '');
            $accepted = self::ACCEPTED[$extension] ?? [];
            if (! in_array($mime, $accepted, true) || ($imagesOnly && ! str_starts_with($mime, 'image/'))) {
                throw ValidationException::withMessages([$key => $message]);
            }
            if ($mime === 'application/pdf' && ! str_starts_with((string) file_get_contents((string) $file->getRealPath(), false, null, 0, 5), '%PDF-')) {
                throw ValidationException::withMessages([$key => $message]);
            }
            if (str_starts_with($mime, 'image/') && @getimagesize($file->getRealPath()) === false) {
                throw ValidationException::withMessages([$key => 'This image could not be read. Choose another file.']);
            }
            $checked[] = [
                'name' => mb_substr(self::safeName($file->getClientOriginalName()), 0, 240),
                'size' => (int) $file->getSize(), 'mime' => $mime,
                'sha256' => (string) hash_file('sha256', $file->getRealPath()),
            ];
        }

        return $checked;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function validMeta(array $meta, bool $editing): array
    {
        Validator::make($meta, [
            'category' => ['required', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
            'source_type' => ['nullable', 'in:'.implode(',', array_keys(self::SOURCES))],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'reminder' => ['nullable', 'array'],
            'reminder.enabled' => ['nullable', 'boolean'],
        ], [], ['category' => 'document type', 'document_date' => 'document date', 'expires_on' => 'expiry date', 'reason' => 'reason'])->validate();
        if (! empty($meta['expires_on']) && $meta['expires_on'] < $meta['document_date']) {
            throw ValidationException::withMessages(['expires_on' => 'Expiry must be on or after the document date.']);
        }
        $reminder = null;
        if (filter_var($meta['reminder']['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            if (empty($meta['expires_on'])) {
                throw ValidationException::withMessages(['expires_on' => 'Add an expiry date here before scheduling its reminder.']);
            }
            $reminder = collect($meta['reminder'])->only(['remind_local', 'remind_offset', 'owner_user_id', 'backup_user_id'])->all();
        }

        return [
            'category' => FleetCatalogueService::clean((string) $meta['category']),
            'reference' => trim((string) ($meta['reference'] ?? '')) ?: null,
            'document_date' => $meta['document_date'],
            'expires_on' => $meta['expires_on'] ?? null ?: null,
            'reason' => trim((string) $meta['reason']),
            'source_type' => $editing ? null : ($meta['source_type'] ?? null),
            'source_id' => $editing ? null : (isset($meta['source_id']) ? (int) $meta['source_id'] : null),
            'reminder' => $reminder,
        ];
    }

    private function assertSource(Asset $asset, ?string $type, ?int $id): void
    {
        if ($type === null) {
            return;
        }
        $belongs = $id !== null && match ($type) {
            'compliance_version' => FleetVehicleComplianceVersion::query()->whereKey($id)
                ->whereHas('record', fn ($record) => $record->where('asset_id', $asset->id))->exists(),
            'odometer_observation' => FleetVehicleOdometerObservation::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            'service_completion' => FleetServiceCompletion::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            'service_schedule' => DB::table('fleet_service_schedules')->where('id', $id)->where('asset_id', $asset->id)->exists(),
            'booking' => DB::table('fleet_vehicle_bookings')->where('id', $id)->where('asset_id', $asset->id)
                ->whereNull('deleted_at')->exists(),
            'unavailable_period' => DB::table('fleet_vehicle_unavailable_periods')->where('id', $id)
                ->where('asset_id', $asset->id)->exists(),
            // Files can only be added while Finance has not yet decided the request.
            'finance_review_request' => DB::table('fleet_finance_review_requests')->where('id', $id)
                ->where('asset_id', $asset->id)->where('status', 'submitted')->exists(),
            'checklist_run' => DB::table('fleet_checklist_runs')->where('id', $id)->where('asset_id', $asset->id)
                ->whereNotNull('submitted_at')->exists(),
            'speed_limit' => DB::table('fleet_speed_limits')->where('id', $id)->where('asset_id', $asset->id)
                ->whereIn('status', ['pending', 'approved'])->exists(),
            default => false,
        };
        if (! $belongs) {
            throw ValidationException::withMessages(['source_id' => 'Choose a record that belongs to this vehicle.']);
        }
    }

    /** @return array{0: User, 1: Asset} */
    private function resolve(User $actor, int $assetId, ?int $bookingEvidenceFor = null): array
    {
        $current = User::query()->findOrFail($actor->id);
        $asset = $this->access->assignableVehicle($current, $assetId, true) ?? abort(404);
        abort_unless($this->canManage($current, $asset)
            || ($bookingEvidenceFor !== null && $this->mayAddBookingEvidence($current, $asset, $bookingEvidenceFor)), 403);

        return [$current, $asset];
    }

    /**
     * Evidence for a booking (approval, checkout or return files) may also come
     * from the person who requested it, or a booking approver, when they can
     * open that booking. Every other vehicle file needs document management.
     */
    private function mayAddBookingEvidence(User $actor, Asset $asset, int $bookingId): bool
    {
        $booking = $this->bookings->accessibleBookings($actor)->whereKey($bookingId)
            ->where('asset_id', $asset->id)->first(['id', 'user_id']);

        return $booking !== null && ((int) $booking->user_id === (int) $actor->id
            || $actor->canDo('fleet.bookings.approve') || $actor->canDo('fleet.manage'));
    }

    private function lockSet(Asset $asset, int $setId): AssetDocumentSet
    {
        return AssetDocumentSet::query()->whereKey($setId)->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
    }

    private function replayed(AssetDocumentSet $set, string $requestKey, string $fingerprint): bool
    {
        $prior = AssetDocumentSetEvent::query()->where('document_set_id', $set->id)->where('request_key', $requestKey)->first();
        if (! $prior) {
            return false;
        }
        abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different change.');

        return true;
    }

    /** @return array<string,mixed> */
    private function snapshot(AssetDocumentSet $set): array
    {
        return [
            'category' => $set->category, 'reference' => $set->reference,
            'document_date' => $set->document_date?->toDateString(), 'expires_on' => $set->expires_on?->toDateString(),
            'lock_version' => $set->lock_version, 'current_revision' => $set->current_revision,
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function event(AssetDocumentSet $set, User $actor, string $action, ?string $reason, ?array $before, ?array $after, ?string $requestKey, ?string $fingerprint): void
    {
        AssetDocumentSetEvent::query()->create([
            'document_set_id' => $set->id, 'asset_id' => $set->asset_id, 'action' => $action,
            'set_version' => $set->lock_version, 'actor_user_id' => $actor->id,
            'before_json' => $before, 'after_json' => $after, 'reason' => $reason,
            'request_key' => $requestKey, 'request_fingerprint' => $fingerprint, 'occurred_at' => now(),
        ]);
    }

    private static function assertKey(string $requestKey): void
    {
        if (trim($requestKey) === '' || mb_strlen($requestKey) > 80) {
            throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 80 characters.']);
        }
    }

    private static function extensionFor(string $mime): string
    {
        return ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? 'bin';
    }

    public static function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', '_', $name));

        return $clean !== '' ? $clean : 'vehicle-document';
    }
}
