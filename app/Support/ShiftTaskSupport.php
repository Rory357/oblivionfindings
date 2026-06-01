<?php

namespace App\Support;

use App\Models\Shift;
use App\Models\ShiftTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ShiftTaskSupport
{
    public static function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr($value, 0, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return Collection<int, array{id:mixed,label:string,scheduled_time:?string,sort_order:int}>
     */
    public static function normalizeInputs(array $tasks): Collection
    {
        return collect($tasks)
            ->map(fn (array $task, int $index) => [
                'id' => $task['id'] ?? null,
                'label' => (string) ($task['label'] ?? ''),
                'scheduled_time' => self::normalizeTime($task['scheduled_time'] ?? null),
                'sort_order' => $index,
            ])
            ->filter(fn (array $task) => trim($task['label']) !== '')
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public static function createForShift(Shift $shift, array $tasks): void
    {
        foreach (self::normalizeInputs($tasks) as $task) {
            ShiftTask::query()->create([
                'shift_id' => $shift->id,
                'label' => $task['label'],
                'scheduled_time' => $task['scheduled_time'],
                'sort_order' => $task['sort_order'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public static function syncForShift(Shift $shift, array $tasks): void
    {
        $existing = $shift->tasks()->get()->keyBy('id');
        $incoming = self::normalizeInputs($tasks);

        $keepIds = $incoming->pluck('id')->filter()->all();
        if ($keepIds === []) {
            $shift->tasks()->delete();
        } else {
            $shift->tasks()->whereNotIn('id', $keepIds)->delete();
        }

        foreach ($incoming as $task) {
            if ($task['id'] && $existing->has($task['id'])) {
                $existingTask = $existing[$task['id']];
                $previousTime = self::normalizeTime($existingTask->scheduled_time);
                $nextTime = $task['scheduled_time'];

                $payload = [
                    'label' => $task['label'],
                    'scheduled_time' => $nextTime,
                    'sort_order' => $task['sort_order'],
                ];

                if ($previousTime !== $nextTime) {
                    $payload['reminder_sent_at'] = null;
                }

                $existingTask->update($payload);

                continue;
            }

            ShiftTask::query()->create([
                'shift_id' => $shift->id,
                'label' => $task['label'],
                'scheduled_time' => $task['scheduled_time'],
                'sort_order' => $task['sort_order'],
            ]);
        }
    }

    public static function clearRemindersForShiftStartChange(Shift $shift, mixed $previousStartsAt): void
    {
        if (! $previousStartsAt || ! $shift->starts_at) {
            return;
        }

        $previous = Carbon::parse($previousStartsAt);
        if ($previous->equalTo($shift->starts_at)) {
            return;
        }

        $shift->tasks()
            ->whereNotNull('scheduled_time')
            ->whereNotNull('reminder_sent_at')
            ->where('is_completed', false)
            ->update(['reminder_sent_at' => null]);

        $shift->unsetRelation('tasks');
    }

    public static function payload(ShiftTask $task): array
    {
        return [
            'id' => $task->id,
            'label' => $task->label,
            'scheduled_time' => self::normalizeTime($task->scheduled_time),
            'is_completed' => (bool) $task->is_completed,
        ];
    }

    public static function timedPayloadForShift(Shift $shift): array
    {
        return self::payloadsForShift($shift, true);
    }

    public static function payloadsForShift(Shift $shift, bool $timedOnly = false): array
    {
        return $shift->tasks
            ->when($timedOnly, fn (Collection $tasks) => $tasks->filter(fn (ShiftTask $task) => filled($task->scheduled_time)))
            ->sortBy(fn (ShiftTask $task) => $timedOnly
                ? sprintf('%s-%04d-%08d', self::normalizeTime($task->scheduled_time) ?? '', $task->sort_order ?? 0, $task->id ?? 0)
                : sprintf('%04d-%08d', $task->sort_order ?? 0, $task->id ?? 0))
            ->map(fn (ShiftTask $task) => self::payload($task))
            ->values()
            ->all();
    }
}
