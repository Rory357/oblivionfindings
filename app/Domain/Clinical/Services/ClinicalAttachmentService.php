<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Persists uploaded evidence files to the polymorphic clinical_attachments table.
 * Shared by the record wizards (create-time) and the detail-modal single-file
 * endpoints. Mirrors the SafeguardingAttachmentController storage pattern.
 */
class ClinicalAttachmentService
{
    public const DISK = 'public';
    public const DIRECTORY = 'clinical_attachments';

    /**
     * Persist one uploaded file as an attachment on the given record.
     *
     * @param  Model  $attachable  a model exposing the `attachments()` morphMany
     * @param  array{kind?: ?string, notes?: ?string, is_sensitive?: bool}  $meta
     */
    public function attach(Model $attachable, UploadedFile $file, ?User $uploader, array $meta = []): ClinicalAttachment
    {
        $path = $file->store(self::DIRECTORY, self::DISK);

        return $attachable->attachments()->create([
            'uploaded_by' => $uploader?->id,
            'disk' => self::DISK,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $meta['kind'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'is_sensitive' => $meta['is_sensitive'] ?? false,
        ]);
    }

    /**
     * Persist many uploaded files (e.g. the staged evidence from a record wizard).
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function attachMany(Model $attachable, array $files, ?User $uploader): int
    {
        $count = 0;
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $this->attach($attachable, $file, $uploader);
                $count++;
            }
        }

        return $count;
    }
}
