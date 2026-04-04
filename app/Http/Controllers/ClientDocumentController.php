<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\TimelineEvent;
use App\Services\Rag\OpenAiVectorStoreClient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientDocumentController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        AuditLogger::log('documents.list', $client);

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->with(['uploadedBy:id,name,email'])
            ->get();

        return inertia('operations/clients/documents', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'can_edit' => $request->user()?->canDo('clients.update') ?? false,
            'documents' => $documents->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'folder' => $d->folder,
                'version' => $d->version,
                'effective_date' => optional($d->effective_date)->toDateString(),
                'expiry_date' => optional($d->expiry_date)->toDateString(),
                'portal_visible' => (bool) $d->portal_visible,
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

    public function store(Request $request, Client $client, OpenAiVectorStoreClient $openai)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'folder' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:30'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'portal_visible' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('file');
        $dir = "client_documents/{$client->id}";
        $stored = $file->store($dir, 'local');

        $doc = ClientDocument::create([
            'client_id' => $client->id,
            'uploaded_by_user_id' => $request->user()?->id,
            'title' => $data['title'] ?? null,
            'category' => $data['category'] ?? null,
            'folder' => $data['folder'] ?? null,
            'version' => $data['version'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'portal_visible' => (bool) ($data['portal_visible'] ?? false),
            'notes' => $data['notes'] ?? null,
            'storage_disk' => 'local',
            'storage_path' => $stored,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        // RAG: attach uploaded docs directly to this client's vector store.
        if ($openai->isEnabled()) {
            if (!$client->openai_vector_store_id) {
                $vsId = $openai->createVectorStore('client_' . $client->id);
                if ($vsId) {
                    $client->forceFill(['openai_vector_store_id' => $vsId])->save();
                }
            }

            if ($client->openai_vector_store_id) {
                $abs = Storage::disk('local')->path($stored);
                $fileId = $openai->uploadFile($abs, basename($stored));
                if ($fileId) {
                    $openai->attachFileToVectorStore($client->openai_vector_store_id, $fileId);
                    $doc->forceFill(['openai_file_id' => $fileId])->save();
                }
            }
        }

        TimelineEvent::create([
            'source_type' => ClientDocument::class,
            'source_id' => $doc->id,
            'occurred_at' => now(),
            'type' => 'document_uploaded',
            'actor_user_id' => $request->user()?->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Document uploaded: ' . ($doc->title ?: $doc->original_name),
            'body' => $data['notes'] ?? null,
            'meta' => array_filter([
                'title' => $doc->title,
                'category' => $doc->category,
                'original_name' => $doc->original_name,
            ]),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $request->user()?->id,
        ]);

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'document', $doc, $client, [
            'title' => 'Document uploaded',
            'body' => ($doc->title ?: $doc->original_name),
            'url' => url("/clients/{$client->id}/documents"),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function update(Request $request, Client $client, ClientDocument $document)
    {
        $this->authorize('update', $client);
        abort_unless($document->client_id === $client->id, 404);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'folder' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:30'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'portal_visible' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Only update portal_visible if it was explicitly sent (so older clients don't accidentally flip)
        if (!array_key_exists('portal_visible', $data)) {
            $data['portal_visible'] = $document->portal_visible;
        }

        $document->update($data);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'document', $document, $client, [
            'title' => 'Document updated',
            'body' => ($document->title ?: $document->original_name),
            'url' => url("/clients/{$client->id}/documents"),
        ]);

        return back()->with('success', 'Document updated.');
    }

    public function download(Request $request, Client $client, ClientDocument $document)
    {
        $this->authorize('view', $client);
        abort_unless($document->client_id === $client->id, 404);

        // If downloaded from the portal, only allow documents explicitly shared.
        $routeName = $request->route()?->getName();
        if ($routeName && str_starts_with($routeName, 'portal.')) {
            abort_unless($document->portal_visible, 403);
        }

        AuditLogger::log('documents.download', $document, [
            'document_id' => $document->id,
            'original_name' => $document->original_name,
        ]);

        return Storage::disk($document->storage_disk)->download(
            $document->storage_path,
            $document->original_name
        );
    }

    public function destroy(Request $request, Client $client, ClientDocument $document)
    {
        $this->authorize('update', $client);
        abort_unless($document->client_id === $client->id, 404);

        Storage::disk($document->storage_disk)->delete($document->storage_path);
        $document->delete();

        app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'document', $document, $client, [
            'title' => 'Document deleted',
            'body' => ($document->title ?: $document->original_name),
            'url' => url("/clients/{$client->id}/documents"),
        ]);

        return back()->with('success', 'Document deleted.');
    }
}
