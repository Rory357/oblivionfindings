<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'shape' => 'array',
        'alert_config' => 'array',
        'time_rules' => 'array',
        'is_active' => 'boolean',
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
}
