<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();

        // Only users with staff.viewAny can list everyone.
        abort_unless($auth && $auth->canDo('staff.viewAny'), 403);

        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($search !== '', fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->with([
                'roles:id,name,label',
                'staffProfile:user_id,phone,job_title,employment_type,start_date,is_active',
            ])
            ->withCount('assignedClients')
            ->paginate(20)
            ->withQueryString();

        return inertia('staff/index', [
            'users' => $users,
            'filters' => ['q' => $search],
        ]);
    }

    public function show(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        // Staff can view themselves; managers/admins can view any staff.
        if ($auth->id !== $user->id) {
            abort_unless($auth->canDo('staff.viewAny'), 403);
        }

        $user->load([
            'roles:id,name,label',
            'staffProfile',
            'assignedClients:id,first_name,last_name,status',
        ]);

        // Today shifts snapshot (for dashboard-like view)
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        $todayShifts = \App\Models\Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->get();

        return inertia('staff/show', [
            'user' => $user,
            'todayShifts' => $todayShifts,
        ]);
    }

    public function edit(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.update'), 403);

        $user->load(['roles:id,name,label', 'staffProfile']);

        $roles = Role::query()->orderBy('label')->get(['id', 'name', 'label']);

        return inertia('staff/edit', [
            'user' => $user,
            'roles' => $roles,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.update'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'profile' => ['array'],
            'profile.phone' => ['nullable', 'string', 'max:50'],
            'profile.job_title' => ['nullable', 'string', 'max:255'],
            'profile.employment_type' => ['nullable', 'string', 'max:255'],
            'profile.start_date' => ['nullable', 'date'],
            'profile.is_active' => ['nullable', 'boolean'],
        ]);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        // Sync RBAC roles (optional)
        if (isset($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);

            // Keep legacy users.role in sync for existing UI checks
            $first = $user->roles()->orderBy('id')->first();
            $user->forceFill(['role' => $first?->name])->save();
        }

        // Staff profile
        $profileData = $data['profile'] ?? [];
        $user->staffProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => $profileData['phone'] ?? null,
                'job_title' => $profileData['job_title'] ?? null,
                'employment_type' => $profileData['employment_type'] ?? null,
                'start_date' => $profileData['start_date'] ?? null,
                'is_active' => (bool) ($profileData['is_active'] ?? true),
            ]
        );

        return redirect()->route('staff.show', $user)->with('success', 'Staff updated.');
    }
}
