<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinBill;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FinBill $bill,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amountDue = number_format($this->bill->getAmountDue(), 2);
        $vendorName = $this->bill->vendor->name ?? 'Unknown vendor';
        $daysOverdue = $this->bill->due_date->diffInDays(now());

        return (new MailMessage)
            ->subject("OVERDUE: Bill {$this->bill->bill_number} — {$daysOverdue} days past due")
            ->line("Bill **{$this->bill->bill_number}** from **{$vendorName}** is **{$daysOverdue} days overdue**.")
            ->line('')
            ->line("**Amount Due:** \${$amountDue} NZD")
            ->line("**Due Date:** {$this->bill->due_date->format('j F Y')}")
            ->action('View Bill', url("/finance/bills/{$this->bill->id}"))
            ->line('Please arrange payment as soon as possible to avoid further delays.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bill_overdue',
            'bill_id' => $this->bill->id,
            'bill_number' => $this->bill->bill_number,
            'amount_due' => $this->bill->getAmountDue(),
            'due_date' => $this->bill->due_date->toDateString(),
            'days_overdue' => $this->bill->due_date->diffInDays(now()),
        ];
    }
}
