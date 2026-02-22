<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ComplianceMatrixController extends Controller
{
    use ResolvesHrTenant;

    /* ------------------------------------------------------------------ */
    /*  Index — matrix grid view                                           */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $requirements = HrComplianceRequirement::where('tenant_id', $tenantId)
            ->with('matrixEntries')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (HrComplianceRequirement $requirement) => [
                'id' => $requirement->id,
                'name' => $requirement->name,
                'type' => $requirement->check_type,
                'description' => $requirement->description,
                'renewal_period_months' => $requirement->validity_months,
                'is_mandatory' => (bool) $requirement->hard_stop,
                'is_active' => (bool) $requirement->is_active,

                // Keep native fields available for newer screens.
                'code' => $requirement->code,
                'category' => $requirement->category,
                'check_type' => $requirement->check_type,
                'validity_months' => $requirement->validity_months,
                'renewal_reminder_days' => $requirement->renewal_reminder_days,
                'hard_stop' => (bool) $requirement->hard_stop,
            ])
            ->values();

        $matrixEntries = HrComplianceMatrix::where('tenant_id', $tenantId)
            ->with('requirement:id,code,name,category')
            ->orderBy('role')
            ->get();

        // Distinct roles and site types used in matrix
        $roles = $matrixEntries->pluck('role')->unique()->sort()->values();
        $siteTypes = $matrixEntries->pluck('site_type')->filter()->unique()->sort()->values();

        return Inertia::render('hr/compliance/matrix', [
            'requirements' => $requirements,
            'matrixEntries' => $matrixEntries,
            'roles' => $roles,
            'siteTypes' => $siteTypes,
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Requirement                                                  */
    /* ------------------------------------------------------------------ */

    public function storeRequirement(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->normalizeLegacyRequirementPayload($request, false);

        $validated = $request->validate([
            'code'                  => ['required', 'string', 'max:50'],
            'name'                  => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:2000'],
            'category'              => ['required', 'string', 'max:100'],
            'check_type'            => ['required', 'string', Rule::in(['training_course', 'credential', 'background_check', 'policy_attestation', 'manual'])],
            'reference_id'          => ['nullable', 'integer'],
            'validity_months'       => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'hard_stop'             => ['required', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
        ]);

        HrComplianceRequirement::create([
            ...$validated,
            'tenant_id'  => $tenantId,
            'is_active'  => $validated['is_active'] ?? true,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Compliance requirement created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update Requirement                                                 */
    /* ------------------------------------------------------------------ */

    public function updateRequirement(Request $request, HrComplianceRequirement $requirement)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $requirement->tenant_id);
        $this->normalizeLegacyRequirementPayload($request, true);

        $validated = $request->validate([
            'code'                  => ['sometimes', 'required', 'string', 'max:50'],
            'name'                  => ['sometimes', 'required', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:2000'],
            'category'              => ['sometimes', 'required', 'string', 'max:100'],
            'check_type'            => ['sometimes', 'required', 'string', Rule::in(['training_course', 'credential', 'background_check', 'policy_attestation', 'manual'])],
            'reference_id'          => ['nullable', 'integer'],
            'validity_months'       => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'hard_stop'             => ['sometimes', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
        ]);

        $validated['updated_by'] = $user->id;
        $requirement->update($validated);

        return redirect()->back()->with('success', 'Compliance requirement updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy Requirement                                                */
    /* ------------------------------------------------------------------ */

    public function destroyRequirement(Request $request, HrComplianceRequirement $requirement)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $requirement->tenant_id);

        // Soft deactivate rather than hard delete to preserve audit trail
        $requirement->update([
            'is_active'  => false,
            'updated_by' => $user->id,
        ]);

        // Remove associated matrix entries
        HrComplianceMatrix::where('requirement_id', $requirement->id)
            ->where('tenant_id', $tenantId)
            ->delete();

        return redirect()->back()->with('success', 'Compliance requirement deactivated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update Matrix — assign/unassign requirement to role/site_type      */
    /* ------------------------------------------------------------------ */

    public function updateMatrix(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'requirement_id' => ['required', 'integer', 'exists:hr_compliance_requirements,id'],
            'role'           => ['required', 'string', 'max:100'],
            'site_type'      => ['nullable', 'string', 'max:100'],
            'is_mandatory'   => ['required', 'boolean'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'action'         => ['required', 'string', Rule::in(['assign', 'unassign'])],
        ]);

        $requirement = HrComplianceRequirement::where('id', $validated['requirement_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($validated['action'] === 'assign') {
            HrComplianceMatrix::updateOrCreate(
                [
                    'tenant_id'      => $tenantId,
                    'requirement_id' => $requirement->id,
                    'role'           => $validated['role'],
                    'site_type'      => $validated['site_type'] ?? null,
                ],
                [
                    'is_mandatory' => $validated['is_mandatory'],
                    'notes'        => $validated['notes'] ?? null,
                ]
            );

            return redirect()->back()->with('success', 'Matrix entry assigned.');
        }

        // Unassign
        HrComplianceMatrix::where('requirement_id', $requirement->id)
            ->where('tenant_id', $tenantId)
            ->where('role', $validated['role'])
            ->when($validated['site_type'], fn ($q) => $q->where('site_type', $validated['site_type']))
            ->delete();

        return redirect()->back()->with('success', 'Matrix entry removed.');
    }

    private function normalizeLegacyRequirementPayload(Request $request, bool $isUpdate): void
    {
        $payload = $request->all();
        $legacyType = isset($payload['type']) ? trim((string) $payload['type']) : '';

        if (! isset($payload['check_type']) && $legacyType !== '') {
            $payload['check_type'] = $this->mapLegacyTypeToCheckType($legacyType);
        }

        if (! isset($payload['category']) && $legacyType !== '') {
            $payload['category'] = $legacyType;
        }

        if (! isset($payload['validity_months']) && array_key_exists('renewal_period_months', $payload)) {
            $payload['validity_months'] = $payload['renewal_period_months'];
        }

        if (! array_key_exists('hard_stop', $payload) && array_key_exists('is_mandatory', $payload)) {
            $payload['hard_stop'] = (bool) $payload['is_mandatory'];
        }

        if ((! isset($payload['code']) || trim((string) $payload['code']) === '') && isset($payload['name'])) {
            $payload['code'] = Str::upper(Str::slug((string) $payload['name'], '_'));
        }

        if (! $isUpdate && (! isset($payload['category']) || trim((string) $payload['category']) === '')) {
            $payload['category'] = 'general';
        }

        if (! $isUpdate && (! isset($payload['check_type']) || trim((string) $payload['check_type']) === '')) {
            $payload['check_type'] = 'manual';
        }

        $request->replace($payload);
    }

    private function mapLegacyTypeToCheckType(string $legacyType): string
    {
        return match ($legacyType) {
            'training' => 'training_course',
            'check' => 'background_check',
            'document' => 'policy_attestation',
            'certification', 'license' => 'credential',
            default => 'manual',
        };
    }
}
