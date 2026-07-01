<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrKeyResult;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A key result is due (or nearly due) and not yet complete. */
class KeyResultDueNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly HrKeyResult $keyResult) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $goalId = $this->keyResult->goal_id;

        return (new MailMessage)
            ->subject('Key result due: '.$this->keyResult->title)
            ->line('A key result you own is due soon and not yet complete.')
            ->line('Key result: '.$this->keyResult->title)
            ->line('Due: '.optional($this->keyResult->due_date)->format('d/m/Y'))
            ->action('Update key result', url("/hr/goals/{$goalId}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_key_result_due',
            'title' => 'Key result due: '.$this->keyResult->title,
            'message' => 'A key result you own is due soon and not yet complete.',
            'url' => "/hr/goals/{$this->keyResult->goal_id}",
            'key_result_id' => $this->keyResult->id,
            'goal_id' => $this->keyResult->goal_id,
            'due_date' => optional($this->keyResult->due_date)->toDateString(),
        ];
    }
}
