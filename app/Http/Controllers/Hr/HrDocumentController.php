<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Services\HrDocumentMergeService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HrDocumentController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly HrDocumentMergeService $mergeService,
    ) {}

    /**
     * List HR documents.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $category = $request->query('category') ?: $request->query('type');
        $q = trim((string) $request->query('q', ''));
        $employeeProfileId = $request->query('employee_profile_id');

        $documents = HrDocument::query()
            ->with([
                'employeeProfile:id,user_id,employee_number',
                'employeeProfile.user:id,name',
                'template:id,name,category',
                'creator:id,name',
            ])
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when($category, fn (Builder $query, string $value) => $query->where('category', $value))
            ->when($employeeProfileId, fn (Builder $query, string $id) => $query->where('employee_profile_id', $id))
            ->when($q !== '', function (Builder $query) use ($q) {
                $query->where(function (Builder $nested) use ($q) {
                    $nested->where('title', 'like', "%{$q}%")
                        ->orWhere('original_name', 'like', "%{$q}%")
                        ->orWhereHas('employeeProfile.user', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$q}%"));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (HrDocument $document): array {
                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'category' => $document->category,
                    'document_type' => $document->category,
                    'related_user' => $document->employeeProfile?->user ? [
                        'id' => $document->employeeProfile->user->id,
                        'name' => $document->employeeProfile->user->name,
                    ] : null,
                    'employee_profile_id' => $document->employee_profile_id,
                    'storage_path' => $document->storage_path,
                    'created_at' => optional($document->created_at)->toDateString(),
                    'created_by_user' => [
                        'name' => $document->creator?->name ?? 'System',
                    ],
                    'generated_from_template' => (bool) $document->generated_from_template,
                    'is_restricted' => (bool) $document->is_restricted,
                    'template' => $document->template ? [
                        'id' => $document->template->id,
                        'name' => $document->template->name,
                    ] : null,
                ];
            });

        $employees = HrEmployeeProfile::query()
            ->with('user:id,name')
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('employee_number')
            ->get(['id', 'user_id', 'employee_number'])
            ->map(fn (HrEmployeeProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->user?->name,
                'employee_number' => $profile->employee_number,
            ])
            ->values();

        return Inertia::render('hr/documents/index', [
            'documents' => $documents,
            'employees' => $employees,
            'categories' => $this->documentCategories(),
            'filters' => [
                'q' => $q,
                'type' => $category,
                'category' => $category,
                'employee_profile_id' => $employeeProfileId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.documents.manage'),
            ],
        ]);
    }

    /**
     * Show upload form.
     */
    public function createUpload(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $employees = HrEmployeeProfile::query()
            ->with('user:id,name')
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('employee_number')
            ->get(['id', 'user_id', 'employee_number'])
            ->map(fn (HrEmployeeProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->user?->name,
                'employee_number' => $profile->employee_number,
            ])
            ->values();

        return Inertia::render('hr/documents/upload', [
            'employees' => $employees,
            'categories' => $this->documentCategories(),
        ]);
    }

    /**
     * Upload a new HR document.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $employeeRule = Rule::exists('hr_employee_profiles', 'id');
        $employeeRule = $employeeRule->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', $employeeRule],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'max:20480'], // 20MB max
            'is_restricted' => ['boolean'],
        ]);

        $profile = HrEmployeeProfile::query()->findOrFail($data['employee_profile_id']);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        $file = $request->file('file');
        $path = $file->store("hr-documents/{$profile->tenant_id}/{$profile->id}", 'private');

        HrDocument::create([
            'tenant_id' => $profile->tenant_id,
            'employee_profile_id' => $data['employee_profile_id'],
            'title' => $data['title'],
            'category' => $data['category'],
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'is_restricted' => $data['is_restricted'] ?? false,
            'generated_from_template' => false,
            'created_by' => $user->id,
            'uploaded_by' => $user->id,
        ]);

        return redirect()->route('hr.documents.index')->with('success', 'Document uploaded.');
    }

    /**
     * Generate a document from a template using merge fields.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $templateRule = Rule::exists('hr_document_templates', 'id');
        $employeeRule = Rule::exists('hr_employee_profiles', 'id');
        $templateRule = $templateRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $employeeRule = $employeeRule->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $data = $request->validate([
            'template_id' => ['required', 'integer', $templateRule],
            'employee_profile_id' => ['required', 'integer', $employeeRule],
            'title' => ['nullable', 'string', 'max:255'],
            'offer_id' => ['nullable', 'integer', Rule::exists('hr_offers', 'id')],
            'merge_data' => ['nullable', 'array'],
        ]);

        $template = HrDocumentTemplate::query()->findOrFail($data['template_id']);
        $profile = HrEmployeeProfile::query()->with('user')->findOrFail($data['employee_profile_id']);

        $this->assertHrTenantAccess($tenantId, $template->tenant_id);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        $offer = null;
        if (! empty($data['offer_id'])) {
            $offer = HrOffer::query()->with('application:id,tenant_id')->findOrFail($data['offer_id']);
            $this->assertHrTenantAccess($tenantId, $offer->application?->tenant_id);
        }

        $document = $this->mergeService->generateDocument(
            $template,
            $profile,
            $user->id,
            $offer,
            $data['merge_data'] ?? [],
        );

        if (! empty($data['title']) && $data['title'] !== $document->title) {
            $document->update([
                'title' => $data['title'],
            ]);
        }

        return redirect()->route('hr.documents.index')->with('success', 'Document generated from template.');
    }

    /**
     * Download an HR document.
     */
    public function download(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

        abort_unless(
            Storage::disk($document->storage_disk)->exists($document->storage_path),
            404,
            'Document file is missing from storage.',
        );

        $filename = $document->original_name ?: basename($document->storage_path);

        return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);
    }

    /**
     * Delete an HR document.
     */
    public function destroy(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

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

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $category = $request->query('category');
        $q = trim((string) $request->query('q', ''));

        $templates = HrDocumentTemplate::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when($category, fn (Builder $query, string $value) => $query->where('category', $value))
            ->when($q !== '', fn (Builder $query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (HrDocumentTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category,
                'merge_fields' => $template->merge_fields ?? [],
                'is_active' => (bool) $template->is_active,
                'approval_required' => (bool) $template->approval_required,
                'version' => (int) $template->version,
                'created_at' => optional($template->created_at)->toDateString(),
                'updated_at' => optional($template->updated_at)->toDateString(),
            ]);

        return Inertia::render('hr/documents/templates', [
            'templates' => $templates,
            'categories' => $this->documentCategories(),
            'filters' => [
                'category' => $category,
                'q' => $q,
            ],
            'can' => [
                'manage' => $user->canDo('hr.documents.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a document template.
     */
    public function createTemplate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        return Inertia::render('hr/documents/create-template', [
            'categories' => $this->documentCategories(),
            'availableMergeFields' => $this->mergeService->getAvailableFields('general'),
        ]);
    }

    /**
     * Show form to edit a document template.
     */
    public function editTemplate(Request $request, HrDocumentTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);

        return Inertia::render('hr/documents/edit-template', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category,
                'content' => $template->content,
                'merge_fields' => $template->merge_fields ?? [],
                'is_active' => (bool) $template->is_active,
                'approval_required' => (bool) $template->approval_required,
                'version' => (int) $template->version,
            ],
            'categories' => $this->documentCategories(),
            'availableMergeFields' => $this->mergeService->getAvailableFields($template->category),
        ]);
    }

    /**
     * Store a new document template.
     */
    public function storeTemplate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:100000'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*' => ['string', 'max:100'],
            'approval_required' => ['boolean'],
        ]);

        $mergeFields = collect($data['merge_fields'] ?? [])
            ->map(fn (mixed $value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        HrDocumentTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'category' => $data['category'],
            'content' => $data['content'],
            'merge_fields' => $mergeFields,
            'is_active' => true,
            'version' => 1,
            'approval_required' => (bool) ($data['approval_required'] ?? false),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->route('hr.documents.templates')->with('success', 'Document template created.');
    }

    /**
     * Update an existing document template.
     */
    public function updateTemplate(Request $request, HrDocumentTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'content' => ['sometimes', 'string', 'max:100000'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
            'approval_required' => ['boolean'],
        ]);

        if (array_key_exists('merge_fields', $data)) {
            $data['merge_fields'] = collect($data['merge_fields'] ?? [])
                ->map(fn (mixed $value) => trim((string) $value))
                ->filter()
                ->values()
                ->all();
        }

        if (array_key_exists('content', $data) && $data['content'] !== $template->content) {
            $data['version'] = ((int) $template->version) + 1;
        }

        $data['updated_by'] = $user->id;

        $template->update($data);

        return redirect()->route('hr.documents.templates')->with('success', 'Document template updated.');
    }

    /**
     * Toggle template active state.
     */
    public function toggleTemplateActive(Request $request, HrDocumentTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);

        $template->update([
            'is_active' => ! (bool) $template->is_active,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Template status updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Profile-scoped document management                                 */
    /* ------------------------------------------------------------------ */

    public function profileDocuments(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);

        $profile->load('user:id,name,email');

        $documents = HrDocument::where('employee_profile_id', $profile->id)
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'folder' => $d->folder ?? null,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'expires_at' => $d->expires_at?->toDateString(),
                'signed_by_employee' => (bool) $d->signed_by_employee,
                'is_restricted' => (bool) $d->is_restricted,
                'created_at' => $d->created_at?->toIso8601String(),
                'uploaded_by' => $d->uploader ? ['id' => $d->uploader->id, 'name' => $d->uploader->name] : null,
            ]);

        return Inertia::render('hr/employees/documents', [
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->user?->name ?? 'Unknown',
            ],
            'documents' => $documents,
            'categories' => $this->documentCategories(),
            'can' => [
                'manage' => $user->canDo('hr.employees.manage'),
            ],
        ]);
    }

    public function storeForProfile(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
            'folder' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'is_restricted' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $file = $request->file('file');
        $path = $file->store("hr-documents/{$tenantId}/{$profile->id}", 'private');

        HrDocument::create([
            'tenant_id' => $tenantId,
            'employee_profile_id' => $profile->id,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'folder' => $validated['folder'] ?? null,
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'is_restricted' => $validated['is_restricted'] ?? false,
            'expires_at' => $validated['expires_at'] ?? null,
            'generated_from_template' => false,
            'created_by' => $user->id,
            'uploaded_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function updateForProfile(Request $request, HrEmployeeProfile $profile, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);
        abort_unless($document->employee_profile_id === $profile->id, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
            'folder' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'is_restricted' => ['boolean'],
        ]);

        $document->update($validated);

        return redirect()->back()->with('success', 'Document updated.');
    }

    public function destroyForProfile(Request $request, HrEmployeeProfile $profile, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);
        abort_unless($document->employee_profile_id === $profile->id, 404);

        if ($document->storage_path && Storage::disk($document->storage_disk ?? 'private')->exists($document->storage_path)) {
            Storage::disk($document->storage_disk ?? 'private')->delete($document->storage_path);
        }

        $document->delete();

        return redirect()->back()->with('success', 'Document deleted.');
    }

    /**
     * @return list<string>
     */
    private function documentCategories(): array
    {
        return ['contract', 'letter', 'policy', 'certificate', 'offer', 'other'];
    }
}
