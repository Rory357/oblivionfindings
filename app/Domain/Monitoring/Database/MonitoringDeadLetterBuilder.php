<?php

namespace App\Domain\Monitoring\Database;

use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnexpectedValueException;

/** @extends Builder<MonitoringDeadLetter> */
final class MonitoringDeadLetterBuilder extends Builder
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

    public function insert(array $values): bool
    {
        return $this->toBase()->insert($this->normaliseRows($values));
    }

    public function insertOrIgnore(array $values): int
    {
        return $this->toBase()->insertOrIgnore($this->normaliseRows($values));
    }

    public function insertOrIgnoreReturning(
        array $values,
        array $returning = ['*'],
        array|string|null $uniqueBy = null,
    ): Collection {
        return $this->toBase()->insertOrIgnoreReturning(
            $this->normaliseRows($values),
            $returning,
            $uniqueBy,
        );
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        return $this->toBase()->insertGetId(MonitoringDeadLetter::withDerivedEvidenceIdentity($values), $sequence);
    }

    public function insertUsing(array $columns, $query): int
    {
        throw new UnexpectedValueException('Monitoring dead-letter insert-from-query is not permitted.');
    }

    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        throw new UnexpectedValueException('Monitoring dead-letter insert-from-query is not permitted.');
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if ($update === null) {
            throw new UnexpectedValueException('Monitoring dead-letter evidence identity is immutable.');
        }

        $this->assertNoImmutableWrites($update);

        return parent::upsert($this->normaliseRows($values), $uniqueBy, $update);
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        throw new UnexpectedValueException('Monitoring dead-letter update-or-insert is not permitted.');
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

            if (in_array($attribute, MonitoringDeadLetter::IMMUTABLE_EVIDENCE_ATTRIBUTES, true)) {
                throw new UnexpectedValueException('Monitoring dead-letter evidence identity is immutable.');
            }
        }
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $values */
    private function normaliseRows(array $values): array
    {
        $isList = isset($values[0]) && is_array($values[0]);
        $rows = $isList ? $values : [$values];
        $rows = array_map(
            fn (array $row): array => MonitoringDeadLetter::withDerivedEvidenceIdentity($row),
            $rows,
        );

        return $isList ? $rows : $rows[0];
    }
}
