<?php

namespace App\Domain\It\Services;

use App\Contracts\CalendarOAuthToken;
use App\Models\ItMailboxConnection;

/** A poll's credential view expires when its canonical connection ownership changes. */
final class ItMailboxPollingToken implements CalendarOAuthToken
{
    public function __construct(
        private readonly ItMailboxConnection $claim,
        private readonly ItMailboxPollState $state,
    ) {}

    public function getAccessToken(): ?string
    {
        return $this->state->current($this->claim)->getAccessToken();
    }

    public function getRefreshToken(): ?string
    {
        return $this->state->current($this->claim)->getRefreshToken();
    }

    public function needsRefresh(): bool
    {
        return $this->state->current($this->claim)->needsRefresh();
    }

    public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void
    {
        $this->state->withinClaim($this->claim,
            fn (ItMailboxConnection $current) => $current->storeRefreshedToken($accessToken, $refreshToken, $expiresInSeconds));
    }
}
