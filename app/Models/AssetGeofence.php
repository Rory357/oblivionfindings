<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetGeofence extends Model
{
    protected $fillable = [
        'asset_id',
        'name',
        'type',
        'shape',
        'breach_type',
        'time_rules',
        'is_active',
    ];

    protected $casts = [
        'shape' => 'array',
        'time_rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
