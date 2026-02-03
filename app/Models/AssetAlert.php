<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAlert extends Model
{
    protected $fillable = [
        'asset_id',
        'asset_tracker_id',
        'asset_alert_policy_id',
        'alert_type',
        'severity',
        'status',
        'triggered_at',
        'resolved_at',
        'context',
        'acknowledged_by_user_id',
        'acknowledged_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'context' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(AssetTracker::class, 'asset_tracker_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AssetAlertPolicy::class, 'asset_alert_policy_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
