<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** S&D Phase H: Lock down the whole Site in UniFi Access (pop-up 86). Exit buttons and handles still open every door. */
final class UnifiAccessLockdownCollectorDescriptor extends UnifiAccessControlCollectorDescriptor
{
    public const string CAPABILITY = 'access.site.lockdown';

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    protected function operation(): string
    {
        return 'lockdown';
    }

    protected function onDoor(): bool
    {
        return false;
    }

    protected function expected(): array
    {
        return ['emergency' => 'lockdown'];
    }
}
