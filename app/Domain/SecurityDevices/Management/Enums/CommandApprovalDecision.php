<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum CommandApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
