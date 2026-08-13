<?php

namespace App\Http\Controllers\System;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Enums\NextOfKinRelationship;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $has2fa = $request->query('has_2fa', 'all'); // all, yes, no
        $activity = $request->query('activity', 'all'); // all, today, week, inactive

        $query = User::query()
            ->with([
                'roles:id,name,label,level',
                'hrEmployeeProfile' => function (Relation $profileRelation) use ($user): void {
                    $this->scopeCurrentEmployeeProfiles($profileRelation->getQuery(), $user);
                },
            ])
            ->when($search, fn ($q) => $q
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
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', (int) $roleFilter));
        }

        // User Type filter
        switch ($typeFilter) {
            case 'staff':
                $query->whereIn('users.id', $this->currentEmployeeProfiles($user)->select('user_id'));
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

        // 2FA filter
        if ($has2fa === 'yes') {
            $query->whereNotNull('two_factor_confirmed_at');
        } elseif ($has2fa === 'no') {
            $query->whereNull('two_factor_confirmed_at');
        }

        // Activity filter
        switch ($activity) {
            case 'today':
                $query->where('last_login_at', '>=', now()->startOfDay());
                break;
            case 'week':
                $query->where('last_login_at', '>=', now()->startOfWeek());
                break;
            case 'inactive':
                $query->where(function ($q) {
                    $q->whereNull('last_login_at')
                        ->orWhere('last_login_at', '<', now()->subDays(30));
                });
                break;
        }

        $users = $query->orderBy('name')
            ->paginate(20)
            ->through(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->profile_photo_url,
                'is_active' => $user->approved_at !== null,
                'approved_at' => $user->approved_at,
                'created_at' => $user->created_at,
                'roles' => $user->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'label' => $r->label,
                    'level' => $r->level,
                ]),
                'user_type' => $this->getUserType($user),
                'staff_profile' => $this->serializeEmployeeProfile($user->hrEmployeeProfile),
                'last_login_at' => $user->last_login_at,
                'last_login_ip' => $user->last_login_ip,
                'login_count' => $user->login_count ?? 0,
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at,
                'session_count' => DB::table('sessions')->where('user_id', $user->id)->count(),
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
                'has_2fa' => $has2fa,
                'activity' => $activity,
            ],
            'roles' => $roles,
            'stats' => [
                'total' => User::count(),
                'active' => User::whereNotNull('approved_at')->count(),
                'pending' => User::whereNull('approved_at')->count(),
                'staff' => $this->currentEmployeeProfiles($user)->count(),
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

        if ($request->query('type') === 'staff') {
            abort_unless($user->canDo('hr.employees.manage'), 403);

            return redirect()->to(route('hr.people.index', [
                'create' => 'staff',
            ], absolute: false));
        }

        $canCreateClient = $user->canDo('clients.create');
        $canManageEmployees = $user->canDo('hr.employees.manage');
        $clientQuery = Client::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->whereNot(function ($q) {
                $q->where('first_name', 'Fleet')->where('last_name', 'Operations');
            });
        $clients = $canCreateClient
            ? app(UserSiteAccessService::class)
                ->applyClientScope($clientQuery, $user, ['clients.viewAny'])
                ->get(['id', 'first_name', 'last_name', 'nhi_number'])
            : collect();

        return Inertia::render('system/users/Create', [
            'clients' => $clients,
            'can' => [
                'createClient' => $canCreateClient,
                'manageEmployees' => $canManageEmployees,
            ],
            'staffLifecycleHref' => $canManageEmployees
                ? route('hr.people.index', ['create' => 'staff'], absolute: false)
                : null,
        ]);
    }

    /**
     * Store new user
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        if ($request->input('user_type') === 'staff') {
            throw ValidationException::withMessages([
                'user_type' => 'Create staff in HR People so their Site, employee number, employment dates, and role provenance are recorded together.',
            ]);
        }

        abort_unless($user->canDo('clients.create'), 403);

        $accessibleClientQuery = app(UserSiteAccessService::class)
            ->applyClientScope(Client::query(), $user, ['clients.viewAny']);
        $accessibleClientIds = $accessibleClientQuery->pluck('clients.id')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'user_type' => ['required', 'in:client,next_of_kin'],
            // Client specific
            'client.nhi_number' => ['required_if:user_type,client', 'nullable', 'string', Rule::unique('clients', 'nhi_number')],
            'client.first_name' => ['required_if:user_type,client', 'nullable', 'string'],
            'client.last_name' => ['required_if:user_type,client', 'nullable', 'string'],
            'client.date_of_birth' => ['nullable', 'date'],
            // Next of Kin specific
            'next_of_kin.client_id' => [
                'required_if:user_type,next_of_kin',
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleClientIds),
                ),
            ],
            'next_of_kin.relationship' => ['required_if:user_type,next_of_kin', 'nullable', 'string', Rule::enum(NextOfKinRelationship::class)],
            'next_of_kin.is_primary_contact' => ['boolean'],
            'next_of_kin.is_emergency_contact' => ['boolean'],
        ]);

        DB::transaction(function () use ($data, $user): void {
            $portalRoleName = $data['user_type'] === 'client' ? 'client' : 'next_of_kin';
            $portalRole = Role::query()->where('name', $portalRoleName)->firstOrFail();
            $newUser = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $portalRoleName,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);
            $newUser->roles()->sync([$portalRole->id]);

            if ($data['user_type'] === 'client') {
                Client::create([
                    'user_id' => $newUser->id,
                    'nhi_number' => $data['client']['nhi_number'] ?? null,
                    'first_name' => $data['client']['first_name'] ?? $data['name'],
                    'last_name' => $data['client']['last_name'] ?? '',
                    'date_of_birth' => $data['client']['date_of_birth'] ?? null,
                    'email' => $data['email'],
                    'status' => 'active',
                ]);
            } else {
                NextOfKin::create([
                    'user_id' => $newUser->id,
                    'client_id' => $data['next_of_kin']['client_id'] ?? null,
                    'relationship' => $data['next_of_kin']['relationship'] ?? null,
                    'is_primary_contact' => $data['next_of_kin']['is_primary_contact'] ?? false,
                    'is_emergency_contact' => $data['next_of_kin']['is_emergency_contact'] ?? true,
                ]);
            }

            AuditLogger::log('user.created', $newUser, [
                'created_by' => $user->id,
                'user_type' => $data['user_type'],
                'role_ids' => [$portalRole->id],
            ]);

        });

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

        $target->load([
            'roles.permissions',
            'hrEmployeeProfile' => function (Relation $profileRelation) use ($user): void {
                $this->scopeCurrentEmployeeProfiles($profileRelation->getQuery(), $user);
            },
        ]);

        $allRoles = Role::query()->orderBy('label')->get(['id', 'name', 'label']);

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
                'staff_profile' => $this->serializeEmployeeProfile($target->hrEmployeeProfile, detailed: true),
                'last_login_at' => $target->last_login_at,
                'last_login_ip' => $target->last_login_ip,
                'login_count' => $target->login_count ?? 0,
                'two_factor_confirmed_at' => $target->two_factor_confirmed_at,
            ],
            'allRoles' => $allRoles,
            'login_logs' => UserLoginLog::where('user_id', $target->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
            'active_sessions' => DB::table('sessions')
                ->where('user_id', $target->id)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'ip_address' => $s->ip_address,
                    'user_agent' => $s->user_agent,
                    'last_activity' => $s->last_activity,
                    'is_current' => $s->id === session()->getId(),
                ]),
            'login_stats' => [
                'this_month' => UserLoginLog::where('user_id', $target->id)
                    ->where('event_type', 'login')
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
                'last_ip' => $target->last_login_ip,
                'active_sessions' => DB::table('sessions')
                    ->where('user_id', $target->id)
                    ->count(),
            ],
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);
        $employeeProfile = $this->accessibleEmployeeProfileOrFail($target, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        DB::transaction(function () use ($data, $employeeProfile, $target, $user): void {
            $target->update([
                'name' => $data['name'] ?? $target->name,
                'email' => $data['email'] ?? $target->email,
            ]);

            if ($employeeProfile && array_key_exists('email', $data)) {
                $employeeProfile->forceFill([
                    'work_email' => $target->email,
                    'updated_by' => $user->id,
                ])->save();
            }

            if (isset($data['role_ids'])) {
                $target->roles()->sync($data['role_ids']);
                $primaryRole = $target->roles()->orderByDesc('level')->first();
                $target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();
            }
        });

        AuditLogger::log('user.updated', $target, [
            'changed_by' => $user->id,
            'fields' => array_keys($data),
            'role_ids' => $data['role_ids'] ?? null,
        ]);

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

        if ($this->accessibleEmployeeProfileOrFail($target, $user)) {
            throw ValidationException::withMessages([
                'user' => 'Staff accounts must be offboarded in HR People so employment history and Site provenance are retained.',
            ]);
        }

        AuditLogger::log('user.deleted', $target, [
            'deleted_by' => $user->id,
        ]);

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

        if (! empty($data['role_ids'])) {
            $target->roles()->sync($data['role_ids']);
            $primaryRole = $target->roles()->orderByDesc('level')->first();
            $target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();
        }

        AuditLogger::log('user.approved', $target, [
            'approved_by' => $user->id,
            'role_ids' => $data['role_ids'] ?? [],
        ]);

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
        $this->accessibleEmployeeProfileOrFail($target, $user);

        $target->update(['approved_at' => null]);

        AuditLogger::log('user.suspended', $target, [
            'suspended_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'User suspended successfully.');
    }

    /**
     * Terminate a specific session for a user
     */
    public function terminateSession(Request $request, User $target, string $sessionId)
    {
        $user = $request->user();
        abort_unless($user?->canDo('settings.access.manage'), 403);

        $deleted = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $target->id)
            ->delete();

        AuditLogger::log('user.session.terminated', $target, [
            'terminated_by' => $user->id,
            'session_id' => $sessionId,
            'deleted' => $deleted,
        ]);

        return back()->with('success', 'Session terminated.');
    }

    /**
     * Terminate all other sessions for a user
     */
    public function terminateAllSessions(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user?->canDo('settings.access.manage'), 403);

        $deleted = DB::table('sessions')
            ->where('user_id', $target->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        AuditLogger::log('user.sessions.terminated', $target, [
            'terminated_by' => $user->id,
            'deleted' => $deleted,
        ]);

        return back()->with('success', 'All other sessions terminated.');
    }

    /**
     * Impersonate a user
     */
    public function impersonate(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.impersonate'), 403);
        abort_if($user->id === $target->id, 403, 'You cannot impersonate yourself.');
        abort_if($target->hasRole('admin'), 403, 'Cannot impersonate administrators.');
        abort_unless($target->canBeImpersonated(), 403, 'This user cannot be impersonated.');

        AuditLogger::log('user.impersonate.start', $target, [
            'impersonator_id' => $user->id,
            'impersonator_name' => $user->name,
            'target_id' => $target->id,
            'target_name' => $target->name,
        ]);

        $user->impersonate($target);

        return redirect()->route('dashboard')
            ->with('info', "You are now impersonating {$target->name}.");
    }

    /**
     * Stop impersonating
     */
    public function stopImpersonating(Request $request)
    {
        $manager = app('impersonate');
        abort_unless($manager->isImpersonating(), 403, 'You are not impersonating anyone.');

        $impersonatedUser = $request->user();
        $impersonatorId = $manager->getImpersonatorId();

        AuditLogger::log('user.impersonate.stop', $impersonatedUser, [
            'impersonator_id' => $impersonatorId,
            'target_id' => $impersonatedUser?->id,
            'target_name' => $impersonatedUser?->name,
        ]);

        $manager->leave();

        return redirect()->route('system.users.index')
            ->with('success', 'You have stopped impersonating.');
    }

    /**
     * Determine user type based on relationships
     */
    private function getUserType(User $user): string
    {
        if ($user->hrEmployeeProfile) {
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

    private function currentEmployeeProfiles(User $viewer): Builder
    {
        return $this->scopeCurrentEmployeeProfiles(HrEmployeeProfile::query(), $viewer);
    }

    private function scopeCurrentEmployeeProfiles(Builder $query, User $viewer): Builder
    {
        return app(UserSiteAccessService::class)->applyCurrentStaffProfileScope(
            $query,
            $viewer,
            ['sites.viewAll'],
        );
    }

    private function accessibleEmployeeProfileOrFail(User $target, User $viewer): ?HrEmployeeProfile
    {
        if (! HrEmployeeProfile::withTrashed()->where('user_id', $target->id)->exists()) {
            return null;
        }

        return $this->currentEmployeeProfiles($viewer)
            ->where('user_id', $target->id)
            ->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    private function serializeEmployeeProfile(?HrEmployeeProfile $profile, bool $detailed = false): ?array
    {
        if (! $profile) {
            return null;
        }

        $serialized = [
            'job_title' => $profile->position_title,
            'status' => $profile->is_active ? 'active' : 'inactive',
        ];

        if (! $detailed) {
            return $serialized;
        }

        return [
            ...$serialized,
            'id' => $profile->id,
            'employee_id' => $profile->employee_number,
            'department' => $profile->department,
            'hire_date' => $profile->start_date?->toDateString(),
            'work_phone' => $profile->work_phone,
        ];
    }
}
