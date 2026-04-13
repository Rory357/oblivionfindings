<?php

namespace App\Domain\SecurityDevices\Enums;

enum AssignmentType: string
{
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case Loan = 'loan';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Temporary => 'Temporary',
            self::Loan => 'Loan',
            self::Shared => 'Shared',
        };
    }
}
