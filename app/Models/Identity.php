<?php

namespace App\Models;

use App\Contracts\CalendarOAuthToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Identity extends Model implements CalendarOAuthToken
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
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
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
     * Determine if the access token has expired.
     */
    public function isExpired(): bool
    {
        if (!$this->token_expires_at) return true;
        return $this->token_expires_at->isPast();
    }

    /**
     * Determine if the access token needs to be refreshed.
     */
    public function needsRefresh(): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }

        // Refresh if token expires within the next 5 minutes. Use copy() so we don't
        // mutate the cached Carbon attribute in place (which would drift on each call).
        return $this->token_expires_at->copy()->subMinutes(5)->isPast();
    }

    /* ------------------------------------------------------------------
     * CalendarOAuthToken
     * ------------------------------------------------------------------ */

    public function getAccessToken(): ?string
    {
        return $this->access_token;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refresh_token;
    }

    public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void
    {
        $this->update([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken ?: $this->refresh_token,
            'token_expires_at' => now()->addSeconds($expiresInSeconds ?? 3600),
        ]);
    }
}
