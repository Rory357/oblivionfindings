<?php

namespace App\Jobs;

use App\Models\SiteHazard;
use App\Models\User;
use App\Notifications\HazardOverdueNotification;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HazardOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(UserSiteAccessService $siteAccess): void
    {
        $today = now()->toDateString();

        // Find hazards approaching due date (2 days warning)
        $warningDate = now()->addDays(2)->toDateString();

        $warningHazards = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereDate('due_date', '<=', $warningDate)
            ->whereDate('due_date', '>=', $today)
            ->whereNull('warning_sent_at')
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->get();

        // D1 (readiness audit, 6 Nov 2026): a hazard is marked as told only when someone was actually told, so one with
        // no reachable assignee (or H&S officer) is tried again on the next run instead of going quiet for good.
        foreach ($warningHazards as $hazard) {
            if ($this->canReceiveForSite($hazard->assignedTo, (int) $hazard->site_id, $siteAccess)) {
                $hazard->assignedTo->notify(new HazardOverdueNotification($hazard, 'warning'));
                $hazard->update(['warning_sent_at' => now()]);
            }
        }

        // Find overdue hazards
        $overdueHazards = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereDate('due_date', '<', $today)
            ->whereNull('overdue_notified_at')
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->get();

        $hsOfficers = $overdueHazards->isEmpty()
            ? collect()
            : User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', 'health_safety_officer'))
                ->get();

        foreach ($overdueHazards as $hazard) {
            $told = 0;
            if ($this->canReceiveForSite($hazard->assignedTo, (int) $hazard->site_id, $siteAccess)) {
                $hazard->assignedTo->notify(new HazardOverdueNotification($hazard, 'overdue'));
                $told++;
            }

            // Also notify H&S officers
            foreach ($hsOfficers as $officer) {
                if ($this->canReceiveForSite($officer, (int) $hazard->site_id, $siteAccess)) {
                    $officer->notify(new HazardOverdueNotification($hazard, 'overdue_escalation'));
                    $told++;
                }
            }

            if ($told > 0) {
                $hazard->update(['overdue_notified_at' => now()]);
            } else {
                Log::warning('Overdue hazard reached nobody; it will be tried again on the next run.', [
                    'hazard_id' => $hazard->id, 'site_id' => $hazard->site_id,
                ]);
            }
        }
    }

    private function canReceiveForSite(?User $recipient, int $siteId, UserSiteAccessService $siteAccess): bool
    {
        return $recipient !== null
            && in_array($siteId, $siteAccess->accessibleHealthSafetySiteIds($recipient), true);
    }
}
