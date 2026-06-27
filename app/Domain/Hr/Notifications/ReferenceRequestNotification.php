<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrReferenceCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a referee a token-guarded link to the reference questionnaire (D3 /
 * handover item 17). On-demand routed to the referee's email.
 */
class ReferenceRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrReferenceCheck $reference,
        private string $candidateName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/careers/references/{$this->reference->response_token}");

        return (new MailMessage)
            ->subject("Reference request for {$this->candidateName}")
            ->greeting("Kia ora {$this->reference->referee_name},")
            ->line("**{$this->candidateName}** has listed you as a referee for a role with us, and we'd value your feedback.")
            ->line('The form takes only a couple of minutes.')
            ->action('Complete the reference', $url)
            ->line('Thank you for taking the time to help.')
            ->salutation('Ngā mihi, The Recruitment Team');
    }
}
