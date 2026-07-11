<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrSupervisionNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupervisionNoteAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private HrSupervisionNote $note) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A supervision note is ready for you')
            ->greeting("Hello {$notifiable->name},")
            ->line('A note from your recent supervision session is ready to review.')
            ->action('Review supervision note', url("/hr/performance/supervision/{$this->note->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supervision_note_added',
            'note_id' => $this->note->id,
            'session_type' => $this->note->session_type,
            'session_date' => optional($this->note->session_date)->toDateString(),
            'action_url' => "/hr/performance/supervision/{$this->note->id}",
        ];
    }
}
