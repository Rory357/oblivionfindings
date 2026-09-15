<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Documents: reference files such as the constitution, terms of reference,
 * templates and certificates. Board policies live in Policies instead.
 */
class GovernanceDocumentController extends Controller
{
    private const UPLOAD_TYPES = 'pdf,doc,docx,odt,rtf,txt,xls,xlsx,ods,csv,ppt,pptx,odp,jpg,jpeg,png,webp';

    public function index(Request $request)
    {
        $this->authorize('viewAny', GovernanceDocument::class);

        $type = in_array($request->query('document_type'), GovernanceDocument::TYPES, true)
            ? (string) $request->query('document_type')
            : null;
        $updated = $request->query('updated') === '30d' ? '30d' : null;
        $search = trim((string) $request->query('search', ''));

        $documents = GovernanceDocument::query()
            ->when($type, fn ($q, $value) => $q->where('document_type', $value))
            ->when($updated, fn ($q) => $q->where('updated_at', '>=', now()->subDays(30)))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('title', 'like', '%'.$this->escapeLike($search).'%')
                ->orWhere('original_name', 'like', '%'.$this->escapeLike($search).'%')))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GovernanceDocument $document) => $this->presentListItem($document));

        $typeCounts = GovernanceDocument::query()
            ->selectRaw('document_type, count(*) as aggregate')
            ->groupBy('document_type')
            ->pluck('aggregate', 'document_type');

        return Inertia::render('Governance/Documents/Index', [
            'documents' => $documents,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'document_type' => $type,
                'updated' => $updated,
            ],
            'summary' => [
                'total' => (int) $typeCounts->sum(),
                'by_type' => $typeCounts->map(fn ($count) => (int) $count),
                'updated_last_30_days' => GovernanceDocument::query()
                    ->where('updated_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'categories' => GovernanceDocument::typeOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', GovernanceDocument::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => ['required', 'string', Rule::in(GovernanceDocument::TYPES)],
            'description' => 'nullable|string',
            'file' => 'required|file|max:20480|mimes:'.self::UPLOAD_TYPES,
        ], [
            'title.required' => 'Give the document a title.',
            'title.max' => 'Keep the title to 255 characters or fewer.',
            'category.required' => 'Choose what kind of document this is.',
            'category.in' => 'Choose what kind of document this is from the list. Board policies go in Policies.',
            'file.required' => 'Choose the file to upload.',
            'file.file' => 'Choose the file to upload.',
            'file.max' => 'The file must be 20 MB or smaller.',
            'file.mimes' => 'Upload a PDF, Word, spreadsheet, presentation, text or image file.',
        ]);

        $file = $request->file('file');
        $path = $file->store('governance/documents/'.$validated['category'], 'local');

        GovernanceDocument::create([
            'title' => $validated['title'],
            'document_type' => $validated['category'],
            'category' => null,
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'original_name' => $this->cleanFileName($file->getClientOriginalName(), $file->extension()),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => auth()->id(),
            'version_number' => 1,
            'is_current' => true,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function show(GovernanceDocument $document)
    {
        $this->authorize('view', $document);

        $document->load('uploadedBy:id,name');

        return Inertia::render('Governance/Documents/Show', [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->document_type,
                'category_label' => GovernanceDocument::typeLabel($document->document_type),
                'description' => $document->description,
                'file_name' => $document->displayName(),
                'format_label' => $document->formatLabel(),
                'file_size' => (int) ($document->file_size ?? 0),
                'version' => (int) $document->version_number,
                'is_current' => (bool) $document->is_current,
                'uploaded_by' => $document->uploadedBy ? [
                    'id' => $document->uploadedBy->id,
                    'name' => $document->uploadedBy->name,
                ] : null,
                'created_at' => $document->created_at?->toIso8601String(),
                'updated_at' => $document->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function download(GovernanceDocument $document)
    {
        $this->authorize('download', $document);

        $name = $document->displayName();

        if (! Storage::disk('local')->exists($document->file_path)) {
            $legacyPath = storage_path('app/'.$document->file_path);
            if (! is_file($legacyPath)) {
                abort(404, 'This file could not be found.');
            }

            return response()->download($legacyPath, $name);
        }

        return Storage::disk('local')->download($document->file_path, $name);
    }

    public function destroy(GovernanceDocument $document)
    {
        $this->authorize('delete', $document);

        $document->delete();

        // Return to the register: going "back" from the removed record's own
        // page would land on a document that no longer exists.
        return redirect()->route('governance.documents.index')->with('success', 'Document removed.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentListItem(GovernanceDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->document_type,
            'category_label' => GovernanceDocument::typeLabel($document->document_type),
            'file_name' => $document->displayName(),
            'format_label' => $document->formatLabel(),
            'file_size' => (int) ($document->file_size ?? 0),
            'version' => (int) $document->version_number,
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    /** A safe display and download name: no folders, no control characters. */
    protected function cleanFileName(?string $name, ?string $extension): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', ' ', basename((string) $name)));

        if ($clean === '') {
            $clean = 'document'.($extension ? '.'.$extension : '');
        }

        return Str::limit($clean, 250, '');
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
