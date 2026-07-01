<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Services\HrDocumentMergeService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrDocumentController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly HrDocumentMergeService $mergeService,
    ) {}

    /**
     * The Documents hub — a single page rendering four tabs (Library /
     * Signatures / Templates / Policies). All data is provided up-front so the
     * tabs filter client-side over employee folders.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.documents.manage');

        /** @var EloquentCollection<int, HrDocument> $records */
        $records = HrDocument::query()
            ->with([
                'employeeProfile:id,user_id,employee_number',
                'employeeProfile.user:id,name',
                'template:id,name,category,version',
                'creator:id,name',
                'signatures.signer:id,name',
            ])
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('created_at')
            ->get();

        $documents = $records->map(fn (HrDocument $document) => $this->mapDocument($document))->values();

        $signatureRequests = $this->mapSignatureRequests($records);

        $templates = HrDocumentTemplate::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get()
            ->map(fn (HrDocumentTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category,
                'version' => (int) $template->version,
                'is_active' => (bool) $template->is_active,
                'approval_required' => (bool) $template->approval_required,
                'merge_fields' => array_values($template->merge_fields ?? []),
                'updated_at' => optional($template->updated_at)->toDateString(),
            ])
            ->values();

        $employees = HrEmployeeProfile::query()
            ->with('user:id,name')
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('employee_number')
            ->get(['id', 'user_id', 'employee_number'])
            ->map(fn (HrEmployeeProfile $profile) => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->user?->name,
                'employee_number' => $profile->employee_number,
            ])
            ->values();

        $today = Carbon::today();
        $expiringCount = $documents->filter(function (array $doc) {
            return $doc['expiry'] !== null && $doc['expiry']['status'] !== 'valid';
        })->count();

        $awaitingCount = $signatureRequests->where('status', 'awaiting')->count();

        // Signature completion ring — across every signature row.
        $allSignatures = $records->flatMap(fn (HrDocument $d) => $d->signatures);
        $signedTotal = $allSignatures->where('status', 'signed')->count();
        $signatureCount = $allSignatures->count();

        return Inertia::render('hr/documents/index', [
            'documents' => $documents,
            'signatureRequests' => $signatureRequests->values(),
            'templates' => $templates,
            'policies' => $this->policySummary($tenantId),
            'employees' => $employees,
            'categories' => $this->documentCategories(),
            'stats' => [
                'on_file' => $documents->count(),
                'awaiting' => $awaitingCount,
                'expiring' => $expiringCount,
                'templates' => $templates->where('is_active', true)->count(),
                'declined' => $signatureRequests->where('status', 'declined')->count(),
            ],
            'signatureCompletion' => [
                'signed' => $signedTotal,
                'total' => $signatureCount,
                'requests' => $signatureRequests->count(),
            ],
            'recent' => $documents->take(5)->values(),
            'can' => [
                'manage' => $canManage,
                'policies_view' => $user->canDo('hr.policies.view'),
                'policies_manage' => $user->canDo('hr.policies.manage'),
                'signatures_manage' => $user->canDo('hr.signatures.manage') || $canManage,
            ],
        ]);
    }

    /**
     * Map a document to the Library row shape.
     *
     * @return array<string, mixed>
     */
    private function mapDocument(HrDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'folder' => $document->folder ?: $this->folderForCategory($document->category),
            'version' => (int) ($document->version ?: 1),
            'employee' => $document->employeeProfile && $document->employeeProfile->user ? [
                'id' => $document->employeeProfile->id,
                'user_id' => $document->employeeProfile->user_id,
                'name' => $document->employeeProfile->user->name,
                'initials' => $this->initials($document->employeeProfile->user->name),
            ] : null,
            'signature' => $this->signatureStatusFor($document),
            'expiry' => $this->expiryInfo($document->expires_at),
            'is_restricted' => (bool) $document->is_restricted,
            'generated_from_template' => (bool) $document->generated_from_template,
            'mime_type' => $document->mime_type,
            'original_name' => $document->original_name,
            'size_bytes' => $document->size_bytes,
            'created_at' => optional($document->created_at)->toDateString(),
            'created_by' => $document->creator?->name ?? 'System',
            'has_signed_pdf' => ! empty($document->signed_document_path),
        ];
    }

    /**
     * Derive a single signature chip status for the Library row.
     */
    private function signatureStatusFor(HrDocument $document): ?string
    {
        $signatures = $document->signatures;
        if ($signatures->isEmpty()) {
            return null;
        }
        if ($signatures->contains(fn (HrDocumentSignature $s) => $s->status === 'pending')) {
            return 'pending';
        }
        if ($signatures->contains(fn (HrDocumentSignature $s) => $s->status === 'declined')) {
            return 'declined';
        }

        return 'signed';
    }

    /**
     * Group signature rows by document into sender-side tracking cards.
     *
     * @param  EloquentCollection<int, HrDocument>  $records
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function mapSignatureRequests(EloquentCollection $records)
    {
        $today = Carbon::today();

        return $records
            ->filter(fn (HrDocument $d) => $d->signatures->isNotEmpty())
            ->map(function (HrDocument $document) use ($today) {
                $signatures = $document->signatures->sortBy('order_index')->values();

                $hasPending = $signatures->contains(fn (HrDocumentSignature $s) => $s->status === 'pending');
                $hasDeclined = $signatures->contains(fn (HrDocumentSignature $s) => $s->status === 'declined');
                $status = $hasPending ? 'awaiting' : ($hasDeclined ? 'declined' : 'signed');

                $signedCount = $signatures->where('status', 'signed')->count();
                $due = $signatures->whereNotNull('due_at')->min('due_at');
                $dueDate = $due ? Carbon::parse($due) : null;
                $declined = $signatures->firstWhere('status', 'declined');

                return [
                    'document_id' => $document->id,
                    'title' => $document->title,
                    'category' => $document->category,
                    'status' => $status,
                    'order' => $signatures->first()?->signing_order ?? 'parallel',
                    'sent' => optional($signatures->min('requested_at'))
                        ? Carbon::parse($signatures->min('requested_at'))->toDateString()
                        : null,
                    'due' => $dueDate?->toDateString(),
                    'overdue' => $status === 'awaiting' && $dueDate !== null && $dueDate->lt($today),
                    'requested_by' => $signatures->first()?->requestedBy?->name
                        ?? $document->creator?->name ?? 'System',
                    'progress' => $signedCount . ' of ' . $signatures->count() . ' signed',
                    'signed_count' => $signedCount,
                    'signer_count' => $signatures->count(),
                    'signed_at' => optional($signatures->max('signed_at'))
                        ? Carbon::parse($signatures->max('signed_at'))->toDateString()
                        : null,
                    'decline_reason' => $declined?->declined_reason,
                    'has_signed_pdf' => ! empty($document->signed_document_path),
                    'signers' => $signatures->map(fn (HrDocumentSignature $s) => [
                        'id' => $s->id,
                        'user_id' => $s->signer_user_id,
                        'name' => $s->signer?->name ?? ('User #' . $s->signer_user_id),
                        'initials' => $this->initials($s->signer?->name ?? '?'),
                        'status' => $s->status,
                    ])->values(),
                ];
            })
            ->sortByDesc(fn (array $row) => $row['sent'])
            ->values();
    }

    /**
     * Attestation summary for the Policies tab.
     *
     * @return array<int, array<string, mixed>>
     */
    private function policySummary(?int $tenantId): array
    {
        $activeEmployees = HrEmployeeProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->count();

        $policies = HrPolicy::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->with('currentVersion')
            ->orderBy('title')
            ->get();

        return $policies->map(function (HrPolicy $policy) use ($activeEmployees) {
            $requires = (bool) $policy->requires_attestation;
            $total = $requires ? $activeEmployees : 0;

            $attested = 0;
            if ($requires && $policy->currentVersion) {
                $attested = HrPolicyAttestation::query()
                    ->where('policy_id', $policy->id)
                    ->where('policy_version_id', $policy->currentVersion->id)
                    ->distinct('user_id')
                    ->count('user_id');
            }
            $attested = min($attested, $total);

            return [
                'id' => $policy->id,
                'name' => $policy->title,
                'version' => (int) ($policy->currentVersion?->version_number ?? 1),
                'owner' => $this->categoryLabel($policy->category),
                'requires_attestation' => $requires,
                'attested' => $attested,
                'total' => $total,
                'overdue' => max(0, $total - $attested),
            ];
        })->values()->all();
    }

    /**
     * Show upload form (kept for deep-link / non-JS fallback).
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
     * Upload a new HR document (hub-level, with full field parity).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $employeeRule = Rule::exists('hr_employee_profiles', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', $employeeRule],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf'],
            'expires_at' => ['nullable', 'date'],
            'is_restricted' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
            'folder' => $data['folder'] ?? $this->folderForCategory($data['category']),
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'is_restricted' => $data['is_restricted'] ?? false,
            'expires_at' => $data['expires_at'] ?? null,
            'generated_from_template' => false,
            'created_by' => $user->id,
            'uploaded_by' => $user->id,
        ]);

        return redirect()->route('hr.documents.index')->with('success', 'Document uploaded.');
    }

    /**
     * Generate a document (PDF) from a template using merge fields.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $templateRule = Rule::exists('hr_document_templates', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $employeeRule = Rule::exists('hr_employee_profiles', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

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

        // Approval-required templates may not be generated as a sendable doc
        // until they have been approved (no approval workflow record yet → block).
        if ($template->approval_required) {
            return redirect()->back()->with('error', 'This template requires approval before documents can be generated from it.');
        }

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
            $document->update(['title' => $data['title']]);
        }

        return redirect()->route('hr.documents.index')->with('success', 'Document generated from template.');
    }

    /**
     * Live merge preview for the Generate wizard (JSON, no storage).
     */
    public function preview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $templateRule = Rule::exists('hr_document_templates', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $employeeRule = Rule::exists('hr_employee_profiles', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $data = $request->validate([
            'template_id' => ['required', 'integer', $templateRule],
            'employee_profile_id' => ['required', 'integer', $employeeRule],
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

        $report = $this->mergeService->previewReport($template, $profile, $offer, $data['merge_data'] ?? []);

        return response()->json([
            'content' => $report['content'],
            'resolved' => $report['resolved'],
            'unresolved' => $report['unresolved'],
            'field_count' => count($report['resolved']) + count($report['unresolved']),
        ]);
    }

    /**
     * Download an HR document — restricted documents require manage rights.
     */
    public function download(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

        if ($document->is_restricted) {
            abort_unless($user->canDo('hr.documents.manage'), 403, 'This document is restricted to managers.');
        }

        abort_unless(
            Storage::disk($document->storage_disk)->exists($document->storage_path),
            404,
            'Document file is missing from storage.',
        );

        $filename = $document->original_name ?: basename($document->storage_path);

        return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);
    }

    /**
     * Download the signed PDF + certificate for a completed document.
     */
    public function downloadSigned(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

        abort_unless(
            $document->signed_document_path && Storage::disk('private')->exists($document->signed_document_path),
            404,
            'No signed document available.',
        );

        $filename = Str::slug($document->title) . '-signed.pdf';

        return Storage::disk('private')->download($document->signed_document_path, $filename);
    }

    /**
     * Bulk download a set of documents as a zip.
     */
    public function bulkDownload(Request $request): StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $documents = HrDocument::query()
            ->whereIn('id', $data['ids'])
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->get();

        if ($documents->isEmpty()) {
            return redirect()->back()->with('error', 'No documents found to download.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'hrdocs');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $used = [];
        foreach ($documents as $document) {
            $disk = Storage::disk($document->storage_disk ?? 'private');
            if (! $document->storage_path || ! $disk->exists($document->storage_path)) {
                continue;
            }
            $name = $document->original_name ?: basename($document->storage_path);
            // de-duplicate names within the archive
            $base = $name;
            $i = 1;
            while (isset($used[$name])) {
                $name = pathinfo($base, PATHINFO_FILENAME) . "-{$i}." . pathinfo($base, PATHINFO_EXTENSION);
                $i++;
            }
            $used[$name] = true;
            $zip->addFromString($name, (string) $disk->get($document->storage_path));
        }
        $zip->close();

        return response()->streamDownload(function () use ($tmp) {
            readfile($tmp);
            @unlink($tmp);
        }, 'hr-documents-' . now()->format('Ymd_His') . '.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Move a set of documents into a folder.
     */
    public function move(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'folder' => ['required', 'string', 'max:255'],
        ]);

        HrDocument::query()
            ->whereIn('id', $data['ids'])
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->update(['folder' => $data['folder']]);

        return redirect()->back()->with('success', 'Documents moved.');
    }

    /**
     * Bulk delete documents.
     */
    public function bulkDestroy(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $documents = HrDocument::query()
            ->whereIn('id', $data['ids'])
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->get();

        foreach ($documents as $document) {
            if ($document->storage_path && Storage::disk($document->storage_disk ?? 'private')->exists($document->storage_path)) {
                Storage::disk($document->storage_disk ?? 'private')->delete($document->storage_path);
            }
            $document->delete();
        }

        return redirect()->back()->with('success', $documents->count() . ' document(s) deleted.');
    }

    /**
     * Update a document's metadata from the hub (edit details).
     */
    public function update(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $document->tenant_id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'is_restricted' => ['boolean'],
        ]);

        $document->update($data);

        return redirect()->back()->with('success', 'Document updated.');
    }

    /**
     * Export the filtered document list as CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.documents.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $category = $request->query('category');
        $q = trim((string) $request->query('q', ''));

        $documents = HrDocument::query()
            ->with(['employeeProfile.user:id,name', 'creator:id,name', 'signatures'])
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when($category, fn (Builder $query, string $value) => $query->where('category', $value))
            ->when($q !== '', fn (Builder $query) => $query->where('title', 'like', "%{$q}%"))
            ->orderByDesc('created_at')
            ->get();

        $filename = 'hr-documents-' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($documents) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Title', 'Category', 'Folder', 'Employee', 'Signature', 'Expiry', 'Restricted', 'Version', 'Uploaded by', 'Created']);
            foreach ($documents as $document) {
                fputcsv($out, [
                    $document->title,
                    $document->category,
                    $document->folder ?: $this->folderForCategory($document->category),
                    $document->employeeProfile?->user?->name ?? 'All staff',
                    $this->signatureStatusFor($document) ?? '—',
                    $document->expires_at?->toDateString() ?? '',
                    $document->is_restricted ? 'Yes' : 'No',
                    'v' . (int) ($document->version ?: 1),
                    $document->creator?->name ?? 'System',
                    optional($document->created_at)->toDateString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
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
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf'],
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

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<string>
     */
    private function documentCategories(): array
    {
        return ['contract', 'letter', 'policy', 'certificate', 'offer', 'payslip', 'other'];
    }

    private function folderForCategory(?string $category): string
    {
        return match ($category) {
            'contract' => 'Contracts',
            'certificate' => 'Certificates',
            'letter', 'offer' => 'Letters',
            'policy' => 'Policies',
            'payslip' => 'Payslips',
            default => 'Compliance',
        };
    }

    private function categoryLabel(?string $value): string
    {
        if (! $value) {
            return 'General';
        }

        return Str::of($value)->replace('_', ' ')->title()->toString();
    }

    private function initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last) ?: '?';
    }

    /**
     * @return array{date: string, status: string, label: string}|null
     */
    private function expiryInfo(?Carbon $expires): ?array
    {
        if (! $expires) {
            return null;
        }

        $days = Carbon::today()->diffInDays($expires, false);
        $status = $days < 0 ? 'expired' : ($days <= 60 ? 'expiring' : 'valid');

        return [
            'date' => $expires->toDateString(),
            'status' => $status,
            'label' => $expires->format('d M Y'),
        ];
    }
}
