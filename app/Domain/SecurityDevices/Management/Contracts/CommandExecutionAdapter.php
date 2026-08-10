<?php

namespace App\Domain\SecurityDevices\Management\Contracts;

use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Models\Device;

interface CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool;

    public function execute(CommandExecutionContext $context): CommandExecutionResult;

    public function observe(CommandExecutionContext $context): CommandObservedState;
}
