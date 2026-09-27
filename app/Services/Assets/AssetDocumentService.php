<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\AssetDocumentSetEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Fleet\VehicleDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Non-vehicle files share the canonical document sets, private disk and scanner. */
final class AssetDocumentService
{
    public function __construct(private readonly MalwareScanner $scanner) {}

    public function upload(User $actor, Asset $asset, UploadedFile $file, array $data, ?AssetDocument $replaces = null): AssetDocument
    {
        unset($data['file']);
        $key = (string) $data['request_key'];
        $hash = hash_file('sha256', $file->getRealPath());
        $fingerprint = hash('sha256', json_encode([$actor->id, $asset->id, $data, $hash, $file->getClientOriginalName(), $replaces?->id], JSON_THROW_ON_ERROR));
        $document = DB::transaction(function () use ($actor, $asset, $file, $data, $key, $hash, $fingerprint, $replaces) {
            $asset = $this->writable($actor, $asset);
            $prior = AssetDocument::where('asset_id', $asset->id)->where('request_key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request already describes a different file.');

                return $prior;
            }
            $set = null;
            if ($replaces) {
                $replaces = $asset->documents()->whereKey($replaces->id)->lockForUpdate()->firstOrFail();
                abort_if($replaces->isSourceOwned() || $replaces->archived_at, 409, 'This file must be managed at its original source.');
                if ($replaces->document_set_id) {
                    $set = AssetDocumentSet::whereKey($replaces->document_set_id)->lockForUpdate()->firstOrFail();
                    abort_unless($set->lock_version === (int) ($data['expected_version'] ?? 0), 409, 'This document changed. Refresh before replacing it.');
                    abort_unless($set->current_revision === $replaces->revision, 409, 'Replace the current document version.');
                    abort_if($set->files()->where('revision', '>', $set->current_revision)->whereIn('state', ['reserved', 'stored', 'scan_unavailable', 'publication_failed'])->exists(), 409, 'A replacement is still being checked. Retry that file before adding another version.');
                }
            }
            if (! $set) {
                $set = AssetDocumentSet::create([
                    'asset_id' => $asset->id, 'category' => $data['category'] ?? 'Document',
                    'reference' => $data['title'], 'document_date' => $data['effective_date'] ?? today(),
                    'expires_on' => $data['expiry_date'] ?? null, 'lock_version' => 1,
                    'current_revision' => 1, 'created_by_user_id' => $actor->id,
                    'request_key' => $key, 'request_fingerprint' => $fingerprint,
                ]);
                if ($replaces) {
                    $replaces->forceFill(['document_set_id' => $set->id, 'revision' => 1])->save();
                }
            } elseif ($replaces) {
                $set->increment('lock_version');
            }
            $revision = $replaces ? ((int) $set->files()->max('revision') + 1) : 1;
            $document = AssetDocument::create([
                'asset_id' => $asset->id, 'document_set_id' => $set->id, 'revision' => $revision,
                'uploaded_by_user_id' => $actor->id, 'title' => $data['title'], 'category' => $data['category'] ?? null,
                'effective_date' => $data['effective_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null,
                'notes' => $data['notes'] ?? null, 'storage_disk' => 'private',
                'storage_path' => 'asset-documents/'.$asset->id.'/'.Str::uuid().'.'.$file->extension(),
                'original_name' => VehicleDocumentService::safeName($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(), 'detected_mime' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                'sha256' => $hash, 'state' => 'reserved', 'request_key' => $key, 'request_fingerprint' => $fingerprint,
            ]);
            $this->event($set, $actor, $replaces ? 'replacement_started' : 'created', $data['reason'] ?? 'Document uploaded', ['file_id' => $document->id, 'revision' => $revision]);

            return $document;
        }, 3);

        if (! in_array($document->state, ['reserved', 'storage_failed'], true)) {
            return $document;
        }
        try {
            $stored = Storage::disk('private')->putFileAs(dirname($document->storage_path), $file, basename($document->storage_path));
        } catch (\Throwable) {
            $stored = false;
        }
        $document = DB::transaction(function () use ($document, $stored) {
            $current = AssetDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            // Duplicate requests may finish storage after another request has scanned it.
            // Never downgrade a published, quarantined or archived version.
            if (! $current->archived_at && in_array($current->state, ['reserved', 'storage_failed'], true)) {
                $current->forceFill(['state' => $stored ? 'stored' : 'storage_failed'])->save();
            }

            return $current;
        }, 3);

        return $stored && $document->state === 'stored' && ! $document->archived_at ? $this->retry($actor, $asset, $document) : $document;
    }

    public function retry(User $actor, Asset $asset, AssetDocument $document): AssetDocument
    {
        Gate::forUser($actor)->authorize('manageDocuments', $asset);
        abort_unless($document->asset_id === $asset->id && ! $document->isSourceOwned(), 404);
        abort_if($document->archived_at !== null || $document->state === 'quarantined', 409, 'An archived or quarantined file cannot be retried.');
        if ($document->state === 'available') {
            return $document;
        }
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path), 404);
        try {
            $scan = $this->scanner->scanPath(Storage::disk($document->storage_disk)->path($document->storage_path), (array) config('it.inbound_mail.malware_scanner', []));
        } catch (\Throwable) {
            $scan = new MalwareScanResult(MalwareScanDisposition::Unavailable, 'clamav', 'scanner_failed');
        }

