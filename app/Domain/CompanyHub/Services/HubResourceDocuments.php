<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Public document identities come only from visible File blocks in the current release. */
class HubResourceDocuments
{
    public function __construct(private readonly HubPdfText $pdfText) {}

    public function textIncomplete(): bool
    {
        return $this->pdfText->incomplete();
    }

    public function published(User $viewer, int $documentId, bool $lock = false): HubMedia
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer, 403);
        $access = app(HubAccess::class);
        $access->assertEnter($viewer);
        $root = HubMedia::findOrFail($documentId);
        abort_unless($root->documentId() === $documentId, 404);
        $page = HubPage::with('currentRelease')->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($root->hub_page_id);
        $release = $access->assertRead($viewer, $page);
        $ids = HubContent::visibleBlocks($release->content)->where('type', 'file')->pluck('media_id')->filter()->all();
        $edition = HubMedia::where('hub_page_id', $page->id)->whereIn('id', $ids)->get()
            ->filter(fn (HubMedia $media) => $media->documentId() === $documentId)
            ->sortByDesc(fn (HubMedia $media) => $media->documentSequence())->first();
        abort_unless($edition && Storage::disk('private')->exists($edition->path), 404);

        return $edition;
    }

    /** The caller has already checked the current release and exact File reference. */
    public function metadata(HubMedia $media): array
    {
        $documentId = $media->documentId();

        return [
            'document_id' => $documentId, 'document_file_version' => $media->documentSequence(),
            'media_id' => (int) $media->id, 'media_version' => (int) $media->version,
            'filename' => $media->name, 'mime' => $media->mime, 'size' => (int) $media->size,
            'reader_url' => '/company-hub/documents/'.$documentId,
            'download_url' => '/company-hub/media/'.$media->id.'?download=1',
            'preview_url' => in_array($media->mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
                ? '/company-hub/media/'.$media->id.'/preview' : null,
            'bookmark_subject_type' => 'media', 'bookmark_subject_id' => $documentId,
        ];
    }

    public function matches(User $viewer, array $resource, string $query): bool
    {
        if (HubReaderMetadata::matches($resource, $query, $resource['filename'] ?? '')) {
            return true;
        }
        if (($resource['mime'] ?? '') !== 'application/pdf' || empty($resource['document_id'])) {
            return false;
        }
        // Recheck current access/reference even when an earlier result or cache entry is retained.
        try {
            $edition = $this->published($viewer, (int) $resource['document_id']);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                return false;
            }
            throw $exception;
        }
        if ((int) $edition->id !== (int) $resource['media_id']) {
            return false;
        }

        return str_contains(mb_strtolower($this->pdfText->text($edition)), mb_strtolower(trim($query)));
    }
}
