<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverageReservation extends Model
{
    protected $fillable = [
        'organization_id',
        'site_id',
        'coverage_requirement_id',
        'shift_id',
        'shift_open_position_id',
        'reserved_by_user_id',
        'reservation_token',
        'status',
        'reason',
        'role_key',
        'window_starts_at',
        'window_ends_at',
        'expires_at',
        'meta',
    ];

    protected $casts = [
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SiteCoverageRequirement::class, 'coverage_requirement_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function openPosition(): BelongsTo
    {
        return $this->belongsTo(ShiftOpenPosition::class, 'shift_open_position_id');
    }

    public function reserver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }
}
