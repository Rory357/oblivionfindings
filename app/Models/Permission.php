<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['key', 'description', 'group', 'module'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }

    /**
     * Scope: Filter by group
     */
    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Scope: Filter by module
     */
    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Get all unique groups
     */
    public static function groups(): array
    {
        return static::query()
            ->distinct()
            ->pluck('group')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get all unique modules
     */
    public static function modules(): array
    {
        return static::query()
            ->distinct()
            ->pluck('module')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get grouped permissions for matrix display
     */
    public static function grouped(): array
    {
        return static::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(fn($group) => $group->values())
            ->toArray();
    }
}
