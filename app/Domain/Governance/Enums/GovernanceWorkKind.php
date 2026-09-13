<?php

namespace App\Domain\Governance\Enums;

enum GovernanceWorkKind: string
{
    case Vote = 'vote';
    case Read = 'read';
    case Act = 'act';
    case Know = 'know';

    public function label(): string
    {
        return match ($this) {
            self::Vote => 'Vote',
            self::Read => 'Read',
            self::Act => 'Act',
            self::Know => 'Know',
        };
    }
}
