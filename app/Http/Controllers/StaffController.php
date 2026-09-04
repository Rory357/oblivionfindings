<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\FleetDriverSession;
use App\Models\FleetDrivingMetric;
use App\Models\FleetIncident;
use App\Models\FleetTrip;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\NotificationService;
use App\Services\UserSiteAccessService;
use App\Services\WorkstreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\SchemaCache;
use Inertia\Inertia;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();

        // Only users with staff.viewAny can list everyone.
        abort_unless($auth && $auth->canDo('staff.viewAny'), 403);

        $search = trim((string) $request->query('q', ''));

        $users = User::staff()
            ->when($search !== '', fn ($q) => $q->where(fn ($subQuery) => $subQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderBy('name')
            ->with(['roles:id,name,label'])
            ->paginate(20)
            ->withQueryString();

        $userIds = collect($users->items())->pluck('id')->all();
        $staffProfiles = $this->staffProfileMap($userIds);
        $assignedClientCounts = $this->assignedClientCountMap($userIds);

        $users->through(fn (User $user) => $this->serializeStaffUser(
            $user,
            $staffProfiles[$user->id] ?? null,
            $assignedClientCounts[$user->id] ?? 0,
        ));

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

        $user->load(['roles:id,name,label']);
        $staffProfile = $this->staffProfileMap([$user->id])[$user->id] ?? null;
        $assignedClients = $this->assignedClientsForUser($user->id);

        // Today shifts snapshot (for dashboard-like view)
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        $rangeEnd = now()->addDays(14)->endOfDay();

        $todayShifts = Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->get();

        $upcomingShifts = Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [now(), $rangeEnd])
            ->orderBy('starts_at')
            ->limit(200)
            ->get();

        $shiftClients = $this->clientSummaryMap(
            $todayShifts
                ->pluck('client_id')
                ->merge($upcomingShifts->pluck('client_id'))
                ->filter()
                ->unique()
                ->values()
                ->all()
        );

        $todayShifts = $this->serializeShifts($todayShifts, $shiftClients);
        $upcomingShifts = $this->serializeShifts($upcomingShifts, $shiftClients);

        try {
            $myDayItems = app(WorkstreamService::class)
                ->forStaff($user, (clone $today), (clone $rangeEnd))
                ->take(250)
                ->values();
        } catch (\Throwable) {
            $myDayItems = collect();
        }

        return inertia('staff/show', [
            'user' => array_merge(
                $this->serializeStaffUser($user, $staffProfile),
                ['assigned_clients' => $assignedClients]
            ),
            'myDayItems' => $myDayItems,
            'todayShifts' => $todayShifts,
            'upcomingShifts' => $upcomingShifts,
            'fleet' => Inertia::optional(fn () => $this->buildFleetData($user)),
        ]);
    }

    private function buildFleetData(User $user): array
    {
        $hasSessions = SchemaCache::hasTable('fleet_driver_sessions');
        $hasTrips = SchemaCache::hasTable('fleet_trips');
        $hasMetrics = SchemaCache::hasTable('fleet_driving_metrics');
        $hasIncidents = SchemaCache::hasTable('fleet_incidents');

        // Driver eligibility
        $eligibility = $user->hrDriverEligibility;
        $eligibilityData = $eligibility ? [
            'licence_class' => $eligibility->licence_class,
            'licence_expires_at' => optional($eligibility->licence_expires_at)->toDateString(),
            'can_drive_clients' => $eligibility->can_drive_clients,
            'can_drive_clients_approved_at' => optional($eligibility->can_drive_clients_approved_at)->toISOString(),
            'status' => $eligibility->status,
            'incident_free_since' => optional($eligibility->incident_free_since)->toDateString(),
            'last_reviewed_at' => optional($eligibility->last_reviewed_at)->toISOString(),
            'next_review_at' => optional($eligibility->next_review_at)->toISOString(),
        ] : null;

        // 30-day stats
        $thirtyDaysAgo = now()->subDays(30);

        $tripCount30d = $hasSessions && $hasTrips
            ? FleetTrip::query()
                ->whereHas('driverSession', fn ($q) => $q->where('user_id', $user->id))
                ->where('started_at', '>=', $thirtyDaysAgo)
                ->count()
            : 0;

        $distanceKm30d = $hasSessions && $hasTrips
            ? (float) FleetTrip::query()
                ->whereHas('driverSession', fn ($q) => $q->where('user_id', $user->id))
                ->where('started_at', '>=', $thirtyDaysAgo)
                ->sum('distance_km')
            : 0;

        // Safety score (latest period from FleetDrivingMetric — via any asset driven recently)
        $safetyScore = null;
        if ($hasMetrics && $hasSessions) {
            $recentAssetIds = FleetDriverSession::query()
                ->where('user_id', $user->id)
                ->where('started_at', '>=', $thirtyDaysAgo)
                ->pluck('asset_id')
                ->unique()
                ->all();

            if ($recentAssetIds) {
                $safetyScore = FleetDrivingMetric::query()
                    ->whereIn('asset_id', $recentAssetIds)
                    ->where('period_start', '>=', $thirtyDaysAgo)
                    ->avg('score');

                $safetyScore = $safetyScore !== null ? round($safetyScore) : null;
            }
        }

        // Incident count (30d)
        $incidentCount30d = $hasIncidents
            ? FleetIncident::query()
                ->where('driver_user_id', $user->id)
                ->where('occurred_at', '>=', $thirtyDaysAgo)
                ->count()
            : 0;

        // Recent trips (last 5)
        $recentTrips = $hasSessions && $hasTrips
            ? FleetTrip::query()
                ->whereHas('driverSession', fn ($q) => $q->where('user_id', $user->id))
                ->with(['asset:id,name,asset_tag'])
                ->latest('started_at')
                ->limit(5)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'vehicle' => $t->asset ? ['id' => $t->asset->id, 'name' => $t->asset->name] : null,
                    'started_at' => optional($t->started_at)->toISOString(),
                    'ended_at' => optional($t->ended_at)->toISOString(),
                    'distance_km' => $t->distance_km,
                    'duration_s' => $t->duration_s,
                    'status' => $t->status,
                ])
                ->values()
            : collect();

        return [
            'eligibility' => $eligibilityData,
            'stats' => [
                'trips_30d' => $tripCount30d,
                'distance_km_30d' => round($distanceKm30d, 1),
                'safety_score' => $safetyScore,
                'incidents_30d' => $incidentCount30d,
            ],
            'recent_trips' => $recentTrips,
        ];
    }

    public function edit(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.update'), 403);

        // Portal users should never appear in the staff module.
        abort_if($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true), 404);

        $user->load(['roles:id,name,label']);
        $staffProfile = $this->staffProfileMap([$user->id])[$user->id] ?? null;

        $roles = Role::query()->orderBy('label')->get(['id', 'name', 'label']);

        return inertia('staff/edit', [
            'user' => $this->serializeStaffUser($user, $staffProfile),
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
            'profile.phone' => ['nullable', 'string', 'max:50'],
            'profile.job_title' => ['nullable', 'string', 'max:255'],
            'profile.department' => ['nullable', 'string', 'max:255'],
            'profile.employment_type' => ['nullable', 'string', 'max:255'],
            'profile.work_phone' => ['nullable', 'string', 'max:50'],
            'profile.mobile_phone' => ['nullable', 'string', 'max:50'],
            'profile.start_date' => ['nullable', 'date_format:Y-m-d'],
            'profile.hire_date' => ['nullable', 'date_format:Y-m-d'],
            'profile.is_active' => ['nullable', 'boolean'],
            'profile.status' => ['nullable', 'in:active,on_leave,suspended,terminated'],
        ]);

        $profileData = $data['profile'] ?? [];
        $profile = $profileData !== []
            ? $this->currentAccessibleProfile($auth, $user->id)
            : null;

        $actorId = (int) $auth->id;
        $targetId = (int) $user->id;
        $profileId = $profile?->id;
        $roleIds = collect($data['role_ids'] ?? [])
            ->map(fn ($roleId): int => (int) $roleId)
            ->filter(fn (int $roleId): bool => $roleId > 0)
            ->unique()
            ->sort()
            ->values();
        DB::transaction(function () use ($actorId, $data, $profileData, $profileId, $roleIds, $targetId): void {
            $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                [$actorId, $targetId],
                ['staff.update', 'sites.viewAll'],
                $roleIds->all(),
            );
            /** @var User|null $lockedActor */
            $lockedActor = $lockedUsers->get($actorId);
            /** @var User|null $lockedUser */
            $lockedUser = $lockedUsers->get($targetId);
            abort_unless($lockedActor?->canDo('staff.update'), 403);
            abort_unless($lockedUser, 404);
            abort_if(
                $lockedUser->hasRole('client', 'next_of_kin')
                    || in_array($lockedUser->role, ['client', 'next_of_kin'], true),
                404,
            );
            $lockedUser->update([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            // Sync RBAC roles (optional)
            if (isset($data['role_ids'])) {
                $lockedUser->roles()->sync($roleIds->all());

                // Keep legacy users.role in sync for existing UI checks
                $first = Role::query()
                    ->whereIn('id', $roleIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                $lockedUser->forceFill(['role' => $first?->name])->save();
            }

            if ($profileId) {
                $profileQuery = HrEmployeeProfile::query()
                    ->whereKey($profileId)
                    ->where('user_id', $lockedUser->id);
                app(UserSiteAccessService::class)->applyCurrentStaffProfileScope(
                    $profileQuery,
                    $lockedActor,
                    ['sites.viewAll'],
                );
                $lockedProfile = $profileQuery->lockForUpdate()->firstOrFail();
                $this->persistStaffProfile($lockedProfile, $lockedUser, $profileData, $lockedActor->id);
            }
        });

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'staff', $user, null, [
            'title' => "Staff updated: {$user->name}",
            'url' => url("/staff/{$user->id}"),
            'target_user_ids' => [$user->id],
        ]);

        return redirect()->route('staff.show', $user)->with('success', 'Staff updated.');
    }

    private function serializeStaffUser(User $user, ?array $staffProfile = null, int $assignedClientsCount = 0): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar' => $user->avatar,
            'profile_photo_url' => $user->profile_photo_url,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
            ])->values()->all(),
            'staff_profile' => $staffProfile,
            'assigned_clients_count' => $assignedClientsCount,
        ];
    }

    private function assignedClientCountMap(array $userIds): array
    {
        if ($userIds === [] || ! SchemaCache::hasTable('client_user')) {
            return [];
        }

        $query = DB::table('client_user')
            ->selectRaw('client_user.user_id as user_id, count(*) as assigned_clients_count')
            ->whereIn('client_user.user_id', $userIds)
            ->groupBy('client_user.user_id');

        if (SchemaCache::hasTable('clients')) {
            $query->join('clients', 'clients.id', '=', 'client_user.client_id');

            if (SchemaCache::hasColumn('clients', 'deleted_at')) {
                $query->whereNull('clients.deleted_at');
            }
        }

        return $query
            ->pluck('assigned_clients_count', 'user_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function assignedClientsForUser(int $userId): array
    {
        if (! SchemaCache::hasTable('client_user') || ! SchemaCache::hasTable('clients')) {
            return [];
        }

        $query = DB::table('client_user')
            ->join('clients', 'clients.id', '=', 'client_user.client_id')
            ->where('client_user.user_id', $userId)
            ->orderBy('clients.first_name')
            ->orderBy('clients.last_name')
            ->select('clients.id', 'clients.first_name', 'clients.last_name', 'clients.status');

        if (SchemaCache::hasColumn('clients', 'deleted_at')) {
            $query->whereNull('clients.deleted_at');
        }

        return $query->get()->map(fn ($client) => [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'status' => $client->status,
        ])->all();
    }

    private function clientSummaryMap(array $clientIds): array
    {
        if ($clientIds === [] || ! SchemaCache::hasTable('clients')) {
            return [];
        }

        $query = DB::table('clients')
            ->whereIn('id', $clientIds)
            ->select('id', 'first_name', 'last_name');

        if (SchemaCache::hasColumn('clients', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->get()
            ->keyBy('id')
            ->map(fn ($client) => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ])
            ->all();
    }

    private function serializeShifts($shifts, array $clientMap): array
    {
        return $shifts->map(fn ($shift) => [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toISOString(),
            'ends_at' => optional($shift->ends_at)->toISOString(),
            'status' => $shift->status,
            'location' => $shift->location,
            'client' => $shift->client_id ? ($clientMap[$shift->client_id] ?? null) : null,
        ])->values()->all();
    }

    private function staffProfileMap(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $profiles = HrEmployeeProfile::withTrashed()
            ->whereIn('user_id', $userIds)
            ->get([
                'user_id',
                'position_title',
                'department',
                'employment_type',
                'work_phone',
                'start_date',
                'is_active',
            ])
            ->mapWithKeys(fn (HrEmployeeProfile $profile) => [
                $profile->user_id => [
                    'phone' => $profile->work_phone,
                    'job_title' => $profile->position_title,
                    'department' => $profile->department,
                    'employment_type' => $profile->employment_type,
                    'work_phone' => $profile->work_phone,
                    'mobile_phone' => null,
                    'hire_date' => $profile->start_date?->toDateString(),
                    'start_date' => $profile->start_date?->toDateString(),
                    'status' => $profile->is_active ? 'active' : 'inactive',
                    'is_active' => (bool) $profile->is_active,
                ],
            ])
            ->all();

        $missingUserIds = array_values(array_diff($userIds, array_keys($profiles)));

        // Read-only compatibility for records awaiting a governed backfill into
        // HrEmployeeProfile. StaffController never writes either legacy store.
        if ($missingUserIds !== [] && SchemaCache::hasTable('staff') && SchemaCache::hasColumn('staff', 'user_id')) {
            $query = DB::table('staff')
                ->whereIn('user_id', $missingUserIds)
                ->select($this->availableColumns('staff', [
                    'user_id',
                    'job_title',
                    'department',
                    'work_phone',
                    'mobile_phone',
                    'hire_date',
                    'status',
                ]));

            if (SchemaCache::hasColumn('staff', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $profiles += $query
                ->get()
                ->mapWithKeys(fn ($profile) => [
                    $profile->user_id => [
                        'phone' => $profile->work_phone ?? $profile->mobile_phone ?? null,
                        'job_title' => $profile->job_title ?? null,
                        'department' => $profile->department ?? null,
                        'employment_type' => $profile->department ?? null,
                        'work_phone' => $profile->work_phone ?? null,
                        'mobile_phone' => $profile->mobile_phone ?? null,
                        'hire_date' => $profile->hire_date ?? null,
                        'start_date' => $profile->hire_date ?? null,
                        'status' => $profile->status ?? 'active',
                        'is_active' => ($profile->status ?? 'active') === 'active',
                    ],
                ])
                ->all();
        }

        $missingUserIds = array_values(array_diff($userIds, array_keys($profiles)));
        if ($missingUserIds !== [] && SchemaCache::hasTable('staff_profiles') && SchemaCache::hasColumn('staff_profiles', 'user_id')) {
            $profiles += DB::table('staff_profiles')
                ->whereIn('user_id', $missingUserIds)
                ->select($this->availableColumns('staff_profiles', [
                    'user_id',
                    'phone',
                    'job_title',
                    'employment_type',
                    'start_date',
                    'is_active',
                ]))
                ->get()
                ->mapWithKeys(fn ($profile) => [
                    $profile->user_id => [
                        'phone' => $profile->phone ?? null,
                        'job_title' => $profile->job_title ?? null,
                        'employment_type' => $profile->employment_type ?? null,
                        'start_date' => $profile->start_date ?? null,
                        'hire_date' => $profile->start_date ?? null,
                        'status' => ($profile->is_active ?? true) ? 'active' : 'inactive',
                        'is_active' => (bool) ($profile->is_active ?? true),
                    ],
                ])
                ->all();
        }

        return $profiles;
    }

    private function currentAccessibleProfile(User $actor, int $userId): HrEmployeeProfile
    {
        $query = HrEmployeeProfile::query()->where('user_id', $userId);
        app(UserSiteAccessService::class)->applyCurrentStaffProfileScope(
            $query,
            $actor,
            ['sites.viewAll'],
        );

        return $query->firstOrFail();
    }

    /** @param array<string, mixed> $profileData */
    private function persistStaffProfile(
        HrEmployeeProfile $profile,
        User $user,
        array $profileData,
        int $actorId,
    ): void {
        $values = [
            'work_email' => $user->email,
            'updated_by' => $actorId,
        ];

        if (array_key_exists('phone', $profileData)) {
            $values['work_phone'] = $profileData['phone'];
        } elseif (array_key_exists('work_phone', $profileData)) {
            $values['work_phone'] = $profileData['work_phone'];
        } elseif (array_key_exists('mobile_phone', $profileData)) {
            $values['work_phone'] = $profileData['mobile_phone'];
        }

        if (filled($profileData['job_title'] ?? null)) {
            $values['position_title'] = $profileData['job_title'];
        }

        if (array_key_exists('department', $profileData)) {
            $values['department'] = $profileData['department'];
        }

        if (filled($profileData['employment_type'] ?? null)) {
            $values['employment_type'] = $profileData['employment_type'];
        }

        $startDate = $profileData['start_date'] ?? ($profileData['hire_date'] ?? null);
        if (filled($startDate)) {
            $values['start_date'] = Carbon::createFromFormat('Y-m-d', $startDate)->toDateString();
        }

        if (array_key_exists('is_active', $profileData)) {
            $values['is_active'] = (bool) $profileData['is_active'];
        } elseif (array_key_exists('status', $profileData)) {
            $values['is_active'] = $profileData['status'] === 'active';
        }

        $profile->fill($values)->save();
    }

    private function availableColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn ($column) => SchemaCache::hasColumn($table, $column)
        ));
    }
}
