<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetVehicleComplianceVersion;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AssetDocumentController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('manageDocuments', $asset);
        // PKG-02B: vehicle files are virus-checked and versioned in the vehicle profile.
        if (Asset::vehicles()->whereKey($asset->id)->exists()) {
            throw ValidationException::withMessages([
                'file' => 'Add vehicle documents in the vehicle profile. Files are virus-checked there before anyone can open them.',
            ]);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf'], // 20MB
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

    public function download(Request $request, Asset $asset, AssetDocument $document, VehicleDocumentService $vehicleDocuments)
    {
        $this->authorize('view', $asset);
        abort_unless($document->asset_id === $asset->id, 404);
        // PKG-02B vehicle finance: review evidence (quotes, invoices) opens only for Finance viewers.
        abort_if($document->source_type === 'finance_review_request' && ! $request->user()?->canDo('finance.assets.view'), 404);
        abort_unless($document->isOpenable(), 409, 'This file is not available to open. It has not passed its virus check.');
        // Vehicle files stream through the vehicle profile's download, which
        // rechecks vehicle access and sends the private-file headers.
        if ($document->isVehicleManaged()) {
            return $vehicleDocuments->download($request->user(), $asset->id, $document->id);
        }

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
        // PKG-02B keeps vehicle files: they are archived with a reason, never deleted.
        abort_if($document->isVehicleManaged(), 409,
            'This file is kept in the vehicle profile. Archive it there and record why, instead of deleting it.');
        abort_if(FleetVehicleComplianceVersion::query()->where('asset_document_id', $document->id)->exists(), 409,
            'This file is evidence in the vehicle\'s compliance history, so it can\'t be deleted.');

        // Remove the record before the bytes: anything else that still points
        // at the file blocks the delete while the file is intact.
        $document->delete();
        Storage::disk($document->storage_disk)->delete($document->storage_path);

        AuditLogger::log('assets.documents.delete', $document, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return back();
    }
}
