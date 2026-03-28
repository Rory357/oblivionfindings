<?php

namespace App\Listeners\Finance;

use App\Domain\Finance\Events\JournalPosted;
use Illuminate\Support\Facades\Log;

class LogJournalPosted
{
    public function handle(JournalPosted $event): void
    {
        Log::channel('daily')->info('Journal posted', [
            'journal_id' => $event->journal->id ?? null,
            'type' => $event->journal->type ?? null,
            'posted_by' => $event->journal->posted_by ?? null,
        ]);
    }
}
