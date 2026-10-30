<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetVehicleComplianceVersion;
use App\Services\Assets\AssetDocumentService;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $request->merge(['request_key' => $request->input('request_key') ?: $request->header('Idempotency-Key') ?: (string) Str::uuid()]);
        $data['request_key'] = $request->validate(['request_key' => ['required', 'string', 'max:80']])['request_key'];
        $document = app(AssetDocumentService::class)->upload($request->user(), $asset, $request->file('file'), $data);

        return $request->expectsJson() ? response()->json(['document' => ['id' => $document->id, 'state' => $document->state]], 201) : back();
    }

    public function replace(Request $request, Asset $asset, AssetDocument $document, AssetDocumentService $documents)
    {
        $this->authorize('manageDocuments', $asset);
        abort_unless($document->asset_id === $asset->id, 404);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf'],
            'title' => ['required', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:120'],
            'effective_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'], 'expected_version' => ['required', 'integer', 'min:0'],
            'request_key' => ['required', 'string', 'max:80'],
        ]);
        $file = $documents->upload($request->user(), $asset, $request->file('file'), $data, $document);

        return response()->json(['document' => ['id' => $file->id, 'state' => $file->state]], 201);
    }

    public function archive(Request $request, Asset $asset, AssetDocument $document, AssetDocumentService $documents)
    {
        $this->authorize('manageDocuments', $asset);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/']]);
        $file = $documents->archive($request->user(), $asset, $document, trim($data['reason']));

        return response()->json(['document' => ['id' => $file->id, 'archived' => true]]);
    }

    public function retry(Request $request, Asset $asset, AssetDocument $document, AssetDocumentService $documents)
    {
        $file = $documents->retry($request->user(), $asset, $document);

        return response()->json(['document' => ['id' => $file->id, 'state' => $file->state]]);
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
        if ($document->isVehicleManaged() && Asset::vehicles()->whereKey($asset->id)->exists()) {
            return $vehicleDocuments->download($request->user(), $asset->id, $document->id, $request->boolean('inline'));
        }

        abort_if($document->isSourceOwned(), 404);
        $disk = Storage::disk($document->storage_disk ?: 'private');
        abort_unless($disk->exists($document->storage_path), 404, 'The original file is unavailable.');
        $mime = $document->detected_mime ?: ($disk->mimeType($document->storage_path) ?: 'application/octet-stream');
        $inline = $request->boolean('inline') && in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
        AuditLogger::log('assets.documents.download', $document, ['asset_id' => $asset->id, 'inline' => $inline]);

        return $disk->response($document->storage_path, VehicleDocumentService::safeName($document->original_name ?: 'document'), [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'", 'Referrer-Policy' => 'no-referrer',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, Asset $asset, AssetDocument $document)
    {
        $this->authorize('manageDocuments', $asset);
        abort_unless($document->asset_id === $asset->id, 404);
        // PKG-02B keeps vehicle files: they are archived with a reason, never deleted.
        abort_unless(Asset::vehicles()->whereKey($asset->id)->exists(), 409, 'Archive the document with a reason. Asset file originals and history are retained.');
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
