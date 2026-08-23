<?php

namespace App\Domain\Finance\Events;

use App\Domain\Finance\Models\FinJournal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Rollback-safe in-process journal signal.
 *
 * Laravel releases this event only after the outermost database transaction
 * commits. This is deliberately not a durable outbox: delivery across a
 * process crash after commit needs a separately approved transactional outbox.
 */
class JournalPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public FinJournal $journal,
    ) {}
}
