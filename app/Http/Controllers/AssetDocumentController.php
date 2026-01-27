<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AssetDocumentController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('manageDocuments', $asset);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20MB
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:80'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $file = $request->file('file');
        $disk = 'local';

        $path = $file->storeAs(
            "assets/{$asset->id}",
            time() . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName()),
            $disk
        );

        $doc = AssetDocument::create([
            'asset_id' => $asset->id,
            'uploaded_by_user_id' => $request->user()?->id,
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'version' => $data['version'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        AuditLogger::log('assets.documents.create', $doc, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return back();
    }

    public function download(Request $request, Asset $asset, AssetDocument $document)
    {
        $this->authorize('view', $asset);
        abort_unless($document->asset_id === $asset->id, 404);

        AuditLogger::log('assets.documents.download', $document, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return Storage::disk($document->storage_disk)->download(
            $document->storage_path,
            $document->original_name ?? basename($document->storage_path)
        );
    }

    public function destroy(Request $request, Asset $asset, AssetDocument $document)
    {
        $this->authorize('manageDocuments', $asset);
        abort_unless($document->asset_id === $asset->id, 404);

        Storage::disk($document->storage_disk)->delete($document->storage_path);
        $document->delete();

        AuditLogger::log('assets.documents.delete', $document, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return back();
    }
}
