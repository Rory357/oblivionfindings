<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCase;
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
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'hr_case_update',
            'case_number' => $this->case->case_number,
            'event_type'  => $this->eventType,
            'title'       => $this->case->title,
            'case_id'     => $this->case->id,
            'case_type'   => $this->case->case_type,
            'status'      => $this->case->status,
            'action_url'  => "/hr/cases/{$this->case->id}",
        ];
    }
}
