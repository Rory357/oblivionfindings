<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\User;
use LogicException;

final class RejectingCommandDispatchPort implements CommandDispatchPort
{
    public function dispatch(DeviceCommandRequest $request, User $triggeredBy): DeviceCommandAttempt
    {
        throw new LogicException('Device commands are outside the native monitoring runtime plan.');
    }
}
