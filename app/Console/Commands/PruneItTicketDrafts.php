<?php

namespace App\Console\Commands;

use App\Domain\It\Services\ItTicketDraftPruner;
use Illuminate\Console\Command;

class PruneItTicketDrafts extends Command
{
    protected $signature = 'it.prune-drafts';

    protected $description = 'Scrub expired IT draft content and retry tracked private-file cleanup';

    public function handle(ItTicketDraftPruner $pruner): int
    {
        $result = $pruner->run();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['cleanup_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
