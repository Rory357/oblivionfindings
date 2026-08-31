<?php

namespace App\Jobs;

use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Notifications\InspectionDueNotification;
use App\Services\Facility\FacilitySignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;

class InspectionDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(FacilitySignalService $signalService): void
    {
        $today = now()->toDateString();
        $nextWeek = now()->addWeek()->toDateString();

        // Find inspections due soon (reminder) — notification only, NOT operational
        $schedules = SiteInspectionSchedule::query()
            ->where('is_active', true)
            ->whereDate('next_due_date', '<=', $nextWeek)
            ->whereDate('next_due_date', '>=', $today)
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->get();

        foreach ($schedules as $schedule) {
            $recipient = $this->eligibleRecipient($schedule);
            if ($recipient) {
                $recipient->notify(new InspectionDueNotification($schedule, 'upcoming'));
            }
        }

        // Find overdue inspections — notification + operational signal → Control Room
        $overdueSchedules = SiteInspectionSchedule::query()
            ->where('is_active', true)
            ->whereDate('next_due_date', '<', $today)
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->get();

        foreach ($overdueSchedules as $schedule) {
            // Keep notification for assigned user
            $recipient = $this->eligibleRecipient($schedule);
            if ($recipient) {
                $recipient->notify(new InspectionDueNotification($schedule, 'overdue'));
            }

            // Emit operational signal → Control Room
            $daysOverdue = (int) $schedule->next_due_date->diffInDays(now());
            $signalService->emitInspectionOverdue($schedule, $daysOverdue);
        }
    }

    private function eligibleRecipient(SiteInspectionSchedule $schedule): ?User
    {
        if ($schedule->assigned_to_user_id === null || $schedule->site === null) {
            return null;
        }

        $recipient = User::query()->find($schedule->assigned_to_user_id);

        return $recipient
            && $recipient->canDo('checklists.view')
            && Gate::forUser($recipient)->allows('view', $schedule->site)
                ? $recipient
                : null;
    }
}
