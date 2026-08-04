<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;

final class CommandReconciliationDelay
{
    public function seconds(DeviceCommandRequest $request): int
    {
        if ($request->capability !== 'access.door.unlock_timed') {
            return 0;
        }

        $duration = $request->encrypted_parameters['duration_seconds'] ?? null;
        if (! is_int($duration) || $duration < 5 || $duration > 60) {
            return 0;
        }
        $grace = max(1, min(30, (int) config('security_devices.reconciliation_grace_seconds', 5)));

        return $duration + $grace;
    }
}
