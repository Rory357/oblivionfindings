<?php

namespace App\Http\Controllers\It\Concerns;

use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;
use App\Support\LegacyStorageContext;
use Illuminate\Http\UploadedFile;

/**
 * One write-path for helpdesk uploads: files ride the SAME request as the
 * ticket/comment they evidence (no free-standing upload endpoint to
 * authorise separately) and land on the private disk.
 */
trait StoresItAttachments
{
    /** Validation rules for an `attachments` array on a ticket/comment write. */
    protected function itAttachmentRules(): array
    {
        return [
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:'.ItAttachment::MAX_SIZE_KB,
                'mimes:'.ItAttachment::ALLOWED_MIMES,
            ],
        ];
    }

    /**
     * Persist uploaded files against their parent (ticket or comment).
     *
     * @param  array<int, UploadedFile>|null  $files
     */
    protected function storeItAttachments(
        ItTicket|ItTicketComment $parent,
        ?array $files,
        User $uploader,
    ): void {
        foreach ($files ?? [] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('it_attachments', ItAttachment::DISK);

            $parent->attachments()->create([
                'tenant_id' => LegacyStorageContext::id(),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
                'uploaded_by' => $uploader->id,
            ]);
        }
    }
}
