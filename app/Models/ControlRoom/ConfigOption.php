<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;

class ConfigOption extends Model
{
    protected $table = 'control_room_config_options';

    protected $fillable = [
        'group',
        'value',
        'label',
        'color',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get active options for a specific group, ordered by sort_order.
     */
    public static function forGroup(string $group): array
    {
        return static::where('group', $group)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['value', 'label', 'color'])
            ->toArray();
    }

    /**
     * Scope to a specific group.
     */
    public function scopeInGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Scope to active options.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
