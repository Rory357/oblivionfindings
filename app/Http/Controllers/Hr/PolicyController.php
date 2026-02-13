<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class PolicyController extends Controller
{
    /**
     * List the policy library.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.view'), 403);

        $tenantId = $user->tenant_id ?? $user->organization_id ?? 1;

        $policies = HrPolicy::forTenant($tenantId)
            ->with('currentVersion')
            ->when($request->query('category'), fn ($q, $cat) => $q->where('category', $cat))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString();

        $categories = HrPolicy::forTenant($tenantId)
            ->selectRaw('DISTINCT category')
            ->pluck('category')
            ->filter()
            ->values()
            ->toArray();

        return Inertia::render('hr/policies/index', [
            'policies' => $policies,
            'categories' => $categories,
            'filters' => [
                'category' => $request->query('category'),
                'active_only' => $request->boolean('active_only', true),
            ],
            'can' => [
                'manage' => $user->canDo('hr.policies.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a new policy.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        // Get existing categories for suggestions
        $existingCategories = HrPolicy::selectRaw('DISTINCT category')
            ->pluck('category')
            ->filter()
            ->values();

        return Inertia::render('hr/policies/create', [
            'existingCategories' => $existingCategories,
            'defaultCategories' => [
                ['value' => 'employment', 'label' => 'Employment'],
                ['value' => 'health_and_safety', 'label' => 'Health & Safety'],
                ['value' => 'safeguarding', 'label' => 'Safeguarding'],
                ['value' => 'data_protection', 'label' => 'Data Protection'],
                ['value' => 'conduct', 'label' => 'Conduct'],
                ['value' => 'leave', 'label' => 'Leave'],
                ['value' => 'training', 'label' => 'Training'],
                ['value' => 'general', 'label' => 'General'],
            ],
        ]);
    }

    /**
     * Show form to edit a policy.
     */
    public function edit(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        $policy->load(['versions' => fn ($q) => $q->orderByDesc('version_number')]);

        $existingCategories = HrPolicy::selectRaw('DISTINCT category')
            ->pluck('category')
            ->filter()
            ->values();

        return Inertia::render('hr/policies/edit', [
            'policy' => $policy,
            'existingCategories' => $existingCategories,
            'defaultCategories' => [
                ['value' => 'employment', 'label' => 'Employment'],
                ['value' => 'health_and_safety', 'label' => 'Health & Safety'],
                ['value' => 'safeguarding', 'label' => 'Safeguarding'],
                ['value' => 'data_protection', 'label' => 'Data Protection'],
                ['value' => 'conduct', 'label' => 'Conduct'],
                ['value' => 'leave', 'label' => 'Leave'],
                ['value' => 'training', 'label' => 'Training'],
                ['value' => 'general', 'label' => 'General'],
            ],
        ]);
    }

    /**
     * Show a single policy with its version history.
     */
    public function show(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.view'), 403);

        $policy->load([
            'currentVersion',
            'versions' => fn ($q) => $q->orderByDesc('version_number'),
            'attestations' => fn ($q) => $q->with('user:id,name')->orderByDesc('attested_at')->limit(50),
        ]);

        // Count attestation status
        $attestationStats = [
            'total' => $policy->attestations->count(),
            'requires' => $policy->requires_attestation,
        ];

        return Inertia::render('hr/policies/show', [
            'policy' => $policy,
            'attestationStats' => $attestationStats,
            'can' => [
                'manage' => $user->canDo('hr.policies.manage'),
                'attest' => $user->canDo('hr.policies.attest'),
            ],
        ]);
    }

    /**
     * Store a new policy.
     */
    public function store(Request $request)
    {
        // Attempt to increase upload limits at runtime
        @ini_set('upload_max_filesize', '8M');
        @ini_set('post_max_size', '8M');
        
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'requires_attestation' => ['boolean'],
            'attestation_frequency_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'content_mode' => ['required', 'string', 'in:pdf_only,pdf_and_summary'],
            'content_summary' => [
                Rule::requiredIf(fn () => $request->input('content_mode') === 'pdf_and_summary'),
                'nullable',
                'string',
                'max:5000',
            ],
            'document' => ['required', 'file', 'mimes:pdf', 'max:8192'],
            'effective_from' => ['nullable', 'date'],
        ], [
            'document.max' => 'The PDF file must not be larger than 8MB. Please upload a smaller file or compress your PDF.',
            'document.mimes' => 'The file must be a PDF document.',
            'document.required' => 'Please upload a PDF document.',
        ]);

        // Handle PDF upload
        $documentPath = null;
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            
            // Verify file uploaded successfully
            if (!$file->isValid()) {
                return redirect()->back()->withErrors(['document' => 'The file failed to upload. Error: ' . $file->getErrorMessage()]);
            }
            
            $filename = Str::slug($data['title']) . '-' . time() . '.' . $file->getClientOriginalExtension();
            
            try {
                $tenantId = $user->tenant_id ?? $user->organization_id ?? 1;
                $documentPath = $file->storeAs('policies/' . $tenantId, $filename, 'private');
            } catch (\Exception $e) {
                return redirect()->back()->withErrors(['document' => 'Failed to save the file: ' . $e->getMessage()]);
            }
        }

        $tenantId = $user->tenant_id ?? $user->organization_id ?? 1;
        
        $policy = HrPolicy::create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'slug' => Str::slug($data['title']),
            'category' => $data['category'],
            'is_active' => true,
            'requires_attestation' => $data['requires_attestation'] ?? false,
            'attestation_frequency_months' => $data['attestation_frequency_months'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Create the first version automatically
        HrPolicyVersion::create([
            'policy_id' => $policy->id,
            'version_number' => 1,
            'content_summary' => $data['content_summary'] ?? '',
            'document_path' => $documentPath,
            'effective_from' => $data['effective_from'] ?? now(),
            'is_current' => true,
            'published_by' => $user->id,
        ]);

        return redirect()->route('hr.policies.index')->with('success', 'Policy created successfully.');
    }

    /**
     * Update an existing policy.
     */
    public function update(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'requires_attestation' => ['boolean'],
            'attestation_frequency_months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        if (isset($data['title'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $data['updated_by'] = $user->id;

        $policy->update($data);

        return redirect()->back()->with('success', 'Policy updated.');
    }

    /**
     * Publish a new version of a policy.
     */
    public function storeVersion(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        $data = $request->validate([
            'content_summary' => ['nullable', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
            'effective_from' => ['required', 'date'],
        ]);

        // Handle PDF upload
        $documentPath = null;
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            
            if (!$file->isValid()) {
                return redirect()->back()->withErrors(['document' => 'The file failed to upload.']);
            }
            
            $tenantId = $user->tenant_id ?? $user->organization_id ?? 1;
            $filename = Str::slug($policy->title) . '-v' . ($policy->versions()->max('version_number') + 1) . '-' . time() . '.' . $file->getClientOriginalExtension();
            $documentPath = $file->storeAs('policies/' . $tenantId, $filename, 'private');
        }

        // Determine the next version number
        $latestVersion = $policy->versions()->max('version_number') ?? 0;

        // Mark all existing versions as not current
        $policy->versions()->update(['is_current' => false]);

        HrPolicyVersion::create([
            'policy_id' => $policy->id,
            'version_number' => $latestVersion + 1,
            'content_summary' => $data['content_summary'] ?? '',
            'document_path' => $documentPath,
            'effective_from' => $data['effective_from'],
            'is_current' => true,
            'published_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'New policy version published.');
    }

    /**
     * Delete a policy and all its versions.
     */
    public function destroy(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        // Delete associated files
        foreach ($policy->versions as $version) {
            if ($version->document_path) {
                Storage::disk('private')->delete($version->document_path);
            }
        }

        $policy->delete();

        return redirect()->route('hr.policies.index')->with('success', 'Policy deleted successfully.');
    }

    /**
     * Download/view a policy document.
     */
    public function download(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user->canDo('hr.policies.view') || $user->canDo('hr.policies.attest'), 403);

        $version = $policy->currentVersion;
        abort_if(!$version || !$version->document_path, 404, 'No document available for this policy.');

        $path = $version->document_path;

        if (!Storage::disk('private')->exists($path)) {
            abort(404, 'Document not found.');
        }

        return Storage::disk('private')->response($path, basename($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }

    /**
     * Delete a specific policy version.
     */
    public function destroyVersion(Request $request, HrPolicy $policy, HrPolicyVersion $version)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        // Don't allow deleting the current version
        if ($version->is_current) {
            return redirect()->back()->with('error', 'Cannot delete the current version. Set another version as current first.');
        }

        // Delete the file
        if ($version->document_path) {
            Storage::disk('private')->delete($version->document_path);
        }

        $version->delete();

        return redirect()->back()->with('success', 'Version deleted successfully.');
    }
}
