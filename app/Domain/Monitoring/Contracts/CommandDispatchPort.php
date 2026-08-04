<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\User;

interface CommandDispatchPort
{
    public function dispatch(DeviceCommandRequest $request, User $triggeredBy): DeviceCommandAttempt;
}
