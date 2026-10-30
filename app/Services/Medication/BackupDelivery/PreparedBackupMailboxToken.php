<?php

namespace App\Services\Medication\BackupDelivery;

use App\Contracts\CalendarOAuthToken;
use App\Mail\MailNotSubmitted;
use App\Models\ItMailboxConnection;

/** Canonical access token already durably prepared; submission may never rotate it. */
final class PreparedBackupMailboxToken implements CalendarOAuthToken
{
    public function __construct(private readonly ItMailboxConnection $connection) {}

    public function getAccessToken(): ?string
    {
        $this->needsRefresh();

        return $this->connection->getAccessToken();
    }

    public function getRefreshToken(): ?string
    {
        throw new MailNotSubmitted('backup_mailbox_preparation_required');
    }

    public function needsRefresh(): bool
    {
        if ($this->connection->needsRefresh()) {
            throw new MailNotSubmitted('backup_mailbox_preparation_required');
        }

        return false;
    }

    public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void
    {
        throw new MailNotSubmitted('backup_mailbox_preparation_required');
    }
}
