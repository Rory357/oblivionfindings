<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * "Replace this hub with a new computer" (pop-up 73). The new hub takes over the old one's
 * checks, scans, cameras and covered Sites, and the old one is removed so its saved logins
 * are wiped. It happens when an admin approves the new hub, so a leaked replacement file
 * can't take over anything on its own.
 */
final readonly class HubReplacementService
{
    public function __construct(private CollectorEnrollmentService $enrollments) {}

    /** The hub this new hub was set up to replace, if any (still active). */
    public function replacedBy(MonitoringCollector $new): ?MonitoringCollector
    {
        $code = SetupCode::query()
            ->whereNotNull('replaces_collector_id')
            ->whereHas('enrollment', fn ($query) => $query->where('consumed_collector_id', $new->id))
            ->latest('id')
            ->first();
        if ($code === null || (int) $code->replaces_collector_id === (int) $new->id) {
            return null;
        }

        return MonitoringCollector::query()->whereKey($code->replaces_collector_id)->first();
    }

    public function handOver(MonitoringCollector $new, MonitoringCollector $old, User $actor): void
    {
        DB::transaction(function () use ($new, $old, $actor): void {
            // Checks, scans and cameras move; history (observations, sessions, past commands) stays put.
            $moved = [
                'monitors' => DB::table('monitors')->where('collector_id', $old->id)->update(['collector_id' => $new->id]),
                'discovery_scopes' => DB::table('monitoring_discovery_scopes')->where('collector_id', $old->id)->update(['collector_id' => $new->id]),
                'cctv_sources' => DB::table('cctv_sources')->where('collector_id', $old->id)->update(['collector_id' => $new->id]),
            ];
            $config = is_array($new->config) ? $new->config : [];
            $oldCovered = $old->coveredSiteIds();
            if ($oldCovered !== []) {
                $config['covered_site_ids'] = array_values(array_unique([...($config['covered_site_ids'] ?? []), ...$oldCovered]));
                $config['covered_site_ids'] = array_values(array_filter($config['covered_site_ids'], fn ($id) => (int) $id !== (int) $new->site_id));
            }
            $new->forceFill([
                'config' => $config,
                'update_ring' => $old->update_ring,
                'update_window_start' => $old->update_window_start,
                'update_window_end' => $old->update_window_end,
            ])->save();

            $this->enrollments->revoke($old, (int) $actor->id);
            AuditLogger::logOrFail('security_devices.hub.replaced', $new, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $new->site_id,
                'replaced_collector_id' => (int) $old->id,
                'moved' => $moved,
            ]);
        }, 3);
    }
}
