<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Validation\ValidationException;

final class CommandExecutionAdapterRegistry
{
    /** @param iterable<CommandExecutionAdapter> $adapters */
    public function __construct(private readonly iterable $adapters) {}

    public function supports(Device $device, string $capability): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($device, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function for(Device $device, string $capability): CommandExecutionAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($device, $capability)) {
                return $adapter;
            }
        }

        throw ValidationException::withMessages([
            'command' => 'No approved execution adapter is available for this Device and capability.',
        ]);
    }
}
