<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use DomainException;

final class DeviceCommandBatchAccessService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CommandCapabilityRegistry $capabilities,
    ) {}

    public function assertCanView(User $viewer, DeviceCommandBatch $batch): DeviceCommandBatch
    {
        abort_unless($viewer->canDo('securityDevices.devices.view'), 403);
        $batch->loadMissing(['requestedBy:id,name', 'targets.device', 'targets.site']);
        $deviceIds = $batch->targets->pluck('device_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $visibleCount = $this->access->visibleDevices($viewer)->whereKey($deviceIds->all())->count();
        if ($visibleCount !== $deviceIds->count()) {
            abort(404);
        }

        try {
            $capability = $this->capabilities->definition($batch->capability);
        } catch (DomainException) {
            abort(404);
        }
        foreach ($batch->targets as $target) {
            $decision = $this->authorization->evaluate(
                $viewer,
                $target->device,
                $capability,
                ManagementLevel::Observe,
                true,
            );
            if (! $decision->allowed) {
                abort(404);
            }
        }

        return $batch;
    }
}