        return DB::transaction(function () use ($actor, $asset, $document, $scan) {
            $asset = $this->writable($actor, $asset);
            $document = $asset->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_if($document->archived_at !== null, 409, 'This file was archived while it was being checked.');
            // A concurrent infected result always wins over an older clean result.
            if ($document->state === 'quarantined') {
                return $document;
            }
            if ($document->state === 'available' && $scan->disposition === MalwareScanDisposition::Unavailable) {
                return $document;
            }
            $document->forceFill([
                'state' => match ($scan->disposition) {
                    MalwareScanDisposition::Clean => 'available', MalwareScanDisposition::Infected => 'quarantined', default => 'scan_unavailable'
                },
                'scan_disposition' => $scan->disposition->value, 'scanner' => mb_substr($scan->scanner, 0, 80),
                'scan_failure_code' => $scan->errorCode, 'scan_attempted_at' => now(),
                'scanned_at' => $scan->disposition === MalwareScanDisposition::Unavailable ? null : now(),
            ])->save();
            if ($document->document_set_id && $scan->disposition === MalwareScanDisposition::Clean) {
                $set = AssetDocumentSet::whereKey($document->document_set_id)->lockForUpdate()->firstOrFail();
                if ($document->revision > $set->current_revision) {
                    $set->forceFill(['current_revision' => $document->revision, 'lock_version' => $set->lock_version + 1])->save();
                    $this->event($set, $actor, 'revision_published', 'File passed its virus check', ['file_id' => $document->id, 'revision' => $document->revision]);
                }
            }
            AuditLogger::logOrFail('assets.documents.checked', $document, ['state' => $document->state]);

            return $document;
        }, 3);
    }

    public function archive(User $actor, Asset $asset, AssetDocument $document, string $reason): AssetDocument
    {
        return DB::transaction(function () use ($actor, $asset, $document, $reason) {
            $asset = $this->writable($actor, $asset);
            $document = $asset->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_if($document->isSourceOwned(), 409, 'Archive this file at its original source.');
            if (! $document->archived_at) {
                $document->forceFill(['archived_at' => now(), 'archived_by_user_id' => $actor->id, 'archive_reason' => $reason])->save();
                if ($document->document_set_id) {
                    $set = AssetDocumentSet::whereKey($document->document_set_id)->lockForUpdate()->firstOrFail();
                    $set->increment('lock_version');
                    $this->event($set, $actor, 'file_archived', $reason, ['file_id' => $document->id]);
                }
                AuditLogger::logOrFail('assets.documents.archived', $document, ['reason' => $reason]);
            }

            return $document;
        }, 3);
    }

    private function writable(User $actor, Asset $asset): Asset
    {
        $actor = User::findOrFail($actor->id);
        $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('manageDocuments', $asset);
        abort_if(Asset::vehicles()->whereKey($asset->id)->exists(), 409, 'Manage vehicle documents in the vehicle profile.');
        abort_if($asset->status === 'retired', 409, 'This asset is retired. Retained document versions remain available to read.');

        return $asset;
    }

    private function event(AssetDocumentSet $set, User $actor, string $action, string $reason, array $after): void
    {
        AssetDocumentSetEvent::create(['document_set_id' => $set->id, 'asset_id' => $set->asset_id, 'set_version' => $set->lock_version, 'action' => $action, 'actor_user_id' => $actor->id, 'reason' => $reason, 'after_json' => $after, 'occurred_at' => now()]);
    }
}
