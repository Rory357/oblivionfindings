<?php

namespace App\Notifications\Operations;

use App\Models\FundingClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FundingClaimStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FundingClaim $claim,
        public string $action,
        public string $actorName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Funding Claim {$this->action}: {$this->claim->reference}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                '%s has %s funding claim %s for $%s.',
                $this->actorName,
                $this->action,
                $this->claim->reference,
                number_format($this->claim->amount, 2)
            ))
            ->action('View Funding Claims', url('/operations/funding'))
            ->line('Please review and take any necessary action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'claim_id' => $this->claim->id,
            'reference' => $this->claim->reference,
            'action' => $this->action,
            'amount' => $this->claim->amount,
            'actor' => $this->actorName,
        ];
    }
}
