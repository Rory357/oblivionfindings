<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvvRecord extends Model
{
    protected $table = 'evv_records';

    protected $fillable = [
        'organization_id',
        'shift_id',
        'user_id',
        'client_id',
        'check_in_time',
        'check_out_time',
        'check_in_latitude',
        'check_in_longitude',
        'check_out_latitude',
        'check_out_longitude',
        'check_in_method',
        'check_out_method',
        'verification_status',
        'geofence_check_in',
        'geofence_check_out',
        'distance_from_site_in',
        'distance_from_site_out',
        'flagged_reason',
        'notes',
    ];

    protected $casts = [
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'geofence_check_in' => 'boolean',
        'geofence_check_out' => 'boolean',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('verification_status', 'flagged');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('verification_status', 'pending');
    }
}
