<?php

namespace App\Observers;

use App\Models\Site;
use App\Services\AuditLogger;

class SiteObserver
{
    public function updated(Site $site): void
    {
        // Log risk flag changes
        if ($site->isDirty(['is_high_risk', 'is_high_needs'])) {
            AuditLogger::log('site.risk_flags_changed', $site, [
                'is_high_risk' => [
                    'from' => $site->getOriginal('is_high_risk'),
                    'to' => $site->is_high_risk,
                ],
                'is_high_needs' => [
                    'from' => $site->getOriginal('is_high_needs'),
                    'to' => $site->is_high_needs,
                ],
            ]);
        }

        // Log status changes (archive/restore)
        if ($site->isDirty('is_active')) {
            $action = $site->is_active ? 'site.activated' : 'site.deactivated';
            AuditLogger::log($action, $site);
        }
    }

    public function deleted(Site $site): void
    {
        // Soft delete - log as archive
        if (!$site->isForceDeleting()) {
            AuditLogger::log('site.archived', $site);
        }
    }

    public function restored(Site $site): void
    {
        AuditLogger::log('site.restored', $site);
    }
}
