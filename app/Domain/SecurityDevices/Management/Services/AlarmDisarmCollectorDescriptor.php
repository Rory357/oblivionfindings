<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** R6: disarm one alarm area through the hub that covers its Site (a person and a confirm, never an automation). */
final class AlarmDisarmCollectorDescriptor extends AlarmAreaCollectorDescriptor
{
    public function capability(): string
    {
        return 'alarm.disarm';
    }

    protected function operation(): string
    {
        return 'disarm';
    }
}
