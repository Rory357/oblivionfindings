<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use LogicException;

final class RejectingCommandDispatchPort implements CommandDispatchPort
{
    public function dispatch(string $capability, int $deviceId, array $parameters): never
    {
        throw new LogicException('Device commands are outside the native monitoring runtime plan.');
    }
}
