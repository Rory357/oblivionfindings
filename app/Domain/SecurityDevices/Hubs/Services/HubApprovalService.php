<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Approving a new hub (pop-up 79). Until then it gets no saved logins and no work. Once
 * approved, it gets the logins for the Sites it covers only, encrypted on the hub.
 */
final readonly class HubApprovalService
{
    public function __construct(
        private HubVaultKeyService $vault,
        private SecurityDevicesAccessService $access,
        private HubReplacementService $replacements,
    ) {}

    public function approve(MonitoringCollector $collector, User $actor): MonitoringCollector
    {
        $this->assertMayManage($actor, $collector);

        return DB::transaction(function () use ($collector, $actor): MonitoringCollector {
            $locked = MonitoringCollector::query()->whereKey($collector->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null || $locked->status === 'revoked') {
                throw new DomainException('This hub has been removed, so it can’t be approved.');
            }
            if ($locked->enrolled_at === null) {
                throw new DomainException('This hub hasn’t connected yet.');
            }
            if ($locked->approved_at !== null) {
                return $locked;
            }

            $locked->forceFill(['approved_at' => now(), 'approved_by_user_id' => $actor->id])->save();
            $this->vault->ensureKey($locked);
            $replaced = $this->replacements->replacedBy($locked);
            if ($replaced !== null && $replaced->revoked_at === null) {
                $this->assertMayManage($actor, $replaced);
                $this->replacements->handOver($locked, $replaced, $actor);
            }
            AuditLogger::logOrFail('security_devices.hub.approved', $locked, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $locked->site_id,
                'covered_site_ids' => $locked->coveredSiteIds(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** Hub approval, replacement and removal need hubs.approve and every Site the hub covers. */
    public function assertMayManage(User $actor, MonitoringCollector $collector): void
    {
        if (! $actor->canDo('securityDevices.hubs.approve')) {
            abort(403);
        }
        foreach ($collector->authorisedSiteIds() as $siteId) {
            $this->access->assertCanViewSite($actor, (int) $siteId);
        }
    }
}
