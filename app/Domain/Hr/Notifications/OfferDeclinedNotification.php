<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the hiring manager (and the offer's author) that a candidate has
 * declined or withdrawn from an offer, so the seat can be re-worked instead
 * of silently going stale. Best-effort, sent to real Users.
 */
class OfferDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'declined'|'withdrawn'  $reason
     */
    public function __construct(
        private HrOffer $offer,
        private HrCandidate $candidate,
        private string $reason,
        private ?string $declineReason = null,
        private ?string $requisitionTitle = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->offer->position_title ?: ($this->requisitionTitle ?: 'a role you are hiring for');
        $verb = $this->reason === 'withdrawn' ? 'withdrawn from' : 'declined';

        $mail = (new MailMessage)
            ->subject("Offer {$this->reason} — {$this->candidate->full_name}")
            ->greeting('Kia ora,')
            ->line("**{$this->candidate->full_name}** has {$verb} the offer for **{$role}**.");

        if ($this->declineReason) {
            $mail->line("Reason given: {$this->declineReason}");
        }

        return $mail
            ->line('The opening stays live — you may want to revisit the shortlist or the talent pool.')
            ->action('Open Recruitment', url('/hr/recruitment?tab=offers'))
            ->salutation('Ngā mihi, The Recruitment Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'offer_declined',
            'offer_id' => $this->offer->id,
            'candidate_id' => $this->candidate->id,
            'candidate_name' => $this->candidate->full_name,
            'position_title' => $this->offer->position_title,
            'reason' => $this->reason,
            'decline_reason' => $this->declineReason,
            'action_url' => '/hr/recruitment?tab=offers',
        ];
    }
}
