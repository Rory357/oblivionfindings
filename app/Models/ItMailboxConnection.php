<?php

namespace App\Models;

use App\Contracts\CalendarOAuthToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Org-level OAuth connection to the IT support mailbox (email-to-ticket, E3).
 * Mirrors {@see CalendarSyncConnection}: implements {@see CalendarOAuthToken}
 * so it drives MicrosoftGraphService / GoogleCalendarService interchangeably —
 * the mailbox poller pulls unread mail with the same token machinery the
 * calendar sync uses. account_email consented; mailboxEmail() is what we read.
 */
class ItMailboxConnection extends Model implements CalendarOAuthToken
{
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
        'mailbox_email',
        'last_polled_at',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        // Encrypted at rest; transparently decrypted when read.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'last_polled_at' => 'datetime',
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

    /** The mailbox the poller reads — the dedicated support mailbox, else the account's own. */
    public function mailboxEmail(): ?string
    {
        return $this->mailbox_email ?: $this->account_email;
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
