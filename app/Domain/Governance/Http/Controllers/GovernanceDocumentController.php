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
            ->when(!$request->user()?->canDo('governance.documents.manage'), fn($q) => $q->where('is_confidential', false))
            ->when($request->category, fn($q, $cat) => $q->where('category', $cat))
            ->when($request->search, fn($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderByDesc('updated_at')
            ->paginate(20);

        return Inertia::render('Governance/Documents/Index', [
            'documents' => $documents,
            'categories' => [
                ['value' => 'constitution', 'label' => 'Constitution/Charter'],
                ['value' => 'policy', 'label' => 'Board Policy'],
                ['value' => 'procedure', 'label' => 'Procedure'],
                ['value' => 'template', 'label' => 'Template'],
                ['value' => 'report', 'label' => 'Report'],
                ['value' => 'minutes', 'label' => 'Minutes Archive'],
                ['value' => 'other', 'label' => 'Other'],
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
            'is_confidential' => 'boolean',
        ]);

        $path = $request->file('file')->store('governance/documents/' . $validated['category'], 'local');

        GovernanceDocument::create([
            'title' => $validated['title'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_size' => $request->file('file')->getSize(),
            'mime_type' => $request->file('file')->getMimeType(),
            'is_confidential' => $validated['is_confidential'] ?? false,
            'uploaded_by' => auth()->id(),
            'version' => 1,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function download(GovernanceDocument $document)
    {
        abort_unless(request()->user()?->canDo('governance.documents.view'), 403);

        if ($document->is_confidential) {
            $this->authorize('viewConfidential', $document);
        }

        return response()->download(storage_path('app/' . $document->file_path), $document->file_name);
    }

    public function destroy(GovernanceDocument $document)
    {
        abort_unless(request()->user()?->canDo('governance.documents.manage'), 403);

        $this->authorize('delete', $document);

        $document->delete();

        return redirect()->back()->with('success', 'Document removed.');
    }
}
