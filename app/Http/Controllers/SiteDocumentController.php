<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDocument;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteDocumentController extends Controller
{
    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'version' => ['nullable', 'string', 'max:30'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('file');
        $dir = "site_documents/{$site->id}";
        $stored = $file->store($dir, 'local');

        $doc = SiteDocument::create([
            'site_id' => $site->id,
            'uploaded_by_user_id' => $request->user()?->id,
            'title' => $data['title'] ?? null,
            'category' => $data['category'] ?? null,
            'version' => $data['version'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'storage_disk' => 'local',
            'storage_path' => $stored,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        AuditLogger::log('sites.documents.upload', $doc, [
            'site_id' => $site->id,
            'original_name' => $doc->original_name,
        ]);

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'site_document', $doc, null, [
            'title' => 'Site document uploaded',
            'body' => ($doc->title ?: $doc->original_name),
            'url' => url("/sites/{$site->id}"),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'document' => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'category' => $doc->category,
                    'expiry_date' => $doc->expiry_date?->toDateString(),
                    'notes' => $doc->notes,
                    'original_name' => $doc->original_name,
                    'size_bytes' => $doc->size_bytes,
                ],
            ]);
        }

        return back()->with('success', 'Document uploaded.');
    }

    public function update(Request $request, Site $site, SiteDocument $document)
    {
        $this->authorize('update', $site);
        abort_unless($document->site_id === $site->id, 404);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'version' => ['nullable', 'string', 'max:30'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $document->update($data);

        AuditLogger::log('sites.documents.update', $document, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'site_document', $document, null, [
            'title' => 'Site document updated',
            'body' => ($document->title ?: $document->original_name),
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Document updated.');
    }

    public function download(Request $request, Site $site, SiteDocument $document)
    {
        $this->authorize('view', $site);
        abort_unless($document->site_id === $site->id, 404);

        AuditLogger::log('sites.documents.download', $document, [
            'site_id' => $site->id,
            'original_name' => $document->original_name,
        ]);

        return Storage::disk($document->storage_disk)->download(
            $document->storage_path,
            $document->original_name
        );
    }

    public function destroy(Request $request, Site $site, SiteDocument $document)
    {
        $this->authorize('update', $site);
        abort_unless($document->site_id === $site->id, 404);

        Storage::disk($document->storage_disk)->delete($document->storage_path);
        $document->delete();

        AuditLogger::log('sites.documents.delete', $site, [
            'site_id' => $site->id,
            'original_name' => $document->original_name,
        ]);

        app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'site_document', $document, null, [
            'title' => 'Site document deleted',
            'body' => ($document->title ?: $document->original_name),
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Document deleted.');
    }
}
