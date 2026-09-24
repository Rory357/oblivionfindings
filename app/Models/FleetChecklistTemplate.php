<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetChecklistTemplate extends Model
{
    use WritesLegacyStorageContext;

    /** The checklist the Daily checks page records against (VehicleDailyCheckService). */
    public const TYPE_DAILY_CHECK = 'daily_check';

    protected $fillable = [
        'name',
        'type',
        'items',
        'is_active',
    ];

    protected $casts = [
        'items' => 'array',
        'is_active' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(FleetChecklistRun::class, 'template_id');
    }

    /**
     * Checklists recorded as Maintenance checks. The daily checklist belongs
     * to the Daily checks page, where its checks never gate readiness.
     */
    public function scopeMaintenanceChecklists(Builder $query): Builder
    {
        return $query->where('type', '!=', self::TYPE_DAILY_CHECK);
    }
}
