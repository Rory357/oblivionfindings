<?php

namespace App\Domain\Monitoring\Topology\Database;

use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** @extends Builder<TopologySnapshot> */
final class TopologySnapshotQueryBuilder extends Builder
{
    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        if (! TopologySnapshot::buildWriteAllowed()) {
            throw new LogicException('Completed topology snapshot is immutable.');
        }

        return parent::update($values);
    }

    public function delete(): mixed
    {
        throw new LogicException('Completed topology snapshot is immutable.');
    }
}
