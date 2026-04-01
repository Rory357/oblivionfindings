<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobPosting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrJobPosting $posting,
        private HrCandidate $candidate,
        private HrApplication $application,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->candidate->first_name;
        $trackingUrl = url("/careers/application/{$this->application->candidate_tracking_token}");

        return (new MailMessage)
            ->subject("Application Received: {$this->posting->title}")
            ->greeting("Kia ora {$name},")
            ->line("Thank you for applying for the **{$this->posting->title}** position.")
            ->line('We have received your application and it is now being reviewed by our team.')
            ->line('You can check the status of your application at any time using the link below.')
            ->action('Check Application Status', $trackingUrl)
            ->line('We will be in touch if your application progresses to the next stage.')
            ->salutation('Nga mihi, The Recruitment Team');
    }
}
