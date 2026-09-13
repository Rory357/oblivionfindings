<?php

namespace App\Services\Sites;

use App\Models\User;
use App\Models\VendorAgreement;
use App\Models\VendorAgreementFile;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VendorAgreementFiles
{
    public function upload(User $actor, VendorAgreement $agreement, UploadedFile $upload, int $version, ?int $replace): void
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $mime = $upload->getMimeType();
        $allowed = ['pdf' => ['application/pdf'], 'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']];
        if (! in_array($mime, $allowed[$extension] ?? [], true) || $upload->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Choose a Word (.doc or .docx) or PDF file up to 20 MB.']);
        }
        if ($extension === 'docx') {
            $zip = new \ZipArchive;
            if ($zip->open($upload->getRealPath()) !== true) throw ValidationException::withMessages(['file' => 'This Word file is invalid.']);
            try {
                $entry = $zip->statName('word/document.xml');
                if (! $entry || $entry['size'] > 4 * 1024 * 1024 || $zip->locateName('word/vbaProject.bin') !== false) throw ValidationException::withMessages(['file' => 'Choose a valid Word document without macros.']);
            } finally { $zip->close(); }
        }
        $file = DB::transaction(function () use ($actor, $agreement, $upload, $version, $replace, $mime) {
            $actor = User::findOrFail($actor->id);
            $locked = VendorAgreement::lockForUpdate()->findOrFail($agreement->id);
            app(VendorCommercialAccess::class)->authorize($actor, $locked, 'manage');
            app(VendorAgreements::class)->checkVersion($locked, $version);
            abort_if($locked->status === 'retired' || ! $locked->vendor->is_active, 409);
            $previous = $replace ? $locked->files()->where('state', 'ready')->findOrFail($replace) : null;
            if ($previous && $locked->files()->where('series_id', $previous->series_id)->where('state', 'ready')->where('version', '>', $previous->version)->exists()) throw ValidationException::withMessages(['replace_file_id' => 'Choose the latest ready version of this file.']);
            $series = $previous?->series_id ?? (string) Str::uuid();
            $name = mb_substr(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', $upload->getClientOriginalName()), 0, 240);
            $file = VendorAgreementFile::create(['agreement_id' => $locked->id, 'series_id' => $series,
                'version' => ((int) VendorAgreementFile::where('series_id', $series)->max('version')) + 1,
                'name' => $name, 'path' => 'vendor_agreements/'.Str::uuid(), 'mime' => $mime,
                'size' => $upload->getSize(), 'sha256' => hash_file('sha256', $upload->getRealPath()),
                'state' => 'reserved', 'uploaded_by_user_id' => $actor->id, 'created_at' => now()]);
            app(VendorAgreements::class)->event($locked, $actor, 'file_reserved');
            return $file;
        });
        $disk = Storage::disk('private');
        try {
            $stored = $disk->putFileAs('vendor_agreements', $upload, basename($file->path));
        } catch (\Throwable) {
            $stored = false;
        }
        if ($stored !== $file->path) {
            $file->update(['state' => 'storage_failed']);
            throw ValidationException::withMessages(['file' => 'Upload could not be stored. The previous version is retained.']);
        }
        try {
            $scan = app(MalwareScanner::class)->scanPath($disk->path($file->path), (array) config('it.inbound_mail.malware_scanner'));
        } catch (\Throwable) {
            $file->update(['state' => 'scan_unavailable']);
            throw ValidationException::withMessages(['file' => 'Scanning is unavailable. The previous version is retained.']);
        }
        if ($scan->disposition !== MalwareScanDisposition::Clean) {
            $file->update(['state' => $scan->disposition === MalwareScanDisposition::Infected ? 'quarantined' : 'scan_unavailable']);
            throw ValidationException::withMessages(['file' => 'This upload is unavailable until it passes malware scanning. The previous version is retained.']);
        }
        try {
        DB::transaction(function () use ($actor, $agreement, $file, $version) {
            $actor = User::findOrFail($actor->id);
            $locked = VendorAgreement::lockForUpdate()->findOrFail($agreement->id);
            app(VendorCommercialAccess::class)->authorize($actor, $locked, 'manage');
            app(VendorAgreements::class)->checkVersion($locked, $version);
            abort_if($locked->status === 'retired' || ! $locked->vendor->is_active, 409);
            $file->update(['state' => 'ready']);
            $locked->increment('lock_version');
            app(VendorAgreements::class)->event($locked->refresh(), $actor, 'file_added');
        });
        } catch (\Throwable $exception) {
            $file->fresh()->update(['state' => 'publication_failed']);
            throw $exception;
        }
    }
}
