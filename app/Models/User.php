<?php

namespace App\Models;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Impersonate, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',

        // Keep this while you migrate off users.role
        // (You already reference auth.user.role in React)
        'role',

        // Admin approval gate
        'approved_at',
        'approved_by',

        'profile_photo_path',
        'cellphone',
        'work_phone',
        'last_seen_at',
        'presence_status',
        'timezone',
        'locale',
        'date_format',
        'time_format',

        // Appearance preferences (Phase 2)
        'theme',
        'accent_colour',
        'font_size',
        'sidebar_density',
        'reduce_motion',
        'first_day_of_week',
        'landing_route_preference',

        // Notification delivery preferences (Phase 2)
        'dnd_enabled',
        'dnd_until',
        'desktop_notifications_enabled',
        'notification_sounds_enabled',
        'email_digest_frequency',

        // Job Board "Alert me" subscription
        'job_board_alerts_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $appends = ['profile_photo_url', 'avatar'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'reduce_motion' => 'boolean',
            'font_size' => 'integer',
            'dnd_enabled' => 'boolean',
            'dnd_until' => 'datetime',
            'desktop_notifications_enabled' => 'boolean',
            'notification_sounds_enabled' => 'boolean',
            'job_board_alerts_enabled' => 'boolean',
            'tasks_default_view' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if (
                $user->isDirty('password')
                && ! $user->email_verified_at
            ) {
                $user->email_verified_at = now();
            }
        });
    }

    public function isApproved(): bool
    {
        return ! is_null($this->approved_at);
    }

    /**
     * Scope: staff users (excludes client portal roles).
     *
     * We exclude users who have the RBAC roles `client` or `next_of_kin`.
     * For backwards compatibility during the users.role -> role_user migration,
     * we also exclude legacy users.role values.
     */
    public function scopeStaff($query)
    {
        return $query
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['client', 'next_of_kin']))
            ->whereNotIn('role', ['client', 'next_of_kin']);
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path
            ? Storage::disk('public')->url($this->profile_photo_path)
            : url('/images/avatar-placeholder.svg');
    }

    public function getAvatarAttribute(): ?string
    {
        return $this->profile_photo_url;
    }

    public function getOrganizationIdAttribute($value): ?int
    {
        if (array_key_exists('organization_id', $this->attributes)) {
            return $value === null ? null : (int) $value;
        }

        // A number of domain controllers still scope records by organization_id,
        // while lighter local schemas do not add the column to users.
        return 1;
    }

    // ---------------------------
    // Existing relationship
    // ---------------------------
    public function assignedClients()
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }

    /**
     * Sites where this user is the primary contact/manager.
     */
    public function sitesAsPrimaryContact()
    {
        return $this->hasMany(Site::class, 'primary_contact_user_id');
    }

    // Client portal access (client + next_of_kin)
    public function portalClients()
    {
        return $this->belongsToMany(Client::class, 'client_portal_users')
            ->withPivot('relation')
            ->withTimestamps();
    }

    public function identities()
    {
        return $this->hasMany(Identity::class);
    }

    public function pushSubscriptions()
    {
        return $this->hasMany(UserPushSubscription::class);
    }

    public function canAccessClientPortal(Client $client): bool
    {
        $userOrganizationId = $this->organization_id;
        $clientOrganizationId = $client->organization_id;

        if (
            $userOrganizationId !== null
            && $clientOrganizationId !== null
            && (int) $userOrganizationId !== (int) $clientOrganizationId
        ) {
            return false;
        }

        return $this->portalClients()->whereKey($client->id)->exists();
    }

    public function staffProfile()
    {
        return $this->hasOne(Staff::class);
    }

    public function staffCredentials()
    {
        return $this->hasMany(StaffCredential::class);
    }

    public function staffTrainingRecords()
    {
        return $this->hasMany(StaffTrainingRecord::class);
    }

    public function staffBackgroundChecks()
    {
        return $this->hasMany(StaffBackgroundCheck::class);
    }

    public function complianceStatuses()
    {
        return $this->hasMany(HrStaffComplianceStatus::class);
    }

    public function staffAvailabilities()
    {
        return $this->hasMany(StaffAvailability::class);
    }

    public function boardMember()
    {
        return $this->hasOne(BoardMember::class)
            ->where('is_active', true);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function staffAvailability()
    {
        return $this->hasMany(StaffAvailability::class);
    }

    public function staffTimeOff()
    {
        return $this->hasMany(StaffTimeOff::class);
    }

    public function timesheets()
    {
        return $this->hasMany(Timesheet::class);
    }

    // ---------------------------
    // HR Module relationships
    // ---------------------------

    public function hrEmployeeProfile()
    {
        return $this->hasOne(HrEmployeeProfile::class);
    }

    public function hrLeaveRequests()
    {
        return $this->hasMany(HrLeaveRequest::class);
    }

    public function hrLeaveBalances()
    {
        return $this->hasMany(HrLeaveBalance::class);
    }

    public function hrCases()
    {
        return $this->hasMany(HrCase::class, 'subject_user_id');
    }

    public function hrComplianceStatuses()
    {
        return $this->hasMany(HrStaffComplianceStatus::class);
    }

    public function hrSupervisionNotes()
    {
        return $this->hasMany(HrSupervisionNote::class, 'staff_user_id');
    }

    public function hrPerformanceReviews()
    {
        return $this->hasMany(HrPerformanceReview::class, 'staff_user_id');
    }

    public function hrPolicyAttestations()
    {
        return $this->hasMany(HrPolicyAttestation::class);
    }

    public function hrDriverEligibility()
    {
        return $this->hasOne(HrDriverEligibility::class);
    }

    // ---------------------------
    // RBAC: Roles & Permissions
    // ---------------------------

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function permissionOverrides()
    {
        return $this->belongsToMany(Permission::class, 'permission_user')
            ->withPivot('allowed');
    }

    public function notificationPreferences()
    {
        return $this->hasMany(UserNotificationPreference::class);
    }

    public function breakGlassAccesses()
    {
        return $this->hasMany(ClientBreakGlassAccess::class);
    }

    public function hasRole(string ...$roles): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(
                fn (Role $role) => in_array($role->name, $roles, true),
            );
        }

        return $this->roles()
            ->whereIn('name', $roles)
            ->exists();
    }

    public function canDo(string $permissionKey): bool
    {
        $permissionKeys = $this->permissionLookupKeys($permissionKey);

        if ($this->relationLoaded('permissionOverrides')
            && $this->relationLoaded('roles')
            && $this->roles->every(fn (Role $role) => $role->relationLoaded('permissions'))) {
            $overrides = $this->permissionOverrides
                ->filter(fn (Permission $permission) => in_array($permission->key, $permissionKeys, true));

            if ($overrides->contains(fn (Permission $permission) => ! (bool) $permission->pivot->allowed)) {
                return false;
            }

            if ($overrides->contains(fn (Permission $permission) => (bool) $permission->pivot->allowed)) {
                return true;
            }

            return $this->roles->contains(
                fn (Role $role) => $role->permissions->contains(
                    fn (Permission $permission) => in_array($permission->key, $permissionKeys, true),
                ),
            );
        }

        // 1) explicit deny override wins
        $deny = $this->permissionOverrides()
            ->whereIn('permissions.key', $permissionKeys)
            ->wherePivot('allowed', false)
            ->exists();

        if ($deny) {
            return false;
        }

        // 2) explicit allow override
        $allow = $this->permissionOverrides()
            ->whereIn('permissions.key', $permissionKeys)
            ->wherePivot('allowed', true)
            ->exists();

        if ($allow) {
            return true;
        }

        // 3) role permissions
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->whereIn('key', $permissionKeys))
            ->exists();
    }

    /**
     * Legacy permission keys kept as policy-layer synonyms while routes and
     * controllers use the canonical namespace.
     *
     * @return list<string>
     */
    private function permissionLookupKeys(string $permissionKey): array
    {
        $aliases = [
            'timesheets.viewAny' => ['hr.time.viewAny'],
            'hr.time.viewAny' => ['timesheets.viewAny'],
            'timesheets.manageAny' => ['hr.time.manage'],
            'hr.time.manage' => ['timesheets.manageAny'],
            'timesheets.approve' => ['hr.time.approveTeam'],
            'hr.time.approveTeam' => ['timesheets.approve'],
            'hr.vetting.view' => ['vetting.viewAny'],
            'vetting.viewAny' => ['hr.vetting.view'],
            'hr.vetting.manage' => ['vetting.manage', 'vetting.verify', 'vetting.assessRisk'],
            'vetting.manage' => ['hr.vetting.manage'],
            'vetting.verify' => ['hr.vetting.manage'],
            'vetting.assessRisk' => ['hr.vetting.manage'],
        ];

        return array_values(array_unique([
            $permissionKey,
            ...($aliases[$permissionKey] ?? []),
        ]));
    }

    public function medicationCompetencyAssessments()
    {
        return $this->hasMany(MedicationCompetencyAssessment::class);
    }

    // ── Fleet relationships ──────────────────────────────────────────────────

    public function fleetDriverSessions()
    {
        return $this->hasMany(FleetDriverSession::class);
    }

    public function fleetBookings()
    {
        return $this->hasMany(FleetVehicleBooking::class);
    }

    public function fleetIncidentsReported()
    {
        return $this->hasMany(FleetIncident::class, 'reported_by_user_id');
    }

    public function fleetIncidentsAsDiver()
    {
        return $this->hasMany(FleetIncident::class, 'driver_user_id');
    }

    public function fleetPersonalTrips()
    {
        return $this->hasMany(FleetPersonalTrip::class);
    }

    public function fleetFuelLogs()
    {
        return $this->hasMany(FleetFuelLog::class);
    }

    // ---------------------------
    // Impersonation Guards
    // ---------------------------

    public function canImpersonate(): bool
    {
        return $this->canDo('settings.access.impersonate');
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->hasRole('admin');
    }
}
