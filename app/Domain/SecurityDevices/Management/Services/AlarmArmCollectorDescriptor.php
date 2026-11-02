<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** R6: arm one alarm area through the hub that covers its Site. */
final class AlarmArmCollectorDescriptor extends AlarmAreaCollectorDescriptor
{
    public function capability(): string
    {
        return 'alarm.arm';
    }

    protected function operation(): string
    {
        return 'arm';
    }
}
