<?php

namespace App\Domain\Monitoring\Topology\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @extends Builder<Model> */
final class ImmutableTopologyBuilder extends Builder
{
    public function __construct(
        \Illuminate\Database\Query\Builder $query,
        private readonly string $message,
    ) {
        parent::__construct($query);
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        throw new LogicException($this->message);
    }

    public function delete(): mixed
    {
        throw new LogicException($this->message);
    }
}
