<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\TimelineEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class PortalDocumentController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $documents = ClientDocument::where('client_id', $client->id)
            ->where('portal_visible', true)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'category' => $doc->category,
                'notes' => $doc->notes,
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'version' => $doc->version,
                'created_at' => $doc->created_at?->toISOString(),
            ]);

        // Family-uploaded documents (category = 'family_upload', always visible to portal)
        $familyUploads = ClientDocument::where('client_id', $client->id)
            ->where('category', 'family_upload')
            ->where('uploaded_by_user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'category' => $doc->category,
                'notes' => $doc->notes,
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'version' => $doc->version,
                'created_at' => $doc->created_at?->toISOString(),
                'is_own' => true,
            ]);

        $allDocs = $documents->map(fn ($d) => array_merge($d, ['is_own' => false]))
            ->merge($familyUploads)
            ->unique('id')
            ->sortByDesc('created_at')
            ->values();

        return inertia('portal/documents', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'documents' => $allDocs,
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf', // 20MB
            'title' => 'required|string|max:200',
            'notes' => 'nullable|string|max:1000',
        ]);

        $file = $request->file('file');
        $path = $file->store("client_documents/{$client->id}", 'local');

        $doc = ClientDocument::create([
            'client_id' => $client->id,
            'uploaded_by_user_id' => $user->id,
            'title' => $request->input('title'),
            'category' => 'family_upload',
            'folder' => 'Family Uploads',
            'version' => '1',
            'notes' => $request->input('notes'),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'portal_visible' => true,
        ]);

        TimelineEvent::create([
            'source_type' => ClientDocument::class,
            'source_id' => $doc->id,
            'occurred_at' => now(),
            'type' => 'document_uploaded',
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Family uploaded: ' . ($doc->title ?: $doc->original_name),
            'body' => $request->input('notes'),
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        AuditLogger::log('portal.document.upload', $client);

        return redirect()->back()->with('success', 'Document uploaded successfully.');
    }
}
