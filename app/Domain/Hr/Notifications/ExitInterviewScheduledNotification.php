<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrExitInterview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExitInterviewScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private HrExitInterview $interview) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->interview->employeeProfile?->user?->name ?? 'the departing employee';
        $date = $this->interview->interview_date?->format('l, F j, Y') ?? 'Date to be confirmed';

        return (new MailMessage)
            ->subject("Exit interview scheduled — {$employee}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been selected to conduct {$employee}'s exit interview.")
            ->line("**Date:** {$date}")
            ->action('Open Exit Interviews', url('/hr/exit-interviews'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'exit_interview_scheduled',
            'exit_interview_id' => $this->interview->id,
            'employee_name' => $this->interview->employeeProfile?->user?->name,
            'interview_date' => $this->interview->interview_date?->toDateString(),
            'action_url' => "/hr/exit-interviews/{$this->interview->id}",
        ];
    }
}
