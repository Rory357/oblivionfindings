<?php

namespace App\Http\Resources;

use App\Models\Shift;
use App\Models\Timesheet;
use App\Support\ShiftTaskSupport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class MyShiftResource extends JsonResource
{
    public function __construct($resource, private ?Carbon $workerNow = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return self::fromShift(
            $this->resource,
            $this->workerNow ?? Carbon::now(self::workerTimezone()),
        );
    }

    public static function fromShift(Shift $shift, ?Carbon $workerNow = null): array
    {
        $workerNow ??= Carbon::now(self::workerTimezone());
        $tasks = $shift->relationLoaded('tasks') ? $shift->tasks : collect();
        $timesheets = $shift->relationLoaded('timesheets') ? $shift->timesheets : collect();
        $totalTasks = $tasks->count();
        $completedTasks = $tasks->where('is_completed', true)->count();
        $latestTimesheet = $timesheets
            ->sortByDesc(fn (Timesheet $timesheet) => $timesheet->updated_at ?? $timesheet->created_at)
            ->first();

        return [
            'id' => $shift->id,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'actual_starts_at' => $shift->actual_starts_at?->toIso8601String(),
            'actual_ends_at' => $shift->actual_ends_at?->toIso8601String(),
            'status' => $shift->status,
            'status_state' => self::lifecycleStatus($shift, $workerNow, $latestTimesheet),
            'location' => $shift->location,
            'client' => $shift->client ? [
                'id' => $shift->client->id,
                'name' => trim($shift->client->first_name.' '.$shift->client->last_name),
                'photo_url' => $shift->client->profile_photo_url ?? null,
            ] : null,
            'service_type' => $shift->serviceContext?->name,
            'required_licence_class' => $shift->required_licence_class,
            'required_licence_endorsements' => $shift->required_licence_endorsements ?? [],
            'tasks' => $tasks->map(fn ($task) => [
                'id' => $task->id,
                'label' => $task->label,
                'scheduled_time' => ShiftTaskSupport::normalizeTime($task->scheduled_time),
                'scheduled_for' => $task->setRelation('shift', $shift)->scheduledFor()?->toIso8601String(),
                'is_completed' => (bool) $task->is_completed,
                'completed_at' => $task->completed_at?->toIso8601String(),
            ])->values()->all(),
            'task_progress' => $totalTasks > 0
                ? round(($completedTasks / $totalTasks) * 100)
                : 100,
            'is_today' => $shift->starts_at
                ? $shift->starts_at->copy()->timezone($workerNow->getTimezone())->isSameDay($workerNow)
                : false,
            'day_key' => $shift->starts_at
                ? $shift->starts_at->copy()->timezone($workerNow->getTimezone())->toDateString()
                : null,
            'timesheet' => $latestTimesheet ? [
                'id' => $latestTimesheet->id,
                'status' => $latestTimesheet->status,
                'return_notes' => $latestTimesheet->returned_notes,
            ] : null,
        ];
    }

    private static function lifecycleStatus(
        Shift $shift,
        Carbon $workerNow,
        ?Timesheet $timesheet,
    ): string {
        if ($timesheet?->status === 'returned') {
            return 'returned-timesheet';
        }

        $startsAt = $shift->starts_at?->copy()->timezone($workerNow->getTimezone());
        $endsAt = $shift->ends_at?->copy()->timezone($workerNow->getTimezone());

        if (
            $shift->status === 'scheduled'
            && ! $shift->actual_starts_at
            && $endsAt
            && $workerNow->greaterThan($endsAt)
        ) {
            return 'missed';
        }

        if (in_array($shift->status, ['completed', 'clocked_out', 'finished'], true)) {
            return 'completed';
        }

        if (
            $shift->status === 'scheduled'
            && ! $shift->actual_starts_at
            && $startsAt
            && $workerNow->greaterThan($startsAt->copy()->addMinutes(5))
        ) {
            return 'late';
        }

        if (in_array($shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
            if ($endsAt && $workerNow->betweenIncluded($endsAt->copy()->subMinutes(30), $endsAt)) {
                return 'ending-soon';
            }

            return 'active';
        }

        if ($startsAt && $workerNow->betweenIncluded($startsAt->copy()->subMinutes(30), $startsAt)) {
            return 'starting-soon';
        }

        return 'upcoming';
    }

    private static function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
