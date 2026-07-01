<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{document_id:int,title:string,expires_at:?string,reminder_days:int}  $document
     */
    public function __construct(
        private array $document
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->document['title'] ?? 'HR document';
        $expiresAt = $this->document['expires_at'] ?? null;
        $formattedDate = $expiresAt ? \Carbon\Carbon::parse($expiresAt)->format('l, F j, Y') : 'N/A';

        return (new MailMessage)
            ->subject("Document Expiring: {$title}")
            ->line('An HR document on file is approaching its expiry date:')
            ->line("**{$title}**")
            ->line("Expires: {$formattedDate}")
            ->line('Please arrange a renewal or replacement before it lapses.')
            ->action('View Documents', url('/hr/documents'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_expiry',
            'message' => "Document expiring: {$this->document['title']}",
            'document_id' => $this->document['document_id'] ?? null,
            'expires_at' => $this->document['expires_at'] ?? null,
            'reminder_days' => $this->document['reminder_days'] ?? null,
            'action_url' => '/hr/documents',
        ];
    }
}
