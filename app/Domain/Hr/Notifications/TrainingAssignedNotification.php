<?php

namespace App\Domain\Hr\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrainingAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{assignment_id:?int, course_title:string, due_at:?string}  $payload
     */
    public function __construct(
        private array $payload
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->payload['course_title'] ?? 'a course';
        $due = $this->payload['due_at'] ?? null;
        $formatted = $due ? Carbon::parse($due)->format('l, F j, Y') : null;

        $mail = (new MailMessage)
            ->subject("Training assigned: {$title}")
            ->greeting("Hello {$notifiable->name},")
            ->line('You have been assigned a training course:')
            ->line("**{$title}**");

        if ($formatted) {
            $mail->line("Please complete it by {$formatted}.");
        }

        return $mail
            ->action('View my training', url('/hr/my/training'))
            ->line('You can track this alongside your other requirements on your My HR training page.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'training_assigned',
            'message' => "Training assigned: {$this->payload['course_title']}",
            'assignment_id' => $this->payload['assignment_id'] ?? null,
            'course_title' => $this->payload['course_title'] ?? null,
            'due_at' => $this->payload['due_at'] ?? null,
            'action_url' => '/hr/my/training',
        ];
    }
}
