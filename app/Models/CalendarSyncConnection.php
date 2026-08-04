<?php

namespace App\Models;

use App\Contracts\CalendarOAuthToken;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-level OAuth connection to Google Workspace / Microsoft 365 used by the admin
 * calendar-sync feature to read/write house *resource* calendars.
 *
 * Implements {@see CalendarOAuthToken} so it can drive GoogleCalendarService /
 * MicrosoftGraphService interchangeably with the per-user {@see Identity}.
 */
class CalendarSyncConnection extends Model implements CalendarOAuthToken
{
    use WritesLegacyStorageContext;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_MICROSOFT = 'microsoft';

    protected $fillable = [
        'tenant_id',
        'provider',
        'status',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'account_email',
        'account_name',
        'last_synced_at',
        'last_tested_at',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        // Encrypted at rest; transparently decrypted when read.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->access_token !== null;
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

    public function needsRefresh(): bool
    {
        if (! $this->token_expires_at) {
            return true;
        }

        // Refresh if the token expires within the next 5 minutes.
        return $this->token_expires_at->copy()->subMinutes(5)->isPast();
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
