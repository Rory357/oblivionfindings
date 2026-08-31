<?php

namespace App\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use App\Notifications\ShiftTaskDueNotification;
use App\Services\Facility\FacilitySignalService;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
                    DB::transaction(function () use ($task, $signalService, $now): void {
                        $currentTask = ShiftTask::query()
                            ->lockForUpdate()
                            ->find($task->id);
                        $shift = $currentTask
                            ? Shift::query()
                                ->with([
                                    'client:id,first_name,last_name,site_id',
                                    'site:id,name',
                                ])
                                ->lockForUpdate()
                                ->find($currentTask->shift_id)
                            : null;

                        if ($currentTask && $shift) {
                            $currentTask->setRelation('shift', $shift);
                        }

                        if (
                            ! $currentTask
                            || $currentTask->reminder_sent_at
                            || $currentTask->is_completed
                            || ! $shift
                            || ! in_array($shift->status, ['scheduled', 'in_progress'], true)
                            || ! $shift->user_id
                        ) {
                            return;
                        }

                        $dueAt = $currentTask->scheduledFor();
                        if (! $dueAt || $dueAt->gt($now)) {
                            return;
                        }

                        $worker = User::query()->lockForUpdate()->find($shift->user_id);
                        if (! $worker) {
                            return;
                        }

                        $profile = HrEmployeeProfile::query()
                            ->where('user_id', $worker->id)
                            ->lockForUpdate()
                            ->first();
                        $worker->setRelation('hrEmployeeProfile', $profile);

                        try {
                            (new UserSiteAccessService)->assertCanAccessShift($worker, $shift);
                        } catch (HttpExceptionInterface) {
                            return;
                        }

                        $claimedAt = now();
                        $claimed = ShiftTask::query()
                            ->whereKey($currentTask->id)
                            ->whereNull('reminder_sent_at')
                            ->where('is_completed', false)
                            ->update(['reminder_sent_at' => $claimedAt]);

                        if ($claimed === 0) {
                            return;
                        }

                        $currentTask->reminder_sent_at = $claimedAt;

                        try {
                            $worker->notify(new ShiftTaskDueNotification($currentTask));
                            $signalService->emitShiftTaskDue($currentTask, $worker);
                        } catch (\Throwable $exception) {
                            ShiftTask::query()
                                ->whereKey($currentTask->id)
                                ->where('reminder_sent_at', $claimedAt)
                                ->update(['reminder_sent_at' => null]);

                            throw $exception;
                        }
                    });
                }
            });
    }
}
