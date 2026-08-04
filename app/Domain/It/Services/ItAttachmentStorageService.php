<?php

namespace App\Domain\It\Services;

use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** One private storage path for ticket and conversation evidence. */
final class ItAttachmentStorageService
{
    /**
     * @param  array<int, UploadedFile>  $attachments
     * @param  array<int, string>  $storedPaths
     */
    public function store(
        ItTicket|ItTicketComment $parent,
        array $attachments,
        User $actor,
        array &$storedPaths,
    ): void {
        foreach ($attachments as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('it_attachments', ItAttachment::DISK);
            if (! is_string($path)) {
                throw new DomainException('The attachment could not be stored. Try again.');
            }
            $storedPaths[] = $path;

            $parent->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
                'uploaded_by' => $actor->id,
            ]);
        }
    }

    /** @param array<int, string> $storedPaths */
    public function deleteStored(array $storedPaths): void
    {
        foreach ($storedPaths as $path) {
            Storage::disk(ItAttachment::DISK)->delete($path);
        }
    }
}
