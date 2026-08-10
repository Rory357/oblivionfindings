<?php

namespace App\Domain\Monitoring\Discovery\Database;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** @extends Builder<DiscoveryRun> */
final class DiscoveryRunBuilder extends Builder
{
    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        if (! DiscoveryRun::summaryWriteAllowed()
            && array_intersect(array_keys($values), DiscoveryRun::IMMUTABLE_SUMMARY_ATTRIBUTES) !== []) {
            throw new LogicException('Completed discovery run summary is immutable.');
        }

        return parent::update($values);
    }
}
