<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrSupervisionNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the supervisor when the employee acknowledges a supervision / 1:1
 * note — the supervisor is the waiting party (the note isn't closed out until
 * the employee has read it).
 */
class SupervisionAcknowledgedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrSupervisionNote $note,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->note->employee?->name ?? 'Your team member';

        return (new MailMessage)
            ->subject("Supervision note acknowledged — {$employeeName}")
            ->greeting('Hello,')
            ->line("{$employeeName} has acknowledged the supervision note from your session.")
            ->action('View note', url("/hr/performance/supervision/{$this->note->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supervision_acknowledged',
            'note_id' => $this->note->id,
            'employee_name' => $this->note->employee?->name,
            'action_url' => "/hr/performance/supervision/{$this->note->id}",
        ];
    }
}
