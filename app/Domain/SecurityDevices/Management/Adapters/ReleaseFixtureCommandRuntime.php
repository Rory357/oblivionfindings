<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\SecurityDevices\Models\Device;

interface ReleaseFixtureCommandRuntime
{
    public function isApprovedStagingFixtureRuntime(): bool;

    public function owns(Device $device): bool;
}
