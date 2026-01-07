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
        ];
    }

    // ---------------------------
    // Existing relationship
    // ---------------------------
    public function assignedClients()
    {
        return $this->belongsToMany(\App\Models\Client::class)->withTimestamps();
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

        // 3) role permissions
        return $this->roles()
            ->whereHas('permissions', fn($q) => $q->where('key', $permissionKey))
            ->exists();
    }
}
