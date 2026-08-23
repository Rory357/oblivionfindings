<?php

namespace App\Jobs;

use App\Models\IncidentReportDraft;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneIncidentReportDrafts implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        IncidentReportDraft::query()
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
