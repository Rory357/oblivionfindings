<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the candidate their offer-portal link (careers.offer.show) + expiry.
 * On-demand routed to the candidate's personal email — candidates are not Users.
 * Dispatched from CandidateController::sendOffer / resendOffer.
 */
class OfferSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOffer $offer,
        private HrCandidate $candidate,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->candidate->first_name ?: 'there';
        $portalUrl = url("/careers/offers/{$this->offer->candidate_portal_token}");
        $role = $this->offer->position_title ?: 'the role';

        $mail = (new MailMessage)
            ->subject("Your offer of employment — {$role}")
            ->greeting("Kia ora {$name},")
            ->line("We're delighted to offer you the position of **{$role}**.")
            ->line('You can review the full offer and respond using the secure link below.')
            ->action('Review your offer', $portalUrl);

        if ($this->offer->portal_expires_at) {
            $mail->line('This link expires on '.$this->offer->portal_expires_at->format('j F Y').'.');
        }

        return $mail
            ->line('If you have any questions, just reply to this email.')
            ->salutation('Ngā mihi, The Recruitment Team');
    }
}
