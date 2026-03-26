<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Identity extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'avatar_url',
        'raw_profile',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'scopes' => 'array',
        'raw_profile' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if the access token needs to be refreshed.
     */
    public function needsRefresh(): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }

        // Refresh if token expires within the next 5 minutes.
        return $this->token_expires_at->subMinutes(5)->isPast();
    }
}
