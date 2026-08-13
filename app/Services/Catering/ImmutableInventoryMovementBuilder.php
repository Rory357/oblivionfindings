<?php

namespace App\Services\Catering;

use App\Models\SiteMealInventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** @extends Builder<SiteMealInventoryMovement> */
final class ImmutableInventoryMovementBuilder extends Builder
{
    /** @param  array<string, mixed>  $values */
    public function update(array $values): int
    {
        throw new LogicException('Inventory movements are immutable.');
    }

    public function delete(): mixed
    {
        throw new LogicException('Inventory movements are immutable.');
    }
}
