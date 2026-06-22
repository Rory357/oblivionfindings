<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrPosition;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    use ResolvesHrTenant;

    private function canManage($user): bool
    {
        return $user && ($user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage'));
    }

    /**
     * Departments are folded into the People hub "Departments" tab. Preserve the
     * route by redirecting, carrying filters across as the namespaced hub keys.
     */
    public function index(Request $request)
    {
        abort_unless($this->canManage($request->user()), 403);

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
        abort_unless($this->canManage($user), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_departments')->where('tenant_id', $tenantId)],
            'code' => ['nullable', 'string', 'max:50'],
            'cost_centre' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'parent_id' => ['nullable', 'exists:hr_departments,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        HrDepartment::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()->back()->with('success', 'Department created successfully.');
    }

    /**
     * Read-only detail for the View modal: head, parent, children, direct +
     * rolled-up headcount, and linked positions. Returns JSON (the hub fetches
     * it on demand — the People hub is modal-first, no standalone page).
     */
    public function show(Request $request, HrDepartment $department)
    {
        abort_unless($this->canManage($request->user()), 403);

        $department->loadMissing(['manager:id,name', 'parent:id,name']);

        $children = HrDepartment::query()
            ->where('parent_id', $department->id)
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active']);

        // Positions link to departments by name (HrPosition.department is a string).
        $linkedPositions = HrPosition::query()
            ->where('department', $department->name)
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'headcount_budget', 'is_active']);

        return response()->json([
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'cost_centre' => $department->cost_centre,
            'description' => $department->description,
            'is_active' => (bool) $department->is_active,
            'sort_order' => $department->sort_order,
            'manager' => $department->manager
                ? ['id' => $department->manager->id, 'name' => $department->manager->name]
                : null,
            'parent' => $department->parent
                ? ['id' => $department->parent->id, 'name' => $department->parent->name]
                : null,
            'direct_employee_count' => $department->employees()->where('is_active', true)->count(),
            'rolled_up_employee_count' => $department->rolledUpEmployeeCount(),
            'children' => $children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'is_active' => (bool) $c->is_active,
                'employee_count' => $c->employees_count,
            ])->values(),
            'linked_positions' => $linkedPositions->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'code' => $p->code,
                'headcount_budget' => $p->headcount_budget,
                'current_headcount' => $p->employees_count,
                'is_active' => (bool) $p->is_active,
            ])->values(),
        ]);
    }

    public function update(Request $request, HrDepartment $department)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_departments')->where('tenant_id', $tenantId)->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'cost_centre' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'parent_id' => ['nullable', 'exists:hr_departments,id'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Cycle-safe parent: reject self-parent or any parent that is a descendant.
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        if ($parentId !== null && $department->wouldCreateCycle($parentId)) {
            throw ValidationException::withMessages([
                'parent_id' => 'That parent would create a circular department hierarchy.',
            ]);
        }

        $department->update($validated);

        return redirect()->back()->with('success', 'Department updated successfully.');
    }

    public function destroy(HrDepartment $department)
    {
        $user = request()->user();
        abort_unless($this->canManage($user), 403);

        $activeEmployees = $department->employees()->where('is_active', true)->count();
        if ($activeEmployees > 0) {
            return redirect()->back()->with('error', "Cannot deactivate department with {$activeEmployees} active employee(s). Reassign them first.");
        }

        // Keep the tree connected: reparent any children up to this department's
        // own parent rather than leaving them orphaned under an inactive node.
        HrDepartment::query()
            ->where('parent_id', $department->id)
            ->update(['parent_id' => $department->parent_id]);

        $department->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Department deactivated.');
    }
}
