<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignatureOutcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{signature_id:int, document_title:string, signer_name:string, outcome:'signed'|'declined'}  $payload
     */
    public function __construct(
        private array $payload,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->payload['document_title'];
        $signer = $this->payload['signer_name'];
        $outcome = $this->payload['outcome'];
        $verb = $outcome === 'signed' ? 'signed' : 'declined';

        return (new MailMessage)
            ->subject("Signature request {$verb}: {$title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$signer} has {$verb} the signature request for {$title}.")
            ->action('Open HR documents', url('/hr/documents'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'signature_outcome',
            'signature_id' => $this->payload['signature_id'],
            'document_title' => $this->payload['document_title'],
            'signer_name' => $this->payload['signer_name'],
            'outcome' => $this->payload['outcome'],
            'action_url' => '/hr/documents',
        ];
    }
}
