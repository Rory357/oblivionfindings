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
            'title'       => "HR Case Update: {$this->case->title}",
            'message'     => "Case {$this->case->case_number} has been {$this->eventType}.",
            'url'         => "/hr/cases/{$this->case->id}",
            'action_url'  => "/hr/cases/{$this->case->id}",
            'context'     => [
                'Case number' => $this->case->case_number,
                'Status'      => $this->case->status,
                'Type'        => $this->case->case_type,
            ],
            'case_number' => $this->case->case_number,
            'event_type'  => $this->eventType,
            'case_id'     => $this->case->id,
            'case_type'   => $this->case->case_type,
            'status'      => $this->case->status,
        ];
    }
}
