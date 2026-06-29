<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Drives the offer approval chain: 'requested' goes to the approver, while
 * 'approved' / 'declined' go back to the offer's creator. Sent to real Users.
 */
class OfferApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOffer $offer,
        private string $type,
        private string $candidateName,
        private ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->offer->position_title ?: 'a role';
        $url = url('/hr/recruitment?tab=offers');
        $mail = (new MailMessage)->greeting('Kia ora,');

        return match ($this->type) {
            'requested' => $mail
                ->subject("Offer approval needed — {$this->candidateName}")
                ->line("An offer for **{$this->candidateName}** ({$role}) is awaiting your approval before it can be sent.")
                ->action('Review the offer', $url)
                ->salutation('Ngā mihi, The Recruitment Team'),
            'reminder' => $mail
                ->subject("Reminder: offer still awaiting approval — {$this->candidateName}")
                ->line("A gentle reminder that the offer for **{$this->candidateName}** ({$role}) is still awaiting your approval. It can't be sent to the candidate until it's approved.")
                ->action('Review the offer', $url)
                ->salutation('Ngā mihi, The Recruitment Team'),
            'approved' => $mail
                ->subject("Offer approved — {$this->candidateName}")
                ->line("Your offer for **{$this->candidateName}** ({$role}) has been approved and is ready to send.")
                ->action('Send the offer', $url)
                ->salutation('Ngā mihi, The Recruitment Team'),
            default => $mail
                ->subject("Offer needs changes — {$this->candidateName}")
                ->line("Your offer for **{$this->candidateName}** ({$role}) was declined.")
                ->line($this->reason ? "Reason: {$this->reason}" : 'No reason was provided.')
                ->action('Revise the offer', $url)
                ->salutation('Ngā mihi, The Recruitment Team'),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'offer_approval_'.$this->type,
            'offer_id' => $this->offer->id,
            'candidate_name' => $this->candidateName,
            'position_title' => $this->offer->position_title,
            'reason' => $this->reason,
            'action_url' => '/hr/recruitment?tab=offers',
        ];
    }
}
