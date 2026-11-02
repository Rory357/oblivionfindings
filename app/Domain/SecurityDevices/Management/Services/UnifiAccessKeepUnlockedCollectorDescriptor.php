<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** S&D Phase H: Keep a UniFi Access door unlocked for up to 4 hours (pop-up 70). */
final class UnifiAccessKeepUnlockedCollectorDescriptor extends UnifiAccessControlCollectorDescriptor
{
    public const string CAPABILITY = 'access.door.keep_unlocked';

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    protected function operation(): string
    {
        return 'keep_unlocked';
    }

    protected function onDoor(): bool
    {
        return true;
    }

    protected function expected(): array
    {
        return ['keep_unlocked' => true];
    }
}
