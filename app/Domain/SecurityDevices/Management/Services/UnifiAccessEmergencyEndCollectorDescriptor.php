<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** S&D Phase H: End lockdown or evacuation: doors go back to their schedules. */
final class UnifiAccessEmergencyEndCollectorDescriptor extends UnifiAccessControlCollectorDescriptor
{
    public const string CAPABILITY = 'access.site.emergency_end';

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    protected function operation(): string
    {
        return 'emergency_end';
    }

    protected function onDoor(): bool
    {
        return false;
    }

    protected function expected(): array
    {
        return ['emergency' => 'normal'];
    }
}
