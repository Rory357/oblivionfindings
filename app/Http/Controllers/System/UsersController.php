<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UsersController extends Controller
{
    /**
     * Users Management - List all users
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $search = $request->query('search', '');
        $statusFilter = $request->query('status', 'all'); // all, active, pending
        $roleFilter = $request->query('role', 'all'); // all, specific role id
        $typeFilter = $request->query('type', 'all'); // all, staff, client, next_of_kin, board

        $query = User::query()
            ->with(['roles:id,name,label,level', 'staffProfile:id,user_id,job_title,status'])
            ->when($search, fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            );

        // Status filter
        switch ($statusFilter) {
            case 'active':
                $query->whereNotNull('approved_at');
                break;
            case 'pending':
                $query->whereNull('approved_at');
                break;
        }

        // Role filter
        if ($roleFilter !== 'all' && is_numeric($roleFilter)) {
            $query->whereHas('roles', fn($q) => $q->where('roles.id', (int) $roleFilter));
        }

        // User Type filter
        switch ($typeFilter) {
            case 'staff':
                $query->whereHas('staffProfile');
                break;
            case 'client':
                $query->whereHas('client');
                break;
            case 'next_of_kin':
                $query->whereHas('nextOfKin');
                break;
            case 'board':
                $query->whereHas('boardMember');
                break;
        }

        $users = $query->orderBy('name')
            ->paginate(20)
            ->through(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->profile_photo_url,
                'is_active' => $user->approved_at !== null,
                'approved_at' => $user->approved_at,
                'created_at' => $user->created_at,
                'roles' => $user->roles->map(fn($r) => [
                    'id' => $r->id,
                    'label' => $r->label,
                    'level' => $r->level,
                ]),
                'user_type' => $this->getUserType($user),
                'staff_profile' => $user->staffProfile ? [
                    'job_title' => $user->staffProfile->job_title,
                    'status' => $user->staffProfile->status,
                ] : null,
            ]);

        // Get all roles for filter dropdown
        $roles = Role::orderByDesc('level')->get(['id', 'name', 'label', 'level', 'type']);

        return Inertia::render('settings/users/index', [
            'users' => $users,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
                'role' => $roleFilter,
                'type' => $typeFilter,
            ],
            'roles' => $roles,
            'stats' => [
                'total' => User::count(),
                'active' => User::whereNotNull('approved_at')->count(),
                'pending' => User::whereNull('approved_at')->count(),
                'staff' => Staff::distinct('user_id')->count(),
            ],
        ]);
    }

    /**
     * Create new user form
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $clients = Client::orderBy('first_name')
            ->orderBy('last_name')
            ->whereNot(function ($q) {
                $q->where('first_name', 'Fleet')->where('last_name', 'Operations');
            })
            ->get(['id', 'first_name', 'last_name', 'nhi_number']);

        $roles = Role::orderByDesc('level')
            ->orderBy('label')
            ->get(['id', 'name', 'label', 'level', 'type']);

        return Inertia::render('system/users/Create', [
            'clients' => $clients,
            'roles' => $roles,
            'can' => [
                'createStaff' => $user->canDo('staff.create'),
                'createClient' => $user->canDo('clients.create'),
            ],
        ]);
    }

    /**
     * Store new user
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'user_type' => ['required', 'in:staff,client,next_of_kin'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            // Staff specific
            'staff.job_title' => ['required_if:user_type,staff', 'nullable', 'string'],
            'staff.department' => ['nullable', 'string'],
            'staff.employee_id' => ['nullable', 'string', Rule::unique('staff', 'employee_id')],
            // Client specific
            'client.nhi_number' => ['required_if:user_type,client', 'nullable', 'string', Rule::unique('clients', 'nhi_number')],
            'client.first_name' => ['required_if:user_type,client', 'nullable', 'string'],
            'client.last_name' => ['required_if:user_type,client', 'nullable', 'string'],
            'client.date_of_birth' => ['nullable', 'date'],
            // Next of Kin specific
            'next_of_kin.client_id' => ['required_if:user_type,next_of_kin', 'nullable', 'integer', 'exists:clients,id'],
            'next_of_kin.relationship' => ['required_if:user_type,next_of_kin', 'nullable', 'string', 'in:parent,sibling,spouse,child,grandparent,grandchild,aunt_uncle,niece_nephew,cousin,friend,other'],
            'next_of_kin.is_primary_contact' => ['boolean'],
            'next_of_kin.is_emergency_contact' => ['boolean'],
        ]);

        // Create user
        $newUser = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        // Assign roles
        if (!empty($data['role_ids'])) {
            $newUser->roles()->sync($data['role_ids']);
            $primaryRole = $newUser->roles()->orderByDesc('level')->first();
            $newUser->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();
        } elseif ($data['user_type'] === 'staff') {
            // Default role for staff is support_worker
            $supportWorkerRole = Role::where('name', 'support_worker')->first();
            if ($supportWorkerRole) {
                $newUser->roles()->sync([$supportWorkerRole->id]);
                $newUser->forceFill(['role' => 'support_worker'])->save();
            }
        } elseif ($data['user_type'] === 'next_of_kin') {
            // Default role for next of kin
            $nokRole = Role::where('name', 'next_of_kin')->first();
            if ($nokRole) {
                $newUser->roles()->sync([$nokRole->id]);
                $newUser->forceFill(['role' => 'next_of_kin'])->save();
            }
        }

        // Create entity record based on type
        switch ($data['user_type']) {
            case 'staff':
                Staff::create([
                    'user_id' => $newUser->id,
                    'employee_id' => $data['staff']['employee_id'] ?? null,
                    'job_title' => $data['staff']['job_title'] ?? null,
                    'department' => $data['staff']['department'] ?? null,
                    'status' => 'active',
                ]);
                break;

            case 'client':
                Client::create([
                    'user_id' => $newUser->id,
                    'nhi_number' => $data['client']['nhi_number'] ?? null,
                    'first_name' => $data['client']['first_name'] ?? $data['name'],
                    'last_name' => $data['client']['last_name'] ?? '',
                    'date_of_birth' => $data['client']['date_of_birth'] ?? null,
                    'email' => $data['email'],
                    'status' => 'active',
                ]);
                break;

            case 'next_of_kin':
                NextOfKin::create([
                    'user_id' => $newUser->id,
                    'client_id' => $data['next_of_kin']['client_id'] ?? null,
                    'relationship' => $data['next_of_kin']['relationship'] ?? null,
                    'is_primary_contact' => $data['next_of_kin']['is_primary_contact'] ?? false,
                    'is_emergency_contact' => $data['next_of_kin']['is_emergency_contact'] ?? true,
                ]);
                break;
        }

        return redirect()->route('system.users.index')
            ->with('success', 'User created successfully.');
    }

    /**
     * Show user details
     */
    public function show(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $target->load(['roles.permissions', 'staffProfile']);

        $allRoles = \App\Models\Role::query()->orderBy('label')->get(['id', 'name', 'label']);

        return Inertia::render('settings/users/show', [
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'avatar' => $target->profile_photo_url,
                'is_active' => $target->approved_at !== null,
                'approved_at' => $target->approved_at,
                'created_at' => $target->created_at,
                'roles' => $target->roles,
                'user_type' => $this->getUserType($target),
                'staff_profile' => $target->staffProfile,
            ],
            'allRoles' => $allRoles,
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $target->update([
            'name' => $data['name'] ?? $target->name,
            'email' => $data['email'] ?? $target->email,
        ]);

        if (isset($data['role_ids'])) {
            $target->roles()->sync($data['role_ids']);
            $primaryRole = $target->roles()->orderByDesc('level')->first();
            $target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    /**
     * Delete user
     */
    public function destroy(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        // Cannot delete self
        abort_if($user->id === $target->id, 403, 'You cannot delete your own account.');

        // Soft delete related records first
        if ($target->staffProfile) {
            $target->staffProfile->delete();
        }

        // Delete user (cascade will handle role_user pivot)
        $target->delete();

        return redirect()->route('system.users.index')
            ->with('success', 'User deleted successfully.');
    }

    /**
     * Approve pending user
     */
    public function approve(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $target->update([
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        if (!empty($data['role_ids'])) {
            $target->roles()->sync($data['role_ids']);
            $primaryRole = $target->roles()->orderByDesc('level')->first();
            $target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();
        }

        return redirect()->back()->with('success', 'User approved successfully.');
    }

    /**
     * Suspend user
     */
    public function suspend(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        abort_if($user->id === $target->id, 403, 'You cannot suspend your own account.');

        $target->update(['approved_at' => null]);

        // Also update staff status if applicable
        if ($target->staffProfile) {
            $target->staffProfile->update(['status' => 'suspended']);
        }

        return redirect()->back()->with('success', 'User suspended successfully.');
    }

    /**
     * Determine user type based on relationships
     */
    private function getUserType(User $user): string
    {
        if ($user->staffProfile) {
            return 'staff';
        }
        
        // Check if user is linked as a client
        $clientCount = Client::where('user_id', $user->id)->count();
        if ($clientCount > 0) {
            return 'client';
        }
        
        // Check if user is linked as next of kin
        $nokCount = NextOfKin::where('user_id', $user->id)->count();
        if ($nokCount > 0) {
            return 'next_of_kin';
        }

        // Check board membership via roles
        if ($user->hasRole('board_chair', 'board_secretary', 'board_member', 'board_observer')) {
            return 'board';
        }

        return 'user';
    }
}
