<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDocument;
use App\Models\SiteDocumentFolder;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Support\SiteRecommendedDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteDocumentController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        AuditLogger::log('sites.documents.list', $site, [
            'site_id' => $site->id,
        ]);

        $documents = SiteDocument::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->with(['uploadedBy:id,name,email'])
            ->get();

        $folderRecords = SiteDocumentFolder::query()
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);

        $folderNames = $folderRecords
            ->pluck('name')
            ->merge($documents->pluck('folder')->filter())
            ->map(fn ($folder) => trim((string) $folder))
            ->filter(fn ($folder) => $folder !== '')
            ->unique()
            ->sort()
            ->values();

        return inertia('sites/documents', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'display_type' => $site->display_type,
            ],
            'can_edit' => (bool) ($request->user()?->canDo('sites.update') && $request->user()?->can('update', $site)),
            'recommendedDocuments' => SiteRecommendedDocuments::forType($site->type),
            'folders' => $folderNames->map(fn ($name) => [
                'id' => $folderRecords->firstWhere('name', $name)?->id,
                'name' => $name,
            ])->values(),
            'documents' => $documents->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'folder' => $d->folder,
                'version' => $d->version,
                'effective_date' => optional($d->effective_date)->toDateString(),
                'expiry_date' => optional($d->expiry_date)->toDateString(),
                'notes' => $d->notes,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'created_at' => optional($d->created_at)->toISOString(),
                'uploaded_by' => $d->uploadedBy ? [
                    'id' => $d->uploadedBy->id,
                    'name' => $d->uploadedBy->name,
                    'email' => $d->uploadedBy->email,
                ] : null,
            ])->values(),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf'],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'folder' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:30'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $folder = $this->ensureFolder($site, $data['folder'] ?? null);

        $file = $request->file('file');
        $dir = "site_documents/{$site->id}";
        $stored = $file->store($dir, 'local');

        $doc = SiteDocument::create([
            'site_id' => $site->id,
            'uploaded_by_user_id' => $request->user()?->id,
            'title' => $data['title'] ?? null,
            'category' => $data['category'] ?? null,
            'folder' => $folder,
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
                    'folder' => $doc->folder,
                    'version' => $doc->version,
                    'effective_date' => $doc->effective_date?->toDateString(),
                    'expiry_date' => $doc->expiry_date?->toDateString(),
                    'notes' => $doc->notes,
                    'original_name' => $doc->original_name,
                    'mime_type' => $doc->mime_type,
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
            'folder' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:30'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('folder', $data)) {
            $data['folder'] = $this->ensureFolder($site, $data['folder']);
        }

        $document->update($data);

        AuditLogger::log('sites.documents.update', $document, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'site_document', $document, null, [
            'title' => 'Site document updated',
            'body' => ($document->title ?: $document->original_name),
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Document updated.');
    }

    public function storeFolder(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $folder = $this->ensureFolder($site, $data['name']);
        if ($folder === null) {
            return back()->withErrors(['name' => 'The folder name field is required.']);
        }

        return back()->with('success', 'Folder created.');
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

    private function ensureFolder(Site $site, ?string $folder): ?string
    {
        $folder = trim((string) $folder);
        if ($folder === '') {
            return null;
        }

        SiteDocumentFolder::query()->firstOrCreate([
            'site_id' => $site->id,
            'name' => $folder,
        ]);

        return $folder;
    }
}
