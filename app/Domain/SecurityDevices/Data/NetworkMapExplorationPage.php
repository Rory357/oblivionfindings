<?php

namespace App\Domain\SecurityDevices\Data;

use Illuminate\Support\Collection;

/** One request's immutable evidence selection and bounded current visibility projection. */
final readonly class NetworkMapExplorationPage
{
    public function __construct(public Collection $deviceIds, public Collection $snapshots, public Collection $edgeIds, public bool $runtime, public array $metadata, public NetworkMapFilters $filters) {}
}
