<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** S&D Phase H: Evacuate the whole Site in UniFi Access (pop-up 86): every door unlocks until someone ends it. */
final class UnifiAccessEvacuateCollectorDescriptor extends UnifiAccessControlCollectorDescriptor
{
    public const string CAPABILITY = 'access.site.evacuate';

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    protected function operation(): string
    {
        return 'evacuate';
    }

    protected function onDoor(): bool
    {
        return false;
    }

    protected function expected(): array
    {
        return ['emergency' => 'evacuation'];
    }
}
