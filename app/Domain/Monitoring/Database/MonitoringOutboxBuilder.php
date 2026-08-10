<?php

namespace App\Domain\Monitoring\Database;

use App\Domain\Monitoring\Models\MonitoringOutbox;
use Illuminate\Database\Eloquent\Builder;
use UnexpectedValueException;

/** @extends Builder<MonitoringOutbox> */
final class MonitoringOutboxBuilder extends Builder
{
    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        $this->assertNoImmutableWrites(array_keys($values));

        return parent::update($values);
    }

    public function updateFrom(array $values): int
    {
        $this->assertNoImmutableWrites(array_keys($values));

        return $this->toBase()->updateFrom($values);
    }

    public function touch($column = null): int|false
    {
        if ($column !== null) {
            $this->assertNoImmutableWrites(is_array($column) ? $column : [$column]);
        }

        return parent::touch($column);
    }

    public function insertUsing(array $columns, $query): int
    {
        throw new UnexpectedValueException('Monitoring outbox insert-from-query is not permitted.');
    }

    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        throw new UnexpectedValueException('Monitoring outbox insert-from-query is not permitted.');
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if ($update === null) {
            throw new UnexpectedValueException('Monitoring outbox delivery identity and evidence are immutable.');
        }

        $this->assertNoImmutableWrites($update);

        return parent::upsert($values, $uniqueBy, $update);
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        throw new UnexpectedValueException('Monitoring outbox update-or-insert is not permitted.');
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->assertNoImmutableWrites([$column, ...array_keys($extra)]);

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->assertNoImmutableWrites([$column, ...array_keys($extra)]);

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = []): int
    {
        $this->assertNoImmutableWrites([...array_keys($columns), ...array_keys($extra)]);

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = []): int
    {
        $this->assertNoImmutableWrites([...array_keys($columns), ...array_keys($extra)]);

        return parent::decrementEach($columns, $extra);
    }

    /** @param array<int, mixed> $columns */
    private function assertNoImmutableWrites(array $columns): void
    {
        foreach ($columns as $key => $column) {
            $column = is_int($key) ? $column : $key;

            if (! is_string($column)) {
                continue;
            }

            $attribute = strtolower(str_contains($column, '.')
                ? substr($column, strrpos($column, '.') + 1)
                : $column);

            if (in_array($attribute, MonitoringOutbox::IMMUTABLE_DELIVERY_ATTRIBUTES, true)) {
                throw new UnexpectedValueException('Monitoring outbox delivery identity and evidence are immutable.');
            }
        }
    }
}
