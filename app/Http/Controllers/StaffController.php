<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkstreamService;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();

        // Only users with staff.viewAny can list everyone.
        abort_unless($auth && $auth->canDo('staff.viewAny'), 403);

        $search = trim((string) $request->query('q', ''));

        $users = User::staff()
            ->when($search !== '', fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->with([
                'roles:id,name,label',
                'staffProfile:user_id,job_title,department,status,hire_date',
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

        // Portal users should never appear in the staff module.
        abort_if($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true), 404);

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

        $rangeEnd = now()->addDays(14)->endOfDay();

        $todayShifts = \App\Models\Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->get();

        $upcomingShifts = \App\Models\Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [now(), $rangeEnd])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->limit(200)
            ->get();

        $myDayItems = app(WorkstreamService::class)
            ->forStaff($user, (clone $today), (clone $rangeEnd))
            ->take(250)
            ->values();

        return inertia('staff/show', [
            'user' => $user,
            'myDayItems' => $myDayItems,
            'todayShifts' => $todayShifts,
            'upcomingShifts' => $upcomingShifts,
        ]);
    }

    public function edit(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.update'), 403);

        // Portal users should never appear in the staff module.
        abort_if($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true), 404);

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

        // Portal users should never be editable from the staff module.
        abort_if($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'profile' => ['array'],
            'profile.job_title' => ['nullable', 'string', 'max:255'],
            'profile.department' => ['nullable', 'string', 'max:255'],
            'profile.work_phone' => ['nullable', 'string', 'max:50'],
            'profile.mobile_phone' => ['nullable', 'string', 'max:50'],
            'profile.hire_date' => ['nullable', 'date'],
            'profile.status' => ['nullable', 'in:active,on_leave,suspended,terminated'],
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
                'job_title' => $profileData['job_title'] ?? null,
                'department' => $profileData['department'] ?? null,
                'work_phone' => $profileData['work_phone'] ?? null,
                'mobile_phone' => $profileData['mobile_phone'] ?? null,
                'hire_date' => $profileData['hire_date'] ?? null,
                'status' => $profileData['status'] ?? 'active',
            ]
        );

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'staff', $user, null, [
            'title' => "Staff updated: {$user->name}",
            'url' => url("/staff/{$user->id}"),
            'target_user_ids' => [$user->id],
        ]);

        return redirect()->route('staff.show', $user)->with('success', 'Staff updated.');
    }
}
