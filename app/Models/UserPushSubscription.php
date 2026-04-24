<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'token',
        'device_id',
        'platform',
        'enabled',
        'last_used_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
