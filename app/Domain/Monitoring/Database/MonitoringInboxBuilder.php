<?php

namespace App\Domain\Monitoring\Database;

use App\Domain\Monitoring\Models\MonitoringInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnexpectedValueException;

/** @extends Builder<MonitoringInbox> */
final class MonitoringInboxBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
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
        $this->assertPayloadIntegrity($values);

        return $this->toBase()->insert($values);
    }

    public function insertOrIgnore(array $values): int
    {
        $this->assertPayloadIntegrity($values);

        return $this->toBase()->insertOrIgnore($values);
    }

    public function insertOrIgnoreReturning(
        array $values,
        array $returning = ['*'],
        array|string|null $uniqueBy = null,
    ): Collection {
        $this->assertPayloadIntegrity($values);

        return $this->toBase()->insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        $this->assertPayloadIntegrity($values);

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function insertUsing(array $columns, $query): int
    {
        throw new UnexpectedValueException('Monitoring inbox insert-from-query is not permitted.');
    }

    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        throw new UnexpectedValueException('Monitoring inbox insert-from-query is not permitted.');
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->assertPayloadIntegrity($values);

        if ($update === null) {
            throw new UnexpectedValueException('Monitoring inbox delivery identity and evidence are immutable.');
        }

        $this->assertNoImmutableWrites($update);

        return parent::upsert($values, $uniqueBy, $update);
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        if (is_callable($values)) {
            throw new UnexpectedValueException('Monitoring inbox update-or-insert callable is not permitted.');
        }

        $this->assertNoImmutableWrites(array_keys($values));
        $this->assertPayloadIntegrity(array_merge($attributes, $values));

        return $this->toBase()->updateOrInsert($attributes, $values);
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->assertCounterWriteIsMutable($column, $extra);

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->assertCounterWriteIsMutable($column, $extra);

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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function assertCounterWriteIsMutable(mixed $column, array $extra): void
    {
        if (! is_string($column)) {
            throw new UnexpectedValueException('Monitoring inbox delivery identity and evidence are immutable.');
        }

        $this->assertNoImmutableWrites([$column, ...array_keys($extra)]);
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    private function assertNoImmutableWrites(array $columns): void
    {
        foreach ($columns as $key => $column) {
            $column = is_int($key) ? $column : $key;

            if (! is_string($column)) {
                continue;
            }

            $attribute = str_contains($column, '.')
                ? substr($column, strrpos($column, '.') + 1)
                : $column;
            $attribute = strtolower($attribute);

            if (in_array($attribute, MonitoringInbox::IMMUTABLE_DELIVERY_ATTRIBUTES, true)) {
                throw new UnexpectedValueException('Monitoring inbox delivery identity and evidence are immutable.');
            }
        }
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $values
     */
    private function assertPayloadIntegrity(array $values): void
    {
        $rows = isset($values[0]) && is_array($values[0]) ? $values : [$values];

        foreach ($rows as $row) {
            $envelopeBytes = $row['envelope_bytes'] ?? null;
            $payloadHash = $row['payload_hash'] ?? null;

            if (! is_string($envelopeBytes)
                || ! is_string($payloadHash)
                || ! hash_equals(hash('sha256', $envelopeBytes), $payloadHash)) {
                throw new UnexpectedValueException('Monitoring inbox payload hash does not match envelope bytes.');
            }
        }
    }
}
