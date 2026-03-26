<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
