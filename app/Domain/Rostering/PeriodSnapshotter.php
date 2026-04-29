<?php

namespace App\Domain\Rostering;

use App\Models\RosterPeriod;
use App\Models\Shift;
use Illuminate\Support\Collection;

class PeriodSnapshotter
{
    private const DIFF_FIELDS = [
        'client_id' => 'Client',
        'site_id' => 'Site',
        'service_context_id' => 'Service context',
        'user_id' => 'Staff',
        'starts_at' => 'Start',
        'ends_at' => 'End',
        'status' => 'Status',
        'shift_type' => 'Shift type',
        'is_sleepover' => 'Sleepover',
        'is_on_call' => 'On call',
        'expected_break_minutes' => 'Break minutes',
        'coverage_roles' => 'Coverage roles',
        'location' => 'Location',
        'notes' => 'Notes',
    ];

    /**
     * @param  iterable<int, Shift>  $shifts
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(iterable $shifts): array
    {
        $rows = [];

        foreach ($shifts as $shift) {
            if (! $shift instanceof Shift) {
                continue;
            }

            $rows[] = $this->row($shift);
        }

        return $rows;
    }

    /**
     * @param  iterable<int, Shift>  $currentShifts
     * @return array{summary: array{added: int, removed: int, changed: int, total: int}, changes: array<int, array<string, mixed>>}
     */
    public function diff(RosterPeriod $period, iterable $currentShifts): array
    {
        $previous = collect($period->snapshot ?? [])->keyBy('id');
        $current = collect($this->snapshot($currentShifts))->keyBy('id');
        $changes = [];

        foreach ($current as $id => $row) {
            $old = $previous->get($id);

            if (! $old) {
                $changes[] = [
                    'type' => 'added',
                    'shift_id' => $id,
                    'label' => $this->labelFor($row),
                    'starts_at' => $row['starts_at'] ?? null,
                    'changes' => [],
                ];

                continue;
            }

            $fieldChanges = $this->fieldChanges($old, $row);
            if ($fieldChanges !== []) {
                $changes[] = [
                    'type' => 'changed',
                    'shift_id' => $id,
                    'label' => $this->labelFor($row),
                    'starts_at' => $row['starts_at'] ?? $old['starts_at'] ?? null,
                    'changes' => $fieldChanges,
                ];
            }
        }

        foreach ($previous->keys()->diff($current->keys()) as $id) {
            $row = $previous->get($id);
            $changes[] = [
                'type' => 'removed',
                'shift_id' => $id,
                'label' => $this->labelFor($row),
                'starts_at' => $row['starts_at'] ?? null,
                'changes' => [],
            ];
        }

        $collection = collect($changes);

        return [
            'summary' => [
                'added' => $collection->where('type', 'added')->count(),
                'removed' => $collection->where('type', 'removed')->count(),
                'changed' => $collection->where('type', 'changed')->count(),
                'total' => $collection->count(),
            ],
            'changes' => $collection
                ->sortBy(fn (array $change) => sprintf('%s-%s', $change['starts_at'] ?? '', $change['shift_id']))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'client_id' => $shift->client_id,
            'client_name' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
            'site_id' => $shift->site_id,
            'site_name' => $shift->site?->name,
            'service_context_id' => $shift->service_context_id,
            'service_context_name' => $shift->serviceContext?->name,
            'user_id' => $shift->user_id,
            'staff_name' => $shift->staff?->name,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'status' => $shift->status,
            'shift_type' => $shift->shift_type,
            'is_sleepover' => (bool) $shift->is_sleepover,
            'is_on_call' => (bool) $shift->is_on_call,
            'expected_break_minutes' => $shift->expected_break_minutes,
            'coverage_roles' => $shift->coverage_roles,
            'location' => $shift->location,
            'notes' => $shift->notes,
            'published_at' => $shift->published_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<int, array{field: string, label: string, before: mixed, after: mixed}>
     */
    private function fieldChanges(array $old, array $new): array
    {
        $changes = [];

        foreach (self::DIFF_FIELDS as $field => $label) {
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;

            if ($this->normalise($before) === $this->normalise($after)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'before' => $this->displayValue($field, $old, $before),
                'after' => $this->displayValue($field, $new, $after),
            ];
        }

        return $changes;
    }

    private function normalise(mixed $value): string
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            ksort($value);

            return (string) json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) ($value ?? '');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function displayValue(string $field, array $row, mixed $value): mixed
    {
        return match ($field) {
            'client_id' => $row['client_name'] ?? $value,
            'site_id' => $row['site_name'] ?? $value,
            'service_context_id' => $row['service_context_name'] ?? $value,
            'user_id' => $row['staff_name'] ?? 'Unassigned',
            'coverage_roles' => is_array($value)
                ? collect($value)
                    ->map(fn ($item) => is_scalar($item) ? (string) $item : (string) json_encode($item))
                    ->implode(', ')
                : $value,
            'is_sleepover', 'is_on_call' => $value ? 'Yes' : 'No',
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function labelFor(array $row): string
    {
        $parts = array_filter([
            $row['client_name'] ?? null,
            $row['staff_name'] ?? 'Unassigned',
            $row['starts_at'] ?? null,
        ]);

        return implode(' - ', $parts) ?: 'Shift '.$row['id'];
    }
}
