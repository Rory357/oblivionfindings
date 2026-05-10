<?php

namespace App\Observers;

use App\Models\SiteChecklistRun;
use App\Services\AuditLogger;

class SiteChecklistRunObserver
{
    public function updated(SiteChecklistRun $run): void
    {
        // Log completion
        if ($run->wasChanged('status') && $run->status === 'completed') {
            AuditLogger::log('checklist.completed', $run, [
                'site_id' => $run->site_id,
                'template_id' => $run->template_id,
                'completed_by' => $run->completed_by_user_id,
                'items_passed' => $run->items_passed,
                'items_failed' => $run->items_failed,
            ]);

            // Update completion stats
            $run->calculateCompletion();
        }
    }
}
