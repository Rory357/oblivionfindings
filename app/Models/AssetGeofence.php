<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AssetGeofence extends Model
{
    protected $fillable = [
        'asset_id',
        'site_id',
        'name',
        'type',
        'scope',
        'shape',
        'breach_type',
        'alert_config',
        'time_rules',
        'is_active',
        'address',
        'permitted_uses',
    ];

    protected $casts = [
        'shape' => 'array',
        'alert_config' => 'array',
        'time_rules' => 'array',
        'is_active' => 'boolean',
        'geometry_version' => 'integer',
        'revision' => 'integer',
        'client_location_eligible' => 'boolean',
        'permitted_uses' => 'array',
        'copy_source' => 'array',
        'retired_at' => 'immutable_datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function assignedAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_geofence_assignments')
            ->withTimestamps();
    }

    public function scopeEligibleForClientSite(Builder $query, ?int $siteId): Builder
    {
        if ($siteId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('site_id', $siteId)
            ->where('is_active', true)->whereNull('retired_at')
            ->whereIn('scope', ['house', 'resident']);
    }
}
