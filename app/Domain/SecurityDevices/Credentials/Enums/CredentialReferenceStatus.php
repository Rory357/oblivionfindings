<?php

namespace App\Domain\SecurityDevices\Credentials\Enums;

enum CredentialReferenceStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
