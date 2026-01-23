<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',

        // Keep this while you migrate off users.role
        // (You already reference auth.user.role in React)
        'role',

        // Admin approval gate
        'approved_at',
        'approved_by',
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
        ];
    }

    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
    }

    // ---------------------------
    // Existing relationship
    // ---------------------------
    public function assignedClients()
    {
        return $this->belongsToMany(\App\Models\Client::class)->withTimestamps();
    }

    // Client portal access (client + next_of_kin)
    public function portalClients()
    {
        return $this->belongsToMany(\App\Models\Client::class, 'client_portal_users')
            ->withPivot('relation')
            ->withTimestamps();
    }

    public function canAccessClientPortal(\App\Models\Client $client): bool
    {
        return $this->portalClients()->whereKey($client->id)->exists();
    }

    public function staffProfile()
    {
        return $this->hasOne(\App\Models\StaffProfile::class);
    }

    public function staffCredentials()
    {
        return $this->hasMany(\App\Models\StaffCredential::class);
    }

    public function staffAvailabilities()
    {
        return $this->hasMany(\App\Models\StaffAvailability::class);
    }

    public function shifts()
    {
        return $this->hasMany(\App\Models\Shift::class);
    }

    public function timesheets()
    {
        return $this->hasMany(\App\Models\Timesheet::class);
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

        // 2.5) Backwards-compatibility: if the user hasn't been migrated into role_user yet,
        // fall back to the legacy users.role column.
        // This prevents "I'm an admin but I can't ..." issues when the pivot table is empty.
        if (!$this->roles()->exists() && !empty($this->role)) {
            // Legacy admin was effectively "allow all"
            if ($this->role === 'admin') {
                return true;
            }

            $legacyRole = \App\Models\Role::query()->where('name', $this->role)->first();
            if ($legacyRole) {
                return $legacyRole->permissions()->where('key', $permissionKey)->exists();
            }
        }

        // 3) role permissions
        return $this->roles()
            ->whereHas('permissions', fn($q) => $q->where('key', $permissionKey))
            ->exists();
    }
}
