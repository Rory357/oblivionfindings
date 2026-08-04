<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Services\HrCaseAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class HrCaseUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCase $case,
        private string $eventType
    ) {}

    /**
     * Database only - no mail for case updates (sensitive HR data).
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $recipient = User::query()->find($notifiable->getKey());
        if (! $recipient
            || ! app(HrCurrentStaffService::class)->isCurrent($recipient)
            || ! $recipient->canDo('hr.cases.view')) {
            return [];
        }

        $case = HrCase::query()
            ->whereKey($this->case->getKey())
            ->where('assigned_to', $recipient->getKey())
            ->first();
        if (! $case) {
            return [];
        }

        $caseAccess = new HrCaseAccessService(new UserSiteAccessService);
        if (! $caseAccess
            ->applyVisibleCaseScope(HrCase::query(), $recipient)
            ->whereKey($case->getKey())
            ->exists()) {
            return [];
        }

        $this->case = $case;

        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_case_update',
            'case_number' => $this->case->case_number,
            'event_type' => $this->eventType,
            'title' => $this->case->title,
            'case_id' => $this->case->id,
            'case_type' => $this->case->case_type,
            'status' => $this->case->status,
            'action_url' => "/hr/cases/{$this->case->id}",
        ];
    }
}
