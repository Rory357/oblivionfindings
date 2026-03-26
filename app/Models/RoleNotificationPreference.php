<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleNotificationPreference extends Model
{
    protected $fillable = [
        'role_id',
        'key',
        'enabled',
        'channel_inapp',
        'channel_email',
        'channel_push',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'channel_inapp' => 'boolean',
        'channel_email' => 'boolean',
        'channel_push' => 'boolean',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
