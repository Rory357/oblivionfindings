<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GovernanceDocumentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.documents.view'), 403);

        $documents = GovernanceDocument::query()
            ->when($request->document_type, fn($q, $type) => $q->where('document_type', $type))
            ->when($request->search, fn($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->through(fn (GovernanceDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->document_type,
                'file_name' => basename($document->file_path),
                'file_size' => (int) ($document->file_size ?? 0),
                'is_confidential' => false,
                'version' => (int) $document->version_number,
                'updated_at' => $document->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Governance/Documents/Index', [
            'documents' => $documents,
            'categories' => [
                ['value' => 'constitution', 'label' => 'Constitution / Charter'],
                ['value' => 'terms_of_reference', 'label' => 'Terms Of Reference'],
                ['value' => 'policy', 'label' => 'Board Policy'],
                ['value' => 'procedure', 'label' => 'Procedure'],
                ['value' => 'template', 'label' => 'Template'],
                ['value' => 'report', 'label' => 'Report'],
                ['value' => 'certificate', 'label' => 'Certificate'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.documents.manage'), 403);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'file' => 'required|file|max:20480',
        ]);

        $path = $request->file('file')->store('governance/documents/' . $validated['category'], 'local');

        GovernanceDocument::create([
            'title' => $validated['title'],
            'document_type' => $validated['category'],
            'category' => null,
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'file_size' => $request->file('file')->getSize(),
            'mime_type' => $request->file('file')->getMimeType(),
            'uploaded_by' => auth()->id(),
            'version_number' => 1,
            'is_current' => true,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function download(GovernanceDocument $document)
    {
        abort_unless(request()->user()?->canDo('governance.documents.view'), 403);

        $path = storage_path('app/' . $document->file_path);
        abort_unless(is_file($path), 404);

        return response()->download($path, basename($document->file_path));
    }

    public function destroy(GovernanceDocument $document)
    {
        abort_unless(request()->user()?->canDo('governance.documents.manage'), 403);

        $document->delete();

        return redirect()->back()->with('success', 'Document removed.');
    }
}
