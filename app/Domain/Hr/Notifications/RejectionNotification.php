<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Optional, opt-in respectful decline email. Dispatched from rejectApplication
 * ONLY when the manager ticks "send decline email" — never default-on.
 * On-demand routed to the candidate's personal email.
 */
class RejectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCandidate $candidate,
        private HrApplication $application,
        private ?string $message = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->candidate->first_name ?: 'there';
        $role = $this->application->position_title ?: 'the role you applied for';

        $mail = (new MailMessage)
            ->subject("An update on your application — {$role}")
            ->greeting("Kia ora {$name},")
            ->line("Thank you for your interest in **{$role}** and for the time you spent with us.")
            ->line('After careful consideration, we will not be progressing your application on this occasion.');

        if ($this->message) {
            $mail->line($this->message);
        }

        return $mail
            ->line('We genuinely appreciated the opportunity to meet you and wish you every success.')
            ->salutation('Ngā mihi, The Recruitment Team');
    }
}
