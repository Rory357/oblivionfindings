<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Enums\MonitorKind;
use LogicException;

final class ProbeAdapterRegistry
{
    /** @var array<string, ProbeAdapter> */
    private array $adapters;

    public function __construct(ProbeAdapter ...$adapters)
    {
        $this->adapters = [];
        foreach ($adapters as $adapter) {
            $kind = $adapter->kind()->value;
            if (isset($this->adapters[$kind])) {
                throw new LogicException("Duplicate direct probe adapter for {$kind}.");
            }
            $this->adapters[$kind] = $adapter;
        }
    }

    public function for(MonitorKind $kind): ProbeAdapter
    {
        return $this->adapters[$kind->value]
            ?? throw new LogicException("No direct probe adapter is registered for {$kind->value}.");
    }
}
