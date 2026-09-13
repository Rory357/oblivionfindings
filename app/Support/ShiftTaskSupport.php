<?php

namespace App\Support;

use App\Models\Shift;
use App\Models\ShiftTask;
use App\Services\MyDay\ShiftTaskHelpService;
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
            $shift->tasks()->whereNull('creation_key')->whereNull('source_handover_id')->delete();
        } else {
            $shift->tasks()->whereNull('creation_key')->whereNull('source_handover_id')->whereNotIn('id', $keepIds)->delete();
        }

        foreach ($incoming as $task) {
            if ($task['id'] && $existing->has($task['id'])) {
                $existingTask = $existing[$task['id']];
                // Worker-added work has its own audited outcome/edit path. A stale
                // roster form must neither delete it nor overwrite its timing.
                if ($existingTask->creation_key || $existingTask->source_handover_id) {
                    continue;
                }
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

    public static function workPayload(ShiftTask $task): array
    {
        $shift = $task->shift;

        return [
            ...self::payload($task),
            'shift_id' => $task->shift_id,
            'client_id' => $task->task_scope === 'site' ? null : ($task->client_id ?? $shift?->client_id),
            'task_scope' => $task->task_scope ?? ($shift?->client_id ? 'client' : 'site'),
            'scheduled_for' => $task->scheduledFor()?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'completed_by' => $task->completed_by,
            'created_by' => $task->created_by,
            'assigned_to' => $shift?->user_id,
            'source_label' => $task->source_handover_id ? 'Handover follow-up' : ($task->creation_key ? 'Added during shift' : 'Shift task'),
            'steps' => $task->steps ?? [],
            'help' => $task->help_status ? [
                'status' => $task->help_status,
                'recipient_id' => $task->help_requested_to,
                'recipient_name' => $task->helpRecipient?->name,
                'reason' => $task->help_reason,
                'requested_at' => $task->help_requested_at?->toIso8601String(),
                'responded_at' => $task->help_responded_at?->toIso8601String(),
            ] : null,
            'source_handover_id' => $task->source_handover_id,
            'follow_through' => ! $task->is_completed && app(ShiftTaskHelpService::class)->accepted($task) ? 'accepted_help' : null,
            'version' => (int) ($task->version ?? 0),
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
