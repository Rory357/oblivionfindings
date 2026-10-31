<?php

namespace App\Services\Incidents;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Models\ClientIncidentAttachment;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ordinary incident attachments only. No Control Room read grant, byte provenance certificate or public URL. */
final class IncidentEvidenceReadService
{
    use ServesPrivateAttachments;

    private const FILE_COLUMNS = ['id', 'incident_id', 'disk', 'path', 'original_name', 'mime', 'mime_type'];

    public function __construct(private readonly IncidentRegisterReadService $sources) {}

    public function download(Request $request, int $incidentId, int $attachmentId): StreamedResponse
    {
        abort_unless($attachmentId > 0, 404);
        $prepared = $this->sources->consumeSource($request, $incidentId,
            function ($working, $id, $reads, $parent) use ($attachmentId): array {
                $descriptor = $this->descriptorInReads($working, $id, $attachmentId, $reads, $parent);
                // The trait's existence/MIME/length/header preparation also runs inside admitted source evidence.
                $response = $this->streamPrivateAttachment($descriptor['disk'], $descriptor['path'], $descriptor['name'], $descriptor['mime']);
                return ['descriptor' => $descriptor, 'headers' => $response->headers->all()];
            });
        $descriptor = $prepared['descriptor'];
        $headers = $prepared['headers'];
        $headers['cache-control'] = ['private, no-store, no-cache, must-revalidate'];
        return new StreamedResponse(function () use ($descriptor, $headers): void {
            // Resolve the live request receiver, not a captured User or expired read handle.
            $live = request();
            abort_unless((int) $live->user()?->id === $descriptor['viewer_id'], 403);
            $this->sources->consumeSource($live, $descriptor['parent_id'],
                function ($working, $id, $reads, $parent) use ($descriptor, $headers): array {
                    $fresh = $this->descriptorInReads($working, $id, $descriptor['id'], $reads, $parent);
                    abort_unless($fresh === $descriptor, 404);
                    $response = $this->streamPrivateAttachment($fresh['disk'], $fresh['path'], $fresh['name'], $fresh['mime']);
                    // A changed observed file length must not be emitted behind stale HTTP headers. This is not a digest proof.
                    foreach (['content-length', 'content-type', 'content-disposition', 'content-security-policy', 'referrer-policy'] as $header) {
                        abort_unless($response->headers->all($header) === ($headers[$header] ?? []), 404);
                    }
                    // Actual Storage::readStream/fpassthru/fclose executes BEFORE current source evidence expires.
                    $response->sendContent();
                    $reads->assertActive();
                    return [];
                });
        }, 200, $headers);
    }

    /** Internal descriptor only, with original root snapshot and exact raw file metadata; never a page/API DTO. */
    private function descriptorInReads(Request $request, int $incidentId, int $attachmentId, CurrentAuthorizationReads $reads, Builder $parent): array
    {
        $reads->assertActive();
        $root = $reads->query((clone $parent)->reorder()->select('client_incidents.id')
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('root', 'client_incidents').' AS source_binding'))->first();
        abort_unless($root, 404);
        $parents = (clone $parent)->reorder()->select('client_incidents.id')
            ->whereRaw(IncidentIndependenceBindingSql::fingerprint('root', 'client_incidents').' = ?', [$root->source_binding]);
        $file = $reads->query(ClientIncidentAttachment::query()->whereKey($attachmentId)->where('incident_id', $incidentId)
            ->whereIn('incident_id', $parents))->first(self::FILE_COLUMNS);
        abort_unless($file, 404);
        $raw = [];
        foreach (self::FILE_COLUMNS as $column) { $raw[$column] = $file->getRawOriginal($column); }
        $disk = $file->disk ?: self::$PRIVATE_ATTACHMENT_DISK;
        $path = $file->path;
        abort_unless($disk === self::$PRIVATE_ATTACHMENT_DISK && is_string($path) && $path !== ''
            && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! str_contains($path, ':')
            && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! in_array('..', explode('/', $path), true), 404);
        return ['id' => (int) $file->id, 'parent_id' => $incidentId, 'viewer_id' => (int) $request->user()->id,
            'disk' => $disk, 'path' => $path, 'name' => (string) $file->original_name, 'mime' => $file->mime ?: $file->mime_type,
            'source_binding' => $root->source_binding, 'metadata' => $raw, 'attribution_state' => 'legacy_unknown'];
    }
}
