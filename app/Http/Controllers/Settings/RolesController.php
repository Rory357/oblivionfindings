<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RolesController extends Controller
{
    private function gate(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);
    }

    public function index(Request $request)
    {
        $this->gate($request);

        $roles = Role::query()
            ->orderBy('label')
            ->with(['permissions:id,key,description'])
            ->get(['id', 'name', 'label']);

        $permissions = Permission::query()
            ->orderBy('key')
            ->get(['id', 'key', 'description']);

        return inertia('settings/roles/index', [
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => $r->label,
                'permission_keys' => $r->permissions->pluck('key')->values(),
            ])->values(),
            'permissions' => $permissions,
        ]);
    }

    public function create(Request $request)
    {
        $this->gate($request);

        $permissions = Permission::query()->orderBy('key')->get(['id', 'key', 'description']);

        return inertia('settings/roles/edit', [
            'mode' => 'create',
            'role' => null,
            'permissions' => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $this->gate($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:80'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
        ], [
            'name.regex' => 'Role key must be lowercase letters, numbers, or underscores (e.g. support_worker).',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'label' => $data['label'],
        ]);

        $keys = collect($data['permission_keys'] ?? [])->unique()->values();
        if ($keys->isNotEmpty()) {
            $permissionIds = Permission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permissionIds);
        }

        return redirect()->route('settings.roles.index');
    }

    public function edit(Request $request, Role $role)
    {
        $this->gate($request);

        $role->load(['permissions:id,key,description']);
        $permissions = Permission::query()->orderBy('key')->get(['id', 'key', 'description']);

        return inertia('settings/roles/edit', [
            'mode' => 'edit',
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permission_keys' => $role->permissions->pluck('key')->values(),
            ],
            'permissions' => $permissions,
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->gate($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')->ignore($role->id)],
            'label' => ['required', 'string', 'max:80'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
        ], [
            'name.regex' => 'Role key must be lowercase letters, numbers, or underscores (e.g. support_worker).',
        ]);

        $role->update([
            'name' => $data['name'],
            'label' => $data['label'],
        ]);

        $keys = collect($data['permission_keys'] ?? [])->unique()->values();
        $permissionIds = Permission::whereIn('key', $keys)->pluck('id')->all();
        $role->permissions()->sync($permissionIds);

        return redirect()->route('settings.roles.index');
    }
}
