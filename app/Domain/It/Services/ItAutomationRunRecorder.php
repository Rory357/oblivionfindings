<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

class ItAutomationRunRecorder
{
    /** @var array<int, int> task object id => run id */
    private static array $activeRunIds = [];

    public function starting(ScheduledTaskStarting $event): void
    {
        if (! $this->isSchedulerRecordedAutomation($event->task)) {
            return;
        }

        $run = $this->begin(
            $event->task->description,
            $event->task->expression,
        );
        self::$activeRunIds[spl_object_id($event->task)] = $run->id;
    }

    public function begin(string $key, ?string $expression = null): ItAutomationRun
    {
        return ItAutomationRun::query()->create([
            'automation_key' => $key,
            'schedule_expression' => $expression,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->complete($event->task, 'succeeded', (int) round($event->runtime * 1000));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->complete(
            $event->task,
            'failed',
            null,
            Str::limit($event->exception->getMessage(), 2000, ''),
        );
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        if (! $this->isItAutomation($event->task)) {
            return;
        }
        ItAutomationRun::query()->create([
            'automation_key' => $event->task->description,
            'schedule_expression' => $event->task->expression,
            'status' => 'skipped',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    private function complete(Event $task, string $status, ?int $runtimeMs = null, ?string $error = null): void
    {
        if (! $this->isSchedulerRecordedAutomation($task)) {
            return;
        }
        $objectId = spl_object_id($task);
        $runId = self::$activeRunIds[$objectId] ?? null;
        unset(self::$activeRunIds[$objectId]);
        $run = $runId ? ItAutomationRun::query()->find($runId) : null;
        if (! $run) {
            $run = $this->begin($task->description, $task->expression);
        }
        $this->completeRun($run, $status, $runtimeMs, $error);
    }

    /** @param array<string, mixed>|null $result */
    public function completeRun(
        ItAutomationRun $run,
        string $status,
        ?int $runtimeMs = null,
        ?string $error = null,
        ?array $result = null,
    ): void {
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'runtime_ms' => $runtimeMs,
            'error_summary' => $error,
            'result_summary' => $result,
        ])->save();
    }

    private function isSchedulerRecordedAutomation(Event $task): bool
    {
        return $this->isItAutomation($task) && $task->description !== 'it.poll-mailbox';
    }

    private function isItAutomation(Event $task): bool
    {
        return is_string($task->description) && str_starts_with($task->description, 'it.');
    }
}
