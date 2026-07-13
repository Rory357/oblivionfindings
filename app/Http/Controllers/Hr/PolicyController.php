<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Domain\Hr\Notifications\PolicyAttestationRequiredNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PolicyController extends Controller
{
    use ResolvesHrTenant;

    /** Seed categories offered by the policy wizard alongside tenant-created ones. */
    private const DEFAULT_CATEGORIES = [
        ['value' => 'employment', 'label' => 'Employment'],
        ['value' => 'health_and_safety', 'label' => 'Health & Safety'],
        ['value' => 'safeguarding', 'label' => 'Safeguarding'],
        ['value' => 'data_protection', 'label' => 'Data Protection'],
        ['value' => 'conduct', 'label' => 'Conduct'],
        ['value' => 'leave', 'label' => 'Leave'],
        ['value' => 'training', 'label' => 'Training'],
        ['value' => 'general', 'label' => 'General'],
    ];

    /**
     * List the policy library.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

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

        // Hero stats — computed over the whole register, not the current page.
        $stats = [
            'total' => HrPolicy::forTenant($tenantId)->count(),
            'active' => HrPolicy::forTenant($tenantId)->active()->count(),
            'need_attestation' => HrPolicy::forTenant($tenantId)->active()->where('requires_attestation', true)->count(),
            'attestations' => HrPolicyAttestation::where('tenant_id', $tenantId)->count(),
        ];

        // Wizard edit-mode prefill: ?edit={id} may point at a policy outside the
        // current page of results, so ship the requested record explicitly.
        $editPolicy = null;
        if ($request->query('edit') && $user->canDo('hr.policies.manage')) {
            $editPolicy = HrPolicy::forTenant($tenantId)
                ->select(['id', 'title', 'category', 'is_active', 'requires_attestation', 'attestation_frequency_months'])
                ->find($request->query('edit'));
        }

        return Inertia::render('hr/documents/policies/index', [
            'policies' => $policies,
            'categories' => $categories,
            'defaultCategories' => self::DEFAULT_CATEGORIES,
            'stats' => $stats,
            'editPolicy' => $editPolicy,
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
     * Legacy full-page create form — the flow now lives in the PolicyWizard
     * modal on the index, so send the old GET route there with ?new=1.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);

        return redirect()->route('hr.policies.index', ['new' => 1]);
    }

    /**
     * Legacy full-page edit form — the flow now lives in the PolicyWizard
     * modal on the index, so send the old GET route there with ?edit={id}.
     */
    public function edit(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

        return redirect()->route('hr.policies.index', ['edit' => $policy->id]);
    }

    /**
     * Show a single policy with its version history.
     */
    public function show(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

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

        return Inertia::render('hr/documents/policies/show', [
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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

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
                $documentPath = $file->storeAs('policies/' . $tenantId, $filename, 'private');
            } catch (\Exception $e) {
                return redirect()->back()->withErrors(['document' => 'Failed to save the file: ' . $e->getMessage()]);
            }
        }
        
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
        $version = HrPolicyVersion::create([
            'policy_id' => $policy->id,
            'version_number' => 1,
            'content_summary' => $data['content_summary'] ?? '',
            'document_path' => $documentPath,
            'effective_from' => $data['effective_from'] ?? now(),
            'is_current' => true,
            'published_by' => $user->id,
        ]);

        // Attestation awareness: publishing a version that requires attestation
        // tells affected staff straight away (best-effort, queued).
        $this->notifyAttestationRequired($policy, $version, $tenantId);

        return redirect()->route('hr.policies.index')->with('success', 'Policy created successfully.');
    }

    /**
     * Update an existing policy.
     */
    public function update(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

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
            
            $filename = Str::slug($policy->title) . '-v' . ($policy->versions()->max('version_number') + 1) . '-' . time() . '.' . $file->getClientOriginalExtension();
            $documentPath = $file->storeAs('policies/' . $tenantId, $filename, 'private');
        }

        // Determine the next version number
        $latestVersion = $policy->versions()->max('version_number') ?? 0;

        // Mark all existing versions as not current
        $policy->versions()->update(['is_current' => false]);

        $version = HrPolicyVersion::create([
            'policy_id' => $policy->id,
            'version_number' => $latestVersion + 1,
            'content_summary' => $data['content_summary'] ?? '',
            'document_path' => $documentPath,
            'effective_from' => $data['effective_from'],
            'is_current' => true,
            'published_by' => $user->id,
        ]);

        // Attestation awareness: a new current version resets everyone's
        // attestation — tell affected staff (best-effort, queued).
        $this->notifyAttestationRequired($policy, $version, $tenantId);

        return redirect()->back()->with('success', 'New policy version published.');
    }

    /**
     * Notify every active staff member that a freshly published policy version
     * requires their attestation. Best-effort per recipient — a notification
     * hiccup never blocks the publish. No-op unless the policy is active and
     * flagged requires_attestation.
     */
    private function notifyAttestationRequired(HrPolicy $policy, HrPolicyVersion $version, int $tenantId): void
    {
        if (! $policy->requires_attestation || ! $policy->is_active) {
            return;
        }

        $staff = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id');

        foreach ($staff as $member) {
            try {
                $member->notify(new PolicyAttestationRequiredNotification([
                    'policy_id' => $policy->id,
                    'policy_version_id' => $version->id,
                    'policy_title' => $policy->title,
                    'version_number' => (int) $version->version_number,
                    'kind' => 'published',
                ]));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send policy attestation required notification', [
                    'policy_id' => $policy->id,
                    'user_id' => $member->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Archive a policy while retaining its versions, attestations and files.
     */
    public function destroy(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

        $policy->update([
            'is_active' => false,
            'updated_by' => $user->id,
        ]);

        return redirect()->route('hr.policies.index')->with('success', 'Policy archived successfully.');
    }

    /**
     * Download/view a policy document.
     */
    public function download(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user->canDo('hr.policies.view') || $user->canDo('hr.policies.attest'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);
        abort_unless($version->policy_id === $policy->id, 404);

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
