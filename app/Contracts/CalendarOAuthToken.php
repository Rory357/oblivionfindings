<?php

namespace App\Contracts;

/**
 * A source of OAuth credentials that can drive GoogleCalendarService /
 * MicrosoftGraphService. Implemented by both the per-user {@see \App\Models\Identity}
 * and the org-level {@see \App\Models\CalendarSyncConnection}, so the same calendar
 * API services work for personal "add to my calendar" and admin resource-calendar sync.
 */
interface CalendarOAuthToken
{
    public function getAccessToken(): ?string;

    public function getRefreshToken(): ?string;

    /**
     * True when the access token is missing or about to expire and should be
     * refreshed before the next API call.
     */
    public function needsRefresh(): bool;

    /**
     * Persist a freshly refreshed access token (and, if rotated, refresh token).
     */
    public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void;
}
