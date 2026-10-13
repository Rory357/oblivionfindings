<?php

namespace App\Notifications;

use App\Models\PortalInvitation;
use App\Support\BrandName;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The family portal invitation email. The link carries the plain token; it
 * works for PortalInvitation::VALID_FOR_DAYS days and only once.
 */
class PortalInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $acceptUrl,
        public ?string $inviterName,
        public ?string $clientFirstName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = BrandName::display();
        $who = $this->inviterName ?: $brand;
        $about = $this->clientFirstName ? " to see updates about {$this->clientFirstName}" : '';

        return (new MailMessage)
            ->subject("You're invited to the {$brand} family portal")
            ->greeting('Kia ora '.($notifiable->name ?? 'there').',')
            ->line("{$who} invited you to the family portal{$about}.")
            ->line('Choose how you will sign in: a password, or your Google or Microsoft account.')
            ->action('Set up your account', $this->acceptUrl)
            ->line('This invitation works for '.PortalInvitation::VALID_FOR_DAYS.' days.')
            ->line("Not expecting this email? Don't use the link, and let {$brand} know.");
    }
}
