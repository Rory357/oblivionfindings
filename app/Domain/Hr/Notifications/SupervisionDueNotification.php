<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SupervisionDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $employeeName,
        private string $dueDate,
        private ?int $noteId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supervision_due',
            'employee_name' => $this->employeeName,
            'due_date' => $this->dueDate,
            'note_id' => $this->noteId,
            'action_url' => $this->noteId
                ? "/hr/performance/supervision/{$this->noteId}"
                : '/hr/performance?tab=supervision',
        ];
    }
}
