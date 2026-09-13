<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Auth\Access\AuthorizationException;

final class ItTicketVersionService
{
    /** Refresh capabilities inside the transaction; a form's actor can be stale. */
    public function currentActor(User $actor, bool $lockAuthorizationEvidence = false): User
    {
        $current = $lockAuthorizationEvidence
            ? app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['*'])
            : User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        if ($current->approved_at === null) {
            throw new AuthorizationException('Your staff access is no longer available.');
        }

        return $current;
    }

    /** Call only after locking and authorizing the canonical ticket. */
    public function assertCurrent(ItTicket $ticket, ?int $expectedVersion): void
    {
        // Existing non-browser adapters retain their contracts; all web
        // mutation requests require an explicit version before reaching here.
        if ($expectedVersion !== null && (int) $ticket->lock_version !== $expectedVersion) {
            throw new ItTicketVersionConflict($ticket);
        }
    }
}
