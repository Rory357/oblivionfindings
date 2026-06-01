<?php

namespace App\Jobs;

use App\Models\ShiftTask;
use App\Notifications\ShiftTaskDueNotification;
use App\Services\Facility\FacilitySignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ShiftTaskDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(FacilitySignalService $signalService): void
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $now = now($timezone);
        $windowStart = $now->copy()->subDay()->startOfDay()->utc();
        $windowEnd = $now->copy()->endOfDay()->utc();

        ShiftTask::query()
            ->whereNotNull('scheduled_time')
            ->whereNull('reminder_sent_at')
            ->where('is_completed', false)
            ->whereHas('shift', fn ($query) => $query
                ->whereIn('status', ['scheduled', 'in_progress'])
                ->whereNotNull('user_id')
                ->whereBetween('starts_at', [$windowStart, $windowEnd]))
            ->with(['shift.client:id,first_name,last_name,site_id', 'shift.staff:id,name,email', 'shift.site:id,name'])
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use ($signalService, $now): void {
                foreach ($tasks as $task) {
                    $dueAt = $task->scheduledFor();
                    $worker = $task->shift?->staff;

                    if (! $dueAt || $dueAt->gt($now) || ! $worker) {
                        continue;
                    }

                    $claimedAt = now();
                    $claimed = ShiftTask::query()
                        ->whereKey($task->id)
                        ->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => $claimedAt]);

                    if ($claimed === 0) {
                        continue;
                    }

                    try {
                        $worker->notify(new ShiftTaskDueNotification($task));
                        $signalService->emitShiftTaskDue($task, $worker);
                    } catch (\Throwable $exception) {
                        ShiftTask::query()
                            ->whereKey($task->id)
                            ->update(['reminder_sent_at' => null]);

                        throw $exception;
                    }
                }
            });
    }
}
