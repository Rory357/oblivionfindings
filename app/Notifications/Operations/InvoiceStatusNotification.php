<?php

namespace App\Notifications\Operations;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
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
            ->subject("Invoice {$this->action}: {$this->invoice->invoice_number}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                '%s has %s invoice %s for $%s.',
                $this->actorName,
                $this->action,
                $this->invoice->invoice_number,
                number_format($this->invoice->total_amount, 2)
            ))
            ->action('View Invoice', url("/operations/invoices/{$this->invoice->id}"))
            ->line('Please review and take any necessary action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'action' => $this->action,
            'amount' => $this->invoice->total_amount,
            'actor' => $this->actorName,
        ];
    }
}
