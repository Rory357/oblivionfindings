<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use UnexpectedValueException;

final class CollectorCommandContract
{
    public function hash(
        DeviceCommandRequest $request,
        DeviceCommandAttempt $attempt,
        MonitoringCollector $collector,
    ): string {
        if (! is_string($request->signature) || $request->signature === '') {
            throw new UnexpectedValueException('The signed command contract is unavailable.');
        }

        return hash('sha256', implode('|', [
            'collector-command-v1',
            $request->command_uuid,
            $attempt->attempt_uuid,
            (string) $attempt->attempt_number,
            (string) $request->device_id,
            (string) $request->site_id,
            (string) $collector->collector_uuid,
            hash('sha256', $request->signature),
        ]));
    }
}
