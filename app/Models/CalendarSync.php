<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarSync extends Model
{
    protected $table = 'calendar_syncs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'provider',
        'calendar_id',
        'sync_token',
        'is_active',
        'last_synced_at',
        'sync_direction',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
