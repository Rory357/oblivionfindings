<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\User;
use App\Services\Fleet\VehicleDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private vehicle documents and the profile photo. Route models are ID
 * carriers only; the service re-resolves every record inside its scope.
 */
class VehicleDocumentController extends Controller
{
    public function __construct(private readonly VehicleDocumentService $documents) {}

    public function store(Request $request, Asset $asset): JsonResponse
    {
        $result = $this->documents->create($this->actor($request), (int) $asset->getKey(), [
            'category' => $request->input('category'),
            'reference' => $request->input('reference'),
            'document_date' => $request->input('document_date'),
            'expires_on' => $request->input('expires_on'),
            'reason' => $request->input('reason'),
            'source_type' => $request->input('source_type'),
            'source_id' => $request->input('source_id'),
            'reminder' => $request->input('reminder'),
        ], array_values((array) $request->file('files', [])), $this->key($request));

        return response()->json($this->result($result['set'], $result['files']));
    }

    public function replace(Request $request, Asset $asset, AssetDocumentSet $set): JsonResponse
    {
        $result = $this->documents->replace($this->actor($request), (int) $asset->getKey(), (int) $set->getKey(),
            array_values((array) $request->file('files', [])), (string) $request->input('reason', ''),
            (int) $request->input('expected_version'), $this->key($request));

        return response()->json($this->result($result['set'], $result['files']));
    }

    public function update(Request $request, Asset $asset, AssetDocumentSet $set): JsonResponse
    {
        $updated = $this->documents->update($this->actor($request), (int) $asset->getKey(), (int) $set->getKey(), [
            'category' => $request->input('category'),
            'reference' => $request->input('reference'),
            'document_date' => $request->input('document_date'),
            'expires_on' => $request->input('expires_on'),
            'reason' => $request->input('reason'),
            'reminder' => $request->input('reminder'),
        ], (int) $request->input('expected_version'), $this->key($request));

        return response()->json($this->result($updated, []));
    }

    public function archive(Request $request, Asset $asset, AssetDocument $document): JsonResponse
    {
        $archived = $this->documents->archiveFile($this->actor($request), (int) $asset->getKey(), (int) $document->getKey(),
            (string) $request->input('reason', ''), $request->boolean('pause_renewal'), $this->key($request));

        return response()->json(['file' => $this->fileDto($archived)]);
    }

    public function retry(Request $request, Asset $asset, AssetDocument $document): JsonResponse
    {
        return response()->json(['file' => $this->fileDto($this->documents->retryFile($this->actor($request), (int) $asset->getKey(), (int) $document->getKey()))]);
    }

    public function verify(Request $request, Asset $asset, AssetDocument $document): JsonResponse
    {
        return response()->json(['file' => $this->fileDto($this->documents->verifyLegacyFile($this->actor($request), (int) $asset->getKey(), (int) $document->getKey()))]);
    }

    public function file(Request $request, Asset $asset, AssetDocument $document): StreamedResponse
    {
        return $this->documents->download($this->actor($request), (int) $asset->getKey(), (int) $document->getKey(), $request->boolean('inline'));
    }

    public function uploadPhoto(Request $request, Asset $asset): JsonResponse
    {
        $file = $request->file('photo');
        $photo = $this->documents->uploadPhoto($this->actor($request), (int) $asset->getKey(),
            $file instanceof \Illuminate\Http\UploadedFile ? $file : abort(422, 'Choose a PNG or JPEG image up to 10 MiB.'), $this->key($request));

        return response()->json(['file' => $this->fileDto($photo)]);
    }

    public function removePhoto(Request $request, Asset $asset): JsonResponse
    {
        $this->documents->removePhoto($this->actor($request), (int) $asset->getKey());

        return response()->json(['removed' => true]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function key(Request $request): string
    {
        return (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: '');
    }

    /** @param list<AssetDocument> $files @return array<string,mixed> */
    private function result(AssetDocumentSet $set, array $files): array
    {
        return [
            'set' => ['id' => $set->id, 'lock_version' => (int) $set->lock_version, 'current_revision' => (int) $set->current_revision],
            'files' => array_map(fn (AssetDocument $file): array => $this->fileDto($file), $files),
        ];
    }

    /** @return array<string,mixed> */
    private function fileDto(AssetDocument $file): array
    {
        return ['id' => $file->id, 'name' => $file->original_name, 'state' => $file->state, 'archived' => $file->archived_at !== null];
    }
}
