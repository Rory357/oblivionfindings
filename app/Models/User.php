<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Illuminate\Support\Facades\Storage;
use Lab404\Impersonate\Models\Impersonate;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, Impersonate;

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
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if (
                $user->isDirty('password')
                && !$user->email_verified_at
            ) {
                $user->email_verified_at = now();
            }
        });
    }

    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
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
        return $this->belongsToMany(\App\Models\Client::class)->withTimestamps();
    }

    /**
     * Sites where this user is the primary contact/manager.
     */
    public function sitesAsPrimaryContact()
    {
        return $this->hasMany(\App\Models\Site::class, 'primary_contact_user_id');
    }

    // Client portal access (client + next_of_kin)
    public function portalClients()
    {
        return $this->belongsToMany(\App\Models\Client::class, 'client_portal_users')
            ->withPivot('relation')
            ->withTimestamps();
    }

    public function identities()
    {
        return $this->hasMany(\App\Models\Identity::class);
    }

    public function canAccessClientPortal(\App\Models\Client $client): bool
    {
        return $this->portalClients()->whereKey($client->id)->exists();
    }

    public function staffProfile()
    {
        return $this->hasOne(\App\Models\Staff::class);
    }

    public function staffCredentials()
    {
        return $this->hasMany(\App\Models\StaffCredential::class);
    }

    public function staffTrainingRecords()
    {
        return $this->hasMany(\App\Models\StaffTrainingRecord::class);
    }

    public function staffBackgroundChecks()
    {
        return $this->hasMany(\App\Models\StaffBackgroundCheck::class);
    }

    public function complianceStatuses()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrStaffComplianceStatus::class);
    }

    public function staffAvailabilities()
    {
        return $this->hasMany(\App\Models\StaffAvailability::class);
    }

    public function boardMember()
    {
        return $this->hasOne(\App\Domain\Governance\Models\BoardMember::class)
            ->where('is_active', true);
    }

    public function shifts()
    {
        return $this->hasMany(\App\Models\Shift::class);
    }

    public function staffAvailability()
    {
        return $this->hasMany(\App\Models\StaffAvailability::class);
    }

    public function staffTimeOff()
    {
        return $this->hasMany(\App\Models\StaffTimeOff::class);
    }

    public function timesheets()
    {
        return $this->hasMany(\App\Models\Timesheet::class);
    }

    // ---------------------------
    // HR Module relationships
    // ---------------------------

    public function hrEmployeeProfile()
    {
        return $this->hasOne(\App\Domain\Hr\Models\HrEmployeeProfile::class);
    }

    public function hrLeaveRequests()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrLeaveRequest::class);
    }

    public function hrLeaveBalances()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrLeaveBalance::class);
    }

    public function hrCases()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrCase::class, 'subject_user_id');
    }

    public function hrComplianceStatuses()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrStaffComplianceStatus::class);
    }

    public function hrSupervisionNotes()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrSupervisionNote::class, 'staff_user_id');
    }

    public function hrPerformanceReviews()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrPerformanceReview::class, 'staff_user_id');
    }

    public function hrPolicyAttestations()
    {
        return $this->hasMany(\App\Domain\Hr\Models\HrPolicyAttestation::class);
    }

    public function hrDriverEligibility()
    {
        return $this->hasOne(\App\Domain\Hr\Models\HrDriverEligibility::class);
    }

    // ---------------------------
    // RBAC: Roles & Permissions
    // ---------------------------

    public function roles()
    {
        return $this->belongsToMany(\App\Models\Role::class, 'role_user');
    }

    public function permissionOverrides()
    {
        return $this->belongsToMany(\App\Models\Permission::class, 'permission_user')
            ->withPivot('allowed');
    }

    public function notificationPreferences()
    {
        return $this->hasMany(\App\Models\UserNotificationPreference::class);
    }

    public function breakGlassAccesses()
    {
        return $this->hasMany(\App\Models\ClientBreakGlassAccess::class);
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->roles()
            ->whereIn('name', $roles)
            ->exists();
    }

    public function canDo(string $permissionKey): bool
    {
        // 1) explicit deny override wins
        $deny = $this->permissionOverrides()
            ->where('permissions.key', $permissionKey)
            ->wherePivot('allowed', false)
            ->exists();

        if ($deny) {
            return false;
        }

        // 2) explicit allow override
        $allow = $this->permissionOverrides()
            ->where('permissions.key', $permissionKey)
            ->wherePivot('allowed', true)
            ->exists();

        if ($allow) {
            return true;
        }

        // 3) role permissions
        return $this->roles()
            ->whereHas('permissions', fn($q) => $q->where('key', $permissionKey))
            ->exists();
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
        return !$this->hasRole('admin');
    }
}
