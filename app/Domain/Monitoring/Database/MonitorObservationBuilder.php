<?php

namespace App\Domain\Monitoring\Database;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MonitoringObservationScopeGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/** @extends Builder<MonitorObservation> */
final class MonitorObservationBuilder extends Builder
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
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
    }

    public function insertOrIgnore(array $values): int
    {
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
    }

    public function insertOrIgnoreReturning(
        array $values,
        array $returning = ['*'],
        array|string|null $uniqueBy = null,
    ): Collection {
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
    }

    public function insertUsing(array $columns, $query): int
    {
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
    }

    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        $this->assertCanonicalInsert($values);

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        throw new LogicException('Monitoring observations must use the canonical creation boundary.');
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

            $attribute = str_contains($column, '.')
                ? substr($column, strrpos($column, '.') + 1)
                : $column;

            if (in_array(strtolower($attribute), MonitorObservation::IMMUTABLE_PROVENANCE_ATTRIBUTES, true)) {
                throw new LogicException('Monitoring observation provenance is immutable.');
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function assertCanonicalInsert(array $values): void
    {
        if (! MonitorObservation::supportsProvenanceColumns()) {
            return;
        }

        $monitorId = filter_var($values['monitor_id'] ?? null, FILTER_VALIDATE_INT);
        if ($monitorId === false || $monitorId < 1) {
            throw new LogicException('Monitoring observations must use the canonical creation boundary.');
        }

        $monitor = Monitor::query()->with('collector')->find($monitorId);
        if ($monitor === null) {
            throw new LogicException('Monitoring observations must use the canonical creation boundary.');
        }

        $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id);
        app(MonitoringObservationScopeGuard::class)->assertCanonicalSite($monitor, $siteId);
        $collectorId = $monitor->collector_id === null ? null : (int) $monitor->collector_id;
        $writtenCollectorId = array_key_exists('collector_id', $values) && $values['collector_id'] !== null
            ? (int) $values['collector_id']
            : null;

        if ((int) ($values['device_id'] ?? 0) !== (int) $monitor->device_id
            || (int) ($values['site_id'] ?? 0) !== $siteId
            || $writtenCollectorId !== $collectorId) {
            throw new LogicException('Monitoring observations must use the canonical creation boundary.');
        }
    }
}
