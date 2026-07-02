<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hiring-manager-facing offer expiry signal from the daily sweep:
 * - kind=expiring — the candidate has not responded and the portal window
 *   closes within days.
 * - kind=expired  — one-time notice that the offer lapsed unanswered.
 */
class OfferExpiryInternalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'expiring'|'expired'  $kind
     */
    public function __construct(
        private HrOffer $offer,
        private HrCandidate $candidate,
        private string $kind,
        private ?int $daysLeft = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->offer->position_title ?: 'a role you are hiring for';

        if ($this->kind === 'expired') {
            return (new MailMessage)
                ->subject("Offer expired unanswered — {$this->candidate->full_name}")
                ->greeting('Kia ora,')
                ->line("The offer to **{$this->candidate->full_name}** for **{$role}** has passed its expiry date with no response.")
                ->line('You may want to follow up with the candidate, extend the portal window, or move on to other candidates.')
                ->action('Open Recruitment', url('/hr/recruitment?tab=offers'))
                ->salutation('Ngā mihi, The Recruitment Team');
        }

        $when = ($this->daysLeft ?? 0) <= 1 ? 'tomorrow' : "in {$this->daysLeft} days";

        return (new MailMessage)
            ->subject("Offer awaiting response — {$this->candidate->full_name}")
            ->greeting('Kia ora,')
            ->line("**{$this->candidate->full_name}** has not yet responded to the offer for **{$role}**.")
            ->line("The offer portal closes {$when}. The candidate has been sent a reminder.")
            ->action('Open Recruitment', url('/hr/recruitment?tab=offers'))
            ->salutation('Ngā mihi, The Recruitment Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->kind === 'expired' ? 'offer_expired' : 'offer_expiring',
            'offer_id' => $this->offer->id,
            'candidate_id' => $this->candidate->id,
            'candidate_name' => $this->candidate->full_name,
            'position_title' => $this->offer->position_title,
            'days_left' => $this->daysLeft,
            'expires_at' => optional($this->offer->portal_expires_at)->toDateTimeString(),
            'action_url' => '/hr/recruitment?tab=offers',
        ];
    }
}
