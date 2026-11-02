<?php

namespace App\Domain\SecurityDevices\Management\Services;

/** S&D Phase H: End a keep unlocked early: the door locks again. */
final class UnifiAccessKeepUnlockedEndCollectorDescriptor extends UnifiAccessControlCollectorDescriptor
{
    public const string CAPABILITY = 'access.door.keep_unlocked_end';

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    protected function operation(): string
    {
        return 'keep_unlocked_end';
    }

    protected function onDoor(): bool
    {
        return true;
    }

    protected function expected(): array
    {
        return ['keep_unlocked' => false];
    }
}
