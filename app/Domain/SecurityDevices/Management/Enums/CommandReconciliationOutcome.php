<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum CommandReconciliationOutcome: string
{
    case Matched = 'matched';
    case Mismatch = 'mismatch';
    case Uncertain = 'uncertain';
}
