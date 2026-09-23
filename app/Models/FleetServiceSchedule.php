<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetServiceSchedule extends Model
{
    use WritesLegacyStorageContext;

    protected $fillable = [
        'asset_id',
        'name',
        'interval_km',
        'interval_days',
        'last_completed_at',
        'last_completed_km',
        'next_due_at',
        'next_due_km',
        'is_active',
        // PKG-02B: calendar-month intervals, an accountable owner and
        // reminder lead times. lock_version is set only by the service.
        'interval_months',
        'owner_user_id',
        'reminder_days_before',
        'reminder_km_before',
    ];

    protected $casts = [
        'last_completed_at' => 'datetime',
        'last_completed_km' => 'decimal:1',
        'next_due_at' => 'datetime',
        'next_due_km' => 'decimal:1',
        'is_active' => 'boolean',
        'interval_months' => 'integer',
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(FleetServiceCompletion::class, 'schedule_id');
    }
}
