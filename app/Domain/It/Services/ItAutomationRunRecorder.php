<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use WeakMap;

class ItAutomationRunRecorder
{
    /** @var WeakMap<Event, int>|null Keep the run through both finished and failed events. */
    private static ?WeakMap $activeRunIds = null;

    public function starting(ScheduledTaskStarting $event): void
    {
        if (! $this->isSchedulerRecordedAutomation($event->task)) {
            return;
        }

        $run = $this->begin(
            $event->task->description,
            $event->task->expression,
        );
        self::$activeRunIds ??= new WeakMap;
        self::$activeRunIds[$event->task] = $run->id;
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
        // Laravel emits Finished before Failed for a nonzero command exit.
        // A background dispatch is not evidence that execution completed.
        if ($event->task->runInBackground) {
            return;
        }
        $this->complete($event->task, $event->task->exitCode === 0 ? 'succeeded' : 'failed', (int) round($event->runtime * 1000));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->complete(
            $event->task,
            'failed',
            null,
            ItAutomationRunDiagnostics::failure($event->task->description ?? ''),
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
        self::$activeRunIds ??= new WeakMap;
        $runId = self::$activeRunIds[$task] ?? null;
        $run = $runId ? ItAutomationRun::query()->find($runId) : null;
        if (! $run) {
            $run = $this->begin($task->description, $task->expression);
            self::$activeRunIds[$task] = $run->id;
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
        if (! in_array($status, ['succeeded', 'failed', 'skipped'], true)) {
            throw new InvalidArgumentException('An automation completion needs a terminal status.');
        }
        DB::transaction(function () use ($run, $status, $runtimeMs, $result): void {
            $current = ItAutomationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($current->status === 'running' && $current->finished_at === null) {
                $current->forceFill([
                    'status' => $status,
                    'finished_at' => now(),
                    'runtime_ms' => $runtimeMs === null ? null : max(0, $runtimeMs),
                    'error_summary' => $status === 'failed' ? ItAutomationRunDiagnostics::failure($current->automation_key) : null,
                    'result_summary' => $result,
                ])->save();
            }
            // A stale worker or duplicate scheduler event cannot rewrite a terminal outcome.
            $run->setRawAttributes($current->getAttributes(), true);
        });
    }

    private function isSchedulerRecordedAutomation(Event $task): bool
    {
        return $this->isItAutomation($task) && ! in_array($task->description, ['it.poll-mailbox', 'it.check-sla', 'it.retry-attachment-cleanup', 'it.check-approval-deadlines'], true);
    }

    private function isItAutomation(Event $task): bool
    {
        return is_string($task->description) && str_starts_with($task->description, 'it.');
    }
}
