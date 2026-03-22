<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class HrDocumentController extends Controller
{
    /**
     * List HR documents.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = null;

        $documents = HrDocument::query()
            ->with([
                'employeeProfile:id,user_id,employee_number',
                'employeeProfile.user:id,name',
                'template:id,name',
            ])
            ->when($request->query('category'), fn ($q, $cat) => $q->where('category', $cat))
            ->when($request->query('employee_profile_id'), fn ($q, $id) => $q->where('employee_profile_id', $id))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/documents/index', [
            'documents' => $documents,
            'filters' => [
                'category' => $request->query('category'),
                'employee_profile_id' => $request->query('employee_profile_id'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.documents.manage'),
            ],
        ]);
    }

    /**
     * List documents expiring within 30/60/90 days.
     */
    public function expiring(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $days = (int) ($request->query('days', 30));
        $days = in_array($days, [7, 30, 60, 90]) ? $days : 30;

        $documents = HrDocument::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->where('expires_at', '>=', now()->subDays(30)) // Include recently expired too
            ->with([
                'employeeProfile:id,user_id,employee_number',
                'employeeProfile.user:id,name',
            ])
            ->orderBy('expires_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('hr/documents/expiring', [
            'documents' => $documents,
            'filters' => [
                'days' => $days,
            ],
        ]);
    }

    /**
     * Upload a new HR document.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'max:20480'], // 20MB max
            'is_restricted' => ['boolean'],
        ]);

        $file = $request->file('file');
        $path = $file->store('hr-documents/' . $data['employee_profile_id'], 'private');

        HrDocument::create([
            'tenant_id' => $user->tenant_id,
            'employee_profile_id' => $data['employee_profile_id'],
            'title' => $data['title'],
            'category' => $data['category'],
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'is_restricted' => $data['is_restricted'] ?? false,
            'generated_from_template' => false,
            'created_by' => $user->id,
            'uploaded_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    /**
     * Generate a document from a template using merge fields.
     *
     * Uses HrDocumentMergeService for business logic.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:hr_document_templates,id'],
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'title' => ['required', 'string', 'max:255'],
            'merge_data' => ['nullable', 'array'],
        ]);

        $template = HrDocumentTemplate::findOrFail($data['template_id']);
        $profile = HrEmployeeProfile::with('user')->findOrFail($data['employee_profile_id']);

        // Build merge fields from employee profile and custom data
        $mergeFields = array_merge([
            'employee_name' => $profile->user?->name ?? '',
            'employee_number' => $profile->employee_number ?? '',
            'position_title' => $profile->position_title ?? '',
            'start_date' => $profile->start_date?->format('d/m/Y') ?? '',
            'date' => now()->format('d/m/Y'),
        ], $data['merge_data'] ?? []);

        // Perform mail-merge style replacement on template content
        $content = $template->content ?? '';
        foreach ($mergeFields as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        // Store the generated document as an HTML file
        $filename = sprintf(
            'hr-documents/%d/generated_%s_%s.html',
            $data['employee_profile_id'],
            \Illuminate\Support\Str::slug($data['title']),
            now()->format('Y-m-d_His')
        );

        Storage::disk('private')->put($filename, $content);

        HrDocument::create([
            'tenant_id' => $user->tenant_id,
            'employee_profile_id' => $data['employee_profile_id'],
            'template_id' => $template->id,
            'title' => $data['title'],
            'category' => $template->category ?? 'generated',
            'storage_disk' => 'private',
            'storage_path' => $filename,
            'original_name' => basename($filename),
            'mime_type' => 'text/html',
            'size_bytes' => strlen($content),
            'is_restricted' => false,
            'generated_from_template' => true,
            'created_by' => $user->id,
            'uploaded_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Document generated from template.');
    }

    /**
     * Delete an HR document.
     */
    public function destroy(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        // Remove the file from storage
        if ($document->storage_path && $document->storage_disk) {
            Storage::disk($document->storage_disk)->delete($document->storage_path);
        }

        $document->delete();

        return redirect()->back()->with('success', 'Document deleted.');
    }

    /**
     * List document templates.
     */
    public function templates(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $templates = HrDocumentTemplate::query()
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/documents/templates', [
            'templates' => $templates,
        ]);
    }

    /**
     * Show form to create a document template.
     */
    public function createTemplate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        return Inertia::render('hr/documents/create-template');
    }

    /**
     * Show form to edit a document template.
     */
    public function editTemplate(Request $request, HrDocumentTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        return Inertia::render('hr/documents/edit-template', [
            'template' => $template,
        ]);
    }

    /**
     * Store a new document template.
     */
    public function storeTemplate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:100000'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*' => ['string', 'max:100'],
            'approval_required' => ['boolean'],
        ]);

        HrDocumentTemplate::create([
            'tenant_id' => $user->tenant_id,
            'is_active' => true,
            'version' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Document template created.');
    }

    /**
     * Update an existing document template.
     */
    public function updateTemplate(Request $request, HrDocumentTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'content' => ['sometimes', 'string', 'max:100000'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
            'approval_required' => ['boolean'],
        ]);

        // Auto-increment version when content changes
        if (isset($data['content']) && $data['content'] !== $template->content) {
            $data['version'] = ($template->version ?? 1) + 1;
        }

        $data['updated_by'] = $user->id;

        $template->update($data);

        return redirect()->back()->with('success', 'Document template updated.');
    }
}
