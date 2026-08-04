<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum ManagementLevel: string
{
    case Observe = 'observe';
    case Operate = 'operate';
    case Manage = 'manage';
    case Control = 'control';
    case Admin = 'admin';

    public function permissionKey(): string
    {
        return 'securityDevices.commands.'.$this->value;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Observe => 10,
            self::Operate => 20,
            self::Manage => 30,
            self::Control => 40,
            self::Admin => 50,
        };
    }
}
