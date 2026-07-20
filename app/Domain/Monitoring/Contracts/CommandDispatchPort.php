<?php

namespace App\Domain\Monitoring\Contracts;

interface CommandDispatchPort
{
    /** @param array<string, scalar|null> $parameters */
    public function dispatch(string $capability, int $deviceId, array $parameters): never;
}
