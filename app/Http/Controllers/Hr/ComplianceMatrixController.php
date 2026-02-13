<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ComplianceMatrixController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — matrix grid view                                           */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $tenantId = null;

        $requirements = HrComplianceRequirement::with('matrixEntries')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $matrixEntries = HrComplianceMatrix::with('requirement:id,code,name,category')
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
            'tenant_id'  => $user->tenant_id,
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

        // Soft deactivate rather than hard delete to preserve audit trail
        $requirement->update([
            'is_active'  => false,
            'updated_by' => $user->id,
        ]);

        // Remove associated matrix entries
        HrComplianceMatrix::where('requirement_id', $requirement->id)
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

        $validated = $request->validate([
            'requirement_id' => ['required', 'integer', 'exists:hr_compliance_requirements,id'],
            'role'           => ['required', 'string', 'max:100'],
            'site_type'      => ['nullable', 'string', 'max:100'],
            'is_mandatory'   => ['required', 'boolean'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'action'         => ['required', 'string', Rule::in(['assign', 'unassign'])],
        ]);

        $requirement = HrComplianceRequirement::where('id', $validated['requirement_id'])
            ->firstOrFail();

        if ($validated['action'] === 'assign') {
            HrComplianceMatrix::updateOrCreate(
                [
                    'tenant_id'      => $user->tenant_id,
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
            ->where('role', $validated['role'])
            ->when($validated['site_type'], fn ($q) => $q->where('site_type', $validated['site_type']))
            ->delete();

        return redirect()->back()->with('success', 'Matrix entry removed.');
    }
}
