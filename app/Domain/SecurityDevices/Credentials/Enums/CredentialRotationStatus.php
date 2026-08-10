<?php

namespace App\Domain\SecurityDevices\Credentials\Enums;

enum CredentialRotationStatus: string
{
    case Current = 'current';
    case Due = 'due';
    case Overdue = 'overdue';
    case Failed = 'failed';
}
