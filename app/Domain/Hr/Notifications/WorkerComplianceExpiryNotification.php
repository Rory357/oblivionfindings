<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkerComplianceExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private array $item) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Renewal due: {$this->item['title']}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$this->item['title']} expires on {$this->item['expires_at']}.")
            ->line('Please arrange renewal before it expires.')
            ->action('View HR compliance', url('/hr/compliance'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'worker_compliance_expiry',
            'source_type' => $this->item['source_type'],
            'source_id' => $this->item['source_id'],
            'title' => $this->item['title'],
            'expires_at' => $this->item['expires_at'],
            'action_url' => '/hr/compliance',
        ];
    }
}
