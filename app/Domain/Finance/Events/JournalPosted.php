<?php

namespace App\Domain\Finance\Events;

use App\Domain\Finance\Models\FinJournal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public FinJournal $journal,
    ) {}
}
