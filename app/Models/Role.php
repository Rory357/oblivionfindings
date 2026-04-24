<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'level', 'type', 'description', 'landing_route'];

    protected $casts = [
        'level' => 'integer',
    ];

    public function notificationPreferences()
    {
        return $this->hasMany(RoleNotificationPreference::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    /**
     * Scope: System roles only
     */
    public function scopeSystem($query)
    {
        return $query->where('type', 'system');
    }

    /**
     * Scope: Custom roles only
     */
    public function scopeCustom($query)
    {
        return $query->where('type', 'custom');
    }

    /**
     * Scope: Order by level (highest first)
     */
    public function scopeByLevel($query)
    {
        return $query->orderByDesc('level');
    }

    /**
     * Get formatted level display (e.g., "L100")
     */
    public function getLevelDisplayAttribute(): string
    {
        return 'L' . $this->level;
    }

    /**
     * Check if this is a system role
     */
    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    /**
     * Check if this is a custom role
     */
    public function isCustom(): bool
    {
        return $this->type === 'custom';
    }
}
