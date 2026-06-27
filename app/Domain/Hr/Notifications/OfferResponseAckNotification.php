<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Acknowledges the candidate's offer response (accepted / declined). On-demand
 * routed to the candidate's personal email. Dispatched from respondOffer and the
 * public careers respondToOffer.
 */
class OfferResponseAckNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOffer $offer,
        private HrCandidate $candidate,
        private string $response,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->candidate->first_name ?: 'there';
        $role = $this->offer->position_title ?: 'the role';
        $mail = (new MailMessage)->greeting("Kia ora {$name},");

        if ($this->response === 'accepted') {
            return $mail
                ->subject("We've received your acceptance — {$role}")
                ->line("Thank you for accepting the offer for **{$role}**. We're thrilled to have you join us.")
                ->line('Our team will be in touch shortly with your onboarding details and next steps.')
                ->salutation('Ngā mihi, The Recruitment Team');
        }

        return $mail
            ->subject("Thank you for letting us know — {$role}")
            ->line("Thank you for letting us know your decision regarding the **{$role}** offer.")
            ->line("We appreciate the time you invested with us and wish you all the very best.")
            ->salutation('Ngā mihi, The Recruitment Team');
    }
}
