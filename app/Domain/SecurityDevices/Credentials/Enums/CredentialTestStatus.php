<?php

namespace App\Domain\SecurityDevices\Credentials\Enums;

enum CredentialTestStatus: string
{
    case Untested = 'untested';
    case Passed = 'passed';
    case Failed = 'failed';
}
