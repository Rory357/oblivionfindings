<?php

namespace App\Models\ControlRoom;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    protected $table = 'control_room_maintenance_windows';

    protected $fillable = [
        'name',
        'description',
        'signal_source_id',
        'site_id',
        'asset_type',
        'asset_id',
        'starts_at',
        'ends_at',
        'status',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class, 'signal_source_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
