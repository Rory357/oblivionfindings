<?php

namespace App\Notifications;

use App\Models\PortalSignInLink;
use App\Support\BrandName;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The emailed family portal sign-in link: one use, 15 minutes, same browser. */
class PortalSignInLinkNotification extends Notification
{
    use Queueable;

    public function __construct(public string $signInUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = BrandName::display();

        return (new MailMessage)
            ->subject("Your {$brand} family portal sign-in link")
            ->greeting('Kia ora '.($notifiable->name ?? 'there').',')
            ->line('Use this link to sign in to the family portal.')
            ->action('Sign in', $this->signInUrl)
            ->line('It works once, for '.PortalSignInLink::VALID_FOR_MINUTES.' minutes, in the browser where you asked for it.')
            ->line("Didn't ask for this? You can ignore this email. Nobody can sign in without the link.");
    }
}
