<?php

namespace App\Domain\Hr\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignatureRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{signature_id:int, document_title:string, due_at:?string, message:?string}  $payload
     */
    public function __construct(
        private array $payload
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->payload['document_title'] ?? 'a document';
        $due = $this->payload['due_at'] ?? null;
        $formatted = $due ? Carbon::parse($due)->format('l, F j, Y') : null;

        $mail = (new MailMessage)
            ->subject("Signature requested: {$title}")
            ->greeting("Hello {$notifiable->name},")
            ->line('A document is awaiting your signature:')
            ->line("**{$title}**");

        if (! empty($this->payload['message'])) {
            $mail->line('"'.$this->payload['message'].'"');
        }

        if ($formatted) {
            $mail->line("Please sign by {$formatted}.");
        }

        return $mail->action('Review & sign', url('/hr/signatures/pending'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'signature_requested',
            'message' => "Signature requested: {$this->payload['document_title']}",
            'signature_id' => $this->payload['signature_id'] ?? null,
            'document_title' => $this->payload['document_title'] ?? null,
            'due_at' => $this->payload['due_at'] ?? null,
            'action_url' => '/hr/signatures/pending',
        ];
    }
}
