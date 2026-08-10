<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum CommandRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function isHighRisk(): bool
    {
        return in_array($this, [self::High, self::Critical], true);
    }
}
