<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Candidate-facing nudge that their offer is still open and expires soon.
 * On-demand routed to the candidate's personal email by the daily
 * SendOfferExpiryRemindersJob sweep.
 */
class OfferExpiryReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOffer $offer,
        private HrCandidate $candidate,
        private int $daysLeft,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->candidate->first_name ?: 'there';
        $role = $this->offer->position_title ?: 'the role';
        $when = $this->daysLeft <= 1 ? 'tomorrow' : "in {$this->daysLeft} days";
        $expires = optional($this->offer->portal_expires_at)->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('D j M Y');

        return (new MailMessage)
            ->subject("Your offer for {$role} expires soon")
            ->greeting("Kia ora {$name},")
            ->line("Just a friendly reminder that your offer for **{$role}** is still waiting for your response.")
            ->line("The offer expires {$when}".($expires ? " (on {$expires})" : '').'.')
            ->line('If you have any questions, reply to your recruitment contact — we are happy to help.')
            ->salutation('Ngā mihi, The Recruitment Team');
    }
}
