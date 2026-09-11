<?php

namespace App\Models;

use App\Contracts\CalendarOAuthToken;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-level OAuth connection to the IT support mailbox (email-to-ticket, E3).
 * Mirrors {@see CalendarSyncConnection}: implements {@see CalendarOAuthToken}
 * so it drives MicrosoftGraphService / GoogleCalendarService interchangeably —
 * the mailbox poller pulls unread mail with the same token machinery the
 * calendar sync uses. account_email consented; mailboxEmail() is what we read.
 */
class ItMailboxConnection extends Model implements CalendarOAuthToken
{
    use WritesLegacyStorageContext;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_MICROSOFT = 'microsoft';

    protected $attributes = ['configuration_version' => 1, 'consecutive_poll_failures' => 0];

    protected $fillable = [
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
        'configuration_version' => 'integer',
        'last_poll_attempt_at' => 'datetime',
        'consecutive_poll_failures' => 'integer',
        'next_poll_at' => 'datetime',
        'poll_claim_expires_at' => 'datetime',
        'inbox_scan_before' => 'datetime',
        'inbox_scan_cursor' => 'encrypted',
        'inbox_scan_cursor_hashes' => 'array',
        'inbox_scan_complete' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'poll_claim_token',
        'inbox_scan_cursor',
        'inbox_scan_cursor_hashes',
        'inbox_scan_scope',
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

    /** Canonical provider/account/mailbox boundary; remote IDs remain case-sensitive within it. */
    public function mailboxScopeHash(): string
    {
        return hash('sha256', json_encode([
            $this->provider, mb_strtolower(trim((string) $this->account_email)),
            mb_strtolower(trim((string) $this->mailboxEmail())),
        ], JSON_THROW_ON_ERROR));
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
