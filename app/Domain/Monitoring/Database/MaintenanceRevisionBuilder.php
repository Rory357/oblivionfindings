<?php

namespace App\Domain\Monitoring\Database;

use App\Domain\Monitoring\Models\MonitoringMaintenanceRevision;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** @extends Builder<MonitoringMaintenanceRevision> */
final class MaintenanceRevisionBuilder extends Builder
{
    public function update(array $values): int
    {
        return $this->deny();
    }

    public function updateFrom(array $values): int
    {
        return $this->deny();
    }

    public function delete(): mixed
    {
        return $this->deny();
    }

    public function forceDelete(): mixed
    {
        return $this->deny();
    }

    public function touch($column = null): int|false
    {
        return $this->deny();
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        return $this->deny();
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        return $this->deny();
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        return $this->deny();
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        return $this->deny();
    }

    public function incrementEach(array $columns, array $extra = []): int
    {
        return $this->deny();
    }

    public function decrementEach(array $columns, array $extra = []): int
    {
        return $this->deny();
    }

    public function truncate(): never
    {
        $this->deny();
    }

    private function deny(): never
    {
        throw new LogicException('Maintenance revisions are append-only evidence.');
    }
}
