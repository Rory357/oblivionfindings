<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDepartment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    /**
     * Departments are folded into the People hub "Departments" tab. Preserve the
     * route by redirecting, carrying filters across as the namespaced hub keys.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage')), 403);

        $params = ['tab' => 'departments'];
        if ($q = trim((string) $request->query('q', ''))) {
            $params['dept_q'] = $q;
        }
        if ($status = $request->query('status')) {
            $params['dept_status'] = $status;
        }

        return redirect()->route('hr.people.index', $params);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage')), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_departments')->where('tenant_id', $user->tenant_id ?? null)],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'parent_id' => ['nullable', 'exists:hr_departments,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        HrDepartment::create([
            ...$validated,
            'tenant_id' => $user->tenant_id,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()->back()->with('success', 'Department created successfully.');
    }

    public function update(Request $request, HrDepartment $department)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage')), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_departments')->where('tenant_id', $user->tenant_id ?? null)->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'parent_id' => ['nullable', 'exists:hr_departments,id'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Prevent self-referencing parent
        if (isset($validated['parent_id']) && $validated['parent_id'] == $department->id) {
            $validated['parent_id'] = null;
        }

        $department->update($validated);

        return redirect()->back()->with('success', 'Department updated successfully.');
    }

    public function destroy(HrDepartment $department)
    {
        $user = request()->user();
        abort_unless($user && ($user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage')), 403);

        $activeEmployees = $department->employees()->where('is_active', true)->count();

        if ($activeEmployees > 0) {
            return redirect()->back()->with('error', "Cannot deactivate department with {$activeEmployees} active employee(s). Reassign them first.");
        }

        $department->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Department deactivated.');
    }
}
