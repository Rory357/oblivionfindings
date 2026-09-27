<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

final class ReportEngine
{
    public function calculate(array $definition, array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => $this->matches($definition, $row)));
        $buckets = [];
        foreach ($rows as $row) {
            $dimensions = array_map(fn ($key) => $this->dimension($definition, $row, $key), $definition['groups']);
            $key = json_encode($dimensions, JSON_THROW_ON_ERROR);
            $buckets[$key]['dimensions'] = $dimensions;
            $buckets[$key]['rows'][] = $row;
            abort_if(count($buckets) > 5000, 422, 'More than 5,000 groups. Use fewer dimensions or a narrower period.');
        }
        if ($definition['groups'] === [] && $buckets === []) {
            $buckets['[]'] = ['dimensions' => [], 'rows' => []];
        }
        $groups = array_map(fn ($bucket) => ['dimensions' => $bucket['dimensions'], 'values' => $this->measures($definition, $bucket['rows']), 'row_count' => count($bucket['rows'])], array_values($buckets));
        $firstMeasure = $definition['measures'][0]['id'];
        usort($groups, function ($a, $b) use ($definition, $firstMeasure) {
            $result = $definition['sort'] === 'value'
                ? (($a['values'][$firstMeasure] ?? -INF) <=> ($b['values'][$firstMeasure] ?? -INF))
                : ($a['dimensions'] <=> $b['dimensions']);

            return $definition['direction'] === 'desc' ? -$result : $result;
        });
        $missing = [];
        foreach ($definition['columns'] as $field) {
            $missing[$field] = count(array_filter($rows, fn ($r) => ! isset($r[$field])));
        }
        $chart = $groups;
        if ($definition['layout'] === 'line') {
            // A contiguous calendar axis, independent of value-sorted tables.
            $byDay = [];
            foreach ($groups as $group) {
                $byDay[$group['dimensions'][0]][] = $group;
            }
            $chart = [];
            $from = $this->bucketStart(CarbonImmutable::parse($definition['date_from'], 'Pacific/Auckland'), $definition['date_bucket'] ?? 'day');
            $to = CarbonImmutable::parse($definition['date_to'], 'Pacific/Auckland');
            for ($day = $from; $day <= $to; $day = match ($definition['date_bucket'] ?? 'day') {
                'week' => $day->addWeek(), 'month' => $day->addMonth(), default => $day->addDay()
            }) {
                $key = $day->toDateString();
                foreach ($byDay[$key] ?? [['dimensions' => [$key], 'values' => array_fill_keys(array_column($definition['measures'], 'id'), null), 'row_count' => 0]] as $group) {
                    $chart[] = $group + ['timestamp' => $day->getTimestamp() * 1000];
                }
            }
        } else {
            $chart = array_slice($chart, 0, $definition['limit']);
        }

        $pivotTotals = ['rows' => [], 'columns' => []];
        if (count($definition['groups']) === 2) {
            foreach (['rows' => 0, 'columns' => 1] as $axis => $index) {
                $axisRows = [];
                foreach ($rows as $row) {
                    $value = $this->dimension($definition, $row, $definition['groups'][$index]);
                    $axisRows[json_encode($value, JSON_THROW_ON_ERROR)][] = $row;
                }
                foreach ($axisRows as $key => $items) {
                    $pivotTotals[$axis][] = ['dimensions' => [json_decode($key, true)], 'values' => $this->measures($definition, $items), 'row_count' => count($items)];
                }
            }
        }
        if (! empty($definition['detail_sort'])) {
            $field = $definition['detail_sort'];
            usort($rows, function ($a, $b) use ($definition, $field) {
                $left = $a[$field] ?? null;
                $right = $b[$field] ?? null;
                if ($left === null || $right === null) {
                    return ($left === null) <=> ($right === null);
                }
                $result = $left <=> $right;

                return ($definition['detail_direction'] ?? 'asc') === 'desc' ? -$result : $result;
            });
        }

        return ['pivot_totals' => $pivotTotals, 'row_count' => count($rows), 'group_count' => count($groups), 'missing' => $missing,
            'totals' => $this->measures($definition, $rows), 'groups' => $groups, 'chart' => $chart,
            'rows' => array_map(fn ($row) => array_intersect_key($row, array_flip($definition['columns'])), $rows)];
    }

    private function bucketStart(CarbonImmutable $date, string $bucket): CarbonImmutable
    {
        return match ($bucket) {
            'week' => $date->startOfWeek(1), 'month' => $date->startOfMonth(), default => $date->startOfDay()
        };
    }

    private function dimension(array $definition, array $row, string $field): mixed
    {
        if ($field !== 'date' || ! isset($row[$field])) {
            return $row[$field] ?? null;
        }

        return $this->bucketStart(CarbonImmutable::parse($row[$field], 'Pacific/Auckland')->setTimezone('Pacific/Auckland'), $definition['date_bucket'] ?? 'day')->toDateString();
    }

    private function matches(array $definition, array $row): bool
    {
        if (! empty($definition['filter_groups'])) {
            $results = array_map(fn ($group) => $this->matches(['source' => $definition['source'], 'filters' => $group['filters'], 'match' => $group['match']], $row), $definition['filter_groups']);

            return $definition['match'] === 'all' ? ! in_array(false, $results, true) : in_array(true, $results, true);
        }
        if ($definition['filters'] === []) {
            return true;
        }
        $fields = config('operational-reports.sources.'.$definition['source'].'.fields');
        $results = array_map(function ($filter) use ($row, $fields) {
            $value = $row[$filter['field']] ?? null;
            $expected = $filter['value'] ?? '';
            $dateField = ($fields[$filter['field']]['type'] ?? '') === 'date';
            if ($dateField && $value !== null) {
                $value = CarbonImmutable::parse($value, 'Pacific/Auckland')->setTimezone('Pacific/Auckland')->toDateString();
            }
            $comparable = $dateField ? $expected : (float) $expected;
            $equal = ($fields[$filter['field']]['type'] ?? '') === 'number' && is_numeric($expected) ? (float) $value === (float) $expected : (string) $value === $expected;

            return match ($filter['operator']) {
                'missing' => $value === null, 'known' => $value !== null,
                'contains' => $value !== null && mb_stripos((string) $value, $expected) !== false,
                'eq' => $value !== null && $equal,
                'ne' => $value !== null && ! $equal,
                'gt' => $value !== null && $value > $comparable, 'gte' => $value !== null && $value >= $comparable,
                'lt' => $value !== null && $value < $comparable, 'lte' => $value !== null && $value <= $comparable,
            };
        }, $definition['filters']);

        return $definition['match'] === 'all' ? ! in_array(false, $results, true) : in_array(true, $results, true);
    }

    private function measures(array $definition, array $rows): array
    {
        $measures = $definition['measures'];
        $values = [];
        foreach ($measures as $measure) {
            if ($measure['operation'] === 'formula') {
                continue;
            }
            $subset = isset($measure['where']) ? array_values(array_filter($rows, fn ($row) => $this->matches(['source' => $definition['source'], 'filters' => [$measure['where']], 'match' => 'all'], $row))) : $rows;
            $known = array_values(array_filter(array_map(fn ($r) => $r[$measure['field'] ?? ''] ?? null, $subset), fn ($v) => $v !== null));
            $numbers = array_values(array_filter($known, fn ($v) => is_numeric($v) && is_finite((float) $v)));
            sort($numbers, SORT_NUMERIC);
            $values[$measure['id']] = match ($measure['operation']) {
                'count' => count($subset), 'known' => count($known), 'distinct' => count(array_unique(array_map(fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $known))),
                'sum' => $numbers === [] ? null : array_sum($numbers),
                'avg' => $numbers === [] ? null : array_sum($numbers) / count($numbers),
                'min' => $numbers === [] ? null : min($numbers), 'max' => $numbers === [] ? null : max($numbers),
                'p50' => $this->percentile($numbers, .5), 'p95' => $this->percentile($numbers, .95),
            };
        }
        foreach ($measures as $measure) {
            if ($measure['operation'] === 'formula') {
                $values[$measure['id']] = app(ReportFormula::class)->calculate($measure['formula'], $values);
            }
        }

        return $values;
    }

    /** Linear interpolation, excluding unknown values. */
    private function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        $index = (count($values) - 1) * $p;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
    }
}
