<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum CommandConfirmationMode: string
{
    case None = 'none';
    case AcknowledgeImpact = 'acknowledge_impact';
    case TypeDeviceName = 'type_device_name';
}
