<?php

namespace App\Domain\Monitoring\Contracts;

interface RestoreDependencyProbe
{
    /** @return array{redis: bool, timeseries: bool, snapshots: bool, secret_manager: bool} */
    public function health(): array;
}
