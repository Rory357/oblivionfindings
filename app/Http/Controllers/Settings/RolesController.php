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
            ->withCount('users')
            ->get(['id', 'name', 'label', 'description']);

        $permissions = Permission::query()
            ->orderBy('key')
            ->get(['id', 'key', 'description']);

        return inertia('settings/roles/index', [
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => $r->label,
                'description' => $r->description,
                'users_count' => $r->users_count,
                'permission_keys' => $r->permissions->pluck('key')->values(),
            ])->values(),
            'permissions' => $permissions,
        ]);
    }

    public function create(Request $request)
    {
        $this->gate($request);

        $permissions = Permission::query()->orderBy('key')->get(['id', 'key', 'description']);

        // Support cloning from an existing role
        $cloneRole = null;
        if ($cloneId = $request->query('clone')) {
            $source = Role::with('permissions:id,key')->find($cloneId);
            if ($source) {
                $cloneRole = [
                    'id' => 0,
                    'name' => $source->name . '_copy',
                    'label' => $source->label . ' (Copy)',
                    'description' => $source->description,
                    'users_count' => 0,
                    'permission_keys' => $source->permissions->pluck('key')->values(),
                ];
            }
        }

        return inertia('settings/roles/edit', [
            'mode' => 'create',
            'role' => $cloneRole,
            'permissions' => $permissions,
            'landingRoutes' => $this->landingRouteOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->gate($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
            'landing_route' => ['nullable', 'string', Rule::in(array_keys(config('landing_routes', [])))],
        ], [
            'name.regex' => 'Role key must be lowercase letters, numbers, or underscores (e.g. support_worker).',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'landing_route' => $data['landing_route'] ?? null,
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
        $role->loadCount('users');
        $permissions = Permission::query()->orderBy('key')->get(['id', 'key', 'description']);

        return inertia('settings/roles/edit', [
            'mode' => 'edit',
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'users_count' => $role->users_count,
                'permission_keys' => $role->permissions->pluck('key')->values(),
                'landing_route' => $role->landing_route,
            ],
            'permissions' => $permissions,
            'landingRoutes' => $this->landingRouteOptions(),
        ]);
    }

    /**
     * @return array<array{key: string, label: string}>
     */
    private function landingRouteOptions(): array
    {
        return collect(config('landing_routes', []))
            ->map(fn ($config, $key) => ['key' => (string) $key, 'label' => (string) ($config['label'] ?? $key)])
            ->values()
            ->all();
    }

    public function update(Request $request, Role $role)
    {
        $this->gate($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')->ignore($role->id)],
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
            'landing_route' => ['nullable', 'string', Rule::in(array_keys(config('landing_routes', [])))],
        ], [
            'name.regex' => 'Role key must be lowercase letters, numbers, or underscores (e.g. support_worker).',
        ]);

        $role->update([
            'name' => $data['name'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'landing_route' => $data['landing_route'] ?? null,
        ]);

        $keys = collect($data['permission_keys'] ?? [])->unique()->values();
        $permissionIds = Permission::whereIn('key', $keys)->pluck('id')->all();
        $role->permissions()->sync($permissionIds);

        return redirect()->route('settings.roles.index');
    }
}
