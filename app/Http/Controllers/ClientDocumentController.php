<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Services\Rag\OpenAiVectorStoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientDocumentController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->with(['uploadedBy:id,name,email'])
            ->get();

        return inertia('clients/documents', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'can_edit' => $request->user()?->canDo('clients.update') ?? false,
            'documents' => $documents->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
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

        return back()->with('success', 'Document uploaded.');
    }

    public function download(Request $request, Client $client, ClientDocument $document)
    {
        $this->authorize('view', $client);
        abort_unless($document->client_id === $client->id, 404);

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

        return back()->with('success', 'Document deleted.');
    }
}
